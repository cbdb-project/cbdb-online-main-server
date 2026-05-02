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
     * 之前的非貪婪正則會在第一個內部單引號就提前結束，殘留字串字面量
     * 會破壞 SQLite DDL 解析。回歸案例來自 BIOG_MAIN.c_surname。
     */
    public function test_strips_comment_with_doubled_single_quote_escape(): void {
        $sql = "CREATE TABLE `BIOG_MAIN` (\n"
            . "  `c_personid` int(11) NOT NULL,\n"
            . "  `c_surname` varchar(255) DEFAULT NULL COMMENT 'person''s surname; auto-generated from c_surname_chn via pinyin lookup table',\n"
            . "  PRIMARY KEY (`c_personid`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $result = $this->convert($sql);

        $this->assertStringNotContainsString('COMMENT', $result['table']);
        $this->assertStringNotContainsString("surname'", $result['table']);
        $this->assertStringNotContainsString("'s surname", $result['table']);
        $this->assertMatchesRegularExpression('/"c_surname"\s+\S+\s+DEFAULT NULL/', $result['table']);
    }

    /**
     * MySQL 反斜線跳脫風格（\'）也應該被正確處理。
     */
    public function test_strips_comment_with_backslash_escape(): void {
        $sql = "CREATE TABLE `BIOG_MAIN` (\n"
            . "  `c_personid` int(11) NOT NULL,\n"
            . "  `c_surname` varchar(255) DEFAULT NULL COMMENT 'person\\'s surname; auto-generated',\n"
            . "  PRIMARY KEY (`c_personid`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $result = $this->convert($sql);

        $this->assertStringNotContainsString('COMMENT', $result['table']);
        $this->assertStringNotContainsString("'s surname", $result['table']);
        $this->assertMatchesRegularExpression('/"c_surname"\s+\S+\s+DEFAULT NULL/', $result['table']);
    }

    /**
     * 同一張表上多個欄位都帶有含撇號的 COMMENT 時，每個都要正確去除。
     */
    public function test_strips_multiple_comments_with_apostrophes(): void {
        $sql = "CREATE TABLE `BIOG_MAIN` (\n"
            . "  `c_personid` int(11) NOT NULL,\n"
            . "  `c_surname` varchar(255) DEFAULT NULL COMMENT 'person''s surname',\n"
            . "  `c_mingzi` varchar(255) DEFAULT NULL COMMENT 'don''t break me',\n"
            . "  PRIMARY KEY (`c_personid`)\n"
            . ") ENGINE=InnoDB";

        $result = $this->convert($sql);

        $this->assertStringNotContainsString('COMMENT', $result['table']);
        $this->assertStringNotContainsString("'s surname", $result['table']);
        $this->assertStringNotContainsString("'t break", $result['table']);
        $this->assertMatchesRegularExpression('/"c_surname"\s+\S+\s+DEFAULT NULL/', $result['table']);
        $this->assertMatchesRegularExpression('/"c_mingzi"\s+\S+\s+DEFAULT NULL/', $result['table']);
    }

    /**
     * COMMENT 內含逗號時，切分欄位定義的階段不能把字串字面量中的逗號當成欄位分隔符。
     * 回歸案例如 BIOG_MAIN.c_surname_proper。
     */
    public function test_strips_comment_with_comma_inside_string_literal(): void {
        $sql = "CREATE TABLE `BIOG_MAIN` (\n"
            . "  `c_personid` int(11) NOT NULL,\n"
            . "  `c_surname_proper` varchar(255) DEFAULT NULL COMMENT 'Surname in the person''s native language (non-Chinese), if applicable; user-editable',\n"
            . "  `c_backslash_escape` varchar(255) DEFAULT NULL COMMENT 'Surname in the person\\'s native language, if applicable',\n"
            . "  `c_name` varchar(255) DEFAULT NULL COMMENT 'Name after comma case',\n"
            . "  PRIMARY KEY (`c_personid`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $result = $this->convert($sql);

        $this->assertStringNotContainsString('COMMENT', $result['table']);
        $this->assertStringNotContainsString('if applicable', $result['table']);
        $this->assertStringNotContainsString("person''s native language", $result['table']);
        $this->assertMatchesRegularExpression('/"c_surname_proper"\s+\S+\s+DEFAULT NULL/', $result['table']);
        $this->assertMatchesRegularExpression('/"c_backslash_escape"\s+\S+\s+DEFAULT NULL/', $result['table']);
        $this->assertMatchesRegularExpression('/"c_name"\s+\S+\s+DEFAULT NULL/', $result['table']);
    }

    /**
     * 不含撇號的一般 COMMENT 仍要照常去除。
     */
    public function test_strips_plain_comment(): void {
        $sql = "CREATE TABLE `T` (\n"
            . "  `id` int(11) NOT NULL COMMENT 'plain comment',\n"
            . "  PRIMARY KEY (`id`)\n"
            . ") ENGINE=InnoDB";

        $result = $this->convert($sql, 'T');

        $this->assertStringNotContainsString('COMMENT', $result['table']);
        $this->assertStringNotContainsString('plain comment', $result['table']);
    }
}
