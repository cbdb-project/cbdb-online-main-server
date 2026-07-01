<?php

namespace App\Services\Pinyin;

use Illuminate\Support\Facades\DB;

/**
 * 人名拼音 v→ü 遷移的「規劃器」（只計算、不寫入）。
 *
 * 依 docs/PINYIN_V_TO_UMLAUT_MIGRATION.md §D-3／§D-5／§D-6 與 Frank #1087 審查：
 * - BIOG_MAIN：以 auto_pinyin 重新合成（regenerate），寫入前先做漂移檢查（現值須 == Sheet wrong_pinyin），
 *   並以 oracle 閘把關（重生結果須 == Sheet correct_pinyin）；只送 c_surname/c_mingzi，c_name 由 handler 重算。
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

            // 重生（regenerate）：以現有中文名重新合成拼音。
            $regen = ($this->regenerator)((string) ($current['c_name_chn'] ?? ''));

            $driftFields = [];       // 現值既非 wrong 也非 correct → 未預期漂移
            $mismatchFields = [];    // 重生結果 != Sheet correct → oracle 不過
            $allAlready = true;      // 全部欄位現值已 == correct

            foreach ($personRows as $row) {
                $field = $row['field'];
                $wrong = $row['wrong_pinyin'];
                $correct = $row['correct_pinyin'];
                $currentVal = (string) ($current[$field] ?? '');
                $regenVal = (string) ($regen[$field] ?? '');

                if ($currentVal === $wrong) {
                    $allAlready = false;
                } elseif ($currentVal === $correct) {
                    // 該欄位已遷移；不影響其它欄位判斷。
                } else {
                    $allAlready = false;
                    $driftFields[] = ['field' => $field, 'current' => $currentVal, 'expected_wrong' => $wrong];
                }

                // oracle：重生結果須等於 Sheet correct（含 c_name 列作為額外核對）。
                if ($regenVal !== $correct) {
                    $mismatchFields[] = ['field' => $field, 'regenerated' => $regenVal, 'expected_correct' => $correct];
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
            if ($mismatchFields !== []) {
                $out['exceptions'][] = ['id' => $personId, 'reason' => 'regenerate-mismatch', 'fields' => $mismatchFields];

                continue;
            }

            // 通過：決定 changes。
            // - 若有 c_name 列（完整名已被 oracle 驗證）：**一律送兩個分量的重生值**，確保 handler
            //   重算的 c_name == 已驗證的 correct（避免只送 surname、卻讓另一分量殘留 v → c_name 仍錯）。
            // - 若無 c_name 列：只送 Sheet 明確標記的分量，尊重人工 scoping（不動未標記的欄位）。
            $hasCNameRow = false;
            $flagged = [];
            foreach ($personRows as $row) {
                if ($row['field'] === 'c_name') {
                    $hasCNameRow = true;
                } elseif (in_array($row['field'], ['c_surname', 'c_mingzi'], true)) {
                    $flagged[$row['field']] = true;
                }
            }

            if ($hasCNameRow) {
                $changes = [
                    'c_surname' => (string) $regen['c_surname'],
                    'c_mingzi' => (string) $regen['c_mingzi'],
                ];
            } else {
                $changes = [];
                foreach (array_keys($flagged) as $field) {
                    $changes[$field] = (string) ($regen[$field] ?? '');
                }
            }

            $out['mutations'][] = [
                'resource' => 'basicinformation',
                'pk' => ['c_personid' => (int) $personId],
                'changes' => $changes,
                'preview' => [
                    'c_name_chn' => $current['c_name_chn'] ?? '',
                    'from' => trim(((string) ($current['c_surname'] ?? '')).' '.((string) ($current['c_mingzi'] ?? ''))),
                    'to' => trim(((string) $regen['c_surname']).' '.((string) $regen['c_mingzi'])),
                ],
            ];
        }

        return $out;
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
