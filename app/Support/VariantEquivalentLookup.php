<?php

namespace App\Support;

use App\Models\Operation;
use App\Services\CharVariantMapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * D7「兩形並存」查重：既有列可能存**變體形**（D6 不做回溯校正），所以新增時只拿
 * 「替換後的值」做精確比對是不夠的——會鑄出兩列語義相同、字形不同的資料，而資料庫
 * 唯一鍵**擋不住**（不同字形＝不同鍵值）。那比完全不替換更糟。
 *
 * 例：`ALTNAME_DATA` 已有 `('愼齋', type 4, person)`。使用者再送一次 `愼齋`
 * → 替換成 `慎齋` → 精確查重找不到 → INSERT ⇒ 同一人有 `愼齋` 與 `慎齋` 兩筆別名。
 * 在落地替換上線之前，同樣的輸入會被乾淨的 409 擋下。
 *
 * 做法：**把不在替換範圍內的主鍵欄固定在 SQL 條件裡，取回那一小群候選列，再在 PHP 端
 * 把它們的文本主鍵歸一後比對**。這樣是**精確**的（不管對照表是什麼形狀）、而且只要
 * **一次查詢**。早期版本「列舉所有等價字形再逐一查」有兩個缺點：查詢次數是等價字形數，
 * 而且為避免組合爆炸設的上限本身就是正確性缺口（超過上限就退回只比正規形）。
 *
 * 呼叫端：`CodesController`（代碼表 CRUD 的 5 條寫入路徑）與 v2 mutation 的 create
 * 路徑（`AbstractPersonSubresourceCreateHandler`、`SourceMutationHandler`）。
 */
class VariantEquivalentLookup {
    /**
     * 主鍵欄位分成「在替換範圍內的文本欄」與「其餘」。
     *
     * @param array<int,string> $keyColumns
     * @return array{0: array<int,string>, 1: array<int,string>} [替換範圍內的, 其餘的]
     */
    public static function splitKeyColumns(string $table, array $keyColumns): array {
        $inScope = [];
        $fixed = [];

        foreach ($keyColumns as $column) {
            if (VariantReplaceScope::modeFor($table, $column) === null) {
                $fixed[] = $column;
            } else {
                $inScope[] = $column;
            }
        }

        return [$inScope, $fixed];
    }

    /**
     * 找出「歸一後與本次輸入相同」的既有列（可能存的是變體形）。
     *
     * @param array<int,string> $keyColumns
     * @param array<string,mixed> $after 替換後的資料
     * @param array<int,array<string,mixed>> $excludePks 要排除的列（以完整主鍵指定），
     *        典型用途是改鍵時排除「正在編輯的那一列自己」。
     *        **排除必須在這裡做、不能由呼叫端拿回傳值判斷**：候選集可以有多列同時歸一成
     *        同一個值（例如 `愼齋` 與 `慬齋` 都歸一成 `慎齋`），若這裡回傳第一筆、呼叫端
     *        看到那是自己就當成「沒衝突」，真正衝突的另一列就被漏掉、照樣鑄出兩形並存列。
     * @return mixed 既有列（stdClass）或 null
     */
    public static function findExistingRow(string $table, array $keyColumns, array $after, array $excludePks = []) {
        [$inScope, $fixed] = self::splitKeyColumns($table, $keyColumns);

        // 主鍵沒有任何可替換的文本欄 ⇒ 一般查重就夠，零額外成本。
        if ($inScope === []) {
            return null;
        }

        // 全部主鍵欄都可替換 ⇒ 沒有能收斂候選集的 SQL 條件，會變成全表掃描。
        // 目前沒有這種表（ALTNAME_DATA 是 3 鍵、只有 1 個文本欄可替換），
        // 真的出現時記 warning 並跳過，不要靜默做全表掃描。
        if ($fixed === []) {
            Log::warning('D7 去重跳過：主鍵全部在替換範圍內，無法收斂候選集', [
                'table' => $table,
                'key_columns' => $keyColumns,
            ]);

            return null;
        }

        $query = DB::table($table);
        foreach ($fixed as $column) {
            if (!array_key_exists($column, $after)) {
                return null;
            }
            $query->where($column, $after[$column]);
        }

        foreach ($query->get() as $row) {
            if (!self::rowMatchesAfterNormalization($table, $inScope, (array) $row, $after)) {
                continue;
            }
            if (self::matchesAnyPk($keyColumns, (array) $row, $excludePks)) {
                continue; // 是被排除的列（通常就是正在編輯的自己）——繼續找下一個候選
            }

            return $row;
        }

        return null;
    }

