<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 補齊 2026_07_10_000000_restructure_pinyin_table 漏做的一半：把 audit_log 裡
 * pinyin 的欄名快照一併改名（lastname_chn → c_chn、lastname_pinyin → c_pinyin）。
 *
 * 為什麼要改：audit_log.row_pk 記的是「寫入當下的欄名」，
 * OperationsController::resolveAuditCurrentRow() 會直接拿它組 WHERE 去查現況。
 * pinyin 欄位在 2026_07_10 改名後，這批舊快照的欄名在現行 schema 已不存在，
 * 查詢直接 SQLSTATE[42S22]，把 /operations 與 /app/operations 整頁打成 500
 * （生產事故 2026-08-17）。程式端已改為「欄名對不上就放棄查現況」不再 500；
 * 本 migration 進一步把欄名補正，讓這幾筆的「現況」欄真的顯示得出來。
 *
 * row_pk_text / old_data / new_data 也一起改：buildAuditDiff() 是照 key 名逐欄比對的，
 * 只改 row_pk 會查到現況列卻每一欄都對不上 key，仍舊顯示「未取得」。
 * **只改欄名，不動任何值**——稽核事實（誰在何時把值改成什麼）完整保留，
 * 改的只是同一個欄位現在叫什麼名字。
 *
 * 生產實測影響範圍：audit_log 全表僅 4 列（id 450–453，operation 259047/259049/259050/259051）。
 * 以「JSON 內含舊欄名」為條件篩選，可重複執行（跑第二次會篩不到任何列）。
 */
