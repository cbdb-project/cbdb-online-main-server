<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 異體字落地替換的「範圍」單一權威來源：回答「(表, 欄位) 該用哪個模式」。
 *
 * 設計見 docs/CHAR_VARIANT_MAP_TEXT_COLUMN_ROLLOUT_PLAN.md：
 * - D1 以**欄位型別**判定範圍（文本型別才過），不以命名規律、不預判有沒有中文。
 *   對照表 key 全是 CJK，所以套在拼音／羅馬字欄上必然 no-op。
 * - D2 fail-closed：未知表一律不替換；表名／欄位名比對大小寫不敏感。
 * - D3 排除清單（本檔常數）：本身做文本替換／字形對照，或語義上必須保留原字的地方一律不掛。
 * - D4 **預設 lenient**；strict 只用於人名／別名欄，且是逐欄位例外。
 *
 * 排除清單刻意寫成常數而非 config：這些排除是**程式正確性前提**（尤其對照表自身與拼音
 * 字典鍵，替換會造成資料自我損毀），不應該可由部署設定關掉。
 */
final class VariantReplaceScope {
    /** 文本型別白名單（`Schema::getColumns()` 的 type_name，小寫）。 */
    public const TEXT_TYPES = [
        'varchar',
        'char',
        'tinytext',
        'text',
        'mediumtext',
        'longtext',
    ];

    /**
     * 逐欄位 strict 清單（人名／別名）。比對大小寫不敏感。
     *
     * strict = 只套 c_strict_excluded = 0 的對照（目前 6 筆，排除「峯→峰」）。
     * 這是**逐欄位**而非逐表：同一列 BIOG_MAIN 裡姓名欄 strict、c_notes 走預設 lenient。
     *
     * @var array<string,array<int,string>>
     */
    public const STRICT_COLUMNS = [
        'BIOG_MAIN' => ['c_name_chn', 'c_surname_chn', 'c_mingzi_chn'],
        'ALTNAME_DATA' => ['c_alt_name_chn'],
    ];

    /**
     * 整表排除。
     *
     * @var array<int,string>
     */
    public const EXCLUDED_TABLES = [
        // 對照表自身：內容就是字形本身，替換等於自我吞噬（新增「峯→峰」後，
        // 含「峯」的既有列——包括那筆對照自己的 c_variant_char——都會被改寫）。
        'char_variant_map',
        // 唯讀派生搜尋索引，且刻意同時保存繁簡兩形供檢索命中。
        'CBDB__NAME_FTS',
        // 紀錄／帳號類：紀錄的語義是「當時實際發生了什麼」，改寫等於偽造紀錄。
        // （D2 fail-closed 已涵蓋這些非 CBDB 表，明文列出以固定意圖。）
        'users',
        'nl_query_logs',
        'ai_fill_logs',
        'audit_log',
        'operations',
        // 派生表：內容由源頭重建（RegenerateAddresses），改派生物只會與源頭不一致。
        'ADDRESSES',
        // DB view（Schema::getColumns() 會回傳 view）。
        'View_BiogInstData',
        'View_PossessionsData',
    ];

