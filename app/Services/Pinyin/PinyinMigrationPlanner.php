<?php

namespace App\Services\Pinyin;

use Illuminate\Support\Facades\DB;

/**
 * 人名拼音 v→ü 遷移的「規劃器」（只計算、不寫入）。
 *
 * 依 docs/PINYIN_V_TO_UMLAUT_MIGRATION.md §D-2／§D-3／§D-5／§D-6：
 * - BIOG_MAIN（**Sheet 權威優先**，dry-run 實測後定案）：寫入值取自 Sheet 的 correct 人工值——
 *   兩分量行都有則直接採用；一分量行＋完整名（c_name）行則由完整名扣除已知分量推導另一分量。
 *   寫入前做漂移檢查（現值須 == Sheet wrong）。`auto_pinyin` 重生**僅作交叉驗證信心標記**
 *   （regen==最終值→high，否則→low、仍寫 Sheet 值），不再當寫不寫的 oracle——因實測顯示
 *   auto_pinyin 對生僻字/多音字/「之妻」英譯等約 27% 人名無法復現人工校訂，Sheet 才是權威。
 *   只送 c_surname/c_mingzi，c_name 由 handler 重算。孤兒（只有 c_name 行）交人工。
 * - ALTNAME_DATA：以 (c_personid, c_alt_name=wrong_pinyin) 定位完整 3-key PK；>1 命中→歧義例外。
 *
 * 產物為「預定變更集 + 例外／跳過清單」，供 dry-run 複核與（M5）實際送出。
 * 本類別**不做任何寫入**、亦不觸碰 token。
 */
class PinyinMigrationPlanner {
    /** @var \Closure(string): array{c_surname:string,c_mingzi:string,c_name:string} */
    private $regenerator;

    /**
     * @param \Closure $regenerator 以中文名（c_name_chn）重新合成拼音，回傳含 c_surname/c_mingzi/c_name 的陣列。
     */
    public function __construct(\Closure $regenerator) {
        $this->regenerator = $regenerator;
    }