return new class () extends Migration {
    /** 舊欄名 → 新欄名。 */
    private const RENAMES = [
        'lastname_chn' => 'c_chn',
        'lastname_pinyin' => 'c_pinyin',
    ];

    public function up(): void {
        $this->renameKeys(self::RENAMES);
    }

    /**
     * 故意留空（比照 2026_08_11_000001_downgrade_wildcard_api_token_abilities）。
     *
     * 反向改名不能做：`migrate:rollback` 只回滾**最後一批**，而 pinyin 表本身改名的
     * 2026_07_10 是更早的批次。所以單跑一次 rollback 之後，`pinyin` 表還是 `c_chn`，
     * 反向卻會把稽核快照全寫回 `lastname_chn`——而且篩選條件是「含新欄名」，
     * 命中的是**所有** pinyin 稽核列（不只 up() 改過的 4 筆），等於把本次要修的
     * 欄名漂移從 4 列擴散到全部。已實測確認過這個放大效果。
     *
     * 本 migration 只是把欄名對齊現行 schema，不新增也不刪除任何資訊，
     * 留空不會擋住任何後續回滾；真要退回舊欄名，跑一次 up() 的反向腳本即可。
     */
    public function down(): void {
        // 故意留空，理由見上方 docblock。
    }

    /**
     * @param array<string, string> $renames 來源欄名 → 目標欄名
     */
    private function renameKeys(array $renames): void {
        if (!Schema::hasTable('audit_log')) {
            return;
        }

        $sourceKeys = array_keys($renames);

        DB::table('audit_log')
            ->where('table_name', 'pinyin')
            // 三個快照欄任一含舊欄名就要處理；用 LIKE 粗篩，實際改名仍逐欄精確比對 key。
            ->where(function ($query) use ($sourceKeys) {
                foreach ($sourceKeys as $key) {
                    foreach (['row_pk', 'row_pk_text', 'old_data', 'new_data'] as $column) {
                        $query->orWhere($column, 'like', '%' . $key . '%');
                    }
                }
            })
            ->select(['id', 'row_pk', 'row_pk_text', 'old_data', 'new_data'])
            // 用 chunkById 而非 chunk：改名後該列就不再符合上面的 LIKE 條件，
            // 用 OFFSET 分頁會把後續列整批跳過；按 id 推進才不漏。
            ->chunkById(200, function ($rows) use ($renames) {
                foreach ($rows as $row) {
                    // 所有「不安全」判定都在這裡一次做完，而且是**整列**放棄。
                    // 逐欄各自決定會出現「row_pk 改了、new_data 沒改」的半套狀態，
                    // 讓同一列的各個快照互相矛盾——那比原封不動更糟。
                    $skip = $this->rowSkipReason($row, $renames);
                    if ($skip !== null) {
                        Log::warning('audit_log 稽核快照不安全，整列略過改名', [
                            'migration' => '2026_08_17_000000_rename_pinyin_columns_in_audit_log_snapshots',
                            'audit_log_id' => $row->id,
                            'reason' => $skip['reason'],
                            'column' => $skip['column'],
                        ]);

                        continue;
                    }

                    $updates = [];

                    foreach (['row_pk', 'old_data', 'new_data'] as $column) {
                        $renamed = $this->renameJsonObjectKeys($row->{$column} ?? null, $renames);
                        if ($renamed !== null) {
                            $updates[$column] = $renamed;
                        }
                    }

                    $renamedText = $this->renameQueryStringKeys($row->row_pk_text ?? null, $renames);
                    if ($renamedText !== null) {
                        $updates['row_pk_text'] = $renamedText;
                    }

                    if (!empty($updates)) {
                        DB::table('audit_log')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }

    /**
     * 這一列是否有任何理由不該動。有的話回傳 ['reason' => …, 'column' => …]，安全則回傳 null。
     *
     * 兩種不安全：
     *  1. 新舊欄名並存——哪個才權威無從判斷，硬改會覆蓋掉其中一個值。
     *  2. 需要改名的欄無法無損重新編碼——`json_decode()` 對重複 key 只留最後一個
     *     （`{"a":1,"a":2}` → `{"a":2}`），重編就會永久丟掉前一個值；非標準轉義／空白排版
     *     也會讓重編順手改掉不該改的位元組。
     *
     * 不需要改名的欄即使不是標準編碼也不影響——反正不會被寫入，不該因此擋掉整列。
     *
     * @param array<string, string> $renames
     * @return array{reason: string, column: string}|null
     */
    private function rowSkipReason(object $row, array $renames): ?array {
        foreach (['row_pk', 'old_data', 'new_data'] as $column) {
            $raw = $row->{$column} ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            $raw = (string) $raw;

            $decoded = json_decode($raw, true);

            // 不是合法 JSON ⇒ 無從得知它的 key 有哪些，也就無法保證這一欄不需要改名；
            // 此時改別的欄會留下半套狀態，故整列不動。
            // 刻意不用「字面上有沒有舊欄名」來判斷——key 可以是 \uXXXX 轉義
            // （`{"lastname_chn":…}`），字串比對抓不到。反正我們本來就不會改動
            // 解不開的快照，整列跳過是唯一能保證不出現半套狀態的做法。
            // （MariaDB 的 json 欄本身帶 CHECK json_valid，生產庫進不了非法 JSON；
            //   這條是給 SQLite／歷史遺留資料的防線。）
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['reason' => '快照不是合法 JSON，無從判斷欄名', 'column' => $column];
            }

            // 合法 JSON 但不是物件（陣列／純量）⇒ 最外層沒有字串 key，不可能需要改名。
            if (!is_array($decoded) || array_is_list($decoded)) {
                continue;
            }

            foreach ($renames as $from => $to) {
                if (array_key_exists($from, $decoded) && array_key_exists($to, $decoded)) {
                    return ['reason' => "新舊欄名並存（{$from} / {$to}）", 'column' => $column];
                }
            }

            $needsRename = !empty(array_intersect(array_keys($decoded), array_keys($renames)));
            if ($needsRename && $this->encode($decoded) !== $raw) {
                return ['reason' => '無法無損重新編碼（可能含重複 key 或非標準編碼）', 'column' => $column];
            }
        }

        $text = (string) ($row->row_pk_text ?? '');
        if ($text !== '') {
            $presentKeys = [];
            foreach (explode('&', $text) as $pair) {
                $separatorAt = strpos($pair, '=');
                $presentKeys[] = rawurldecode($separatorAt === false ? $pair : substr($pair, 0, $separatorAt));
            }
            foreach ($renames as $from => $to) {
                if (in_array($from, $presentKeys, true) && in_array($to, $presentKeys, true)) {
                    return ['reason' => "新舊欄名並存（{$from} / {$to}）", 'column' => 'row_pk_text'];
                }
            }
        }

        return null;
    }

    /**
     * 改寫 JSON 物件的最外層 key 名，值與 key 順序原樣保留。
     * 不是 JSON 物件、或沒有任何 key 需要改名時回傳 null（呼叫端據此略過該欄）。
     *
     * @param array<string, string> $renames
     */
    private function renameJsonObjectKeys(?string $json, array $renames): ?string {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        // 「新舊欄名並存」與「無法無損重新編碼」都已由 rowSkipReason() 在列層級擋掉，
        // 走到這裡代表這一列整體安全，這裡只負責純改名。
        $changed = false;
        $result = [];
        foreach ($decoded as $key => $value) {
            $newKey = $renames[$key] ?? $key;
            if ($newKey !== $key) {
                $changed = true;
            }
            $result[$newKey] = $value;
        }

        if (!$changed) {
            return null;
        }

        return $this->encode($result);
    }

    /** 與 AuditLogService::encodeJson() 同旗標，避免改名後中文變成 \uXXXX。 */
    private function encode(array $value): string {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 改寫 row_pk_text（`k=v&k=v` 形式）的 key 名。
     * 刻意只動 '=' 左邊、不解碼右邊，讓值的既有百分號編碼原封不動。
     *
     * @param array<string, string> $renames
     */
    private function renameQueryStringKeys(?string $text, array $renames): ?string {
        if ($text === null || $text === '') {
            return null;
        }

        // 新舊欄名並存的情形已由 rowSkipReason() 在列層級擋掉，這裡只負責純改名。
        $segments = explode('&', $text);

        $changed = false;
        $pairs = [];
        foreach ($segments as $pair) {
            $separatorAt = strpos($pair, '=');
            // 沒有 '=' 的片段整段當 key 看待——與 rowSkipReason() 的並存判定同一套解讀，
            // 否則「只有 key 沒有值」的片段會被留在舊欄名，形成半套狀態。
            $key = $separatorAt === false ? $pair : substr($pair, 0, $separatorAt);
            $suffix = $separatorAt === false ? '' : substr($pair, $separatorAt);

            $newKey = $renames[rawurldecode($key)] ?? null;
            if ($newKey === null) {
                $pairs[] = $pair;

                continue;
            }

            $changed = true;
            $pairs[] = rawurlencode($newKey) . $suffix;
        }

        return $changed ? implode('&', $pairs) : null;
    }
};
