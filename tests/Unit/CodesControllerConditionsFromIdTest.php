<?php

namespace Tests\Unit;

use App\Http\Controllers\CodesController;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 單元測試：CodesController::buildConditionsFromId()。
 *
 * codes 編輯頁的 path id 有兩種來源格式：
 *  - '_._' 分隔（CodesController::buildCompositeId 自己產生的）
 *  - query-string（mutation handler 存進 operations.resource_id 的標準格式，見
 *    CompositePrimaryKey::buildStoredResourceId；操作紀錄的「查閱」連結原樣塞進 path）
 *
 * 這裡直接驗解析函式而非走 HTTP：getKeyColumns() 帶 process 級 static 快取，
 * 同一次 phpunit 程序內先跑過的測試類若建了同名但不同主鍵的表，會讓 HTTP 層的期望隨執行順序改變。
 */
class CodesControllerConditionsFromIdTest extends TestCase {
    private function invoke(array $keyColumns, string $id): array {
        $controller = $this->app->make(CodesController::class);
        $method = new \ReflectionMethod(CodesController::class, 'buildConditionsFromId');
        $method->setAccessible(true);

        return $method->invoke($controller, $keyColumns, $id);
    }

    #[Test]
    public function testQueryStringIdIsParsedByColumnName(): void {
        // 這就是操作紀錄「查閱」連結帶進來的格式（MERGED_PERSON_DATA 的 create 記錄）。
        $this->assertSame(
            ['c_personid' => '108625', 'c_merged_from_personid' => '404794'],
            $this->invoke(
                ['c_personid', 'c_merged_from_personid'],
                'c_personid=108625&c_merged_from_personid=404794'
            )
        );
    }

    #[Test]
    public function testQueryStringIdIgnoresColumnOrderInTheString(): void {
        // 具名比對 → 字串內欄序與資料庫主鍵欄序不必一致（SCHEMAS 與 PK 欄序不保證相同）。
        $this->assertSame(
            ['c_personid' => '108625', 'c_merged_from_personid' => '404794'],
            $this->invoke(
                ['c_personid', 'c_merged_from_personid'],
                'c_merged_from_personid=404794&c_personid=108625'
            )
        );
    }

    #[Test]
    public function testSingleKeyQueryStringIdIsLeftToTheUpstreamNormalizer(): void {
        // 單主鍵刻意不在此解析：連結產生時 normalizeSingleKeyResourceIdForCodeRoute() 已抽成 '464'。
        // 若這裡也接受，值本身以「欄名=」開頭的舊 id 會被改讀成另一個值（見 docblock）。
        $this->assertSame(
            ['c_nianhao_id' => 'c_nianhao_id=464'],
            $this->invoke(['c_nianhao_id'], 'c_nianhao_id=464')
        );
    }

    #[Test]
    public function testSingleKeyValueThatLooksLikeANamedPairIsNotReinterpreted(): void {
        // c_textid 的值字面上就是 'c_textid=a=b' 時，仍查那個字面值，不可改查 'a=b'（會開到別的列）。
        $this->assertSame(
            ['c_textid' => 'c_textid=a=b'],
            $this->invoke(['c_textid'], 'c_textid=a=b')
        );
    }

    #[Test]
    public function testIncompleteQueryStringIdIsNotParsedIntoPartialConditions(): void {
        // 全有全無：殘缺條件會讓 first() 撈到「剛好第一段主鍵相同」的另一列，
        // 把使用者送去編輯錯誤的資料——比查不到更糟。故退回舊解析（整串當第一欄的值 → 查不到）。
        $this->assertSame(
            ['c_personid' => 'c_personid=108625'],
            $this->invoke(['c_personid', 'c_merged_from_personid'], 'c_personid=108625')
        );
    }

    #[Test]
    public function testNullSentinelIsNotParsed(): void {
        // buildStoredResourceId 把 null 編成字面 'NULL'。主鍵欄不可為 null，而 MySQL 拿 'NULL'
        // 比對 int 欄會轉成 0、可能誤中 id 為 0 的列，故不採用具名解析。
        $conditions = $this->invoke(['c_personid', 'c_merged_from_personid'], 'c_personid=108625&c_merged_from_personid=NULL');
        $this->assertSame(['c_personid' => 'c_personid=108625&c_merged_from_personid=NULL'], $conditions);
    }

    #[Test]
    public function testNonScalarValueIsNotParsed(): void {
        // parse_str 會把 c_personid[]=1 變成陣列；拿陣列去組 WHERE 會炸或撈到非預期結果。
        $id = 'c_personid[]=108625&c_merged_from_personid=404794';
        $this->assertSame(
            ['c_personid' => $id],
            $this->invoke(['c_personid', 'c_merged_from_personid'], $id)
        );
    }

    #[Test]
    public function testEmptyValueIsNotParsed(): void {
        // 空字串主鍵值刻意不支援（見 buildNamedConditionsFromId 的 docblock）：
        // 空字串比對 int 欄會被 MySQL 轉成 0，光憑 id 分不出「真的是空字串」還是「缺值」。
        $id = 'c_personid=108625&c_merged_from_personid=';
        $this->assertSame(
            ['c_personid' => $id],
            $this->invoke(['c_personid', 'c_merged_from_personid'], $id)
        );
    }

    #[Test]
    public function testThreeKeyCodeTableIdIsParsed(): void {
        // 同一類故障不只 MERGED_PERSON_DATA：TEXT_INSTANCE_DATA（3-key）也以 query-string 存 resource_id。
        // 欄名取自 config/code_table_mutations.php 的 key_columns（＝資料庫 PRIMARY KEY）。
        $this->assertSame(
            ['c_textid' => '68942', 'c_text_edition_id' => '3', 'c_text_instance_id' => '1'],
            $this->invoke(
                ['c_textid', 'c_text_edition_id', 'c_text_instance_id'],
                'c_textid=68942&c_text_edition_id=3&c_text_instance_id=1'
            )
        );
    }

    #[Test]
    public function testUnknownExtraColumnsAreIgnored(): void {
        // 有些寫入路徑會把整個 payload 當 pk 傳（如 OFFICE_CODE_TYPE_REL 的匯入），
        // 多出來的欄位不該讓解析失敗，也不該進 WHERE。
        $this->assertSame(
            ['c_personid' => '108625', 'c_merged_from_personid' => '404794'],
            $this->invoke(
                ['c_personid', 'c_merged_from_personid'],
                'c_personid=108625&c_merged_from_personid=404794&c_notes=x'
            )
        );
    }

    #[Test]
    public function testLegacyUnderscoreSeparatedIdStillWorks(): void {
        $this->assertSame(
            ['c_personid' => '108625', 'c_merged_from_personid' => '404794'],
            $this->invoke(['c_personid', 'c_merged_from_personid'], '108625_._404794')
        );
    }

    #[Test]
    public function testLegacyIdContainingEqualsSignIsNotMisreadAsQueryString(): void {
        // 欄位值本身含 '=' 的舊格式 id：解析不出主鍵欄名 → 走舊路徑，行為與修改前一致。
        $this->assertSame(
            ['c_textid' => 'a=b', 'c_pages' => '12'],
            $this->invoke(['c_textid', 'c_pages'], 'a=b_._12')
        );
    }

    #[Test]
    public function testPlainSingleValueIdStillWorks(): void {
        $this->assertSame(['c_textid' => '68942'], $this->invoke(['c_textid'], '68942'));
    }
}