    /**
     * 逐欄位排除：表 => 欄位清單。比對大小寫不敏感。
     *
     * @var array<string,array<int,string>>
     */
    public const EXCLUDED_COLUMNS = [
        // 拼音字典的鍵。第一階段明文設計「異體字各自在 pinyin 表有自己的讀音」，
        // 替換這欄會直接破壞該設計（「峯」的讀音條目會變成「峰」）。
        'pinyin' => ['c_chn'],

        // ── 拉丁人名欄（共 13 欄，D4）：排除而非 strict。
        // 理由 1：strict 仍會套那 6 筆規則，真有人填漢字時 愼→慎／靑→青 照樣被改；
        //         只有排除能真正不碰。
        // 理由 2：排除順帶消掉組合欄失步——BiogMainRepository::updateById() 的
        //         c_name／c_name_proper／c_name_rm 是從 $request 重組的，若分欄在 $data
        //         被替換而組合欄從未替換的 $request 組出就會不一致。
        // 語義：羅馬字轉寫的用途就是保留錄入者寫的拼法；中文源頭欄已歸一，轉寫欄不該再被改。
        'BIOG_MAIN' => [
            'c_surname', 'c_mingzi', 'c_name',
            'c_surname_proper', 'c_mingzi_proper', 'c_name_proper',
            'c_surname_rm', 'c_mingzi_rm', 'c_name_rm',
            // 跨表代碼鍵 → INDEXYEAR_TYPE_CODES.c_index_year_type_code（varchar PK）。
            'c_index_year_type_code',
        ],
        'ALTNAME_DATA' => [
            'c_alt_name', 'c_alt_name_pinyin', 'c_alt_name_pinyin2', 'c_alt_name_pinyin3',
            // c_alt_name_role：prod-only varchar(50)（不在 migrations／DATABASE_SCHEMA.md，
            // 只出現在 allowedFields 白名單與測試合成表），全庫**零**應用邏輯讀它。
            // 語義無法從程式判定，所以刻意選保守側排除：漏一次替換是可回復的
            // （日後歸類清楚再放進範圍），而誤改一個代碼／角色鍵是不可回復的。
            // 若日後確認它是散文性質的說明文字，再移出這份清單。
            'c_alt_name_role',
        ],

        // ── 跨表 join／樹狀關聯的「代碼鍵」（D3）。
        // 判準是「這個值是用來跟別表對上的代碼」，**不是**「是不是 varchar PK 成員」——
        // ALTNAME_DATA.c_alt_name_chn／ASSOC_DATA.c_text_title／BIOG_SOURCE_DATA.c_pages
        // 同樣是 varchar PK 成員，但語義是別名／書名／頁碼，屬「內容」、必須替換。
        //
        // 7 組 `*_CODE_TYPE_REL` ↔ `*_TYPES` 的文字型 code PK：REL 側與 PK 側**都要排除**，
        // 只排一邊等於「只改 join 一邊」，照樣打斷關聯。權威來源是
        // CodesController::$tableJoinConfigurations（見 tests/Unit/VariantReplaceJoinKeyGuardTest.php
        // 的自動守衛：新增 join 設定卻忘了登記排除時，那支測試會紅）。
        'ADMIN_CAT_TYPES' => ['c_admin_cat_type_code'],
        'ADMIN_CAT_CODE_TYPE_REL' => ['c_admin_cat_type_code'],
        'APPOINTMENT_TYPES' => ['c_appt_type_code'],
        'APPOINTMENT_CODE_TYPE_REL' => ['c_appt_type_code'],
        // c_assoc_type_code 另被 `LIKE '$x%'` 前綴走訪（Api/ApiController5.php:333），
        // 替換會打斷樹狀查詢而非只是打斷單筆 join。
        'ASSOC_TYPES' => ['c_assoc_type_code', 'c_assoc_type_parent_id'],
        'ASSOC_CODE_TYPE_REL' => ['c_assoc_type_code'],
        'ENTRY_TYPES' => ['c_entry_type', 'c_entry_type_parent_id'],
        'ENTRY_CODE_TYPE_REL' => ['c_entry_type'],
        // 注意 STATUS_TYPES 的父鍵後綴是 _parent_code 而非 _parent_id——只按 *_parent_id 掃會漏掉。
        'STATUS_TYPES' => ['c_status_type_code', 'c_status_type_parent_code'],
        'STATUS_CODE_TYPE_REL' => ['c_status_type_code'],
        'TEXT_BIBLCAT_TYPES' => ['c_text_cat_type_id', 'c_text_cat_type_parent_id'],
        'TEXT_BIBLCAT_CODE_TYPE_REL' => ['c_text_cat_type_id'],
        // c_text_cat_parent_id 是第 6 個自參照樹狀父鍵（指向同表 PK c_text_cat_code）。
        'TEXT_BIBLCAT_CODES' => ['c_text_cat_parent_id'],
        // TEXT_TYPE 這組沒有宣告 FK，關聯是 Eloquent belongsTo（TextCode.php:30）。
        // **PK 本身也要排除**——只排 parent 卻不排被 parent 指到的 PK，關聯照樣斷。
        'TEXT_TYPE' => ['c_text_type_code', 'c_text_type_parent_id'],
        'TEXT_CODES' => ['c_text_type_id'],
        //
        // 官職類型樹：REL 側的欄名（c_office_tree_id）與 PK 側（c_office_type_node_id）
        // **不同名**，只掃 node_id 會漏掉 REL 側。這一組有實際風險而非理論風險——
        // c_office_type_node_id 的值域含中文，且被 `LIKE '%$q%'` 做中文搜尋
        // （ApiController.php:269），也被 `LIKE '$id%'` 前綴走訪（Api/ApiController.php:463）。
        'OFFICE_TYPE_TREE' => ['c_office_type_node_id', 'c_parent_id'],
        'OFFICE_CODE_TYPE_REL' => ['c_office_tree_id'],
        //
        // 親屬關係字串：c_kinrel 系列是跨表對照的來源側與對面側，兩邊都要排。
        // c_kinrel_alt 目前程式零引用，但值域與 c_kinrel 相同，一併排除較保守。
        'KINSHIP_CODES' => ['c_kinrel', 'c_kinrel_alt', 'c_kinrel_simplified'],
        'KIN_MOURNING' => ['c_kinrel', 'c_kinrel_alt'],
        'KIN_MOURNING_STEPS' => ['c_kinrel'],
        'KINREL_REDUCTION' => ['c_kinrel_target', 'c_kinrel_replacement', 'c_sex'],
        //
        // 跨表代碼鍵：對上 APPOINTMENT_CODES.c_appt_code，被 exact where 比對
        // （Api/ApiController2.php:184）。兩邊都是 smallint，所以型別閘門本來就擋掉了；
        // 列在此處是為了讓「它是代碼鍵」這個判定有據可查，不是因為它是文本欄。
        'POSTED_TO_OFFICE_DATA' => ['c_appt_code'],
    ];

