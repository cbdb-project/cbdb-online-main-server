<?php

namespace Tests\Feature;

use App\Console\Commands\ExportMysqlToSqlite;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * 端到端驗證：把含 COMMENT 的 MySQL DDL 經過轉換後，餵給真實 SQLite，
 * 再從 sqlite_master.sql 讀回原文，確認欄位／表級註解都被保留下來。
 */
class SqliteExportPreservesCommentsTest extends TestCase {
    private function convert(string $mysqlSql, string $tableName): array {
        $command = new class () extends ExportMysqlToSqlite {
            public function option($key = null) {
                return false;
            }
        };
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('convertCreateTableToSqlite');
        $method->setAccessible(true);

        return $method->invoke($command, $mysqlSql, $tableName);
    }

    public function test_sqlite_master_sql_retains_column_and_table_comments(): void {
        $mysql = "CREATE TABLE `BIOG_MAIN_TEST` (\n"
            . "  `c_personid` int(11) NOT NULL COMMENT '人物 ID（CBDB 主鍵）',\n"
            . "  `c_surname` varchar(255) DEFAULT NULL COMMENT 'person''s surname; auto-generated',\n"
            . "  `c_chinese` varchar(255) DEFAULT NULL COMMENT '人物姓氏，由 c_surname_chn 自動生成',\n"
            . "  PRIMARY KEY (`c_personid`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='人物主表，CBDB 核心資料'";

        $converted = $this->convert($mysql, 'BIOG_MAIN_TEST');

        // 真正餵給 SQLite，再讀回 sqlite_master.sql
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('此測試需要 SQLite 連線');
        }

        $connection->statement('DROP TABLE IF EXISTS BIOG_MAIN_TEST');
        $connection->statement($converted['table']);

        $stored = $connection->selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='BIOG_MAIN_TEST'");
        $this->assertNotNull($stored, 'CREATE TABLE 未被 SQLite 接受');

        $sql = $stored->sql;
        // 表級註解（放在括號內第一個欄位前）必須被 SQLite 保留
        $this->assertStringContainsString('/* 人物主表，CBDB 核心資料 */', $sql);
        // 欄位註解必須被 SQLite 保留，且 '' 已被解碼為單一撇號
        $this->assertStringContainsString('/* 人物 ID（CBDB 主鍵） */', $sql);
        $this->assertStringContainsString("/* person's surname; auto-generated */", $sql);
        $this->assertStringContainsString('/* 人物姓氏，由 c_surname_chn 自動生成 */', $sql);

        // 結構仍可正常運作
        $connection->table('BIOG_MAIN_TEST')->insert([
            'c_personid' => 1,
            'c_surname' => 'Wang',
            'c_chinese' => '王',
        ]);
        $row = $connection->table('BIOG_MAIN_TEST')->where('c_personid', 1)->first();
        $this->assertSame('Wang', $row->c_surname);
        $this->assertSame('王', $row->c_chinese);

        $connection->statement('DROP TABLE IF EXISTS BIOG_MAIN_TEST');
    }
}
