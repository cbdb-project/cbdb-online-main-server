<?php

namespace Tests\Feature;

use App\Models\BiogMain;
use App\Models\User;
use App\Services\CharVariantMapService;
use App\Support\CompositePrimaryKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 姓名搜尋索引自動同步測試
 *
 * 測試 BiogMain 使用 Observer，以及 ALTNAME_DATA 透過實際 Controller + 服務的流程，是否能正確維護 CBDB__NAME_FTS 索引表。
 *
 * - BiogMain：使用 Eloquent + Observer 自動觸發
 * - ALTNAME_DATA：使用 BasicInformationAltnamesController + NameSearchIndexService（因復合主鍵改用 Query Builder）
 */
class NameSearchIndexAutoSyncTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->useLegacyPersonForms(); // 本類測 legacy Blade CRUD 行為，撥回 flag=old 越過下架閘門

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
        // users 表（供 actingAs 與權限判斷）
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->json('settings')->nullable();
            $table->string('avatar')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        // operations 表（BasicInformationAltnamesController 會寫入）
        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid');
            $table->integer('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });

        // audit_log 表（供審計記錄寫入）
        Schema::create('audit_log', function ($table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->char('operation_id', 26);
            $table->json('row_pk');
            $table->string('row_pk_text', 512);
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
        });

        // BIOG_MAIN 表
        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->string('c_surname')->nullable();
            $table->string('c_mingzi')->nullable();
            $table->timestamps();
        });

        // ALTNAME_DATA 表（使用復合主鍵，不使用 Eloquent）
        Schema::create('ALTNAME_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->nullable();
            $table->integer('c_alt_name_type_code');
            $table->string('c_alt_name_chn')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();

            // 實際資料庫使用復合主鍵，但 SQLite 測試環境簡化處理
            $table->index(['c_personid', 'c_sequence', 'c_alt_name_chn', 'c_alt_name_type_code'], 'idx_altname_pk');
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

        // ALTNAME_CODES 表（用於別名類型）
        Schema::create('ALTNAME_CODES', function ($table) {
            $table->integer('c_name_type_code')->primary();
            $table->string('c_name_type_desc')->nullable();
            $table->string('c_name_type_desc_chn')->nullable();
        });

        // 插入測試用別名類型
        DB::table('ALTNAME_CODES')->insert([
            ['c_name_type_code' => 4, 'c_name_type_desc' => 'zi', 'c_name_type_desc_chn' => '字'],
            ['c_name_type_code' => 5, 'c_name_type_desc' => 'hao', 'c_name_type_desc_chn' => '號'],
        ]);

        // char_variant_map：與 database/migrations/2026_07_15_000000_create_char_variant_map_table.php
        // 相同的 7 筆種子資料，供 BiogMainRepository::altnameStoreById()/altnameUpdateById() 的
        // 異體字落地替換查詢使用（本測試走真實 controller，會實際觸發該查詢）。
        Schema::create('char_variant_map', function ($table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(1);
            $table->string('c_notes', 255)->nullable();
            $table->timestamps();

            $table->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });

        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '愼', 'c_reference_char' => '慎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '槀', 'c_reference_char' => '稿', 'c_strict_excluded' => 0],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
            ['c_variant_char' => '靑', 'c_reference_char' => '青', 'c_strict_excluded' => 0],
            ['c_variant_char' => '頴', 'c_reference_char' => '穎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
            ['c_variant_char' => '厰', 'c_reference_char' => '廠', 'c_strict_excluded' => 0],
        ]);
        CharVariantMapService::reset();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ALTNAME_CODES');
        Schema::dropIfExists('CBDB__NAME_FTS');
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function createActiveExpert(): User {
        $user = User::create([
            'name' => 'Admin Tester',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);

        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_EXPERT;
        $user->save();

        return $user;
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

    // ===== AltnameData 測試 =====

    #[Test]
    public function test_creating_altname_automatically_creates_index(): void {
        $user = $this->createActiveExpert();

        // 先創建人物
        BiogMain::create([
            'c_personid' => 2001,
            'c_name_chn' => '蘇軾',
        ]);

        // 透過實際 Controller 路由新增別名，確保使用生產流程與索引服務
        $this->actingAs($user)
            ->post('/basicinformation/2001/altnames', [
                'c_sequence' => 1,
                'c_alt_name_type_code' => 4,
                'c_alt_name_chn' => '子瞻',
            ])
            ->assertStatus(302);

        // 檢查別名索引是否創建
        $indexExists = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 2001)
            ->where('name_type_code', 4)
            ->where('search_term', '子瞻')
            ->exists();

        $this->assertTrue($indexExists, '新增別名後手動調用索引服務應該創建索引');
    }

    #[Test]
    public function test_updating_altname_reindexes(): void {
        $user = $this->createActiveExpert();

        BiogMain::create([
            'c_personid' => 2002,
            'c_name_chn' => '蘇軾',
        ]);

        // 透過生產路由新增別名
        $this->actingAs($user)
            ->post('/basicinformation/2002/altnames', [
                'c_sequence' => 1,
                'c_alt_name_type_code' => 5,
                'c_alt_name_chn' => '東坡居士',
            ])
            ->assertStatus(302);

        // 使用生產路由更新別名，觸發控制器內的索引更新流程
        $this->actingAs($user)
            ->put('/basicinformation/2002/altnames/2002-1-東坡居士-5', [
                'c_sequence' => 1,
                'c_alt_name_type_code' => 5,
                'c_alt_name_chn' => '東坡先生',
            ])
            ->assertStatus(302);

        // 檢查舊索引已刪除
        $oldExists = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 2002)
            ->where('search_term', '東坡居士')
            ->exists();

        $this->assertFalse($oldExists, '舊別名索引應該被刪除');

        // 檢查新索引已創建
        $newExists = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 2002)
            ->where('search_term', '東坡先生')
            ->exists();

        $this->assertTrue($newExists, '新別名索引應該被創建');
    }

    #[Test]
    public function test_creating_altname_with_variant_char_indexes_replaced_value(): void {
        // 迴歸測試：CBDB__NAME_FTS 索引須與 ALTNAME_DATA 實際落地的值一致（都是異體字
        // 落地替換後的值），而不是替換前的原始輸入——見
        // docs/CHAR_VARIANT_MAP_CALL_SITE_WIRING_PLAN.md 步驟 5「實作時發現的第四個
        // 掛鉤點」段落記錄的既存索引同步 bug。
        $user = $this->createActiveExpert();

        BiogMain::create([
            'c_personid' => 2010,
            'c_name_chn' => '測試人物',
        ]);

        $this->actingAs($user)
            ->post('/basicinformation/2010/altnames', [
                'c_sequence' => 1,
                'c_alt_name_type_code' => 4,
                'c_alt_name_chn' => '淸公',
            ])
            ->assertStatus(302);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 2010,
            'c_alt_name_chn' => '清公',
        ]);

        $indexedReplaced = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 2010)
            ->where('name_type_code', 4)
            ->where('search_term', '清公')
            ->exists();
        $indexedOriginal = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 2010)
            ->where('search_term', '淸公')
            ->exists();

        $this->assertTrue($indexedReplaced, '索引應以落地替換後的字形建立');
        $this->assertFalse($indexedOriginal, '索引不應殘留替換前的字形');
    }

    #[Test]
    public function test_updating_altname_with_variant_char_reindexes_replaced_value(): void {
        $user = $this->createActiveExpert();

        BiogMain::create([
            'c_personid' => 2011,
            'c_name_chn' => '測試人物',
        ]);

        $this->actingAs($user)
            ->post('/basicinformation/2011/altnames', [
                'c_sequence' => 1,
                'c_alt_name_type_code' => 5,
                'c_alt_name_chn' => '舊號',
            ])
            ->assertStatus(302);

        $this->actingAs($user)
            ->put('/basicinformation/2011/altnames/2011-1-舊號-5', [
                'c_sequence' => 1,
                'c_alt_name_type_code' => 5,
                'c_alt_name_chn' => '厰記',
            ])
            ->assertStatus(302);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 2011,
            'c_alt_name_chn' => '廠記',
        ]);

        $indexedReplaced = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 2011)
            ->where('search_term', '廠記')
            ->exists();
        $indexedOriginal = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 2011)
            ->where('search_term', '厰記')
            ->exists();

        $this->assertTrue($indexedReplaced, '更新後索引應以落地替換後的字形重建');
        $this->assertFalse($indexedOriginal, '索引不應殘留替換前的字形');
    }

    #[Test]
    public function test_deleting_altname_removes_index(): void {
        $user = $this->createActiveExpert();

        BiogMain::create([
            'c_personid' => 2003,
            'c_name_chn' => '李白',
        ]);

        // 新增別名（生產路由）
        $this->actingAs($user)
            ->post('/basicinformation/2003/altnames', [
                'c_sequence' => 1,
                'c_alt_name_type_code' => 4,
                'c_alt_name_chn' => '太白',
            ])
            ->assertStatus(302);

        // 確認索引已創建
        $this->assertTrue(
            DB::table('CBDB__NAME_FTS')
                ->where('c_personid', 2003)
                ->where('name_type_code', 4)
                ->where('search_term', '太白')
                ->exists(),
            '別名索引應該已創建'
        );

        // 刪除別名（生產路由），並讓控制器負責清理索引
        $deleteUrl = CompositePrimaryKey::buildUrl(
            'basicinformation.altnames.destroy.query',
            ['id' => 2003],
            [
                'c_personid' => 2003,
                'c_sequence' => 1,
                'c_alt_name_chn' => '太白',
                'c_alt_name_type_code' => 4,
            ]
        );

        $this->actingAs($user)
            ->delete($deleteUrl)
            ->assertStatus(302);

        // 檢查別名索引已刪除
        $indexExists = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 2003)
            ->where('name_type_code', 4)
            ->where('search_term', '太白')
            ->exists();

        $this->assertFalse($indexExists, '刪除別名後手動調用服務應該移除對應索引');

        // 但本名索引應該保留
        $mainNameExists = DB::table('CBDB__NAME_FTS')
            ->where('c_personid', 2003)
            ->whereNull('name_type_code')
            ->exists();

        $this->assertTrue($mainNameExists, '本名索引應該保留');
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