    /**
     * 任何表都排除的欄位。
     *
     * @var array<int,string>
     */
    public const EXCLUDED_COLUMNS_ANY_TABLE = [
        // 稽核欄：依 AGENTS.md §1.2 由系統蓋章、經 AuditActor 產生。這是**署名**不是內容，
        // 內容正規化不得改寫他人姓名（中文使用者名稱確實可能含這些字）。
        'c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date',
        'created_at', 'updated_at',
        // URL 欄：識別碼／位址而非文句，改寫會讓連結失效。
        'c_url_api', 'c_url_api_coda', 'c_url_homepage',
    ];

    /** 型別快取：正規化表名 => [正規化欄位名 => type_name]。 */
    private static ?array $columnTypes = null;

    /** 文本欄清單快取：正規化表名 => 正規化欄位名清單（避免每次重新過濾）。 */
    private static array $textColumnCache = [];

    /** 已知 CBDB 資料表快取：正規化表名的 set。 */
    private static ?array $knownTables = null;

    /**
     * modeFor() 的結果快取（"表.欄" => 'strict'|'lenient'|null）。
     *
     * **不是可選的最佳化**：replaceRow() 對整列每個欄位都呼叫 modeFor()，而批次匯入是
     * 逐列迴圈，S2-S7 又要再掛 20+ 個呼叫點，沒有 memo 時是 O(列數 x 欄數 x 清單長度)
     * 的重複 strtolower。實測 200,000 次呼叫 1.17s 降到 12ms。
     */
    private static array $modeCache = [];

    /** 正規化後的排除／strict 清單快取，避免每次呼叫都重建陣列。 */
    private static ?array $normalizedLists = null;

    /**
     * (表, 欄位) 該用哪個替換模式。
     *
     * @return string|null 'strict'|'lenient'；null = 不替換（排除／未知表／非文本欄）
     */
    public static function modeFor(string $table, string $column): ?string {
        $t = self::normalize($table);
        $c = self::normalize($column);
        $key = $t.'.'.$c;

        if (array_key_exists($key, self::$modeCache)) {
            return self::$modeCache[$key];
        }

        return self::$modeCache[$key] = self::resolveMode($t, $c, $table);
    }

