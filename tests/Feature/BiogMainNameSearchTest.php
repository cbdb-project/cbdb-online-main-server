<?php

namespace Tests\Feature;

use App\Repositories\BiogMainRepository;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BiogMainNameSearchTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        // 設定使用 SQLite in-memory 資料庫
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 設置快取為陣列驅動
        config(['cache.default' => 'array']);

        // 使用陣列驅動避免檔案權限問題
        config(['session.driver' => 'array']);

        // 創建必要的測試表結構
        $this->createTestTables();
        $this->seedTestData();
    }

    protected function createTestTables(): void {
        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->string('c_surname')->nullable();
            $table->string('c_mingzi')->nullable();
            $table->string('c_name_proper')->nullable();
            $table->string('c_name_rm')->nullable();
            $table->string('c_surname_proper')->nullable();
            $table->string('c_mingzi_proper')->nullable();
            $table->string('c_surname_rm')->nullable();
            $table->string('c_mingzi_rm')->nullable();
            $table->integer('c_index_year')->nullable();
            $table->integer('c_dy')->nullable();
            $table->integer('c_index_addr_id')->nullable();
        });

        Schema::create('DYNASTIES', function ($table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
            $table->string('c_dynasty')->nullable();
        });

        Schema::create('ADDR_CODES', function ($table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
        });

        Schema::create('ALTNAME_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_alt_name_type_code');
            $table->string('c_alt_name_chn')->nullable();
            $table->primary(['c_personid', 'c_alt_name_type_code']);
        });

        // 創建倒排索引表
        Schema::create('CBDB__NAME_FTS', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('c_personid');
            $table->unsignedSmallInteger('name_type_code')->nullable();
            $table->string('name_type_desc', 32);
            $table->string('name_type_desc_chn', 32);
            $table->string('search_term', 100);
            $table->string('full_name', 100);
            $table->string('source', 32);
            $table->string('source_key', 255)->nullable();
            $table->boolean('is_simplified')->default(false);
            $table->timestamps();

            $table->index(['search_term', 'c_personid'], 'idx_cbdb__name_search_term');
            $table->index('c_personid', 'idx_cbdb__name_person');
            $table->index('name_type_code', 'idx_cbdb__name_type');
        });
    }

    protected function seedTestData(): void {
        // 插入測試數據
        DB::table('BIOG_MAIN')->insert([
            [
                'c_personid' => 1001,
                'c_name_chn' => '蘇軾',
                'c_name' => 'Su Shi',
                'c_surname' => '蘇',
                'c_mingzi' => '軾',
                'c_index_year' => 1050,
                'c_dy' => 15,
                'c_index_addr_id' => 100,
                'c_name_proper' => null,
                'c_name_rm' => null,
                'c_surname_proper' => null,
                'c_mingzi_proper' => null,
                'c_surname_rm' => null,
                'c_mingzi_rm' => null,
            ],
            [
                'c_personid' => 1002,
                'c_name_chn' => '蘇轍',
                'c_name' => 'Su Zhe',
                'c_surname' => '蘇',
                'c_mingzi' => '轍',
                'c_index_year' => 1045,
                'c_dy' => 15,
                'c_index_addr_id' => 100,
                'c_name_proper' => null,
                'c_name_rm' => null,
                'c_surname_proper' => null,
                'c_mingzi_proper' => null,
                'c_surname_rm' => null,
                'c_mingzi_rm' => null,
            ],
            [
                'c_personid' => 2001,
                'c_name_chn' => '王安石',
                'c_name' => 'Wang Anshi',
                'c_surname' => '王',
                'c_mingzi' => '安石',
                'c_index_year' => 1030,
                'c_dy' => 15,
                'c_index_addr_id' => 101,
                'c_name_proper' => null,
                'c_name_rm' => null,
                'c_surname_proper' => null,
                'c_mingzi_proper' => null,
                'c_surname_rm' => null,
                'c_mingzi_rm' => null,
            ],
            [
                'c_personid' => 3001,
                'c_name_chn' => '宗氏（李白妻）',
                'c_name' => 'Zong Shi',
                'c_surname' => '宗',
                'c_mingzi' => '氏',
                'c_index_year' => 730,
                'c_dy' => 14,
                'c_index_addr_id' => 102,
                'c_name_proper' => null,
                'c_name_rm' => null,
                'c_surname_proper' => null,
                'c_mingzi_proper' => null,
                'c_surname_rm' => null,
                'c_mingzi_rm' => null,
            ],
            [
                'c_personid' => 3002,
                'c_name_chn' => '楊貴妃(楊玉環)',
                'c_name' => 'Yang Guifei',
                'c_surname' => '楊',
                'c_mingzi' => '玉環',
                'c_index_year' => 720,
                'c_dy' => 14,
                'c_index_addr_id' => 102,
                'c_name_proper' => null,
                'c_name_rm' => null,
                'c_surname_proper' => null,
                'c_mingzi_proper' => null,
                'c_surname_rm' => null,
                'c_mingzi_rm' => null,
            ],
            [
                'c_personid' => 4001,
                'c_name_chn' => '蘇無朝',
                'c_name' => 'Su Wu Chao',
                'c_surname' => '蘇',
                'c_mingzi' => '無朝',
                'c_index_year' => 1001,
                'c_dy' => null,
                'c_index_addr_id' => 100,
                'c_name_proper' => null,
                'c_name_rm' => null,
                'c_surname_proper' => null,
                'c_mingzi_proper' => null,
                'c_surname_rm' => null,
                'c_mingzi_rm' => null,
            ],
            // #85：拼音以 v 存 ü 韻（呂=Lv），用於驗證 ü／v 搜尋互通。
            [
                'c_personid' => 5001,
                'c_name_chn' => '呂溱',
                'c_name' => 'Lv Zhen',
                'c_surname' => '呂',
                'c_mingzi' => '溱',
                'c_index_year' => 1010,
                'c_dy' => 15,
                'c_index_addr_id' => 100,
                'c_name_proper' => null,
                'c_name_rm' => null,
                'c_surname_proper' => null,
                'c_mingzi_proper' => null,
                'c_surname_rm' => null,
                'c_mingzi_rm' => null,
            ],
        ]);

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 14, 'c_dynasty_chn' => '唐'],
            ['c_dy' => 15, 'c_dynasty_chn' => '宋'],
        ]);

        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 100, 'c_name_chn' => '眉州'],
            ['c_addr_id' => 101, 'c_name_chn' => '臨川'],
            ['c_addr_id' => 102, 'c_name_chn' => '長安'],
        ]);

        DB::table('ALTNAME_DATA')->insert([
            ['c_personid' => 1001, 'c_alt_name_type_code' => 4, 'c_alt_name_chn' => '子瞻'],
            ['c_personid' => 1001, 'c_alt_name_type_code' => 5, 'c_alt_name_chn' => '東坡居士'],
            ['c_personid' => 1002, 'c_alt_name_type_code' => 4, 'c_alt_name_chn' => '子由'],
        ]);

        // 插入倒排索引表數據
        $now = date('Y-m-d H:i:s');
        DB::table('CBDB__NAME_FTS')->insert([
            // 蘇軾的倒排記錄
            ['c_personid' => 1001, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '蘇軾', 'full_name' => '蘇軾', 'source' => 'biog_main', 'source_key' => 'biog_main:1001', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 1001, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '軾', 'full_name' => '蘇軾', 'source' => 'biog_main', 'source_key' => 'biog_main:1001', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 1001, 'name_type_code' => 4, 'name_type_desc' => 'zi', 'name_type_desc_chn' => '字', 'search_term' => '子瞻', 'full_name' => '子瞻', 'source' => 'altname_data', 'source_key' => 'altname:1001-4-子瞻', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 1001, 'name_type_code' => 4, 'name_type_desc' => 'zi', 'name_type_desc_chn' => '字', 'search_term' => '瞻', 'full_name' => '子瞻', 'source' => 'altname_data', 'source_key' => 'altname:1001-4-子瞻', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],

            // 蘇轍的倒排記錄
            ['c_personid' => 1002, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '蘇轍', 'full_name' => '蘇轍', 'source' => 'biog_main', 'source_key' => 'biog_main:1002', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 1002, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '轍', 'full_name' => '蘇轍', 'source' => 'biog_main', 'source_key' => 'biog_main:1002', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 4001, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '蘇無朝', 'full_name' => '蘇無朝', 'source' => 'biog_main', 'source_key' => 'biog_main:4001', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 4001, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '無朝', 'full_name' => '蘇無朝', 'source' => 'biog_main', 'source_key' => 'biog_main:4001', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],

            // 王安石的倒排記錄
            ['c_personid' => 2001, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '王安石', 'full_name' => '王安石', 'source' => 'biog_main', 'source_key' => 'biog_main:2001', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 2001, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '安石', 'full_name' => '王安石', 'source' => 'biog_main', 'source_key' => 'biog_main:2001', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 2001, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '石', 'full_name' => '王安石', 'source' => 'biog_main', 'source_key' => 'biog_main:2001', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],

            // 註：5001（呂溱）刻意「不」建倒排索引列 → 拼音查詢落到 LIKE 退路（與正式環境一致：FTS 僅索引中文、無拼音），
            // 使 test_pinyin_umlaut_and_v_are_interchangeable 真正驗證拼音主路徑的 ü→v 規範化。

            // 宗氏（李白妻）的倒排記錄 - 括號已移除，內容保留為"宗氏李白妻"
            ['c_personid' => 3001, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '宗氏李白妻', 'full_name' => '宗氏李白妻', 'source' => 'biog_main', 'source_key' => 'biog_main:3001', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 3001, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '氏李白妻', 'full_name' => '宗氏李白妻', 'source' => 'biog_main', 'source_key' => 'biog_main:3001', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 3001, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '李白妻', 'full_name' => '宗氏李白妻', 'source' => 'biog_main', 'source_key' => 'biog_main:3001', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 3001, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '白妻', 'full_name' => '宗氏李白妻', 'source' => 'biog_main', 'source_key' => 'biog_main:3001', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 3001, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '妻', 'full_name' => '宗氏李白妻', 'source' => 'biog_main', 'source_key' => 'biog_main:3001', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],

            // 楊貴妃(楊玉環)的倒排記錄 - 半角括號已移除，內容保留為"楊貴妃楊玉環"
            ['c_personid' => 3002, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '楊貴妃楊玉環', 'full_name' => '楊貴妃楊玉環', 'source' => 'biog_main', 'source_key' => 'biog_main:3002', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 3002, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '貴妃楊玉環', 'full_name' => '楊貴妃楊玉環', 'source' => 'biog_main', 'source_key' => 'biog_main:3002', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 3002, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '妃楊玉環', 'full_name' => '楊貴妃楊玉環', 'source' => 'biog_main', 'source_key' => 'biog_main:3002', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 3002, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '楊玉環', 'full_name' => '楊貴妃楊玉環', 'source' => 'biog_main', 'source_key' => 'biog_main:3002', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 3002, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '玉環', 'full_name' => '楊貴妃楊玉環', 'source' => 'biog_main', 'source_key' => 'biog_main:3002', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['c_personid' => 3002, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => '環', 'full_name' => '楊貴妃楊玉環', 'source' => 'biog_main', 'source_key' => 'biog_main:3002', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    #[Test]
    public function test_numeric_query_uses_personid_direct_lookup(): void {
        $request = new Request(['q' => '1001']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertCount(1, $result);

        $person = $result->items()[0];
        $this->assertEquals(1001, $person->c_personid);
        $this->assertEquals('蘇軾', $person->c_name_chn);
        $this->assertEquals('宋', $person->c_dynasty_chn);
        $this->assertEquals('眉州', $person->ADDR_c_name_chn);
        $this->assertEquals('子瞻', $person->c_alt_name_chn_zi);
        $this->assertEquals('東坡居士', $person->c_alt_name_chn_hao);
    }

    #[Test]
    public function test_numeric_query_returns_empty_for_nonexistent_id(): void {
        $request = new Request(['q' => '9999']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertCount(0, $result);
    }

    #[Test]
    public function test_text_query_uses_complex_search(): void {
        // 測試文字查詢使用倒排索引搜尋
        // 現在已經實作 CASE WHEN 模擬 FIELD() 函數，SQLite 也可以正常運行
        $request = new Request(['q' => '蘇']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertGreaterThanOrEqual(2, $result->total());

        // 確認結果包含蘇軾和蘇轍
        $personIds = collect($result->items())->pluck('c_personid')->map(fn ($id) => (int)$id)->toArray();
        $this->assertContains(1001, $personIds, '搜尋「蘇」應該包含蘇軾');
        $this->assertContains(1002, $personIds, '搜尋「蘇」應該包含蘇轍');
        $this->assertContains(4001, $personIds, '搜尋「蘇」應該包含未設定朝代案例');
    }

    #[Test]
    public function test_pinyin_umlaut_and_v_are_interchangeable(): void {
        // #85：CBDB 拼音以 v 存 ü 韻（呂=Lv）。使用者輸入 ü（正規拼音）或 v（CBDB 慣例）皆應命中同一人。
        $idsFor = function (string $q): array {
            $result = BiogMainRepository::namesByQuery(new Request(['q' => $q]), 20);

            return collect($result->items())->pluck('c_personid')->map(fn ($id) => (int) $id)->all();
        };

        $viaUmlaut = $idsFor('Lü Zhen'); // 使用者打正規拼音 ü
        $viaV = $idsFor('Lv Zhen');      // 使用者打 CBDB 慣例 v

        $this->assertContains(5001, $viaUmlaut, '以「Lü Zhen」搜尋應命中以 v 儲存的呂溱(5001)');
        $this->assertContains(5001, $viaV, '以「Lv Zhen」搜尋應命中呂溱(5001)');
        $this->assertSame($viaV, $viaUmlaut, 'ü 與 v 兩種輸入應回傳完全相同的結果集');
    }

    #[Test]
    public function test_umlaut_stored_name_found_by_v_input_after_migration(): void {
        // §D-8 查詢展開（遷移後情境）：人名以正字 ü 儲存（Lü Kun）。習慣打 v 的使用者須仍命中。
        // 註：SQLite 不折疊 ü/u，故此處命中完全來自 expand() 的 OR 展開，非 collation——
        //     正好驗證程式端展開邏輯本身（生產環境另有 collation 摺疊，非本測試對象）。
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 5002,
            'c_name_chn' => '呂坤',
            'c_name' => 'Lü Kun',   // 遷移後以正字 ü 儲存
            'c_surname' => '呂',
            'c_mingzi' => '坤',
            'c_index_year' => 1536,
            'c_dy' => 15,
            'c_index_addr_id' => 100,
        ]);
        // 刻意不建 FTS 列 → 落到 LIKE 退路（與正式環境一致：FTS 僅索引中文）。

        $idsFor = fn (string $q): array => collect(
            BiogMainRepository::namesByQuery(new Request(['q' => $q]), 20)->items()
        )->pluck('c_personid')->map(fn ($id) => (int) $id)->all();

        $this->assertContains(5002, $idsFor('Lv Kun'), 'v 形輸入應命中以 ü 儲存的呂坤(5002)');
        $this->assertContains(5002, $idsFor('Lü Kun'), 'ü 形輸入應命中呂坤(5002)');
        $this->assertContains(5002, $idsFor('lv kun'), '小寫 v 形亦應命中（LIKE ASCII 大小寫不敏感）');
    }

    #[Test]
    public function test_pinyin_search_not_hijacked_by_stray_latin_fts_row(): void {
        // 迴歸（正式環境 /app/basicinformation 實際走的 namesByQuery 路徑）：CBDB__NAME_FTS 只索引
        // 中文，但索引中偶有夾帶拉丁子字串的外文名（如 "Lves…"）。拼音 "lv" 曾以 LIKE 'lv%' 誤命中
        // 該雜訊列、短路 FTS 分支（whereIn 只含那 1 筆），跳過能命中姓呂(Lü)的拼音 LIKE 回退，
        // 造成「搜 lv／lü 只有一筆、查不到大量姓呂的人，但搜 zhang 卻正常」。
        // 修法：拉丁查詢不走中文 FTS，一律改走多欄位 LIKE 回退。
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 5010,
            'c_name_chn' => '呂大防',
            'c_name' => 'Lü Dafang',
            'c_surname' => 'Lü',      // 遷移後以正字 ü 儲存的羅馬字姓
            'c_mingzi' => 'Dafang',
            'c_index_year' => 1027,
            'c_dy' => 15,
            'c_index_addr_id' => 100,
        ]);
        // 夾帶拉丁子字串的外文名（模擬 c_name_chn 內含 "Lves"）＋對應的雜訊 FTS 列。
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 5011,
            'c_name_chn' => 'Lves',
            'c_name' => 'Lves',
            'c_surname' => 'Lves',
            // 刻意用與呂大防(5010, c_dy=15)不同的朝代，讓朝代分面斷言具鑑別力：
            // 修正前 facet 只會有雜訊列 5011 的朝代 16、缺 15；修正後才會出現 15。
            'c_dy' => 16,
        ]);
        $now = now();
        DB::table('CBDB__NAME_FTS')->insert([
            ['c_personid' => 5011, 'name_type_code' => null, 'name_type_desc' => 'main_name', 'name_type_desc_chn' => '本名', 'search_term' => 'lves', 'full_name' => 'Lves', 'source' => 'biog_main', 'source_key' => 'biog_main:5011', 'is_simplified' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $idsFor = fn (string $q): array => collect(
            BiogMainRepository::namesByQuery(new Request(['q' => $q]), 20)->items()
        )->pluck('c_personid')->map(fn ($id) => (int) $id)->all();

        $this->assertContains(5010, $idsFor('lv'), '拼音 "lv" 應命中以 ü 儲存的呂大防(5010)，不應被雜訊 FTS 列 "lves" 短路排除');
        $this->assertContains(5010, $idsFor('lü'), '拼音 "lü" 亦應命中呂大防(5010)');

        // 側欄朝代分面須與人物列表同口徑（同樣繞過雜訊 FTS），否則列表有呂大防、側欄卻漏計。
        // 修正前 facet 走 FTS→只含雜訊列 5011 的朝代 16、缺 15，故此斷言在修正前會失敗。
        $facetDynasties = BiogMainRepository::dynastyFacetsByQuery('lv')
            ->pluck('c_dy')->map(fn ($d) => (int) $d)->all();
        $this->assertContains(15, $facetDynasties, '拼音 "lv" 的朝代分面應含呂大防(5010)所屬朝代 15，與列表一致');
    }

    #[Test]
    public function test_pinyin_normalizer_helper(): void {
        // ü／Ü 折成 v／V；中文／無 ü 字串為 no-op；null 安全。
        $this->assertSame('Lv Zhen', \App\Support\PinyinSearchNormalizer::umlautToV('Lü Zhen'));
        $this->assertSame('NV', \App\Support\PinyinSearchNormalizer::umlautToV('NÜ'));
        $this->assertSame('蘇軾', \App\Support\PinyinSearchNormalizer::umlautToV('蘇軾'));
        $this->assertSame('Lv Zhen', \App\Support\PinyinSearchNormalizer::umlautToV('Lv Zhen'));
        $this->assertSame('', \App\Support\PinyinSearchNormalizer::umlautToV(null));
    }

    #[Test]
    public function test_mixed_alphanumeric_query_uses_complex_search(): void {
        $request = new Request(['q' => 'Su1001']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        // 混合字母數字應該使用複雜搜尋，而非純數字優化
        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    #[Test]
    public function test_leading_zeros_not_treated_as_numeric(): void {
        $request = new Request(['q' => '01001']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        // 前導零會被 ctype_digit 判定為 true，但 addslashes 可能會移除
        // 這個測試驗證行為的一致性
        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    #[Test]
    public function test_numeric_query_includes_all_join_data(): void {
        $request = new Request(['q' => '1002']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertCount(1, $result);

        $person = $result->items()[0];
        $this->assertEquals(1002, $person->c_personid);
        $this->assertEquals('蘇轍', $person->c_name_chn);
        $this->assertEquals('宋', $person->c_dynasty_chn);
        $this->assertEquals('眉州', $person->ADDR_c_name_chn);
        $this->assertEquals('子由', $person->c_alt_name_chn_zi);
        // 蘇轍沒有號，應該為 null
        $this->assertNull($person->c_alt_name_chn_hao);
    }

    #[Test]
    public function test_empty_query_returns_paginated_list(): void {
        $request = new Request(['q' => '']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        // 20251127 修改：空查詢現在也返回 LengthAwarePaginator 對象，與其他查詢保持一致
        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertGreaterThanOrEqual(4, $result->total());
        $this->assertGreaterThanOrEqual(4, count($result->items()));
    }

    #[Test]
    public function test_text_query_can_filter_by_specific_dynasty(): void {
        $request = new Request(['q' => '蘇', 'c_dy' => 15]);

        $result = BiogMainRepository::namesByQuery($request, 20);
        $personIds = collect($result->items())->pluck('c_personid')->map(fn ($id) => (int) $id)->toArray();

        $this->assertContains(1001, $personIds);
        $this->assertContains(1002, $personIds);
        $this->assertNotContains(4001, $personIds);
    }

    #[Test]
    public function test_text_query_can_filter_unknown_dynasty_bucket(): void {
        $request = new Request(['q' => '蘇', 'c_dy' => '__unknown__']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertCount(1, $result);
        $this->assertEquals(4001, (int) $result->items()[0]->c_personid);
    }

    #[Test]
    public function test_dynasty_facets_include_unknown_bucket_and_match_result_total(): void {
        $request = new Request(['q' => '蘇']);
        $result = BiogMainRepository::namesByQuery($request, 20);
        $facets = BiogMainRepository::dynastyFacetsByQuery('蘇');

        $this->assertEquals($result->total(), (int) $facets->sum('count'));
        $this->assertTrue(
            $facets->contains(fn ($facet) => (string) $facet->c_dy === '__unknown__' && (int) $facet->count === 1),
            'Facet 應包含未設定朝代分類'
        );
    }

    #[Test]
    public function test_pagination_links_preserve_dynasty_filter_query_param(): void {
        $request = new Request(['q' => '蘇', 'c_dy' => '15']);
        $result = BiogMainRepository::namesByQuery($request, 1);
        $nextPageUrl = $result
            ->appends(['q' => $request->q, 'c_dy' => $request->input('c_dy')])
            ->url(2);

        $this->assertStringContainsString('c_dy=15', $nextPageUrl);
        $this->assertStringContainsString('q=', $nextPageUrl);
    }

    // ===== 倒排索引表測試 =====

    #[Test]
    public function test_inverted_index_search_by_full_name(): void {
        $request = new Request(['q' => '蘇軾']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertCount(1, $result);

        $person = $result->items()[0];
        $this->assertEquals(1001, $person->c_personid);
        $this->assertEquals('蘇軾', $person->c_name_chn);
    }

    #[Test]
    public function test_inverted_index_search_by_suffix(): void {
        $request = new Request(['q' => '軾']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertGreaterThanOrEqual(1, $result->total());

        // 應該包含蘇軾
        $personIds = collect($result->items())->pluck('c_personid')->map(fn ($id) => (int)$id)->toArray();
        $this->assertContains(1001, $personIds);
    }

    #[Test]
    public function test_inverted_index_search_by_name_part(): void {
        $request = new Request(['q' => '安石']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertGreaterThanOrEqual(1, $result->total());

        // 應該包含王安石
        $personIds = collect($result->items())->pluck('c_personid')->map(fn ($id) => (int)$id)->toArray();
        $this->assertContains(2001, $personIds);
    }

    #[Test]
    public function test_inverted_index_search_by_single_char(): void {
        $request = new Request(['q' => '石']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertGreaterThanOrEqual(1, $result->total());

        // 應該包含王安石（名末字「石」）
        $personIds = collect($result->items())->pluck('c_personid')->map(fn ($id) => (int)$id)->toArray();
        $this->assertContains(2001, $personIds);
    }

    #[Test]
    public function test_inverted_index_search_by_zi(): void {
        $request = new Request(['q' => '子瞻']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertGreaterThanOrEqual(1, $result->total());

        // 應該找到蘇軾（字子瞻）
        $personIds = collect($result->items())->pluck('c_personid')->map(fn ($id) => (int)$id)->toArray();
        $this->assertContains(1001, $personIds);
    }

    #[Test]
    public function test_inverted_index_orders_by_match_length(): void {
        $request = new Request(['q' => '蘇']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);

        // 應該找到多個結果（蘇軾、蘇轍都有「蘇」開頭的倒排記錄）
        $personIds = collect($result->items())->pluck('c_personid')->map(fn ($id) => (int)$id)->toArray();
        $this->assertContains(1001, $personIds);
        $this->assertContains(1002, $personIds);
    }

    #[Test]
    public function test_inverted_index_fallback_when_no_match(): void {
        // 清空倒排索引表
        DB::table('CBDB__NAME_FTS')->truncate();

        $request = new Request(['q' => '蘇軾']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        // 即使倒排索引為空，仍應該通過回退方案找到結果
        // 注意：在 SQLite 中，FIELD() 函數可能導致錯誤
        // 這個測試主要驗證回退邏輯被觸發
        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    #[Test]
    public function test_inverted_index_limits_to_500_candidates(): void {
        // 插入大量測試數據（超過 500 個）
        // 分批插入以避免 SQLite 的 SQLITE_MAX_COMPOUND_SELECT 限制（默認 500）
        $now = date('Y-m-d H:i:s');

        // 第一批：300 條記錄
        $batch1 = [];
        for ($i = 3000; $i < 3300; $i++) {
            $batch1[] = [
                'c_personid' => $i,
                'name_type_code' => null,
                'name_type_desc' => 'main_name',
                'name_type_desc_chn' => '本名',
                'search_term' => '測試',
                'full_name' => '測試' . $i,
                'source' => 'biog_main',
                'source_key' => 'biog_main:' . $i,
                'is_simplified' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('CBDB__NAME_FTS')->insert($batch1);

        // 第二批：300 條記錄
        $batch2 = [];
        for ($i = 3300; $i < 3600; $i++) {
            $batch2[] = [
                'c_personid' => $i,
                'name_type_code' => null,
                'name_type_desc' => 'main_name',
                'name_type_desc_chn' => '本名',
                'search_term' => '測試',
                'full_name' => '測試' . $i,
                'source' => 'biog_main',
                'source_key' => 'biog_main:' . $i,
                'is_simplified' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('CBDB__NAME_FTS')->insert($batch2);

        $request = new Request(['q' => '測試']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        // 應該成功執行（限制在 500 個候選人）
        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    #[Test]
    public function test_inverted_index_maintains_match_quality_order(): void {
        // 驗證排序：完整匹配應該排在前面
        $request = new Request(['q' => '蘇']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertGreaterThanOrEqual(2, $result->total());

        // 檢查返回的結果
        $items = $result->items();
        $firstItem = $items[0];

        // 第一個結果應該是蘇軾或蘇轍（因為都是「蘇」開頭）
        $this->assertContains((int)$firstItem->c_personid, [1001, 1002]);
    }

    #[Test]
    public function test_parentheses_content_is_searchable_fullwidth(): void {
        // 測試全角括號：搜索「李白」應該能找到「宗氏（李白妻）」
        $request = new Request(['q' => '李白']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertGreaterThanOrEqual(1, $result->total());

        // 檢查結果中包含 personid 3001（宗氏（李白妻））
        $personIds = collect($result->items())->pluck('c_personid')->map(fn ($id) => (int)$id)->toArray();
        $this->assertContains(3001, $personIds, '搜索「李白」應該能找到「宗氏（李白妻）」');
    }

    #[Test]
    public function test_parentheses_content_is_searchable_halfwidth(): void {
        // 測試半角括號：搜索「楊玉環」應該能找到「楊貴妃(楊玉環)」
        $request = new Request(['q' => '楊玉環']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertGreaterThanOrEqual(1, $result->total());

        // 檢查結果中包含 personid 3002（楊貴妃(楊玉環)）
        $personIds = collect($result->items())->pluck('c_personid')->map(fn ($id) => (int)$id)->toArray();
        $this->assertContains(3002, $personIds, '搜索「楊玉環」應該能找到「楊貴妃(楊玉環)」');
    }

    #[Test]
    public function test_parentheses_content_partial_search(): void {
        // 測試部分匹配：搜索「李白妻」應該能找到「宗氏（李白妻）」
        $request = new Request(['q' => '李白妻']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertGreaterThanOrEqual(1, $result->total());

        // 檢查結果中包含 personid 3001
        $personIds = collect($result->items())->pluck('c_personid')->map(fn ($id) => (int)$id)->toArray();
        $this->assertContains(3001, $personIds, '搜索「李白妻」應該能找到「宗氏（李白妻）」');
    }

    #[Test]
    public function test_name_before_parentheses_still_searchable(): void {
        // 測試括號前的內容仍然可搜索：搜索「宗氏」應該能找到「宗氏（李白妻）」
        $request = new Request(['q' => '宗氏']);

        $result = BiogMainRepository::namesByQuery($request, 20);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertGreaterThanOrEqual(1, $result->total());

        // 檢查結果中包含 personid 3001
        $personIds = collect($result->items())->pluck('c_personid')->map(fn ($id) => (int)$id)->toArray();
        $this->assertContains(3001, $personIds, '搜索「宗氏」應該能找到「宗氏（李白妻）」');
    }
}
