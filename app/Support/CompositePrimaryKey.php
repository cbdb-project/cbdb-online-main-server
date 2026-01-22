<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * 複合主鍵（Composite Primary Key）URL 處理工具類
 *
 * 本類提供統一的複合主鍵 URL 編碼解決方案，採用查詢參數模式：
 * - 利用 HTTP 查詢參數傳遞複合主鍵欄位
 * - 完全依賴 Laravel 原生的 URL 編碼機制（http_build_query）
 * - 避免自定義編碼邏輯，減少邊界情況處理
 *
 * @see docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md 設計文檔
 */
class CompositePrimaryKey {
    /**
     * 各資料表的複合主鍵欄位定義
     *
     * 注意：這些定義應與資料庫 schema 保持一致
     * 參考：database/migrations/2025_01_01_* baseline migrations
     */
    public const SCHEMAS = [
        'ALTNAME_DATA' => [
            'c_personid',
            'c_sequence',
            'c_alt_name_chn',
            'c_alt_name_type_code',
        ],
        'BIOG_ADDR_DATA' => [
            'c_personid',
            'c_addr_id',
            'c_addr_type',
            'c_sequence',
        ],
        // 注意：實際表名是 BIOG_TEXT_DATA
        'TEXT_DATA' => [
            'c_personid',
            'c_textid',
            'c_role_id',
        ],
        'BIOG_SOURCE_DATA' => [
            'c_personid',
            'c_textid',
            'c_pages',
        ],
        'POSTED_TO_OFFICE_DATA' => [
            'c_office_id',
            'c_posting_id',
        ],
        'POSTED_TO_ADDR_DATA' => [
            'c_personid',
            'c_posting_id',
            'c_office_id',
        ],
        'ASSOC_DATA' => [
            'c_personid',
            'c_assoc_code',
            'c_assoc_id',
            'c_kin_code',
            'c_kin_id',
            'c_assoc_kin_code',
            'c_assoc_kin_id',
            'c_text_title',
            'c_assoc_first_year',
        ],
        'KIN_DATA' => [
            'c_personid',
            'c_kin_id',
            'c_kin_code',
        ],
        'EVENTS_DATA' => [
            'c_personid',
            'c_event_id',
        ],
        'STATUS_DATA' => [
            'c_personid',
            'c_status_id',
        ],
        'ENTRY_DATA' => [
            'c_personid',
            'c_entry_id',
        ],
        'POSSESSION_DATA' => [
            'c_personid',
            'c_possact_code',
            'c_possobj_id',
        ],
        'BIOG_INST_DATA' => [
            'c_personid',
            'c_inst_id',
        ],
        'OFFICE_CODE_TYPE_REL' => [
            'c_office_id',
            'c_office_tree_id',
        ],
    ];

    /**
     * 從請求中提取複合主鍵欄位
     *
     * @param Request $request HTTP 請求
     * @param array $fields 主鍵欄位名稱列表
     * @return array 包含欄位值的關聯陣列（過濾掉 null 和空字串）
     */
    public static function fromRequest(Request $request, array $fields): array {
        return array_filter(
            $request->only($fields),
            fn ($value) => $value !== null && $value !== ''
        );
    }

    /**
     * 生成帶查詢參數的 URL
     *
     * @param string $route 路由名稱
     * @param array $pathParams 路徑參數（如 ['id' => 12345]）
     * @param array $queryParams 查詢參數（複合主鍵欄位）
     * @param bool $absolute 是否生成絕對 URL（預設 false，避免 HTTPS 混合內容問題）
     * @return string 完整的 URL（含查詢參數）
     */
    public static function buildUrl(
        string $route,
        array $pathParams,
        array $queryParams,
        bool $absolute = false
    ): string {
        $url = route($route, $pathParams, $absolute);
        $query = http_build_query($queryParams);

        return $query ? "{$url}?{$query}" : $url;
    }

    /**
     * 取得指定資料表的主鍵欄位定義
     *
     * @param string $table 資料表名稱（不區分大小寫）
     * @return array|null 主鍵欄位列表，若表不存在則返回 null
     */
    public static function getSchema(string $table): ?array {
        return self::SCHEMAS[strtoupper($table)] ?? null;
    }

    /**
     * 驗證複合主鍵是否包含所有必要欄位
     *
     * @param array $pk 複合主鍵陣列
     * @param string $table 資料表名稱
     * @param array $optionalFields 可選欄位列表（允許為 null）
     * @return bool
     */
    public static function validate(array $pk, string $table, array $optionalFields = []): bool {
        $schema = self::getSchema($table);
        if (!$schema) {
            return false;
        }

        foreach ($schema as $field) {
            // 檢查欄位是否為可選
            if (in_array($field, $optionalFields)) {
                continue;
            }

            // 必填欄位必須存在且不為空
            if (!isset($pk[$field]) || $pk[$field] === '' || $pk[$field] === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * 從資料庫記錄建立查詢參數陣列
     *
     * @param object|array $record 資料庫記錄（stdClass 或陣列）
     * @param string $table 資料表名稱
     * @return array 查詢參數陣列
     */
    public static function fromRecord($record, string $table): array {
        $schema = self::getSchema($table);
        if (!$schema) {
            return [];
        }

        $params = [];
        $record = is_object($record) ? (array) $record : $record;

        foreach ($schema as $field) {
            if (isset($record[$field])) {
                $params[$field] = $record[$field];
            }
        }

        return $params;
    }

    /**
     * 解析舊格式的複合主鍵字串（用於向後相容）
     *
     * @deprecated 僅用於過渡期向後相容，新代碼請使用 fromRequest()
     *
     * @param string $pk 舊格式的複合主鍵字串（如 "12345-1-張三-10"）
     * @param string $table 資料表名稱
     * @param callable|null $decoder 自定義解碼函數（處理特殊字符編碼）
     * @return array|null 解析後的複合主鍵陣列，若解析失敗則返回 null
     */
    public static function parseLegacy(string $pk, string $table, ?callable $decoder = null): ?array {
        $schema = self::getSchema($table);
        if (!$schema) {
            return null;
        }

        // 應用解碼器（如 unionPKDef_decode）
        $decoded = $decoder ? $decoder($pk) : $pk;

        // 處理欄位值中的負號（-- 轉為佔位符）
        $decoded = str_replace('--', "\x00MINUS\x00", $decoded);

        // 分割欄位
        $parts = explode('-', $decoded);

        // 還原負號
        $parts = array_map(fn ($p) => str_replace("\x00MINUS\x00", '-', $p), $parts);

        // 特殊處理：某些表的最後一個欄位可能包含分隔符
        // 例如 ASSOC_DATA 的 c_text_title 可能是 "論語-註釋"
        $expectedCount = count($schema);
        $actualCount = count($parts);

        if ($actualCount > $expectedCount) {
            // 將超出的部分合併到最後一個欄位
            $lastFieldParts = array_slice($parts, $expectedCount - 1);
            $parts = array_slice($parts, 0, $expectedCount - 1);
            $parts[] = implode('-', $lastFieldParts);
        } elseif ($actualCount < $expectedCount) {
            // 欄位數不足，解析失敗
            return null;
        }

        return array_combine($schema, $parts);
    }
}