    /**
     * 候選列的主鍵是否等於 $excludePks 裡的任一組。
     *
     * @param array<int,string> $keyColumns
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $excludePks
     */
    private static function matchesAnyPk(array $keyColumns, array $row, array $excludePks): bool {
        foreach ($excludePks as $pk) {
            $same = true;
            foreach ($keyColumns as $column) {
                if ((string) ($row[$column] ?? '') !== (string) ($pk[$column] ?? '')) {
                    $same = false;

                    break;
                }
            }
            if ($same) {
                return true;
            }
        }

        return false;
    }

    /**
     * 待審的新增提案裡有沒有「歸一後與本次輸入相同」的一筆。
     *
     * 一般的 `hasPendingCreateProposal()` 是拿 `resource_id` 做完全相等比對，所以帶
     * 變體形 resource_id 的舊提案不會與帶歸一後 resource_id 的新提案衝突 ⇒ 兩筆並存、
     * 依序核准就落成兩種字形的兩列。
     *
     * @param array<int,string> $keyColumns
     * @param array<string,mixed> $after
     * @param int|null $excludeOperationId 核准重放時要排除的「自己那一筆」
     * @param string|null $resourceIdLikePattern 代碼表用：位置式 resource_id 的 LIKE 樣式
     * @param array<int,string> $conflictingStatuses 哪些 `__review_status` 算衝突。預設
     *        `['pending','rejected']`（對齊 `OperationRepository::hasPendingCreateProposal()`）；
     *        `BIOG_SOURCE_DATA` 的既有語義只擋 `pending`（被拒絕的可重新提交），所以那邊要
     *        明確傳 `['pending']`——否則同一資源會變成「字形相同可重送、只是異體等價不可重送」
     *        的不一致行為。
     * @param int|null $personId 人物子資源用：以有索引的 operations.c_personid 收斂候選集。
     *                            **已知邊界**：舊資料／測試資料的 `operations.c_personid`
     *                            可能是 0（見 OperationsProposalController 同旨註解），
     *                            這類舊 pending 提案的變體形重複不會被抓到——精確字形仍由
     *                            `hasPendingCreateProposal()` 擋住，故接受此邊界。
     */
    public static function hasEquivalentPendingCreateProposal(
        string $table,
        array $keyColumns,
        array $after,
        ?int $excludeOperationId = null,
        ?string $resourceIdLikePattern = null,
        ?int $personId = null,
        array $conflictingStatuses = ['pending', 'rejected']
    ): bool {
        [$inScope] = self::splitKeyColumns($table, $keyColumns);
        if ($inScope === []) {
            return false;
        }

        if (!Schema::hasTable('operations')) {
            return false;
        }

        $query = DB::table('operations')
            ->where('resource', $table)
            ->where('op_type', Operation::TYPE_PROPOSAL_CREATE);
        if ($excludeOperationId !== null) {
            $query->where('id', '!=', $excludeOperationId);
        }

        // 收斂候選集，否則會把該表**所有歷史** create proposal（含已核准／已取消）全部撈
        // 回來反序列化，集合隨歷史無上限成長。
        //
        // 收斂條件由呼叫端給，因為 resource_id 的格式**依表而異**：代碼表是
        // `CodesController::buildCompositeId()` 的位置式 `'_._'` 串接（可用位置式 LIKE
        // 樣式，見 proposalResourceIdPattern()），人物子資源是
        // `CompositePrimaryKey::buildStoredResourceId()` 的 `http_build_query()`
        // 查詢字串（欄名帶在字串裡、值 percent-encoded，位置式樣式對它無效）。
        // 後者改用 operations.c_personid 收斂——那是有索引的欄，效果更好。
        if ($resourceIdLikePattern !== null) {
            $query->where('resource_id', 'like', $resourceIdLikePattern);
        }
        if ($personId !== null) {
            // 也要涵蓋 c_personid = 0 的歷史／測試資料（見 OperationsProposalController
            // 同旨註解）。漏抓的後果不只是「少擋一次」：第一筆送出並核准後，第二筆會在
            // **核准重放**時才被既有資料列的 D7 查重擋下，提案卡在 pending 需人工處理。
            // payload 的 c_personid 仍會在下面逐欄精確比對，所以不會誤判成別人的提案。
            $query->where(function ($q) use ($personId) {
                $q->where('c_personid', $personId)->orWhere('c_personid', 0);
            });
        }

        // 分批而非一次載入：候選集理論上無上限（**不能用 limit 截斷**——截斷會重現
        // 「漏抓重複」的正確性缺口）。用 lazyById() 而不是 cursor()：PDO MySQL 預設是
        // buffered query（config/database.php 沒關掉 MYSQL_ATTR_USE_BUFFERED_QUERY），
        // cursor() 仍會把整個結果集先讀進 PHP 記憶體；lazyById() 以 id 分頁，
        // 每批大小固定，記憶體才真的有界。
        foreach ($query->lazyById(500) as $operation) {
            $payload = json_decode((string) $operation->resource_data, true);
            if (!is_array($payload)) {
                continue;
            }
            if (!in_array($payload['__review_status'] ?? null, $conflictingStatuses, true)) {
                continue;
            }
            // 其餘主鍵欄必須相同，文本主鍵欄則比對歸一後的值
            foreach ($keyColumns as $column) {
                if (in_array($column, $inScope, true)) {
                    continue;
                }
                if ((string) ($payload[$column] ?? '') !== (string) ($after[$column] ?? '')) {
                    continue 2;
                }
            }
            if (self::rowMatchesAfterNormalization($table, $inScope, $payload, $after)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 用「非替換範圍的主鍵欄」組出 `resource_id` 的 LIKE 樣式，把候選集收斂在 SQL 層。
     *
     * **只適用代碼表**：`CodesController::buildCompositeId()` 以 `'_._'` 串起的**位置式**
     * 序列化，所以可以逐位置組樣式：在替換範圍內的欄位放 `%`、其餘放實際值。
     * （人物子資源的 `resource_id` 是 `http_build_query()` 查詢字串，不是位置式，
     * 那條路徑改用 `operations.c_personid` 收斂。）
     *
     * **不能只做「前導前綴」**：production 的 `ALTNAME_DATA` 主鍵順序是
     * `(c_alt_name_chn, c_alt_name_type_code, c_personid)`——第一欄正是可替換的文字欄，
     * 前導前綴會直接失效而退回全掃。位置式樣式在任何順序下都能收斂。
     *
     * 這個樣式是**寬鬆的預篩**（分隔符裡的 `_` 本身是 LIKE 單字元通配、且值含 `%`／`_`
     * 時會退成 `%`），只會多撈不會少撈——精確比對在 PHP 端由
     * `rowMatchesAfterNormalization()` 完成，所以寬鬆是安全的。
     *
     * @param array<int,string> $keyColumns
     * @param array<int,string> $inScope 在替換範圍內的主鍵欄
     * @param array<string,mixed> $after
     * @return string|null null = 無法收斂（所有主鍵欄都是通配），呼叫端就別加這個條件
     */
    public static function proposalResourceIdPattern(array $keyColumns, array $inScope, array $after): ?string {
        $parts = [];
        $hasLiteral = false;

        foreach ($keyColumns as $column) {
            if (in_array($column, $inScope, true)) {
                $parts[] = '%';

                continue;
            }

            $value = array_key_exists($column, $after) && $after[$column] !== null
                ? (string) $after[$column]
                : '';

            // 值本身含 LIKE 通配字元時退成 '%'：跨 driver 的 ESCAPE 語法不一致
            // （SQLite 需要顯式 ESCAPE），而寬鬆預篩本來就安全。
            if ($value === '' || strpbrk($value, '%_') !== false) {
                $parts[] = '%';

                continue;
            }

            $parts[] = $value;
            $hasLiteral = true;
        }

        if (!$hasLiteral) {
            return null;
        }

        return implode('_._', $parts);
    }

    /**
     * 候選列的文本主鍵歸一後是否與本次輸入相同。
     *
     * @param array<int,string> $inScope 在替換範圍內的主鍵欄
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $after 替換後的資料
     */
    public static function rowMatchesAfterNormalization(string $table, array $inScope, array $candidate, array $after): bool {
        foreach ($inScope as $column) {
            $candidateValue = $candidate[$column] ?? null;
            if (!is_string($candidateValue)) {
                return false;
            }

            $normalized = CharVariantMapService::replaceFor($table, $column, $candidateValue)['text'];
            if ($normalized !== (string) ($after[$column] ?? '')) {
                return false;
            }
        }

        return true;
    }
}
