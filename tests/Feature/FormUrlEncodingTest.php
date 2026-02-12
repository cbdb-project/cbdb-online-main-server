<?php

namespace Tests\Feature;

use App\Support\CompositePrimaryKey;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 測試表單 URL 編碼問題修復
 *
 * 當主鍵欄位包含特殊字符（如斜線 /）時，表單提交的 URL 必須正確編碼，
 * 否則會導致 404 錯誤。
 *
 * 問題根源：
 * 這些表單原本就有使用 unionPKDef() 函數來編碼特殊字符，但問題在於
 * $formAction 的計算是在 unionPKDef() 調用之前執行的，導致 URL 中的
 * 特殊字符未被編碼。
 *
 * 相關修復：
 * - PR #740: assoc 表單 (c_text_title 欄位)
 * - 本次修復: altname ($alt 變數), sources (c_pages 欄位)
 */
class FormUrlEncodingTest extends TestCase {
    protected $user;
    protected $testPersonId = 12345;

    // 保存原始配置以便在 tearDown 中恢復
    protected $originalDbDefault;
    protected $originalSqliteConfig;
    protected $originalCacheDefault;
    protected $originalSessionDriver;

    protected function setUp(): void {
        parent::setUp();

        // 保存原始配置
        $this->originalDbDefault = config('database.default');
        $this->originalSqliteConfig = config('database.connections.sqlite');
        $this->originalCacheDefault = config('cache.default');
        $this->originalSessionDriver = config('session.driver');

        // 使用 in-memory SQLite 數據庫
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 設置緩存和 session 為數組驅動
        config(['cache.default' => 'array']);
        config(['session.driver' => 'array']);

        // 重新連接數據庫以使用新配置
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createMinimalTables();
        $this->seedTestData();
    }

    protected function tearDown(): void {
        // 恢復原始配置
        config()->set('database.default', $this->originalDbDefault);
        config()->set('database.connections.sqlite', $this->originalSqliteConfig);
        config()->set('cache.default', $this->originalCacheDefault);
        config()->set('session.driver', $this->originalSessionDriver);

        // 重新連接原始數據庫
        DB::purge('sqlite');

        parent::tearDown();
    }

