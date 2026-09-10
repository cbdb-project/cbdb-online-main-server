<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 代碼／查找表寫入 API 的兩份 config 之間、以及 config 與實際 schema 之間的漂移守衛。
 *
 * **為什麼需要（其一：兩份 config）**：同一張表的 create 讀
 * `config/code_table_writes.php`、update 讀 `config/code_table_mutations.php`，兩份手打
 * 清單之間沒有任何機制保證一致。這正是本專案已經踩過的坑：`ADDR_CODES` 的 create
 * 白名單有 11 欄、update 只有 `c_name`——**新增時填得進去、之後改不了**，而且是靜默的
 * （`array_intersect_key()` 把白名單外的 key 直接丟掉）。與 AGENTS.md §4「必填欄位
 * create／update 一致」同一類問題，只是發生在代碼表這一側。
 *
 * `MutationCreateUpdateParityTest` 不涵蓋這條線：它把 `CodeTableCreateHandler` 列進
 * `CREATE_NOT_COMPARED`，理由寫的是「create/update 共用同一份 config 白名單」——那在
 * create 端只服務 TEXT_CODES 的年代成立，現在兩邊是兩份清單，所以改由本測試接手。
 *
 * **為什麼需要（其二：config vs schema）**：`integer_fields`／`float_fields`／
 * `long_text_fields`／`not_null_fields` 是照著 schema 手抄的。抄漏的症狀不是報錯而是
 * **500 取代 422**（送 null 給 NOT NULL 欄、送超長字串給 longtext 欄被誤擋），
 * 而且要等真的有人送那個值才會發現。
 *
 * **必須掛 `RefreshDatabase`**，schema 才真的來自 `database/migrations`；不掛的話
 * `Schema` 只看得到各測試自建的合成表（同 MutationAllowedFieldsSchemaDriftTest 的理由）。
 */
class CodeTableWriteConfigDriftTest extends TestCase {
    use RefreshDatabase;

    /**
     * 兩份 config 都有、但**刻意**不要求白名單相等的表：表 => 理由。
     * 完整比對：不在這裡、兩邊又不相等的表一律判紅。
     */
    private const ALLOWED_FIELDS_MAY_DIFFER = [
        // TEXT_CODES 的 update 端（code_table_mutations）是 Phase B 拼音專用入口，
        // 刻意只開 c_title 一欄；整列編輯走 text-entity 實體聚合頁，不是這條路。
        // 見 config/entity_aggregates.php 的 text-entity 註解。
        'TEXT_CODES' => 'update 端是拼音專用入口（只開 c_title），整列編輯走 text-entity 聚合',
    ];