    /** modeFor() 的實際判定（未快取）。$table 保留原樣傳給 textColumns()。 */
    private static function resolveMode(string $t, string $c, string $table): ?string {
        $lists = self::normalizedLists();

        // fail closed：未知表一律不替換（D2）。框架表、紀錄表、客戶端亂傳的字串都落在這裡。
        if (!self::isKnownDataTable($table)) {
            return null;
        }

        if (isset($lists['tables'][$t])) {
            return null;
        }

        if (isset($lists['anyTableColumns'][$c])) {
            return null;
        }

        if (isset($lists['columns'][$t][$c])) {
            return null;
        }

        // 非文本型別不替換。
        if (!in_array($c, self::textColumns($table), true)) {
            return null;
        }

        if (isset($lists['strict'][$t][$c])) {
            return 'strict';
        }

        // D4：預設是 lenient（全量規則），不是 strict。
        return 'lenient';
    }

    /**
     * 把三組排除常數與 strict 清單一次正規化成 hash set，之後查詢都是 O(1)。
     *
     * @return array{tables: array<string,true>, columns: array<string,array<string,true>>, anyTableColumns: array<string,true>, strict: array<string,array<string,true>>}
     */
    private static function normalizedLists(): array {
        if (self::$normalizedLists !== null) {
            return self::$normalizedLists;
        }

        $tables = [];
        foreach (self::EXCLUDED_TABLES as $table) {
            $tables[self::normalize($table)] = true;
        }

        $anyTableColumns = [];
        foreach (self::EXCLUDED_COLUMNS_ANY_TABLE as $column) {
            $anyTableColumns[self::normalize($column)] = true;
        }

        $columns = [];
        foreach (self::EXCLUDED_COLUMNS as $table => $tableColumns) {
            foreach ($tableColumns as $column) {
                $columns[self::normalize($table)][self::normalize($column)] = true;
            }
        }

        $strict = [];
        foreach (self::STRICT_COLUMNS as $table => $tableColumns) {
            foreach ($tableColumns as $column) {
                $strict[self::normalize($table)][self::normalize($column)] = true;
            }
        }

        return self::$normalizedLists = [
            'tables' => $tables,
            'columns' => $columns,
            'anyTableColumns' => $anyTableColumns,
            'strict' => $strict,
        ];
    }

    /**
     * 該表所有文本型別欄位（正規化欄位名），按表快取。
     *
     * 批次匯入是逐列迴圈，若每列都查 information_schema 會造成 N 次 metadata 查詢。
     *
     * @return array<int,string>
     */
    public static function textColumns(string $table): array {
        $t = self::normalize($table);

        if (self::$columnTypes === null) {
            self::$columnTypes = [];
        }

        if (!array_key_exists($t, self::$columnTypes)) {
            // 用 canonical 名查 schema：快取鍵是正規化名，但查詢必須用註冊表的原始拼法，
            // 否則「BIOG_MAIN 」（尾空白）這種外部字串會查到空結果並被快取住，
            // 讓該表在這個 process 之後都不替換（S1 註解警告過的靜默失效模式）。
            self::$columnTypes[$t] = self::loadColumnTypes(self::canonicalTableName($table) ?? $table);
        }

        if (!array_key_exists($t, self::$textColumnCache)) {
            $textColumns = [];
            foreach (self::$columnTypes[$t] as $column => $type) {
                if (in_array($type, self::TEXT_TYPES, true)) {
                    $textColumns[] = $column;
                }
            }
            self::$textColumnCache[$t] = $textColumns;
        }

        return self::$textColumnCache[$t];
    }

    /**
     * 是否為已知 CBDB 資料表（D2 的 fail-closed 判定）。
     *
     * 「已知」= config/codes.php['tables'] ∪ CompositePrimaryKey::SCHEMAS
     *          ∪ config/code_table_writes.php['tables'] ∪ config/code_table_mutations.php['tables']
     *
     * 這幾份 registry 已窮舉所有 CBDB 資料表，所以判定是自動的、不需手工維護。
     */
    public static function isKnownDataTable(string $table): bool {
        if (self::$knownTables === null) {
            self::$knownTables = self::loadKnownTables();
        }

        return isset(self::$knownTables[self::normalize($table)]);
    }

    /**
     * 把（可能來自外部、大小寫／空白不精確的）表名換成註冊表裡的原始拼法。
     *
     * 未知表回 null——呼叫端據此走 fail-closed，也避免把未驗證字串送進 Schema 查詢。
     */
    public static function canonicalTableName(string $table): ?string {
        if (self::$knownTables === null) {
            self::$knownTables = self::loadKnownTables();
        }

        $canonical = self::$knownTables[self::normalize($table)] ?? null;

        return is_string($canonical) ? $canonical : null;
    }

