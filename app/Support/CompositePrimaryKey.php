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
        'BIOG_MAIN' => [
            'c_personid',
        ],
        // ALTNAME_DATA 定位 key (#834)
        // 資料庫 PK 為 3-key: (c_personid, c_alt_name_chn, c_alt_name_type_code)
        // c_sequence 為一般排序欄位，不參與定位。
        'ALTNAME_DATA' => [
            'c_personid',
            'c_alt_name_chn',
            'c_alt_name_type_code',
        ],
        'BIOG_ADDR_DATA' => [
            'c_personid',
            'c_addr_id',
            'c_addr_type',
            'c_sequence',
        ],
        // 注意：實際表名是 BIOG_TEXT_DATA，此處同時提供兩個鍵名以便查詢
        'TEXT_DATA' => [
            'c_personid',
            'c_textid',
            'c_role_id',
        ],
        'BIOG_TEXT_DATA' => [
            'c_personid',
            'c_textid',
            'c_role_id',
        ],
        'BIOG_SOURCE_DATA' => [
            'c_personid',
            'c_textid',
            'c_pages',
        ],
        // 合併人物記錄：PK 為 (survivor c_personid, 被併入的已刪 c_merged_from_personid)。
        // c_merged_from_personid 是「刻意的已刪 id」，handler 不對它做 BIOG_MAIN 存在性校驗。
        'MERGED_PERSON_DATA' => [
            'c_personid',
            'c_merged_from_personid',
        ],
        'POSTED_TO_OFFICE_DATA' => [
            'c_office_id',
            'c_posting_id',
        ],
        'POSTED_TO_ADDR_DATA' => [
            'c_addr_id',
            'c_office_id',
            'c_posting_id',
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
            'c_sequence',
            'c_event_code',
        ],
        'STATUS_DATA' => [
            'c_personid',
            'c_sequence',
            'c_status_code',
        ],
        'ENTRY_DATA' => [
            'c_personid',
            'c_entry_code',
            'c_sequence',
            'c_kin_code',
            'c_assoc_code',
            'c_kin_id',
            'c_year',
            'c_assoc_id',
            'c_inst_code',
            'c_inst_name_code',
        ],
        'POSSESSION_DATA' => [
            'c_possession_record_id',
        ],
        'BIOG_INST_DATA' => [
            'c_personid',
            'c_inst_code',
            'c_inst_name_code',
            'c_bi_role_code',
        ],
        'OFFICE_CODE_TYPE_REL' => [
            'c_office_id',
            'c_office_tree_id',
        ],
        'NIAN_HAO' => [
            'c_nianhao_id',
        ],
        // 與 OperationsController::resourceKeyColumns() 同步：兩處表名映射必須對齊，
        // 否則 normalizeResourceId() 會把 follow-up 操作 resource_id 重寫成
        // query-string 格式 (c_textid=N) 而 parseStoredResourceId() 卻無法解析，
        // 造成 /operations 編輯連結變成 /codes/TEXT_CODES/c_textid=N/edit。
        'TEXT_CODES' => [
            'c_textid',
        ],
        // Phase B code 表（受審計寫入 API）——主鍵取自生產 DB 實際 PRIMARY KEY。
        // 與 config/code_table_mutations.php 的 key_columns 及 OperationsController::resourceKeyColumns() 必須一致。
        'OFFICE_CODES' => [
            'c_office_id',
        ],
        'DYNASTIES' => [
            'c_dy',
        ],
        'CHORONYM_CODES' => [
            'c_choronym_code',
        ],
        'ETHNICITY_TRIBE_CODES' => [
            'c_ethnicity_code',
        ],
        'TEXT_INSTANCE_DATA' => [
            'c_textid',
            'c_text_edition_id',
            'c_text_instance_id',
        ],
        'TEXT_BIBLCAT_CODES' => [
            'c_text_cat_code',
        ],
        'GANZHI_CODES' => [
            'c_ganzhi_code',
        ],
        'SOCIAL_INSTITUTION_NAME_CODES' => [
            'c_inst_name_code',
        ],
        'SOCIAL_INSTITUTION_TYPES' => [
            'c_inst_type_code',
        ],
        // 社會機構匯入（SocialInstituteImportService）寫入的兩張表——主鍵取自生產 DB 實際 PRIMARY KEY，
        // 欄位順序須與 recordOp() 傳入的 pk 一致，並與 OperationsController::resourceKeyColumns() 同步。
        'SOCIAL_INSTITUTION_CODES' => [
            'c_inst_code',
            'c_inst_name_code',
        ],
        'SOCIAL_INSTITUTION_ADDR' => [
            'c_inst_addr_id',
            'c_inst_addr_type_code',
            'c_inst_code',
            'c_inst_name_code',
            'inst_xcoord',
            'inst_ycoord',
        ],
        'ADMIN_CAT_CODES' => [
            'c_admin_cat_code',
        ],
        'ADDR_CODES' => [
            'c_addr_id',
        ],
        'CHAR_VARIANT_MAP' => [
            'id',
        ],
    ];

    /**
     * ALTNAME_DATA 歷史 4-key 格式
     *
     * 2025 年以前的 resource_id 使用 4-key 格式（含 c_sequence），
     * 此常數用於解析歷史操作紀錄的 resource_id，
     * 解析後會自動 strip c_sequence 返回當前 3-key 格式。
     *
     * @see https://github.com/cbdb-project/cbdb-online-main-server/issues/834
     */
    private const ALTNAME_LEGACY_4KEY = ['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'];

    /**
     * resource_id 的 schema 別名對應
     *
     * 某些資料表的 resource_id 刻意沿用其他表的格式（共用編輯頁面），
     * 因此解析 resource_id 時需使用目標表的 schema 而非自身的。
     *
     * 例如：POSTED_TO_ADDR_DATA 的 resource_id 沿用 POSTED_TO_OFFICE_DATA
     * 的 c_office_id + c_posting_id 格式，地址資料存放在 resource_data['rows'] 中。
     */
    private const RESOURCE_ID_SCHEMA_ALIAS = [
        'POSTED_TO_ADDR_DATA' => 'POSTED_TO_OFFICE_DATA',
    ];

    /**
     * 取得 resource_id 解析用的 schema 表名
     *
     * 若該表有別名對應（如 POSTED_TO_ADDR_DATA → POSTED_TO_OFFICE_DATA），
     * 返回別名表名；否則返回原始表名。
     *
     * @param string $table 資料表名稱
     * @return string 用於解析 resource_id 的 schema 表名
     */
    public static function getResourceIdSchemaTable(string $table): string {
        return self::RESOURCE_ID_SCHEMA_ALIAS[strtoupper($table)] ?? $table;
    }

    /**
     * 從請求中提取複合主鍵欄位
     *
     * @param Request $request HTTP 請求
     * @param array $fields 主鍵欄位名稱列表
     * @return array 包含欄位值的關聯陣列（過濾掉 null 和空字串）
     */
    public static function fromRequest(Request $request, array $fields): array {
        $values = [];
        foreach ($fields as $field) {
            $value = self::normalizeQueryValue($request->input($field));
            if ($value !== null) {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    /**
     * 將查詢字串中的特殊保留值還原為實際值。
     *
     * 目前主要處理 buildUrl()/buildStoredResourceId() 使用的 'NULL' 哨兵值，
     * 避免 query-string 在往返過程中把 null 與缺少欄位混為一談。
     *
     * @param mixed $value
     * @return mixed
     */
    public static function normalizeQueryValue(mixed $value): mixed {
        return $value === 'NULL' ? null : $value;
    }

    /**
     * 將空值（null / 空字串 / '-999' / -999）正規化為指定哨兵值，
     * 讓使用者清空表單欄位時仍能寫入 NOT NULL 複合主鍵欄位。
     *
     * 不同資料表的「未知」哨兵慣例不同：
     * - 數字欄位（c_year, c_kin_id 等整數主鍵）：'0'
     * - BIOG_SOURCE_DATA.c_pages（varchar）：''（現行 DB 已有 4823 筆以此為慣例）
     * - ASSOC_DATA.c_text_title（varchar）：'[n/a]'（現行 DB 已有 68203 筆以此為慣例）
     *
     * 傳入非空值時原樣回傳（轉為字串），避免干擾合法內容。
     */
    public static function emptyToSentinel(mixed $value, string $sentinel = '0'): string {
        if ($value === null || $value === '' || $value === -999 || $value === '-999') {
            return $sentinel;
        }

        return (string) $value;
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
        // 將 null 值轉為 'NULL' 字串，避免 http_build_query 靜默丟棄
        // （與 buildStoredResourceId() 保持一致的處理方式）
        $encoded = array_map(fn ($v) => $v === null ? 'NULL' : $v, $queryParams);
        $url = route($route, $pathParams, $absolute);
        $query = http_build_query($encoded);

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

            // 必填欄位必須存在且不為 NULL
            if (!array_key_exists($field, $pk) || $pk[$field] === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * 驗證並返回缺失的必填欄位
     *
     * @param array $pk 複合主鍵陣列
     * @param string $table 資料表名稱
     * @param array $optionalFields 可選欄位列表（允許為 null）
     * @return array 缺失欄位列表，若驗證通過則為空陣列
     */
    public static function getMissingFields(array $pk, string $table, array $optionalFields = []): array {
        $schema = self::getSchema($table);
        if (!$schema) {
            return ['__unknown_table__'];
        }

        $missing = [];
        foreach ($schema as $field) {
            // 檢查欄位是否為可選
            if (in_array($field, $optionalFields)) {
                continue;
            }

            // 必填欄位必須存在且不為 NULL
            if (!array_key_exists($field, $pk) || $pk[$field] === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * 驗證複合主鍵，失敗時拋出 HTTP 400 錯誤
     *
     * @param array $pk 複合主鍵陣列
     * @param string $table 資料表名稱
     * @param array $optionalFields 可選欄位列表（允許為 null）
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public static function validateOrFail(array $pk, string $table, array $optionalFields = []): void {
        $missing = self::getMissingFields($pk, $table, $optionalFields);
        if (!empty($missing)) {
            abort(400, '缺少必要的複合主鍵參數：' . implode(', ', $missing));
        }
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
     * 資源表名對應的查詢參數模式編輯路由名稱
     *
     * 用於 Operations 模組等需要根據資源表名生成編輯連結的場景。
     */
    public const EDIT_ROUTE_MAP = [
        'ALTNAME_DATA' => 'basicinformation.altnames.edit.query',
        'BIOG_ADDR_DATA' => 'basicinformation.addresses.edit.query',
        'TEXT_DATA' => 'basicinformation.texts.edit.query',
        'BIOG_TEXT_DATA' => 'basicinformation.texts.edit.query',
        'BIOG_SOURCE_DATA' => 'basicinformation.sources.edit.query',
        'POSTED_TO_OFFICE_DATA' => 'basicinformation.offices.edit.query',
        'POSTED_TO_ADDR_DATA' => 'basicinformation.offices.edit.query',
        'ASSOC_DATA' => 'basicinformation.assoc.edit.query',
        'KIN_DATA' => 'basicinformation.kinship.edit.query',
        'EVENTS_DATA' => 'basicinformation.events.edit.query',
        'STATUS_DATA' => 'basicinformation.statuses.edit.query',
        'ENTRY_DATA' => 'basicinformation.entries.edit.query',
        'POSSESSION_DATA' => 'basicinformation.possession.edit.query',
        'BIOG_INST_DATA' => 'basicinformation.socialinst.edit.query',
    ];

    /**
     * 資源表 → [React edit-v2 路由名, 對應 migration flag key]。
     * 當該子資源 flag=new 且路由存在時，buildResourceEditUrl 改導向 React /app edit-v2（帶完整 PK query，
     * edit-v2 controller 以「全 PK 皆存在」判定 edit 模式並載入該列），否則沿用 legacy .edit.query。
     * 修「新介面（如 /app/operations）點子資源仍開 legacy 編輯頁」。
     */
    public const APP_EDIT_ROUTE_MAP = [
        'ALTNAME_DATA' => ['app.basicinformation.altnames.editv2', 'basicinformation.altname'],
        'BIOG_ADDR_DATA' => ['app.basicinformation.addresses.editv2', 'basicinformation.addresses'],
        'TEXT_DATA' => ['app.basicinformation.texts.editv2', 'basicinformation.texts'],
        'BIOG_TEXT_DATA' => ['app.basicinformation.texts.editv2', 'basicinformation.texts'],
        'BIOG_SOURCE_DATA' => ['app.basicinformation.sources.editv2', 'basicinformation.sources'],
        'POSTED_TO_OFFICE_DATA' => ['app.basicinformation.offices.editv2', 'basicinformation.offices'],
        'POSTED_TO_ADDR_DATA' => ['app.basicinformation.offices.editv2', 'basicinformation.offices'],
        'ASSOC_DATA' => ['app.basicinformation.assoc.editv2', 'basicinformation.assoc'],
        'KIN_DATA' => ['app.basicinformation.kinship.editv2', 'basicinformation.kinship'],
        'EVENTS_DATA' => ['app.basicinformation.events.editv2', 'basicinformation.events'],
        'STATUS_DATA' => ['app.basicinformation.statuses.editv2', 'basicinformation.statuses'],
        'ENTRY_DATA' => ['app.basicinformation.entries.editv2', 'basicinformation.entries'],
        'POSSESSION_DATA' => ['app.basicinformation.possession.editv2', 'basicinformation.possession'],
        'BIOG_INST_DATA' => ['app.basicinformation.socialinst.editv2', 'basicinformation.socialinst'],
    ];

    /**
     * 將複合主鍵陣列編碼為可儲存在 resource_id 欄位的字串
     *
     * 使用 PHP 標準的 http_build_query() 產生查詢參數格式，
     * 所有特殊字符（中文、負號、斜線等）自動 URL 編碼，
     * 完全消除舊格式 `-` 分隔符的解析歧義。
     *
     * null 值會被轉為字串 'NULL'，因為 http_build_query() 會省略 null 欄位，
     * 導致解析時缺少欄位。解析端已有 'NULL' → whereNull 的對應處理。
     *
     * 範例：
     *   buildStoredResourceId(['c_personid' => 12345, 'c_sequence' => 1, 'c_alt_name_chn' => '張三'])
     *   => 'c_personid=12345&c_sequence=1&c_alt_name_chn=%E5%BC%B5%E4%B8%89'
     *
     *   buildStoredResourceId(['c_personid' => 12345, 'c_sequence' => null, ...])
     *   => 'c_personid=12345&c_sequence=NULL&...'
     *
     * @param array $pk 複合主鍵的具名欄位陣列
     * @return string http_build_query 格式的字串
     */
    public static function buildStoredResourceId(array $pk): string {
        $encoded = array_map(fn ($v) => $v === null ? 'NULL' : $v, $pk);

        return http_build_query($encoded);
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

    /**
     * 解析資料庫中儲存的 resource_id 字串為具名欄位陣列
     *
     * 支援兩種分隔符格式：
     * - '_._' 分隔符（CodesController 使用）
     * - '-' 分隔符（BasicInformation 系列 Controller 使用，欄位值中的特殊字符以 (minus)/(slash) 等編碼）
     *
     * @param string $resourceId 資料庫中儲存的 resource_id
     * @param string $table 資料表名稱
     * @return array|null 解析後的具名欄位陣列，解析失敗則返回 null
     */
    public static function parseStoredResourceId(string $resourceId, string $table): ?array {
        // resource_id 可能使用別名表的 schema（如 POSTED_TO_ADDR_DATA → POSTED_TO_OFFICE_DATA）
        $effectiveTable = self::getResourceIdSchemaTable($table);
        $schema = self::getSchema($effectiveTable);
        if (!$schema) {
            return null;
        }

        // 格式 0：query-string 格式（新格式，由 buildStoredResourceId() 產生）
        // 以 '=' 存在且不含 '_._' 作為判斷條件
        // 額外驗證：解析後的 key 必須完整覆蓋所有 schema key，避免：
        // 1. 舊格式欄位值含 '=' 被誤判為新格式
        // 2. 部分 key 匹配導致 WHERE 條件不完整而查到錯誤資料列
        if (str_contains($resourceId, '=') && !str_contains($resourceId, '_._')) {
            parse_str($resourceId, $parsed);
            if (!empty($parsed) && count(array_intersect($schema, array_keys($parsed))) === count($schema)) {
                // 自動過濾多餘欄位（如歷史 4-key ALTNAME 的 c_sequence）
                return array_intersect_key($parsed, array_flip($schema));
            }
        }

        $expectedCount = count($schema);

        // 格式 1：_._  分隔符（CodesController）
        if (strpos($resourceId, '_._') !== false) {
            $parts = explode('_._', $resourceId);

            // #834: 歷史 4-key _._  格式 → strip c_sequence 返回 3-key
            // 必須在通用匹配前處理，否則 4 parts >= 3 expectedCount 會導致錯位
            if (strtoupper($effectiveTable) === 'ALTNAME_DATA' && count($parts) === count(self::ALTNAME_LEGACY_4KEY)) {
                $parsed4 = array_combine(self::ALTNAME_LEGACY_4KEY, $parts);
                unset($parsed4['c_sequence']);

                return $parsed4;
            }

            if (count($parts) >= $expectedCount) {
                return array_combine($schema, array_slice($parts, 0, $expectedCount));
            }

            return null;
        }

        // 格式 2：- 分隔符，欄位值中的特殊字符以佔位符編碼
        // 先解碼除 (minus) 以外的保留字
        $decoded = str_replace(
            ['(slash)', '(backslash)', '(brackets)', '(brackets_r)', '(question)', '(hash)', '(amp)'],
            ['/', '\\', '{', '}', '?', '#', '&'],
            $resourceId
        );

        // 以 - 分割，然後在每個欄位中還原 (minus)
        $parts = explode('-', $decoded);
        $parts = array_map(fn ($p) => str_replace('(minus)', '-', $p), $parts);

        $result = self::combinePartsWithSchema($parts, $schema, $table);
        if ($result !== null) {
            return $result;
        }

        // 回退策略：嘗試舊格式（-- 代表欄位值中的負號）
        $decoded2 = str_replace(
            ['(slash)', '(backslash)', '(brackets)', '(brackets_r)', '(question)', '(hash)', '(amp)'],
            ['/', '\\', '{', '}', '?', '#', '&'],
            $resourceId
        );
        $decoded2 = str_replace('--', "\x00MINUS\x00", $decoded2);
        $parts2 = explode('-', $decoded2);
        $parts2 = array_map(function ($p) {
            $p = str_replace("\x00MINUS\x00", '-', $p);
            $p = str_replace('(minus)', '-', $p);

            return $p;
        }, $parts2);

        $result2 = self::combinePartsWithSchema($parts2, $schema, $table);
        if ($result2 !== null) {
            return $result2;
        }

        // #834: 歷史 4-key dash 格式 → strip c_sequence 返回 3-key
        if (strtoupper($effectiveTable) === 'ALTNAME_DATA') {
            $legacy4key = self::ALTNAME_LEGACY_4KEY;
            $legacy4keyCount = count($legacy4key);
            if (count($parts) === $legacy4keyCount) {
                $parsed4 = array_combine($legacy4key, $parts);
                unset($parsed4['c_sequence']);

                return $parsed4;
            }
            if (count($parts2) === $legacy4keyCount) {
                $parsed4 = array_combine($legacy4key, $parts2);
                unset($parsed4['c_sequence']);

                return $parsed4;
            }
        }

        return null;
    }

    /**
     * 將分割後的欄位值陣列與 schema 定義合併為具名陣列
     *
     * @param array $parts 分割後的欄位值
     * @param array $schema schema 欄位名稱
     * @param string $table 資料表名稱（用於特殊處理）
     * @return array|null 成功時返回具名陣列，失敗返回 null
     */
    private static function combinePartsWithSchema(array $parts, array $schema, string $table): ?array {
        $expectedCount = count($schema);
        $actualCount = count($parts);

        if ($actualCount === $expectedCount) {
            return array_combine($schema, $parts);
        }

        if ($actualCount > $expectedCount) {
            $upperTable = strtoupper($table);

            // ASSOC_DATA：c_text_title（倒數第 2 個欄位）可能含 -，c_assoc_first_year 固定在最後
            if ($upperTable === 'ASSOC_DATA' && $expectedCount === 9) {
                $lastPart = $parts[$actualCount - 1];
                $firstSeven = array_slice($parts, 0, 7);
                $textTitle = implode('-', array_slice($parts, 7, $actualCount - 8));
                $combined = array_merge($firstSeven, [$textTitle, $lastPart]);
                if (count($combined) === $expectedCount) {
                    return array_combine($schema, $combined);
                }
            }

            // BIOG_SOURCE_DATA：c_pages（最後一個欄位）可能含 -
            if ($upperTable === 'BIOG_SOURCE_DATA' && $expectedCount === 3) {
                $firstTwo = array_slice($parts, 0, 2);
                $cPages = implode('-', array_slice($parts, 2));
                $combined = array_merge($firstTwo, [$cPages]);

                return array_combine($schema, $combined);
            }

            // 其他表不應有多餘的 parts，視為解析失敗
            return null;
        }

        // actualCount < expectedCount：欄位不足，解析失敗
        return null;
    }

    /**
     * 根據資源表名和 resource_id 生成查詢參數模式的編輯頁面 URL
     *
     * @param string $resource 資源表名（如 'ALTNAME_DATA'）
     * @param string $resourceId 資料庫中儲存的 resource_id
     * @param int|string $personId 人物 ID（用於路由的 {id} 參數）
     * @return string|null 成功時返回新格式 URL，無法解析時返回 null
     */
    public static function buildResourceEditUrl(string $resource, string $resourceId, $personId): ?string {
        $upperResource = strtoupper($resource);

        $routeName = self::EDIT_ROUTE_MAP[$upperResource] ?? null;
        if (!$routeName) {
            return null;
        }

        // flag-aware：子資源 flag=new 且 React edit-v2 路由存在時，改導向 /app edit-v2（帶完整 PK query → edit 模式）。
        $appRoute = self::APP_EDIT_ROUTE_MAP[$upperResource] ?? null;
        if ($appRoute !== null
            && function_exists('migration_flag_is_new')
            && migration_flag_is_new($appRoute[1])
            && \Illuminate\Support\Facades\Route::has($appRoute[0])) {
            $routeName = $appRoute[0];
        }

        // parseStoredResourceId() 內部已透過 getResourceIdSchemaTable() 處理別名對應
        // （如 POSTED_TO_ADDR_DATA → POSTED_TO_OFFICE_DATA），直接傳入原始表名即可
        $pk = self::parseStoredResourceId($resourceId, $upperResource);
        if ($pk === null) {
            return null;
        }

        return self::buildUrl($routeName, ['id' => $personId], $pk);
    }

    /**
     * 將單一主鍵的 resource_id 正規化為 codes 路由可用的 path id。
     *
     * 某些全域代碼表歷史上曾把單主鍵也存成 query-string 格式
     * （如 c_nianhao_id=464）。codes.edit 需要的是純 path segment，
     * 因此這裡在可安全判定為單一主鍵時抽出其值，其餘情況保持原值。
     *
     * 複合主鍵**刻意**原樣傳出，由 CodesController::buildConditionsFromId() 以欄名解析。
     * 不要改成在這裡重組 '_._' 位置式 id：SCHEMAS 的欄序與資料庫 PRIMARY KEY 欄序在
     * ALTNAME_DATA、ASSOC_DATA、KIN_DATA 等表並不一致，重組會靜默查到錯誤的資料列；
     * '_._' 也無法表達「空字串主鍵值」（buildCompositeId 會把空段濾掉）。
     */
    public static function normalizeSingleKeyResourceIdForCodeRoute(string $resource, string $resourceId): string {
        $pk = self::parseStoredResourceId($resourceId, $resource);
        if (!is_array($pk) || count($pk) !== 1) {
            return $resourceId;
        }

        $value = reset($pk);

        return is_scalar($value) || $value === null ? (string) $value : $resourceId;
    }
}
