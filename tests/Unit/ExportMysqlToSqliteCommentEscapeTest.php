<?php

namespace Tests\Unit;

use App\Console\Commands\ExportMysqlToSqlite;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ExportMysqlToSqliteCommentEscapeTest extends TestCase {
    private function convert(string $mysqlSql, string $tableName = 'BIOG_MAIN'): array {
        // 匿名子類別覆寫 option()，讓我們可以脫離 Laravel console pipeline 直接呼叫
        // 受保護的轉換方法。
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

    /**
     * MySQL 以 SQL 標準雙寫單引號（''）跳脫 COMMENT 內的撇號時，
     * 解析必須能正確識別字串範圍，否則殘留字面量會破壞 SQLite DDL。
     * COMMENT 內容應以區塊註解形式保留，並把 '' 還原為單一撇號。
     */
    public function test_preserves_comment_with_doubled_single_quote_escape(): void {
        $sql = "CREATE TABLE `BIOG_MAIN` (\n"
            . "  `c_personid` int(11) NOT NULL,\n"
            . "  `c_surname` varchar(255) DEFAULT NULL COMMENT 'person''s surname; auto-generated from c_surname_chn via pinyin lookup table',\n"
            . "  PRIMARY KEY (`c_personid`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $result = $this->convert($sql);

        // 不應殘留 MySQL 的 COMMENT 關鍵字
        $this->assertStringNotContainsString('COMMENT ', $result['table']);
        // '' 必須被解碼回單一撇號
        $this->assertStringContainsString("/* person's surname; auto-generated from c_surname_chn via pinyin lookup table */", $result['table']);
        // 欄位定義仍然合法
        $this->assertMatchesRegularExpression('/"c_surname"\s+\S+\s+DEFAULT NULL\s+\/\*/', $result['table']);
    }

    /**
     * MySQL 反斜線跳脫風格（\'）也應被正確解碼。
     */
    public function test_preserves_comment_with_backslash_escape(): void {
        $sql = "CREATE TABLE `BIOG_MAIN` (\n"
            . "  `c_personid` int(11) NOT NULL,\n"
            . "  `c_surname` varchar(255) DEFAULT NULL COMMENT 'person\\'s surname; auto-generated',\n"
            . "  PRIMARY KEY (`c_personid`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $result = $this->convert($sql);

        $this->assertStringNotContainsString('COMMENT ', $result['table']);
        $this->assertStringContainsString("/* person's surname; auto-generated */", $result['table']);
    }

    /**
     * 同一張表上多個欄位都帶有 COMMENT 時，每一個都要被獨立保留。
     */
    public function test_preserves_multiple_comments_with_apostrophes(): void {
        $sql = "CREATE TABLE `BIOG_MAIN` (\n"
            . "  `c_personid` int(11) NOT NULL,\n"
            . "  `c_surname` varchar(255) DEFAULT NULL COMMENT 'person''s surname',\n"
            . "  `c_mingzi` varchar(255) DEFAULT NULL COMMENT 'don''t break me',\n"
            . "  PRIMARY KEY (`c_personid`)\n"
            . ") ENGINE=InnoDB";

        $result = $this->convert($sql);

        $this->assertStringNotContainsString('COMMENT ', $result['table']);
        $this->assertStringContainsString("/* person's surname */", $result['table']);
        $this->assertStringContainsString("/* don't break me */", $result['table']);
    }

    /**
     * COMMENT 內含逗號時，splitDefinitionItems 的字串感知必須保護它，否則會被誤切。
     */
    public function test_preserves_comment_with_comma_inside_string_literal(): void {
        $sql = "CREATE TABLE `BIOG_MAIN` (\n"
            . "  `c_personid` int(11) NOT NULL,\n"
            . "  `c_surname_proper` varchar(255) DEFAULT NULL COMMENT 'Surname in the person''s native language (non-Chinese), if applicable; user-editable',\n"
            . "  `c_backslash_escape` varchar(255) DEFAULT NULL COMMENT 'Surname in the person\\'s native language, if applicable',\n"
            . "  `c_name` varchar(255) DEFAULT NULL COMMENT 'Name after comma case',\n"
            . "  PRIMARY KEY (`c_personid`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $result = $this->convert($sql);

        $this->assertStringNotContainsString('COMMENT ', $result['table']);
        $this->assertStringContainsString(
            "/* Surname in the person's native language (non-Chinese), if applicable; user-editable */",
            $result['table']
        );
        $this->assertStringContainsString(
            "/* Surname in the person's native language, if applicable */",
            $result['table']
        );
        $this->assertStringContainsString('/* Name after comma case */', $result['table']);
        // 三個欄位都應該獨立存在，沒有被 COMMENT 內的逗號弄壞
        $this->assertMatchesRegularExpression('/"c_surname_proper"/', $result['table']);
        $this->assertMatchesRegularExpression('/"c_backslash_escape"/', $result['table']);
        $this->assertMatchesRegularExpression('/"c_name"/', $result['table']);
    }

    /**
     * 普通的純 ASCII COMMENT 也要被保留。
     */
    public function test_preserves_plain_comment(): void {
        $sql = "CREATE TABLE `T` (\n"
            . "  `id` int(11) NOT NULL COMMENT 'plain comment',\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB";

        $result = $this->convert($sql, 'T');

        $this->assertStringNotContainsString('COMMENT ', $result['table']);
        $this->assertStringContainsString('/* plain comment */', $result['table']);
    }

    /**
     * 中日韓字元的 COMMENT 必須原樣保留，不能被字節級正則破壞。
     */
    public function test_preserves_chinese_comment(): void {
        $sql = "CREATE TABLE `BIOG_MAIN` (\n"
            . "  `c_personid` int(11) NOT NULL,\n"
            . "  `c_surname` varchar(255) DEFAULT NULL COMMENT '人物姓氏，由 c_surname_chn 自動生成',\n"
            . "  PRIMARY KEY (`c_personid`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $result = $this->convert($sql);

        $this->assertStringContainsString('/* 人物姓氏，由 c_surname_chn 自動生成 */', $result['table']);
    }

    /**
     * COMMENT 內含 *​/ 必須被改寫，否則會把區塊註解提早關掉。
     */
    public function test_escapes_block_comment_terminator_inside_comment(): void {
        $sql = "CREATE TABLE `T` (\n"
            . "  `id` int(11) NOT NULL COMMENT 'tricky */ payload',\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB";

        $result = $this->convert($sql, 'T');

        $this->assertStringNotContainsString("'tricky */ payload'", $result['table']);
        $this->assertStringContainsString('/* tricky * / payload */', $result['table']);
        // 整段 CREATE TABLE 應該只有一個結束的 */（屬於該欄位的註解），且結構完整
        $this->assertMatchesRegularExpression('/"id"\s+\S+(?:\s+NOT NULL)?\s+\/\* tricky \* \/ payload \*\//', $result['table']);
    }

    /**
     * 表級 COMMENT='...' 應作為區塊註解，保留在 CREATE TABLE 括號內第一個欄位之前。
     * 必須位於括號內才能被 SQLite 原樣保存到 sqlite_master.sql；位於 CREATE TABLE
     * 之前或分號之後的註解都會被解析器丟棄。
     */
    public function test_preserves_table_level_comment(): void {
        $sql = "CREATE TABLE `BIOG_MAIN` (\n"
            . "  `c_personid` int(11) NOT NULL,\n"
            . "  PRIMARY KEY (`c_personid`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='人物主表，CBDB 核心資料'";

        $result = $this->convert($sql);

        $this->assertStringContainsString('/* 人物主表，CBDB 核心資料 */', $result['table']);
        // 表級註解必須出現在 CREATE TABLE ( 之後、第一個欄位之前
        $this->assertMatchesRegularExpression(
            '/CREATE TABLE\s+"BIOG_MAIN"\s*\(\s*\/\*\s*人物主表，CBDB 核心資料\s*\*\//s',
            $result['table']
        );
    }

    /**
     * 沒有 COMMENT 的欄位不應被加上空 /* *​/。
     */
    public function test_does_not_add_empty_block_comment(): void {
        $sql = "CREATE TABLE `T` (\n"
            . "  `id` int(11) NOT NULL,\n"
            . "  `name` varchar(255) DEFAULT NULL,\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB";

        $result = $this->convert($sql, 'T');

        $this->assertStringNotContainsString('/*', $result['table']);
        $this->assertStringNotContainsString('*/', $result['table']);
    }
}
