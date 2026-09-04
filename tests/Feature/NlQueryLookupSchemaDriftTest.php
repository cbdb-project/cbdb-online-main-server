<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `config/nl_query.php` 的對照表欄位與實際 schema 的漂移守衛。
 *
 * **為什麼需要**：`display_columns` 直接進 `DatabaseSchemaService::getLookupTableData()`
 * 的 `select()`。列了不存在的欄，整張對照表查詢會丟 1054，而該方法的 `catch` 會把它
 * 吞成空陣列並只記一行 warning ⇒ **不會 500，但提示詞裡從此靜默少了這張對照表**，
 * 自然語言查詢生出來的 SQL 因此變差。這種「安靜地壞掉」比 500 更難發現，
 * 所以更需要機械守衛。`BIOG_ADDR_CODES` 曾誤列 `c_addr_id`（那是 ADDR_CODES 的欄）
 * 與根本不存在的 `c_firstlevel_desc`。
 *
 * 掛 `RefreshDatabase` 的理由同 MutationAllowedFieldsSchemaDriftTest：schema 要真的
 * 來自 `database/migrations`，而不是別的測試建的合成表。
 */
class NlQueryLookupSchemaDriftTest extends TestCase {
    use RefreshDatabase;

    #[Test]
    public function every_lookup_display_column_exists_in_the_migrated_schema(): void {
        $lookupTables = config('nl_query.lookup_tables');
        $this->assertIsArray($lookupTables);
        $this->assertNotEmpty($lookupTables, 'nl_query.lookup_tables 是空的——設定檔可能被清掉了');

        $drift = [];
        $checked = 0;

        foreach ($lookupTables as $table => $definition) {
            $columns = $definition['display_columns'] ?? [];
            if ($columns === []) {
                // 沒指定欄位＝select 全部，沒有漂移風險。
                continue;
            }

            if (!Schema::hasTable($table)) {
                $drift[] = "對照表「{$table}」不存在於 migration 建出的 schema";

                continue;
            }

            // 同一個失敗模式的另一半：generateSchemaPrompt() 以 config('codes.tables') 為
            // 過濾器，不在那份清單裡的表根本不會進提示詞——症狀同樣是「安靜地缺料」。
            if (!array_key_exists($table, (array) config('codes.tables'))) {
                $drift[] = "對照表「{$table}」不在 config('codes.tables') 裡，提示詞根本不會納入它";
            }

            $actual = array_map('strtolower', Schema::getColumnListing($table));
            foreach ($columns as $column) {
                ++$checked;
                if (!in_array(strtolower((string) $column), $actual, true)) {
                    $drift[] = "{$table}.{$column} 不存在於 migration 建出的 schema";
                }
            }
        }

        $this->assertGreaterThan(0, $checked, '沒檢查到任何欄位——設定檔結構可能變了');
        $this->assertSame([], $drift, implode("\n", array_merge(
            ['nl_query 對照表列了資料表沒有的欄位（該表會被靜默吞成空，NL 提示詞因此缺料）：'],
            $drift
        )));
    }
}
