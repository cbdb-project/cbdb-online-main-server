<?php

namespace Tests\Feature;

use App\Console\Commands\ExportMysqlToSqlite;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * 驗證 db:export-to-sqlite 匯出時，會排除已被軟刪除
 * （BIOG_MAIN.c_name_chn = '<待删除>'，見 App\Services\Mutations\BiogMainDeleteHandler::DELETE_MARKER）
 * 人物的資料列，避免這些人物外流到公開釋出的 SQLite 檔案：
 *   - BIOG_MAIN 本身排除該列。
 *   - 其餘表中，所有指向 BIOG_MAIN.c_personid 的欄位（含 c_personid 本身與關係欄位，
 *     如 KIN_DATA.c_kin_id）只要命中已刪除人物即排除該列。
 *   - 沒有任何人物 ID 欄位的表（代碼表等）不受影響。
 */
class ExportMysqlToSqliteExcludesDeletedBiogMainTest extends TestCase {
    private function invokeExcludeSoftDeletedPersons(string $tableName, $query, array $personIdColumnsOverride = null) {
        $command = new class ($personIdColumnsOverride) extends ExportMysqlToSqlite {
            private $personIdColumnsOverride;

            public function __construct($personIdColumnsOverride) {
                parent::__construct();
                $this->personIdColumnsOverride = $personIdColumnsOverride;
            }

            public function option($key = null) {
                return false;
            }

            protected function getPersonIdColumnsForTable($tableName) {
                if ($this->personIdColumnsOverride !== null) {
                    return $this->personIdColumnsOverride;
                }

                return parent::getPersonIdColumnsForTable($tableName);
            }
        };
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('excludeSoftDeletedPersons');
        $method->setAccessible(true);

        return $method->invoke($command, $tableName, $query);
    }

    private function invokeGetPersonIdColumnsForTable(ExportMysqlToSqlite $command, string $tableName): array {
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getPersonIdColumnsForTable');
        $method->setAccessible(true);

        return $method->invoke($command, $tableName);
    }

    private function invokeGetTableRowCount(ExportMysqlToSqlite $command, string $tableName): int {
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('getTableRowCount');
        $method->setAccessible(true);

        return $method->invoke($command, $tableName);
    }