    /**
     * 規劃 BIOG_MAIN 人名修正。
     *
     * @param  array<int, array{id:int|string, field:string, wrong_pinyin:string, correct_pinyin:string}>  $rows
     * @return array{mutations:array<int,array>, exceptions:array<int,array>, skipped:array<int,array>, alreadyDone:array<int,array>}
     */
    public function planBiogMain(array $rows): array {
        $byPerson = [];
        foreach ($rows as $row) {
            // 依 §D-3 忽略 Sheet 的 c_name 直寫列作為「待寫欄位」，但仍留作 oracle 核對。
            $byPerson[(int) $row['id']][] = $row;
        }

        $out = ['mutations' => [], 'exceptions' => [], 'skipped' => [], 'alreadyDone' => []];

        foreach ($byPerson as $personId => $personRows) {
            $current = DB::table('BIOG_MAIN')->where('c_personid', $personId)->first();
            if ($current === null) {
                $out['exceptions'][] = ['id' => $personId, 'reason' => 'person-not-found'];

                continue;
            }
            $current = (array) $current;

            // 收集該人的 Sheet 各欄位 wrong/correct；同一欄位重複且值不同 → 資料衝突例外（不靜默覆寫）。
            $sheet = [];
            $dupField = null;
            foreach ($personRows as $row) {
                $field = $row['field'];
                if (
                    isset($sheet[$field])
                    && (
                        $sheet[$field]['wrong'] !== $row['wrong_pinyin']
                        || $sheet[$field]['correct'] !== $row['correct_pinyin']
                    )
                ) {
                    $dupField = $field;
                }
                $sheet[$field] = ['wrong' => $row['wrong_pinyin'], 'correct' => $row['correct_pinyin']];
            }
            if ($dupField !== null) {
                $out['exceptions'][] = ['id' => $personId, 'reason' => 'duplicate-field', 'field' => $dupField];

                continue;
            }

            // 寫入前漂移檢查（②a）：逐欄比對現值。
            $allAlready = true;
            $driftFields = [];
            foreach ($sheet as $field => $wc) {
                $currentVal = (string) ($current[$field] ?? '');
                if ($currentVal === $wc['wrong']) {
                    $allAlready = false;
                } elseif ($currentVal === $wc['correct']) {
                    // 該欄已遷移。
                } else {
                    $allAlready = false;
                    $driftFields[] = ['field' => $field, 'current' => $currentVal, 'expected_wrong' => $wc['wrong']];
                }
            }
            if ($allAlready) {
                $out['alreadyDone'][] = ['id' => $personId, 'c_name_chn' => $current['c_name_chn'] ?? ''];

                continue;
            }
            if ($driftFields !== []) {
                $out['skipped'][] = ['id' => $personId, 'reason' => 'drift', 'fields' => $driftFields];

                continue;
            }

            // Sheet 權威：由 Sheet 的 correct 分量／完整名推導最終 c_surname / c_mingzi。
            [$finalSurname, $finalMingzi, $onlyField, $err] = $this->resolveTargetName($sheet, $current);
            if ($err !== null) {
                $out['exceptions'][] = [
                    'id' => $personId,
                    'reason' => $err,
                    'c_name_chn' => $current['c_name_chn'] ?? '',
                    'sheet' => $sheet,
                ];

                continue;
            }

            // 以 regenerate 交叉驗證信心（不決定寫不寫，只標記供抽查）。
            $regen = ($this->regenerator)((string) ($current['c_name_chn'] ?? ''));
            $regenName = trim(((string) $regen['c_surname']).' '.((string) $regen['c_mingzi']));
            $finalName = trim($finalSurname.' '.$finalMingzi);
            $confidence = ($regenName === $finalName) ? 'high' : 'low';

            if ($onlyField === 'c_surname') {
                $changes = ['c_surname' => $finalSurname];
            } elseif ($onlyField === 'c_mingzi') {
                $changes = ['c_mingzi' => $finalMingzi];
            } else {
                // 有完整名／兩分量：兩分量一併送，確保 handler 重算的 c_name 正確。
                $changes = ['c_surname' => $finalSurname, 'c_mingzi' => $finalMingzi];
            }

            $out['mutations'][] = [
                'resource' => 'basicinformation',
                'pk' => ['c_personid' => (int) $personId],
                'changes' => $changes,
                'confidence' => $confidence,
                'preview' => [
                    'c_name_chn' => $current['c_name_chn'] ?? '',
                    'from' => trim(((string) ($current['c_surname'] ?? '')).' '.((string) ($current['c_mingzi'] ?? ''))),
                    'to' => $finalName,
                    'regen' => $regenName,
                ],
            ];
        }

        return $out;
    }