    #[Test]
    public function create_and_update_whitelists_agree_for_tables_in_both_configs(): void {
        $writes = $this->byTable(config('code_table_writes.tables', []));
        $updates = $this->byTable(config('code_table_mutations.tables', []));

        $shared = array_intersect(array_keys($writes), array_keys($updates));
        $this->assertNotEmpty($shared, '沒有任何表同時登錄在兩份 config——掃描邏輯壞了');

        $mismatched = [];
        foreach ($shared as $table) {
            if (isset(self::ALLOWED_FIELDS_MAY_DIFFER[$table])) {
                continue;
            }

            $createFields = array_values((array) ($writes[$table]['allowed_fields'] ?? []));
            $updateFields = array_values((array) ($updates[$table]['allowed_fields'] ?? []));
            sort($createFields);
            sort($updateFields);

            if ($createFields !== $updateFields) {
                $mismatched[$table] = [
                    'create_only' => array_values(array_diff($createFields, $updateFields)),
                    'update_only' => array_values(array_diff($updateFields, $createFields)),
                ];
            }
        }

        $this->assertSame(
            [],
            $mismatched,
            "同一張表的 create 與 update 白名單不一致：\n"
                . json_encode($mismatched, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\ncreate 有、update 沒有的欄位＝使用者新增時填得進去、之後改不了（靜默）；"
                . '反向則是白白多一次寫入才發現寫不進去。刻意的差異請登記進 ALLOWED_FIELDS_MAY_DIFFER 並寫理由。'
        );
    }

    /**
     * 兩份 config 的別名清單**刻意**不對稱的表：表 => 理由。
     * 完整比對：不在這裡、兩邊別名集合又不相等的表一律判紅。
     */
    private const ALIASES_MAY_DIFFER = [
        // 歷史遺留且已對外文件化（API.md 13.2 最後一條）：create 接受
        // text-codes／text_codes／textcodes，update 只接受 text_codes。
        // 改動會打斷既有客戶端，故維持並登記在此。
        'TEXT_CODES' => 'create 與 update 別名刻意不對稱，已在 API.md 13.2 明文說明',
    ];

    #[Test]
    public function aliases_agree_between_both_configs_for_shared_tables(): void {
        // 症狀很難查：某個拼法「新增成功、修改卻 501」——呼叫端會以為是自己弄錯表名。
        $writes = $this->byTable(config('code_table_writes.tables', []));
        $updates = $this->byTable(config('code_table_mutations.tables', []));

        $mismatched = [];
        foreach (array_intersect(array_keys($writes), array_keys($updates)) as $table) {
            if (isset(self::ALIASES_MAY_DIFFER[$table])) {
                continue;
            }

            $createAliases = array_values((array) ($writes[$table]['aliases'] ?? []));
            $updateAliases = array_values((array) ($updates[$table]['aliases'] ?? []));
            sort($createAliases);
            sort($updateAliases);

            if ($createAliases !== $updateAliases) {
                $mismatched[$table] = [
                    'create_only' => array_values(array_diff($createAliases, $updateAliases)),
                    'update_only' => array_values(array_diff($updateAliases, $createAliases)),
                ];
            }
        }

        $this->assertSame(
            [],
            $mismatched,
            "同一張表的 create 與 update 別名清單不一致：
"
                . json_encode($mismatched, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                . "
只有一邊認得的拼法會讓呼叫端拿到 501（找不到 handler），而錯誤訊息看起來像是表名寫錯。"
                . '刻意的差異請登記進 ALIASES_MAY_DIFFER 並寫理由。'
        );
    }

    #[Test]
    public function every_configured_resource_name_is_one_of_its_own_aliases(): void {
        // resource 是回應與提案 meta 用的正規名；不在自己的 aliases 裡的話，
        // 呼叫端照回應的 resource 值回送會 501。
        $problems = [];
        foreach ([
            'code_table_writes' => config('code_table_writes.tables', []),
            'code_table_mutations' => config('code_table_mutations.tables', []),
        ] as $configName => $definitions) {
            foreach ($definitions as $def) {
                if (!in_array($def['resource'] ?? '', (array) ($def['aliases'] ?? []), true)) {
                    $problems[] = $configName . ': ' . ($def['table'] ?? '?') . ' 的 resource「' . ($def['resource'] ?? '') . '」不在自己的 aliases 內';
                }
            }
        }

        $this->assertSame([], $problems, implode("
", $problems));
    }

    #[Test]
    public function type_registries_agree_between_both_configs_for_shared_tables(): void {
        $writes = $this->byTable(config('code_table_writes.tables', []));
        $updates = $this->byTable(config('code_table_mutations.tables', []));

        $registries = ['integer_fields', 'float_fields', 'long_text_fields', 'not_null_fields'];
        $mismatched = [];

        foreach (array_intersect(array_keys($writes), array_keys($updates)) as $table) {
            foreach ($registries as $registry) {
                // 只比對「兩邊都收得下」的欄位：白名單本身允許刻意不同（見上一支測試的
                // 例外清冊），拿 update 收不到的欄位去比對型別登記沒有意義。
                $common = array_intersect(
                    (array) ($writes[$table]['allowed_fields'] ?? []),
                    (array) ($updates[$table]['allowed_fields'] ?? [])
                );

                $inCreate = array_values(array_intersect((array) ($writes[$table][$registry] ?? []), $common));
                $inUpdate = array_values(array_intersect((array) ($updates[$table][$registry] ?? []), $common));
                sort($inCreate);
                sort($inUpdate);

                if ($inCreate !== $inUpdate) {
                    $mismatched[$table . '.' . $registry] = ['create' => $inCreate, 'update' => $inUpdate];
                }
            }
        }

        $this->assertSame(
            [],
            $mismatched,
            "同一張表、同一個欄位在 create 與 update 被登記成不同型別：\n"
                . json_encode($mismatched, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                . "\n症狀是同一個值在新增時 200、修改時 422（或反過來）。"
        );
    }

    /**
     * 型別比對的例外：`表.欄位`（小寫）=> 理由。
     *
     * 這裡的存在本身就是一個已知限制：`2026_02_12_000001_convert_fields_to_smallint`
     * 整支在 `is_sqlite()` 時直接 return，所以測試環境（SQLite）看到的是 baseline 的
     * 舊型別、prod（MariaDB）是轉換後的型別。凡是被那支 migration 動過、又出現在
     * 代碼表寫入 config 裡的欄位，都會在這裡誤報一次。**config 要以 prod 型別為準**
     * （已逐欄對過 `SHOW COLUMNS`），所以此處登記例外、不是改 config。
     */
    private const TYPE_CHECK_EXEMPT = [
        // prod 是 SMALLINT（該 migration 轉過），SQLite 仍是 baseline 的 varchar(255)。
        'text_instance_data.c_pub_year' => 'prod 為 smallint，convert_fields_to_smallint 在 SQLite 跳過',
    ];

    #[Test]
    public function every_configured_field_exists_and_its_type_registry_matches_the_schema(): void {
        $problems = [];

        foreach ([
            'code_table_writes' => config('code_table_writes.tables', []),
            'code_table_mutations' => config('code_table_mutations.tables', []),
        ] as $configName => $definitions) {
            foreach ($definitions as $def) {
                $table = $def['table'] ?? null;
                if ($table === null || !Schema::hasTable($table)) {
                    // 表不存在於 migration（例如僅 prod 有）——不是本測試能判定的事。
                    continue;
                }

                $types = $this->columnTypes($table);
                $nullables = $this->columnNullability($table);
                $keyColumns = (array) ($def['key_columns'] ?? []);

                // 型別登記裡出現白名單以外的欄位＝該登記完全不會生效（純打字錯誤，
                // 而且是靜默的：欄位照樣被當成預設的 string|null 處理）。
                foreach (['integer_fields', 'float_fields', 'long_text_fields', 'not_null_fields'] as $registry) {
                    foreach ((array) ($def[$registry] ?? []) as $field) {
                        if (!in_array($field, (array) ($def['allowed_fields'] ?? []), true)) {
                            $problems[] = "{$configName}: {$table}.{$field} 登記在 {$registry}，但不在 allowed_fields（登記不會生效）";
                        }
                    }
                }

                foreach (array_merge((array) ($def['allowed_fields'] ?? []), $keyColumns) as $field) {
                    if (!isset($types[strtolower($field)])) {
                        $problems[] = "{$configName}: {$table}.{$field} 不存在於 migration schema";
                    }
                }

                // 登記為整數／浮點的欄位，實際型別必須真的是數值欄；反之，白名單裡的
                // 數值欄必須被登記（漏登＝JSON 客戶端送數字會被 422 誤擋）。
                foreach ((array) ($def['allowed_fields'] ?? []) as $field) {
                    $type = $types[strtolower($field)] ?? null;
                    if ($type === null) {
                        continue;
                    }

                    if (isset(self::TYPE_CHECK_EXEMPT[strtolower($table) . '.' . strtolower($field)])) {
                        continue;
                    }

                    $registeredNumeric = in_array($field, (array) ($def['integer_fields'] ?? []), true)
                        || in_array($field, (array) ($def['float_fields'] ?? []), true);
                    $actuallyNumeric = $this->isNumericType($type);

                    if ($actuallyNumeric && !$registeredNumeric) {
                        $problems[] = "{$configName}: {$table}.{$field} 是數值欄（{$type}）卻未登記 integer_fields／float_fields";
                    }
                    if (!$actuallyNumeric && $registeredNumeric) {
                        $problems[] = "{$configName}: {$table}.{$field} 登記為數值欄，實際型別是 {$type}";
                    }

                    // 長文欄必須登記，否則基底的 255 上限會把合法內容誤擋成 422。
                    $registeredLong = in_array($field, (array) ($def['long_text_fields'] ?? []), true);
                    $actuallyLong = in_array($type, ['text', 'mediumtext', 'longtext'], true);
                    if ($actuallyLong && !$registeredLong) {
                        $problems[] = "{$configName}: {$table}.{$field} 是 {$type} 卻未登記 long_text_fields（會被 255 上限誤擋）";
                    }

                    // NOT NULL 欄必須登記，否則送 null 是資料庫層 1048（500）而不是 422。
                    // 這正是本測試最初要防的那一類，而第一版漏了 nullable 這條規則——
                    // 於是 char_variant_map／GANZHI_CODES 等表的 NOT NULL 欄一直沒被登記到。
                    $registeredNotNull = in_array($field, (array) ($def['not_null_fields'] ?? []), true);
                    $nullable = $nullables[strtolower($field)] ?? true;
                    if (!$nullable && !$registeredNotNull) {
                        $problems[] = "{$configName}: {$table}.{$field} 是 NOT NULL 卻未登記 not_null_fields（送 null 會 500 而不是 422）";
                    }
                    if ($nullable && $registeredNotNull) {
                        $problems[] = "{$configName}: {$table}.{$field} 登記為 not_null_fields，實際可為 null";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            "代碼表寫入 config 與 migration schema 漂移：\n" . implode("\n", $problems)
        );
    }

    #[Test]
    public function key_columns_match_the_composite_primary_key_registry(): void {
        // handler 會在 runtime 以 500 擋下不一致，但那要等有人真的呼叫才會發現。
        $problems = [];

        foreach (config('code_table_writes.tables', []) as $configKey => $def) {
            $table = $def['table'];
            // 陣列鍵必須就是表名：VariantReplaceScope::loadKnownTables() 以 array_keys()
            // 讀這份 config，鍵名不對的話該表會被視為未知表 → 異體字落地替換靜默 fail-closed。
            if ($configKey !== $table) {
                $problems[] = $table . '：code_table_writes 的陣列鍵（' . $configKey . '）必須等於表名，否則落地替換會把它當未知表';
            }
            if (\App\Support\CompositePrimaryKey::getSchema($table) !== (array) ($def['key_columns'] ?? [])) {
                $problems[] = $table . '：code_table_writes 的 key_columns 與 CompositePrimaryKey::SCHEMAS 不一致';
            }
            if (!empty($def['auto_assign_id']) && count((array) $def['key_columns']) !== 1) {
                $problems[] = $table . '：複合主鍵不可設 auto_assign_id';
            }
        }

        // update 端同樣有 500 防呆（AbstractCodeTableMutationHandler::handle()），
        // 但那要等有人真的呼叫該資源才會發現，所以這裡一併靜態檢查。
        foreach (config('code_table_mutations.tables', []) as $def) {
            $table = $def['table'];
            if (\App\Support\CompositePrimaryKey::getSchema($table) !== (array) ($def['key_columns'] ?? [])) {
                $problems[] = $table . '：code_table_mutations 的 key_columns 與 CompositePrimaryKey::SCHEMAS 不一致';
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * @param array<mixed> $definitions
     * @return array<string,array<string,mixed>> 表名 => 定義
     */
    private function byTable(array $definitions): array {
        $byTable = [];
        foreach ($definitions as $def) {
            if (isset($def['table'])) {
                $byTable[$def['table']] = $def;
            }
        }

        return $byTable;
    }

    /** @return array<string,bool> 小寫欄位名 => 是否可為 null */
    private function columnNullability(string $table): array {
        $nullable = [];
        foreach (Schema::getColumns($table) as $column) {
            $nullable[strtolower($column['name'])] = (bool) $column['nullable'];
        }

        return $nullable;
    }

    /** @return array<string,string> 小寫欄位名 => type_name */
    private function columnTypes(string $table): array {
        $types = [];
        foreach (Schema::getColumns($table) as $column) {
            $types[strtolower($column['name'])] = strtolower($column['type_name']);
        }

        return $types;
    }

    private function isNumericType(string $type): bool {
        return in_array($type, [
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
            'float', 'double', 'real', 'decimal', 'numeric',
        ], true);
    }
}