    public function test_biog_main_query_excludes_deleted_marker_rows(): void {
        DB::statement('CREATE TABLE BIOG_MAIN (c_personid INTEGER PRIMARY KEY, c_name_chn TEXT NULL)');

        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name_chn' => '王安石'],
            ['c_personid' => 2, 'c_name_chn' => '<待删除>'],
            ['c_personid' => 3, 'c_name_chn' => null],
        ]);

        $query = DB::table('BIOG_MAIN');
        $this->invokeExcludeSoftDeletedPersons('BIOG_MAIN', $query);

        $remainingIds = $query->pluck('c_personid')->sort()->values()->all();

        // 已標記待刪除的 person 2 應被排除；正常資料與 NULL 姓名的資料須保留。
        $this->assertSame([1, 3], $remainingIds);

        DB::statement('DROP TABLE BIOG_MAIN');
    }

    public function test_related_table_with_c_personid_excludes_rows_of_deleted_person(): void {
        DB::statement('CREATE TABLE BIOG_MAIN (c_personid INTEGER PRIMARY KEY, c_name_chn TEXT NULL)');
        DB::statement('CREATE TABLE ALTNAME_DATA (c_seq INTEGER PRIMARY KEY, c_personid INTEGER NULL, c_alt_name_chn TEXT NULL)');

        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name_chn' => '王安石'],
            ['c_personid' => 2, 'c_name_chn' => '<待删除>'],
        ]);

        DB::table('ALTNAME_DATA')->insert([
            ['c_seq' => 1, 'c_personid' => 1, 'c_alt_name_chn' => '介甫'],
            ['c_seq' => 2, 'c_personid' => 2, 'c_alt_name_chn' => '應被排除的別名'],
            ['c_seq' => 3, 'c_personid' => null, 'c_alt_name_chn' => 'c_personid 為 NULL 應保留'],
        ]);

        $query = DB::table('ALTNAME_DATA');
        $this->invokeExcludeSoftDeletedPersons('ALTNAME_DATA', $query);

        $remainingSeqs = $query->pluck('c_seq')->sort()->values()->all();

        $this->assertSame([1, 3], $remainingSeqs);

        DB::statement('DROP TABLE ALTNAME_DATA');
        DB::statement('DROP TABLE BIOG_MAIN');
    }

    public function test_tables_without_c_personid_column_are_not_filtered(): void {
        DB::statement('CREATE TABLE BIOG_MAIN (c_personid INTEGER PRIMARY KEY, c_name_chn TEXT NULL)');
        DB::statement('CREATE TABLE COUNTRY_CODES (c_country_code INTEGER PRIMARY KEY, c_name_chn TEXT NULL)');

        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name_chn' => '<待删除>'],
        ]);

        DB::table('COUNTRY_CODES')->insert([
            ['c_country_code' => 1, 'c_name_chn' => '<待删除>'],
        ]);

        $query = DB::table('COUNTRY_CODES');
        $this->invokeExcludeSoftDeletedPersons('COUNTRY_CODES', $query);

        // COUNTRY_CODES 沒有 c_personid 欄位，即使 c_name_chn 剛好等於標記字串也不受影響。
        $this->assertSame(1, $query->count());

        DB::statement('DROP TABLE COUNTRY_CODES');
        DB::statement('DROP TABLE BIOG_MAIN');
    }

    public function test_relationship_column_referencing_deleted_person_is_excluded(): void {
        // 模擬 KIN_DATA：c_personid 是資料擁有者，c_kin_id 是關係中的另一方，
        // 兩者都可能指向已刪除人物，任一命中即應排除該列。
        DB::statement('CREATE TABLE BIOG_MAIN (c_personid INTEGER PRIMARY KEY, c_name_chn TEXT NULL)');
        DB::statement('CREATE TABLE KIN_DATA (c_seq INTEGER PRIMARY KEY, c_personid INTEGER NULL, c_kin_id INTEGER NULL)');

        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name_chn' => '王安石'],
            ['c_personid' => 2, 'c_name_chn' => '<待删除>'],
            ['c_personid' => 3, 'c_name_chn' => '蘇軾'],
        ]);

        DB::table('KIN_DATA')->insert([
            ['c_seq' => 1, 'c_personid' => 1, 'c_kin_id' => 3], // 兩邊都正常，應保留
            ['c_seq' => 2, 'c_personid' => 2, 'c_kin_id' => 3], // 擁有者已刪除，應排除
            ['c_seq' => 3, 'c_personid' => 1, 'c_kin_id' => 2], // 關係對象已刪除，應排除
            ['c_seq' => 4, 'c_personid' => 1, 'c_kin_id' => null], // 關係對象為 NULL，應保留
        ]);

        $query = DB::table('KIN_DATA');
        $this->invokeExcludeSoftDeletedPersons('KIN_DATA', $query, ['c_personid', 'c_kin_id']);

        $remainingSeqs = $query->pluck('c_seq')->sort()->values()->all();

        $this->assertSame([1, 4], $remainingSeqs);

        DB::statement('DROP TABLE KIN_DATA');
        DB::statement('DROP TABLE BIOG_MAIN');
    }

    public function test_get_person_id_columns_falls_back_to_c_personid_and_extra_map_without_information_schema(): void {
        // 測試環境的來源連線是 SQLite，沒有 information_schema，getPersonIdColumnsForTable()
        // 應優雅回退為「c_personid（若存在）＋ EXTRA_PERSON_ID_COLUMNS」，不可拋例外。
        DB::statement('CREATE TABLE MERGED_PERSON_DATA (c_personid INTEGER NOT NULL, c_merged_from_personid INTEGER NOT NULL)');

        $command = new class () extends ExportMysqlToSqlite {
            public function option($key = null) {
                return false;
            }
        };
        $columns = $this->invokeGetPersonIdColumnsForTable($command, 'MERGED_PERSON_DATA');
        sort($columns);

        $this->assertSame(['c_merged_from_personid', 'c_personid'], $columns);

        DB::statement('DROP TABLE MERGED_PERSON_DATA');
    }

    public function test_get_table_row_count_rethrows_mysql_metadata_failures_instead_of_returning_zero(): void {
        DB::statement('CREATE TABLE BIOG_MAIN (c_personid INTEGER PRIMARY KEY, c_name_chn TEXT NULL)');
        DB::statement('CREATE TABLE KIN_DATA (c_seq INTEGER PRIMARY KEY, c_personid INTEGER NULL)');

        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name_chn' => '王安石'],
            ['c_personid' => 2, 'c_name_chn' => '<待删除>'],
        ]);
        DB::table('KIN_DATA')->insert([
            ['c_seq' => 1, 'c_personid' => 1],
            ['c_seq' => 2, 'c_personid' => 2],
        ]);

        $command = new class () extends ExportMysqlToSqlite {
            public function option($key = null) {
                return false;
            }

            protected function sourceUsesInformationSchemaPersonReferences(): bool {
                return true;
            }

            protected function getMysqlPersonIdColumnsFromInformationSchema($tableName): array {
                throw new \RuntimeException('mock information_schema failure');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mock information_schema failure');

        try {
            $this->invokeGetTableRowCount($command, 'KIN_DATA');
        } finally {
            DB::statement('DROP TABLE KIN_DATA');
            DB::statement('DROP TABLE BIOG_MAIN');
        }
    }

    public function test_get_table_row_count_still_degrades_gracefully_on_unrelated_count_failure(): void {
        // 排除已刪除人物的過濾條件建立成功後，COUNT(*) 本身失敗（逾時、鎖等）
        // 屬於統計性失敗，只影響進度條，不應該讓整張表的匯出被視為錯誤中斷。
        DB::statement('CREATE TABLE BIOG_MAIN (c_personid INTEGER PRIMARY KEY, c_name_chn TEXT NULL)');
        DB::statement('CREATE TABLE KIN_DATA (c_seq INTEGER PRIMARY KEY, c_personid INTEGER NULL)');

        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_name_chn' => '王安石'],
        ]);
        DB::table('KIN_DATA')->insert([
            ['c_seq' => 1, 'c_personid' => 1],
        ]);

        // KIN_DATA 建完過濾條件後才 DROP，讓 count() 本身失敗，但 excludeSoftDeletedPersons()
        // 已成功執行（不涉及 information_schema 失敗）。
        $command = new class () extends ExportMysqlToSqlite {
            public function option($key = null) {
                return false;
            }
        };
        // getTableRowCount() 在 count() 失敗時會呼叫 warn()；測試直接 new command 時需補上 output，
        // 否則 Console\Command 的輸出物件為 null，會讓這個「應降級為 0」的案例變成輸出層例外。
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        DB::statement('DROP TABLE KIN_DATA');

        $count = $this->invokeGetTableRowCount($command, 'KIN_DATA');

        $this->assertSame(0, $count);

        DB::statement('DROP TABLE BIOG_MAIN');
    }
}