    /**
     * 由 Sheet 的 correct 值決定最終要寫入的 c_surname / c_mingzi（Sheet 權威）。
     *
     * 規則：
     * - 兩分量行都有 → 直接採用。
     * - 一分量行 + 完整名（c_name）行 → 採該分量、另一分量由完整名扣除已知分量推導。
     * - 只有一分量行（無完整名）→ 只改該分量，另一分量維持現值（onlyField）。
     * - 只有 c_name 行（孤兒）或推導對不上前後綴 → 回傳錯誤原因，交人工。
     *
     * @param  array<string, array{wrong:string, correct:string}>  $sheet
     * @param  array<string, mixed>  $current
     * @return array{0:string, 1:string, 2:?string, 3:?string}  [surname, mingzi, onlyField, error]
     */
    private function resolveTargetName(array $sheet, array $current): array {
        $s = $sheet['c_surname']['correct'] ?? null;
        $m = $sheet['c_mingzi']['correct'] ?? null;
        $n = $sheet['c_name']['correct'] ?? null;
        $curS = (string) ($current['c_surname'] ?? '');
        $curM = (string) ($current['c_mingzi'] ?? '');
        $blank = static fn (string $v): bool => trim($v) === '';
        $trimmedName = $n !== null ? trim($n) : null;

        if ($trimmedName !== null) {
            // 有完整名（權威）：最終兩分量必須滿足 trim(surname.' '.mingzi) == n。
            if ($s !== null && $m !== null) {
                if ($blank($s) || $blank($m)) {
                    return ['', '', null, 'derived-empty'];
                }
                if (trim($s.' '.$m) !== $trimmedName) {
                    return ['', '', null, 'sheet-inconsistent'];
                }

                return [$s, $m, null, null];
            }
            if ($s !== null) {
                if ($blank($s)) {
                    return ['', '', null, 'derived-empty'];
                }
                if ($trimmedName === $s) {
                    // 完整名即姓（名應為空）：現值 mingzi 非空則矛盾（避免殘留舊名）→ 交人工。
                    if (!$blank($curM)) {
                        return ['', '', null, 'name-is-surname-but-mingzi-present'];
                    }

                    return [$s, '', 'c_surname', null];
                }
                if (str_starts_with($trimmedName, $s.' ')) {
                    $mingzi = ltrim(substr($trimmedName, strlen($s)));
                    if ($blank($mingzi)) {
                        return ['', '', null, 'derived-empty'];
                    }

                    return [$s, $mingzi, null, null];
                }

                return ['', '', null, 'cannot-split-prefix'];
            }
            if ($m !== null) {
                if ($blank($m)) {
                    return ['', '', null, 'derived-empty'];
                }
                if ($trimmedName === $m) {
                    if (!$blank($curS)) {
                        return ['', '', null, 'name-is-mingzi-but-surname-present'];
                    }

                    return ['', $m, 'c_mingzi', null];
                }
                if (str_ends_with($trimmedName, ' '.$m)) {
                    $surname = substr($trimmedName, 0, strlen($trimmedName) - strlen($m) - 1);
                    if ($blank($surname)) {
                        return ['', '', null, 'derived-empty'];
                    }

                    return [$surname, $m, null, null];
                }

                return ['', '', null, 'cannot-split-suffix'];
            }

            // 只有完整名列，無分量行 → 無法可靠拆分（交人工）。
            return ['', '', null, 'orphan-cname'];
        }

        // 無完整名列：只改 Sheet 明確標記的分量（尊重 scoping）。
        if ($s !== null && $m !== null) {
            if ($blank($s) || $blank($m)) {
                return ['', '', null, 'derived-empty'];
            }

            return [$s, $m, null, null];
        }
        if ($s !== null) {
            if ($blank($s)) {
                return ['', '', null, 'derived-empty'];
            }

            return [$s, $curM, 'c_surname', null];
        }
        if ($m !== null) {
            if ($blank($m)) {
                return ['', '', null, 'derived-empty'];
            }

            return [$curS, $m, 'c_mingzi', null];
        }

        return ['', '', null, 'no-sheet-field'];
    }

    /**
     * 規劃 ALTNAME_DATA 別名修正（複合主鍵定位，§D-5）。
     *
     * @param  array<int, array{id:int|string, wrong_pinyin:string, correct_pinyin:string}>  $rows
     * @return array{mutations:array<int,array>, exceptions:array<int,array>, skipped:array<int,array>, alreadyDone:array<int,array>}
     */
    public function planAltname(array $rows): array {
        $out = ['mutations' => [], 'exceptions' => [], 'skipped' => [], 'alreadyDone' => []];

        foreach ($rows as $row) {
            $personId = (int) $row['id'];
            $wrong = $row['wrong_pinyin'];
            $correct = $row['correct_pinyin'];

            $matches = DB::table('ALTNAME_DATA')
                ->where('c_personid', $personId)
                ->where('c_alt_name', $wrong)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();

            if (count($matches) === 0) {
                // 定位落空：可能已遷移（現存 correct）或值已漂移。
                $alreadyExists = DB::table('ALTNAME_DATA')
                    ->where('c_personid', $personId)
                    ->where('c_alt_name', $correct)
                    ->exists();
                if ($alreadyExists) {
                    $out['alreadyDone'][] = ['id' => $personId, 'c_alt_name' => $correct];
                } else {
                    $out['skipped'][] = ['id' => $personId, 'reason' => 'not-found', 'wrong_pinyin' => $wrong];
                }

                continue;
            }
            if (count($matches) > 1) {
                $out['exceptions'][] = ['id' => $personId, 'reason' => 'ambiguous', 'wrong_pinyin' => $wrong, 'match_count' => count($matches)];

                continue;
            }

            $m = $matches[0];
            $out['mutations'][] = [
                'resource' => 'altnames',
                'pk' => [
                    'c_personid' => $personId,
                    'c_alt_name_chn' => $m['c_alt_name_chn'] ?? '',
                    'c_alt_name_type_code' => (int) ($m['c_alt_name_type_code'] ?? 0),
                ],
                'changes' => ['c_alt_name' => $correct],
                'preview' => ['from' => $wrong, 'to' => $correct, 'c_alt_name_chn' => $m['c_alt_name_chn'] ?? ''],
            ];
        }

        return $out;
    }
}