    protected function createMinimalTables(): void {
        // 創建 users 表
        Schema::create('users', function (Blueprint $table) {
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

        // 創建 BIOG_MAIN 表（基礎人物表）
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn', 50)->nullable();
            $table->string('c_name', 50)->nullable();
            $table->integer('c_dy')->nullable();
            $table->integer('c_index_year')->nullable();
            $table->integer('c_index_addr_id')->nullable();
            $table->integer('c_birthyear')->nullable();
            $table->integer('c_deathyear')->nullable();
            $table->integer('c_death_age')->nullable();
            $table->integer('c_surname_id')->nullable();
            $table->integer('c_mingzi_id')->nullable();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        // 創建 ALTNAME_DATA 表（別名表）
        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->nullable();
            $table->string('c_alt_name_chn', 100)->nullable();
            $table->string('c_alt_name', 100)->nullable();
            $table->integer('c_alt_name_type_code')->default(0);
            $table->integer('c_source')->nullable();
            $table->string('c_pages', 100)->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        // 創建 BIOG_SOURCE_DATA 表（出處表）
        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->string('c_pages', 100)->nullable();
            $table->text('c_notes')->nullable();
            $table->tinyInteger('c_main_source')->default(0);
            $table->tinyInteger('c_self_bio')->default(0);
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        // 創建 ALTNAME_CODES 表（別名類型代碼表）
        Schema::create('ALTNAME_CODES', function (Blueprint $table) {
            $table->integer('c_name_type_code')->primary();
            $table->string('c_name_type_desc_chn', 100)->nullable();
            $table->string('c_name_type_desc', 100)->nullable();
        });

        // 創建 TEXT_CODES 表（文本代碼表）
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title', 200)->nullable();
            $table->string('c_title_chn', 200)->nullable();
        });

        // 創建 DYNASTIES 表（朝代表，BIOG_MAIN 關聯使用）
        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn', 50)->nullable();
            $table->string('c_dynasty', 50)->nullable();
        });

        // 創建 operations 表（操作記錄表）
        Schema::create('operations', function (Blueprint $table) {
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

        // 創建 audit_log 表（供審計記錄寫入）
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->string('operation_id', 26);
            $table->text('row_pk');
            $table->string('row_pk_text', 512);
            $table->text('old_data')->nullable();
            $table->text('new_data')->nullable();
        });

        // 注意：ASSOC_DATA 相關表不需要創建，因為我們只測試編解碼邏輯
        // 完整的 ASSOC_DATA 編輯頁面依賴太多外部表（SOCIAL_INSTITUTION_CODES、ADDR_CODES 等）
        // 無法在 in-memory SQLite 中輕鬆測試
    }

    protected function seedTestData(): void {
        // 創建測試用戶
        $this->user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'is_active' => 1,
            'is_admin' => 1,
            'confirmation_token' => 'test_token_' . time(),
        ]);

        // 創建朝代數據
        DB::table('DYNASTIES')->insert([
            'c_dy' => 1,
            'c_dynasty_chn' => '測試朝代',
            'c_dynasty' => 'Test Dynasty',
        ]);

        // 創建測試人物
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => $this->testPersonId,
            'c_name_chn' => '測試人物',
            'c_name' => 'Test Person',
            'c_dy' => 1,
        ]);

        // 創建關聯人物（用於 assoc 測試）
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 30080,
            'c_name_chn' => '關聯人物',
            'c_name' => 'Related Person',
            'c_dy' => 1,
        ]);


        // 創建代碼表數據
        DB::table('ALTNAME_CODES')->insert([
            'c_name_type_code' => 1,
            'c_name_type_desc_chn' => '字',
            'c_name_type_desc' => 'Courtesy name',
        ]);

        DB::table('TEXT_CODES')->insert([
            'c_textid' => 100,
            'c_title' => 'Test Text',
            'c_title_chn' => '測試文本',
        ]);

    }

    /**
     * 測試：altname 編輯頁面當 c_alt_name_chn 包含斜線時能正確渲染
     *
     * 修復前：formAction URL 中的斜線未編碼，導致路由匹配失敗
     * 修復後：unionPKDef() 在 formAction 計算前執行，斜線被編碼為 (slash)
     */
    #[Test]
    public function testAltnameEditPageRendersWithSlashInAltNameChn(): void {
        // 創建包含斜線的別名記錄
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_sequence' => 1,
            'c_alt_name_chn' => '測試/別名',  // 包含斜線
            'c_alt_name' => 'Test/Altname',
            'c_alt_name_type_code' => 1,
            'c_source' => 100,
        ]);

        // 訪問編輯頁面 - 使用編碼後的 URL
        $response = $this->actingAs($this->user)
            ->get("/basicinformation/{$this->testPersonId}/altnames/{$this->testPersonId}-1-測試(slash)別名-1/edit");

        $response->assertStatus(200);

        // 驗證表單 action URL 中斜線已被正確編碼
        // 使用正則表達式精確匹配 form action 屬性
        // (slash) 會被 URL 編碼為 %28slash%29
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/action="[^"]*%28slash%29[^"]*"/',
            $content,
            '表單 action URL 應該包含編碼後的 (slash)'
        );
    }

    /**
     * 測試：sources 編輯頁面當 c_pages 包含斜線時能正確渲染
     *
     * 修復前：formAction URL 中的斜線未編碼，導致路由匹配失敗
     * 修復後：unionPKDef() 在 formAction 計算前執行，斜線被編碼為 (slash)
     */
    #[Test]
    public function testSourcesEditPageRendersWithSlashInPages(): void {
        // 創建包含斜線的出處記錄
        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_textid' => 100,
            'c_pages' => 'p1/2',  // 包含斜線
            'c_notes' => 'Test notes',
            'c_main_source' => 0,
            'c_self_bio' => 0,
        ]);

        // 訪問編輯頁面 - 使用編碼後的 URL
        $response = $this->actingAs($this->user)
            ->get("/basicinformation/{$this->testPersonId}/sources/{$this->testPersonId}-100-p1(slash)2/edit");

        $response->assertStatus(200);

        // 驗證表單 action URL 中斜線已被正確編碼
        // 使用正則表達式精確匹配 form action 屬性
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/action="[^"]*%28slash%29[^"]*"/',
            $content,
            '表單 action URL 應該包含編碼後的 (slash)'
        );
    }

    /**
     * 測試：altname 包含多個斜線時能正確處理
     */
    #[Test]
    public function testAltnameEditPageRendersWithMultipleSlashes(): void {
        // 創建包含多個斜線的別名記錄
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_sequence' => 2,
            'c_alt_name_chn' => 'a/b/c',  // 包含多個斜線
            'c_alt_name' => 'a/b/c',
            'c_alt_name_type_code' => 1,
            'c_source' => 100,
        ]);

        // 訪問編輯頁面 - 使用編碼後的 URL（每個斜線都被編碼）
        $response = $this->actingAs($this->user)
            ->get("/basicinformation/{$this->testPersonId}/altnames/{$this->testPersonId}-2-a(slash)b(slash)c-1/edit");

        $response->assertStatus(200);

        // 驗證表單 action URL 中斜線已被正確編碼
        // 使用正則表達式精確匹配 form action 屬性
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/action="[^"]*%28slash%29[^"]*"/',
            $content,
            '表單 action URL 應該包含編碼後的 (slash)'
        );
    }

    /**
     * 測試：altname 不包含特殊字符時正常運作
     */
    #[Test]
    public function testAltnameEditPageRendersWithoutSpecialCharacters(): void {
        // 創建不包含特殊字符的別名記錄
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_sequence' => 3,
            'c_alt_name_chn' => '普通別名',
            'c_alt_name' => 'Normal Altname',
            'c_alt_name_type_code' => 1,
            'c_source' => 100,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/basicinformation/{$this->testPersonId}/altnames/{$this->testPersonId}-3-普通別名-1/edit");

        $response->assertStatus(200);
    }

    /**
     * 測試：sources 不包含特殊字符時正常運作
     */
    #[Test]
    public function testSourcesEditPageRendersWithoutSpecialCharacters(): void {
        // 創建不包含特殊字符的出處記錄
        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_textid' => 100,
            'c_pages' => 'p123',  // 不包含特殊字符
            'c_notes' => 'Test notes',
            'c_main_source' => 0,
            'c_self_bio' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/basicinformation/{$this->testPersonId}/sources/{$this->testPersonId}-100-p123/edit");

        $response->assertStatus(200);
    }

    /**
     * 測試：altname 包含問號時能正確處理
     *
     * 問號在 URL 中有特殊意義（查詢字符串開始），必須被編碼
     */
    #[Test]
    public function testAltnameEditPageRendersWithQuestionMark(): void {
        // 創建包含問號的別名記錄
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_sequence' => 4,
            'c_alt_name_chn' => '測試?別名',  // 包含問號
            'c_alt_name' => 'Test?Altname',
            'c_alt_name_type_code' => 1,
            'c_source' => 100,
        ]);

        // 訪問編輯頁面 - 使用編碼後的 URL
        $response = $this->actingAs($this->user)
            ->get("/basicinformation/{$this->testPersonId}/altnames/{$this->testPersonId}-4-測試(question)別名-1/edit");

        $response->assertStatus(200);

        // 驗證表單 action URL 中問號已被正確編碼
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/action="[^"]*%28question%29[^"]*"/',
            $content,
            '表單 action URL 應該包含編碼後的 (question)'
        );
    }

    /**
     * 測試：sources 包含問號時能正確處理
     */
    #[Test]
    public function testSourcesEditPageRendersWithQuestionMark(): void {
        // 創建包含問號的出處記錄
        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_textid' => 100,
            'c_pages' => 'p1?2',  // 包含問號
            'c_notes' => 'Test notes',
            'c_main_source' => 0,
            'c_self_bio' => 0,
        ]);

        // 訪問編輯頁面 - 使用編碼後的 URL
        $response = $this->actingAs($this->user)
            ->get("/basicinformation/{$this->testPersonId}/sources/{$this->testPersonId}-100-p1(question)2/edit");

        $response->assertStatus(200);

        // 驗證表單 action URL 中問號已被正確編碼
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/action="[^"]*%28question%29[^"]*"/',
            $content,
            '表單 action URL 應該包含編碼後的 (question)'
        );
    }

    /**
     * 測試：altname 包含問號和斜線組合時能正確處理
     *
     * 測試組合：斜線和問號 (/?)
     * 注意：減號是分隔符，所以使用不包含減號的測試資料
     */
    #[Test]
    public function testAltnameEditPageRendersWithSlashAndQuestionMark(): void {
        // 創建包含斜線和問號的別名記錄
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_sequence' => 5,
            'c_alt_name_chn' => 'test/?name',  // 包含斜線和問號
            'c_alt_name' => 'test/?name',
            'c_alt_name_type_code' => 1,
            'c_source' => 100,
        ]);

        // 訪問編輯頁面 - 使用編碼後的 URL
        // /? 編碼後變成 (slash)(question)
        $response = $this->actingAs($this->user)
            ->get("/basicinformation/{$this->testPersonId}/altnames/{$this->testPersonId}-5-test(slash)(question)name-1/edit");

        $response->assertStatus(200);

        // 驗證表單 action URL 中特殊字符已被正確編碼
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/action="[^"]*%28slash%29[^"]*%28question%29[^"]*"/',
            $content,
            '表單 action URL 應該包含編碼後的 (slash) 和 (question)'
        );
    }

    /**
     * 測試：sources 包含問號和斜線組合時能正確處理
     */
    #[Test]
    public function testSourcesEditPageRendersWithSlashAndQuestionMark(): void {
        // 創建包含斜線和問號的出處記錄
        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_textid' => 100,
            'c_pages' => 'p1/?2',  // 包含斜線和問號
            'c_notes' => 'Test notes',
            'c_main_source' => 0,
            'c_self_bio' => 0,
        ]);

        // 訪問編輯頁面 - 使用編碼後的 URL
        $response = $this->actingAs($this->user)
            ->get("/basicinformation/{$this->testPersonId}/sources/{$this->testPersonId}-100-p1(slash)(question)2/edit");

        $response->assertStatus(200);

        // 驗證表單 action URL 中特殊字符已被正確編碼
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/action="[^"]*%28slash%29[^"]*%28question%29[^"]*"/',
            $content,
            '表單 action URL 應該包含編碼後的 (slash) 和 (question)'
        );
    }

    /**
     * 測試：sources store 功能可以處理特殊字符（.//?-）
     *
     * 此測試驗證 create 表單提交後，包含特殊字符的頁數欄位可以正確保存，
     * 並且 redirect URL 被正確編碼。
     */
    #[Test]
    public function testSourcesStoreWithSpecialCharactersInPages(): void {
        // 提交 store 請求，c_pages 包含 .//?-
        $response = $this->actingAs($this->user)
            ->post("/basicinformation/{$this->testPersonId}/sources", [
                'c_textid' => 100,
                'c_pages' => './/?-',  // 包含 . / / ? -
                'c_notes' => 'Test notes with special chars',
                'c_main_source' => 0,
                'c_self_bio' => 0,
            ]);

        // 驗證 redirect 目標 URL 被正確編碼
        // 新的查詢參數模式使用 http_build_query() 自動處理 URL 編碼
        // .//?- 會被編碼為標準 URL 編碼格式
        $response->assertRedirect();

        // 驗證數據已正確保存到數據庫
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => $this->testPersonId,
            'c_textid' => 100,
            'c_pages' => './/?-',
        ]);

        // 驗證 redirect URL 使用新的查詢參數模式
        $redirectUrl = $response->headers->get('Location');
        // 新模式使用 ?c_personid=...&c_textid=...&c_pages=... 格式
        $this->assertStringContainsString('c_personid=', $redirectUrl);
        $this->assertStringContainsString('c_textid=', $redirectUrl);
        $this->assertStringContainsString('c_pages=', $redirectUrl);
        // 特殊字符會被標準 URL 編碼（%2F = /, %3F = ?）
        $this->assertMatchesRegularExpression('/c_pages=.*%2F.*%3F/', $redirectUrl);
    }

    /**
     * 測試：altname store 功能可以處理特殊字符（.//?-）
     */
    #[Test]
    public function testAltnameStoreWithSpecialCharactersInAltNameChn(): void {
        // 提交 store 請求，c_alt_name_chn 包含 .//?-
        $response = $this->actingAs($this->user)
            ->post("/basicinformation/{$this->testPersonId}/altnames", [
                'c_sequence' => 10,
                'c_alt_name_chn' => './/?-',  // 包含 . / / ? -
                'c_alt_name' => 'test altname',
                'c_alt_name_type_code' => 1,
                'c_source' => 100,
                'c_pages' => 'p1',
                'c_notes' => 'Test notes',
            ]);

        // 驗證 redirect
        $response->assertRedirect();

        // 驗證數據已正確保存到數據庫
        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => $this->testPersonId,
            'c_sequence' => 10,
            'c_alt_name_chn' => './/?-',
        ]);

        // 驗證 redirect URL 使用新的查詢參數模式
        $redirectUrl = $response->headers->get('Location');
        // 新模式使用 ?c_personid=...&c_sequence=...&c_alt_name_chn=... 格式
        $this->assertStringContainsString('c_personid=', $redirectUrl);
        $this->assertStringContainsString('c_sequence=', $redirectUrl);
        $this->assertStringContainsString('c_alt_name_chn=', $redirectUrl);
        // 特殊字符會被標準 URL 編碼（%2F = /, %3F = ?）
        $this->assertMatchesRegularExpression('/c_alt_name_chn=.*%2F.*%3F/', $redirectUrl);
    }

    /**
     * 測試：sources 編輯頁面當 c_pages 包含減號時能正確處理
     *
     * 減號 (-) 是複合主鍵的分隔符，當欄位值本身包含減號時，必須被編碼為 (minus)
     * 否則在解析複合主鍵時會產生錯誤。
     *
     * 用戶報告的問題：c_pages = ".//?-" 無法編輯或刪除
     * URL: http://127.0.0.1:8000/basicinformation/1/sources/1-0-.(slash)(slash)(question)-/edit
     * 錯誤：Attempt to read property "c_textid" on null
     */
    #[Test]
    public function testSourcesEditPageRendersWithMinusSign(): void {
        // 創建包含減號的出處記錄
        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_textid' => 100,
            'c_pages' => './/?-',  // 包含斜線、問號和減號
            'c_notes' => 'Test notes',
            'c_main_source' => 0,
            'c_self_bio' => 0,
        ]);

        // 訪問編輯頁面 - 使用編碼後的 URL
        // .//?- 編碼後變成 .(slash)(slash)(question)(minus)
        $response = $this->actingAs($this->user)
            ->get("/basicinformation/{$this->testPersonId}/sources/{$this->testPersonId}-100-.(slash)(slash)(question)(minus)/edit");

        $response->assertStatus(200);

        // 驗證表單 action URL 中特殊字符已被正確編碼
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/action="[^"]*%28minus%29[^"]*"/',
            $content,
            '表單 action URL 應該包含編碼後的 (minus)'
        );
    }

    /**
     * 測試：sources 刪除功能當 c_pages 包含減號時能正確處理
     */
    #[Test]
    public function testSourcesDeleteWithMinusSign(): void {
        // 創建包含減號的出處記錄
        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_textid' => 100,
            'c_pages' => './/?-',  // 包含斜線、問號和減號
            'c_notes' => 'Test notes',
            'c_main_source' => 0,
            'c_self_bio' => 0,
        ]);

        // 驗證記錄存在
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => $this->testPersonId,
            'c_textid' => 100,
            'c_pages' => './/?-',
        ]);

        // 刪除記錄 - 使用編碼後的 URL
        // 查詢參數模式由 Laravel 原生 URL 編碼處理特殊字元
        $deleteUrl = CompositePrimaryKey::buildUrl(
            'basicinformation.sources.destroy.query',
            ['id' => $this->testPersonId],
            [
                'c_personid' => $this->testPersonId,
                'c_textid' => 100,
                'c_pages' => './/?-',
            ]
        );

        $response = $this->actingAs($this->user)
            ->delete($deleteUrl);

        $response->assertRedirect();

        // 驗證記錄已被刪除
        $this->assertDatabaseMissing('BIOG_SOURCE_DATA', [
            'c_personid' => $this->testPersonId,
            'c_textid' => 100,
            'c_pages' => './/?-',
        ]);
    }

    /**
     * 測試：sources store 功能正確處理減號
     *
     * 新的查詢參數模式不需要特殊編碼減號，因為減號不會干擾查詢參數解析
     */
    #[Test]
    public function testSourcesStoreEncodesMinusSign(): void {
        // 提交 store 請求，c_pages 包含減號
        $response = $this->actingAs($this->user)
            ->post("/basicinformation/{$this->testPersonId}/sources", [
                'c_textid' => 100,
                'c_pages' => 'test-page',  // 包含減號
                'c_notes' => 'Test notes',
                'c_main_source' => 0,
                'c_self_bio' => 0,
            ]);

        // 驗證 redirect
        $response->assertRedirect();

        // 驗證數據已正確保存到數據庫
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => $this->testPersonId,
            'c_textid' => 100,
            'c_pages' => 'test-page',
        ]);

        // 驗證 redirect URL 使用新的查詢參數模式
        $redirectUrl = $response->headers->get('Location');
        // 新模式使用 ?c_personid=...&c_textid=...&c_pages=... 格式
        $this->assertStringContainsString('c_personid=', $redirectUrl);
        $this->assertStringContainsString('c_textid=', $redirectUrl);
        // 減號在查詢參數中不需要特殊編碼，可以直接使用
        $this->assertStringContainsString('c_pages=test-page', $redirectUrl);
    }

    /**
     * 測試：sources store 當 c_textid 為 -999 時能正確轉換為 0
     *
     * Select2 對 id=0 的處理有問題，因此 API 將 c_textid=0 轉換為 -999。
     * 存入資料庫時需要將 -999 轉回 0。
     *
     * 參考：commit 759d6a8 "fix select bug" (2017-11-25)
     */
    #[Test]
    public function testSourcesStoreConvertsNegative999ToZero(): void {
        // 提交 store 請求，c_textid 為 -999（模擬 Select2 傳來的值）
        $response = $this->actingAs($this->user)
            ->post("/basicinformation/{$this->testPersonId}/sources", [
                'c_textid' => -999,  // Select2 會將 id=0 轉換為 -999
                'c_pages' => 'p1',
                'c_notes' => 'Test with textid 0',
                'c_main_source' => 0,
                'c_self_bio' => 0,
            ]);

        // 驗證 redirect（因為沒有外鍵約束，應該成功）
        $response->assertRedirect();

        // 驗證數據已正確保存到數據庫，c_textid 應該是 0 而不是 -999
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => $this->testPersonId,
            'c_textid' => 0,  // 應該被轉換回 0
            'c_pages' => 'p1',
        ]);

        // 確認沒有 -999 的記錄
        $this->assertDatabaseMissing('BIOG_SOURCE_DATA', [
            'c_personid' => $this->testPersonId,
            'c_textid' => -999,
        ]);
    }

    // ==================== ASSOC_DATA 複合主鍵編解碼測試 ====================
    //
    // 注意：ASSOC_DATA 的編輯頁面依賴太多外部表（SOCIAL_INSTITUTION_CODES、ADDR_CODES 等），
    // 無法在 in-memory SQLite 中輕鬆測試。因此這裡專注測試編解碼邏輯本身，
    // 以驗證 c_text_title 內含負號時的處理是否正確。

    /**
     * 測試：assocById 的複合主鍵解碼邏輯 - c_text_title 包含單個負號
     *
     * 這是同事特別提醒需要測試的場景：
     * 在 BiogMainRepository::assocById() 中，當時是防止 c_text_title 欄位內容「內含負號」
     * 所做的字串重組。負號 (-) 是複合主鍵的分隔符，當欄位值本身包含負號時，
     * 必須被編碼為 (minus)，否則在解析複合主鍵時會產生錯誤。
     *
     * 複合主鍵格式: c_personid-c_assoc_code-c_assoc_id-c_kin_code-c_kin_id-c_assoc_kin_code-c_assoc_kin_id-c_text_title
     */
    #[Test]
    public function testAssocCompositePKDecodingWithMinusInTextTitle(): void {
        $repo = new \App\Repositories\BiogMainRepository();

        // 模擬 c_text_title = "測試-標題" 的複合主鍵
        // 編碼後: 12345-1-30080-0-0-0-0-測試(minus)標題
        $encodedPK = '12345-1-30080-0-0-0-0-測試(minus)標題';

        // 使用與 assocById() 相同的解碼邏輯
        $parts = explode('-', $encodedPK);
        foreach ($parts as $key => $value) {
            $parts[$key] = $repo->unionPKDef_decode($value);
        }

        // 驗證解碼結果
        $this->assertSame('12345', $parts[0], 'c_personid');
        $this->assertSame('1', $parts[1], 'c_assoc_code');
        $this->assertSame('30080', $parts[2], 'c_assoc_id');
        $this->assertSame('0', $parts[3], 'c_kin_code');
        $this->assertSame('0', $parts[4], 'c_kin_id');
        $this->assertSame('0', $parts[5], 'c_assoc_kin_code');
        $this->assertSame('0', $parts[6], 'c_assoc_kin_id');
        $this->assertSame('測試-標題', $parts[7], 'c_text_title 應該正確解碼負號');
    }

    /**
     * 測試：assocById 的複合主鍵解碼邏輯 - c_text_title 包含多個負號
     *
     * 這是更複雜的情況，測試連續多個負號的處理
     */
    #[Test]
    public function testAssocCompositePKDecodingWithMultipleMinusInTextTitle(): void {
        $repo = new \App\Repositories\BiogMainRepository();

        // 模擬 c_text_title = "a-b-c-d" 的複合主鍵
        // 編碼後: 12345-1-30080-0-0-0-0-a(minus)b(minus)c(minus)d
        $encodedPK = '12345-1-30080-0-0-0-0-a(minus)b(minus)c(minus)d';

        $parts = explode('-', $encodedPK);
        foreach ($parts as $key => $value) {
            $parts[$key] = $repo->unionPKDef_decode($value);
        }

        $this->assertCount(8, $parts, '應該有 8 個部分');
        $this->assertSame('a-b-c-d', $parts[7], 'c_text_title 應該正確解碼多個負號');
    }

    /**
     * 測試：assocById 的複合主鍵解碼邏輯 - c_text_title 包含斜線和負號組合
     */
    #[Test]
    public function testAssocCompositePKDecodingWithSlashAndMinusInTextTitle(): void {
        $repo = new \App\Repositories\BiogMainRepository();

        // 模擬 c_text_title = "test/-name" 的複合主鍵
        // 編碼後: 12345-1-30080-0-0-0-0-test(slash)(minus)name
        $encodedPK = '12345-1-30080-0-0-0-0-test(slash)(minus)name';

        $parts = explode('-', $encodedPK);
        foreach ($parts as $key => $value) {
            $parts[$key] = $repo->unionPKDef_decode($value);
        }

        $this->assertCount(8, $parts, '應該有 8 個部分');
        $this->assertSame('test/-name', $parts[7], 'c_text_title 應該正確解碼斜線和負號');
    }

    /**
     * 測試：unionPKDef 編碼和解碼的對稱性 - 針對 c_text_title 常見情況
     *
     * 確保編碼後再解碼能得到原始值
     */
    #[Test]
    public function testUnionPKDefSymmetryForTextTitle(): void {
        $repo = new \App\Repositories\BiogMainRepository();

        $testCases = [
            '普通標題',           // 無特殊字符
            '測試-標題',          // 單個負號
            'a-b-c',              // 多個負號
            '測試/標題',          // 斜線
            'test/-name',         // 斜線和負號組合
            './/?-',              // 多種特殊字符組合
            'a--b',               // 連續兩個負號
            '-start',             // 開頭是負號
            'end-',               // 結尾是負號
            '-',                  // 只有一個負號
        ];

        foreach ($testCases as $original) {
            $encoded = $repo->unionPKDef($original);
            $decoded = $repo->unionPKDef_decode($encoded);
            $this->assertSame($original, $decoded, "編解碼對稱性失敗: '$original' -> '$encoded' -> '$decoded'");
        }
    }

    /**
     * 測試：完整的 assoc 複合主鍵生成和解析流程
     *
     * 模擬 Controller 生成 URL 和 Repository 解析 URL 的完整流程
     */
    #[Test]
    public function testAssocFullCompositePKRoundTrip(): void {
        $repo = new \App\Repositories\BiogMainRepository();

        // 模擬原始數據（從資料庫讀取）
        $data = [
            'c_personid' => 12345,
            'c_assoc_code' => 1,
            'c_assoc_id' => 30080,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => 'test-title-with-minus',  // 包含負號的標題
        ];

        // 模擬 Controller::store() 中生成的 URL
        // 參考 BasicInformationAssocController.php:165
        $compositeKey = $data['c_personid'] . '-' .
                       $data['c_assoc_code'] . '-' .
                       $data['c_assoc_id'] . '-' .
                       $data['c_kin_code'] . '-' .
                       $data['c_kin_id'] . '-' .
                       $data['c_assoc_kin_code'] . '-' .
                       $data['c_assoc_kin_id'] . '-' .
                       $repo->unionPKDef($data['c_text_title']);

        // 模擬 Repository::assocById() 中的解碼邏輯
        // 參考 BiogMainRepository.php:1469-1474
        $parts = explode('-', $compositeKey);
        foreach ($parts as $key => $value) {
            $parts[$key] = $repo->unionPKDef_decode($value);
        }

        // 驗證解碼結果與原始數據一致
        $this->assertSame((string) $data['c_personid'], $parts[0]);
        $this->assertSame((string) $data['c_assoc_code'], $parts[1]);
        $this->assertSame((string) $data['c_assoc_id'], $parts[2]);
        $this->assertSame((string) $data['c_kin_code'], $parts[3]);
        $this->assertSame((string) $data['c_kin_id'], $parts[4]);
        $this->assertSame((string) $data['c_assoc_kin_code'], $parts[5]);
        $this->assertSame((string) $data['c_assoc_kin_id'], $parts[6]);
        $this->assertSame($data['c_text_title'], $parts[7], 'c_text_title 應該完整還原');
    }

    /**
     * 測試：驗證負號編碼不會與分隔符衝突
     *
     * 這是這個 branch 修復的核心問題：確保欄位值中的負號被正確編碼，
     * 使得在用 - 分割複合主鍵時不會出錯
     */
    #[Test]
    public function testMinusEncodingDoesNotConflictWithSeparator(): void {
        $repo = new \App\Repositories\BiogMainRepository();

        // 原始 c_text_title 包含一個負號
        $textTitle = 'part1-part2';

        // 編碼後不應該包含實際的負號字符（除了分隔符）
        $encoded = $repo->unionPKDef($textTitle);
        $this->assertStringNotContainsString('-', $encoded, '編碼後不應包含負號字符');
        $this->assertStringContainsString('(minus)', $encoded, '負號應被編碼為 (minus)');

        // 構建完整的複合主鍵
        $fullKey = "12345-1-30080-0-0-0-0-{$encoded}";

        // 用 - 分割應該得到正好 8 個部分
        $parts = explode('-', $fullKey);
        $this->assertCount(8, $parts, '應該有正好 8 個部分，說明分隔符沒有衝突');
    }
}