    /** 清快取。**必須在 TestCase::setUp() 呼叫**——測試自建的合成表在不同檔案有不同欄位集。 */
    public static function reset(): void {
        self::$columnTypes = null;
        self::$knownTables = null;
        self::$textColumnCache = [];
        self::$modeCache = [];
        self::$normalizedLists = null;
    }

    /**
     * 已知表聯集。注意四份 registry 的**資料形狀不同**：
     * codes.php['tables'] 與 code_table_writes.php['tables'] 是以表名為鍵的 map（array_keys）；
     * code_table_mutations.php['tables'] 是 **list of maps、表名在 'table' 值**（array_column）。
     * 對第三份誤用 array_keys() 會得到 "0".."13" 並漏掉 14 張真表。
     *
     * @return array<string,true>
     */
    private static function loadKnownTables(): array {
        $names = [];

        foreach (array_keys((array) config('codes.tables', [])) as $name) {
            $names[] = (string) $name;
        }

        foreach (array_keys(CompositePrimaryKey::SCHEMAS) as $name) {
            $names[] = (string) $name;
        }

        foreach (array_keys((array) config('code_table_writes.tables', [])) as $name) {
            $names[] = (string) $name;
        }

        foreach ((array) config('code_table_mutations.tables', []) as $definition) {
            if (is_array($definition) && isset($definition['table'])) {
                $names[] = (string) $definition['table'];
            }
        }

        // 值存**註冊表裡的原始拼法**（不是 true）：外部字串（例如 v1 token API 的 resource）
        // 可能帶大小寫差異或前後空白，正規化後雖然通得過 isKnownDataTable()，但拿原字串去查
        // schema 會查不到欄位、而那個空結果會被三層快取記住 ⇒ 該表在這個 process 之後都不
        // 替換。所以要能把外部字串換成 canonical 名再去查 schema。
        $set = [];
        foreach ($names as $name) {
            if ($name !== '') {
                $set[self::normalize($name)] = $name;
            }
        }

        return $set;
    }

    /**
     * 讀該表的欄位型別。`Schema::getColumns()` 的 type_name 在 MariaDB 與 SQLite
     * 都已歸一為小寫（MySqlGrammar 用 information_schema.DATA_TYPE、
     * SQLiteProcessor 做 strtok(strtolower())）。
     *
     * @return array<string,string> 正規化欄位名 => type_name
     */
    private static function loadColumnTypes(string $table): array {
        try {
            $columns = Schema::getColumns($table);
        } catch (\Throwable $e) {
            // **只有「表確實不存在」才降級**（部署未跑 migration、測試建了精簡表集）。
            // 這個判定是確定性的，快取它安全。
            //
            // 其他 Throwable（連線抖動、暫時性 metadata 鎖、driver 不支援）一律往上拋：
            // 這裡的負結果會被 $columnTypes／$textColumnCache／$modeCache 三層快取住，
            // 一次瞬時錯誤就會讓該表在**這個 process 的剩餘生命週期**都不做替換，資料
            // 靜默留變體形而且沒有任何錯誤回應。長生命週期的 queue worker／artisan
            // 批次匯入尤其致命。與 CharVariantMapService::loadEdges() 的策略一致。
            $tableMissing = false;

            try {
                $tableMissing = !Schema::hasTable($table);
            } catch (\Throwable) {
                // hasTable 也失敗 ⇒ 連線本身有問題，不是「表不存在」⇒ 照原例外往上拋。
            }

            if (!$tableMissing) {
                throw $e;
            }

            Log::warning('VariantReplaceScope: 資料表不存在，該表視為無文本欄', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $types = [];
        foreach ($columns as $column) {
            $name = (string) ($column['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $types[self::normalize($name)] = strtolower((string) ($column['type_name'] ?? ''));
        }

        return $types;
    }

    /** 表名／欄位名的正規化：大小寫不敏感比對（D2 第 1 點）。 */
    private static function normalize(string $name): string {
        return strtolower(trim($name));
    }
}
