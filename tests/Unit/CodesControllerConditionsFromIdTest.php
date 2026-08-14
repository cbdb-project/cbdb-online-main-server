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
    public function testExtraColumnsMakeTheIdAmbiguousAndAreRejected(): void {
        // 多出來的欄位可能是「值裡的 & 被 URL 解碼後擠出來的假參數」（見下一個測試），
        // 字串層面分不出兩者，故一律不採用具名解析。
        $id = 'c_personid=108625&c_merged_from_personid=404794&c_notes=x';
        $this->assertSame(
            ['c_personid' => $id],
            $this->invoke(['c_personid', 'c_merged_from_personid'], $id)
        );
    }

    #[Test]
    public function testValueContainingAnAmpersandCannotHijackAnotherRow(): void {
        // 這是解碼後的樣子：某列 c_alt_name_chn 的值字面上是 `A&c_alt_name_type_code=99`。
        // 若照收，會被讀成 c_alt_name_chn='A' + type_code=99，於是開到（並存到）別人那一列。
        // 期望：拒絕具名解析 → 退回舊行為（查不到），而不是誤開別列。
        $id = 'c_personid=7&c_alt_name_chn=A&c_alt_name_type_code=99';
        $conditions = $this->invoke(['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'], $id);
        $this->assertSame(['c_personid' => $id], $conditions);
    }

    #[Test]
    public function testNonIntegerValuesAreRejected(): void {
        // 文字主鍵欄不在支援範圍：文字值可能含 '&'、'='、'+'、'%'，解碼後就無法回推原意。
        $id = 'c_textid=68942&c_pages=12-15';
        $this->assertSame(
            ['c_textid' => $id],
            $this->invoke(['c_textid', 'c_pages'], $id)
        );

        // OFFICE_CODE_TYPE_REL 的 c_office_tree_id 是文字代碼樹 id（如 'x01'）→ 同樣維持既有行為。
        // 這條釘住「已知未涵蓋」而非期望行為，改動支援範圍時應一併更新（見函式 docblock）。
        $treeId = 'c_office_id=101&c_office_tree_id=x01';
        $this->assertSame(
            ['c_office_id' => $treeId],
            $this->invoke(['c_office_id', 'c_office_tree_id'], $treeId)
        );
    }

    #[Test]
    public function testInjectedDuplicateKeyCannotOverrideAValue(): void {
        // parse_str 同名參數後者覆蓋前者，所以注入一組「已存在的欄名=數字」不會讓欄位**數**變多，
        // 單靠比對欄位集合抓不到；分隔符數量檢查才擋得住。
        // 這串是某列 c_alt_name_chn 值字面為 `5&c_alt_name_type_code=9` 時解碼後的樣子。
        $id = 'c_personid=7&c_alt_name_chn=5&c_alt_name_type_code=9&c_alt_name_type_code=4';
        $this->assertSame(
            ['c_personid' => $id],
            $this->invoke(['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'], $id)
        );
    }

    #[Test]
    public function testNegativeValuesAreRejected(): void {
        // 不收負號：值裡帶 '-' 會讓 edit() 的「'-' 舊分隔符」相容回退在查不到時再切一次，
        // 切出來的碎片經 MySQL 型別轉換又可能命中別的列。CBDB 的複合主鍵欄實務上沒有負值。
        $id = 'c_personid=-1&c_merged_from_personid=404794';
        $this->assertSame(
            ['c_personid' => $id],
            $this->invoke(['c_personid', 'c_merged_from_personid'], $id)
        );
    }

    #[Test]
    public function testPercentEncodedValueCannotSlipThroughTheDigitCheck(): void {
        // parse_str 會再解一次碼：字面值為 '%39' 的資料存成 '%2539'、route 解碼成 '%39'，
        // 若照收會被 parse_str 解成 '9' → 通過數字檢查卻指向另一列。含 '%' 一律拒絕。
        $id = 'c_personid=108625&c_merged_from_personid=%39';
        $this->assertSame(
            ['c_personid' => $id],
            $this->invoke(['c_personid', 'c_merged_from_personid'], $id)
        );
    }

    #[Test]
    public function testTrailingNewlineIsRejected(): void {
        // '$' 會放過尾端換行，故用 \z 收尾。
        $id = "c_personid=108625\n&c_merged_from_personid=404794";
        $this->assertSame(
            ['c_personid' => $id],
            $this->invoke(['c_personid', 'c_merged_from_personid'], $id)
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
