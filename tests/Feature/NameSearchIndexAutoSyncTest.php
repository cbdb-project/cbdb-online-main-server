<?php

namespace Tests\Feature;

use App\Models\BiogMain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 姓名搜尋索引自動同步測試
 *
 * 測試 CBDB__NAME_FTS 索引表的自動維護。
 *
 * - BiogMain：Eloquent + BiogMainObserver 自動觸發（create／update／delete 三條）
 * - 別名：直接呼叫 NameSearchIndexService::indexAltname()（ALTNAME_DATA 是複合主鍵、走 Query Builder）
 *
 * 原本這個檔還經 `BasicInformationAltnamesController` 驗別名寫入的索引同步，但該 controller 已於
 * Blade 下架環節 2 刪除，對應的測試也隨之移除；別名寫入路徑的索引同步現在由
 * `ApiV2AltnameNameIndexSyncTest`（v2 create／mutate／delete）覆蓋。
 */
class NameSearchIndexAutoSyncTest extends TestCase {
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
        config(['session.driver' => 'array']);

        // 創建測試表結構
        $this->createTestTables();
    }

    protected function createTestTables(): void {
        // BIOG_MAIN 表
        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->string('c_surname')->nullable();
            $table->string('c_mingzi')->nullable();
            $table->timestamps();
        });

        // CBDB__NAME_FTS 表
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

    protected function tearDown(): void {
        Schema::dropIfExists('CBDB__NAME_FTS');
        Schema::dropIfExists('BIOG_MAIN');
        parent::tearDown();
    }

    // ===== BiogMain 測試 =====

    #[Test]
    public function test_creating_person_automatically_creates_index(): void {
        $person = BiogMain::create([
            'c_personid' => 1001,
            'c_name_chn' => '蘇軾',
            'c_surname' => '蘇',
            'c_mingzi' => '軾',
        ]);

        // 檢查索引是否自動創建
        $indexCount = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 1001)
            ->whereNull('name_type_code')
            ->count();

        $this->assertGreaterThan(0, $indexCount, '新增人物應該自動創建索引');

        // 檢查是否包含預期的後綴
        $searchTerms = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 1001)
            ->whereNull('name_type_code')
            ->pluck('search_term')
            ->toArray();

        $this->assertContains('蘇軾', $searchTerms, '應包含完整名稱');
        $this->assertContains('軾', $searchTerms, '應包含末字');
    }

    #[Test]
    public function test_updating_person_name_reindexes(): void {
        $person = BiogMain::create([
            'c_personid' => 1002,
            'c_name_chn' => '蘇轍',
            'c_surname' => '蘇',
            'c_mingzi' => '轍',
        ]);

        // 修改姓名
        $person->c_name_chn = '蘇子由';
        $person->save();

        // 檢查舊索引已刪除
        $oldTermExists = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 1002)
            ->where('search_term', '轍')
            ->exists();

        $this->assertFalse($oldTermExists, '舊索引應該被刪除');

        // 檢查新索引已創建
        $newTermExists = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 1002)
            ->where('search_term', '由')
            ->exists();

        $this->assertTrue($newTermExists, '新索引應該被創建');
    }

    #[Test]
    public function test_updating_person_non_name_fields_does_not_reindex(): void {
        $person = BiogMain::create([
            'c_personid' => 1003,
            'c_name_chn' => '王安石',
        ]);

        $initialCount = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 1003)
            ->count();

        // 修改非姓名欄位
        $person->c_name = 'Wang Anshi';
        $person->save();

        $afterCount = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 1003)
            ->count();

        $this->assertEquals($initialCount, $afterCount, '修改非姓名欄位不應該觸發重新索引');
    }

    #[Test]
    public function test_deleting_person_removes_all_indexes(): void {
        $person = BiogMain::create([
            'c_personid' => 1004,
            'c_name_chn' => '李白',
        ]);

        // 確認索引已創建
        $this->assertTrue(
            DB::table('CBDB__NAME_FTS')->where('c_personid', 1004)->exists(),
            '索引應該已創建'
        );

        // 刪除人物
        $person->delete();

        // 檢查所有索引已刪除
        $indexExists = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 1004)
            ->exists();

        $this->assertFalse($indexExists, '刪除人物應該移除所有索引');
    }

    // ===== 括號處理測試 =====

    #[Test]
    public function test_person_with_parentheses_creates_correct_index(): void {
        $person = BiogMain::create([
            'c_personid' => 3001,
            'c_name_chn' => '宗氏（李白妻）',
        ]);

        // 檢查括號已移除但內容保留
        $searchTerms = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 3001)
            ->pluck('search_term')
            ->toArray();

        $this->assertContains('宗氏李白妻', $searchTerms, '應包含移除括號後的完整名稱');
        $this->assertContains('李白妻', $searchTerms, '應包含括號內容的後綴');
        $this->assertContains('白妻', $searchTerms, '應包含括號內容的後綴');
    }

    #[Test]
    public function test_person_with_spaced_parentheses_creates_space_free_index(): void {
        // 使用者輸入帶空白的半角括號（例："李白 (青蓮)"）。
        // BiogMain::create 走 Eloquent + Observer → NameSearchIndexService，
        // 索引應與「李白(青蓮)」一致：移除括號與空白後為「李白青蓮」。
        $person = BiogMain::create([
            'c_personid' => 3002,
            'c_name_chn' => '李白 (青蓮)',
        ]);

        $searchTerms = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 3002)
            ->pluck('search_term')
            ->toArray();

        $this->assertContains('李白青蓮', $searchTerms, '應包含移除括號與空白後的完整名稱');
        $this->assertContains('青蓮', $searchTerms, '應包含括號內容的後綴');

        foreach ($searchTerms as $term) {
            $this->assertStringNotContainsString(' ', $term, '搜尋詞不應殘留半形空白');
            $this->assertStringNotContainsString("\u{3000}", $term, '搜尋詞不應殘留全形空白');
        }
    }

    #[Test]
    public function test_altname_with_spaced_parentheses_creates_space_free_index(): void {
        // 別名路徑（indexAltname → normalizeName）：帶空白的半角括號輸入
        // 應與「太白(青蓮)」一致，索引移除括號與空白後為「太白青蓮」。
        BiogMain::create([
            'c_personid' => 3003,
            'c_name_chn' => '李白',
        ]);

        app(\App\Services\NameSearchIndexService::class)
            ->indexAltname(3003, 4, '太白 (青蓮)');

        $searchTerms = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 3003)
            ->where('name_type_code', 4)
            ->pluck('search_term')
            ->toArray();

        $this->assertContains('太白青蓮', $searchTerms, '別名索引應移除括號與空白後的完整名稱');
        $this->assertContains('青蓮', $searchTerms, '別名索引應包含括號內容的後綴');

        foreach ($searchTerms as $term) {
            $this->assertStringNotContainsString(' ', $term, '別名搜尋詞不應殘留半形空白');
            $this->assertStringNotContainsString("\u{3000}", $term, '別名搜尋詞不應殘留全形空白');
        }
    }

    #[Test]
    public function test_index_table_does_not_exist_gracefully_handles(): void {
        // 刪除索引表
        Schema::dropIfExists('CBDB__NAME_FTS');

        // 創建人物不應該報錯
        $person = BiogMain::create([
            'c_personid' => 9001,
            'c_name_chn' => '測試人物',
        ]);

        $this->assertNotNull($person, '即使索引表不存在，創建人物也應該成功');
    }
}
