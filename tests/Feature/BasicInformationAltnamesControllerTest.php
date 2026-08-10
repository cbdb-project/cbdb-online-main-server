<?php

namespace Tests\Feature;

use App\Models\TextCode;
use App\Models\User;
use App\Services\CharVariantMapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BasicInformationAltnamesControllerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->useLegacyPersonForms(); // 本類測 legacy Blade CRUD 行為，撥回 flag=old 越過下架閘門

        // 使用 in-memory SQLite 數據庫
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 創建 users 表
        Schema::dropIfExists('users');
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

        // 創建 ALTNAME_DATA 表
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->nullable();
            $table->string('c_alt_name')->nullable();
            $table->string('c_alt_name_chn');
            $table->string('c_alt_name_type_code');
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
        });

        // 創建 TEXT_CODES 表
        Schema::dropIfExists('TEXT_CODES');
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title')->nullable();
            $table->string('c_title_chn')->nullable();
        });

        // 創建 BIOG_MAIN 表（簡化版）
        Schema::dropIfExists('BIOG_MAIN');
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
        });

        // 創建 ALTNAME_CODES 表（別名類型代碼表）
        Schema::dropIfExists('ALTNAME_CODES');
        Schema::create('ALTNAME_CODES', function (Blueprint $table) {
            $table->integer('c_name_type_code')->primary();
            $table->string('c_name_type_desc_chn', 100)->nullable();
        });

        // 創建 operations 表（用於記錄操作）
        Schema::dropIfExists('operations');
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

        // 創建 audit_log 表
        Schema::dropIfExists('audit_log');
        Schema::create('audit_log', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->string('table_name');
            $table->string('operation');
            $table->string('actor_type');
            $table->string('actor_id');
            $table->string('operation_id');
            $table->json('row_pk');
            $table->string('row_pk_text');
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
        });

        // 插入測試用的別名類型代碼
        DB::table('ALTNAME_CODES')->insert([
            'c_name_type_code' => 1,
            'c_name_type_desc_chn' => '測試別名類型',
        ]);

        // char_variant_map：與 database/migrations/2026_07_15_000000_create_char_variant_map_table.php
        // 相同的 7 筆種子資料，供 altnameStoreById()/altnameUpdateById() 的異體字落地替換查詢使用。
        Schema::dropIfExists('char_variant_map');
        Schema::create('char_variant_map', function (Blueprint $table) {
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
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('ALTNAME_CODES');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    /**
     * 測試新增別名會正確寫入 audit_log
     */
    #[Test]
    public function testStoreWritesAuditLog() {
        // 創建用戶
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 創建 BIOG_MAIN 記錄
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        $data = [
            'c_sequence' => 1,
            'c_alt_name_chn' => '新增別名',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ];

        $response = $this->actingAs($user)
            ->post('/basicinformation/1/altnames', $data);

        $response->assertStatus(302);

        // 驗證 audit_log 記錄
        $this->assertDatabaseHas('audit_log', [
            'table_name' => 'ALTNAME_DATA',
            'operation' => 'INSERT',
            'actor_id' => (string) $user->id,
            'row_pk_text' => 'c_personid=1&c_alt_name_chn=%E6%96%B0%E5%A2%9E%E5%88%A5%E5%90%8D&c_alt_name_type_code=1',
        ]);

        $log = DB::table('audit_log')->first();
        $this->assertNotNull($log->operation_id);
        $new_data = json_decode($log->new_data, true);
        $this->assertEquals('新增別名', $new_data['c_alt_name_chn']);
    }

    #[Test]
    public function testStoreReplacesStrictModeVariantAndFlashesNotice(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'altname-legacy-store-variant@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 1, 'c_name_chn' => '測試人物']);

        $response = $this->actingAs($user)->post('/basicinformation/1/altnames', [
            'c_sequence' => 1,
            'c_alt_name_chn' => '淸X',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response->assertStatus(302);

        // legacy Blade 與 v2 API 走的是平行的兩條寫入路徑（見計畫文件待決事項 3 修正段落）：
        // 落地驗證同一份輸入在 legacy Blade 路徑也確實替換，不能只有 v2 API 有效。
        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '清X',
        ]);
        $this->assertDatabaseMissing('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '淸X',
        ]);

        $flash = session('flash_notification', collect())->toArray();
        $messages = array_column($flash, 'message');
        $this->assertTrue(
            (bool) array_filter($messages, static fn ($m) => str_contains($m, '淸') && str_contains($m, '清')),
            '應有一則含異體字落地替換內容的 flash 訊息'
        );
    }

    #[Test]
    public function testStoreDoesNotReplaceStrictExcludedVariant(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'altname-legacy-store-excluded@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 1, 'c_name_chn' => '測試人物']);

        $this->actingAs($user)->post('/basicinformation/1/altnames', [
            'c_sequence' => 1,
            'c_alt_name_chn' => '峯X',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ])->assertStatus(302);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '峯X',
        ]);
    }

    #[Test]
    public function testStoreDuplicateAltnameShowsErrorAndDoesNotInsertAgain() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'duplicate-altname@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '重複別名',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response = $this->actingAs($user)->post('/basicinformation/1/altnames', [
            'c_sequence' => 1,
            'c_alt_name_chn' => '重複別名',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response->assertStatus(302);
        $this->assertEquals(1, DB::table('ALTNAME_DATA')->count());
    }

    /**
     * 測試當 ALTNAME_DATA 記錄不存在時返回 404
     */
    #[Test]
    public function testEditReturns404WhenAltnameNotFound() {
        // 創建用戶
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 創建 BIOG_MAIN 記錄
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        // 不創建 ALTNAME_DATA 記錄，測試不存在的情況
        $response = $this->actingAs($user)
            ->get('/basicinformation/1/altnames/1-1-不存在的名字-1/edit');

        $response->assertStatus(404);
    }

    /**
     * 測試當 c_source 為 null 時不會發生 NPE
     */
    #[Test]
    public function testEditHandlesNullCSource() {
        // 創建用戶
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 創建 BIOG_MAIN 記錄
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        // 創建 ALTNAME_DATA 記錄，c_source 為 null
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '別名測試',
            'c_alt_name_type_code' => '1',
            'c_source' => null,
        ]);

        $response = $this->actingAs($user)
            ->get('/basicinformation/1/altnames/1-1-別名測試-1/edit');

        $response->assertStatus(200);
        $response->assertViewHas('row');
        $response->assertViewHas('text_str', null);
    }

    /**
     * 測試當 TextCode 不存在時不會發生 NPE
     */
    #[Test]
    public function testEditHandlesNonExistentTextCode() {
        // 創建用戶
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 創建 BIOG_MAIN 記錄
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        // 創建 ALTNAME_DATA 記錄，c_source 指向不存在的 TextCode
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '別名測試',
            'c_alt_name_type_code' => '1',
            'c_source' => 999, // 不存在的 c_source
        ]);

        $response = $this->actingAs($user)
            ->get('/basicinformation/1/altnames/1-1-別名測試-1/edit');

        $response->assertStatus(200);
        $response->assertViewHas('row');
        $response->assertViewHas('text_str', null);
    }

    /**
     * 測試正常情況下能正確載入 TextCode 資訊
     */
    #[Test]
    public function testEditLoadsTextCodeSuccessfully() {
        // 創建用戶
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 創建 BIOG_MAIN 記錄
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        // 創建 TEXT_CODES 記錄
        DB::table('TEXT_CODES')->insert([
            'c_textid' => 100,
            'c_title' => 'Test Title',
            'c_title_chn' => '測試標題',
        ]);

        // 創建 ALTNAME_DATA 記錄
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '別名測試',
            'c_alt_name_type_code' => '1',
            'c_source' => 100,
        ]);

        $response = $this->actingAs($user)
            ->get('/basicinformation/1/altnames/1-1-別名測試-1/edit');

        $response->assertStatus(200);
        $response->assertViewHas('row');
        $response->assertViewHas('text_str', '100 Test Title 測試標題');
    }

    #[Test]
    public function testDestroyQueryCanDeleteWhenSequenceIsNull() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'null-sequence@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => null,
            'c_alt_name_chn' => '空序號別名',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response = $this->actingAs($user)
            ->delete('/basicinformation/1/altnames/delete?c_personid=1&c_alt_name_chn=空序號別名&c_alt_name_type_code=1');

        $response->assertStatus(302);

        $this->assertDatabaseMissing('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '空序號別名',
            'c_alt_name_type_code' => '1',
        ]);
    }

    #[Test]
    public function testUpdateQueryWritesProposalCommentIntoOperationNote() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'update-note@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '舊別名',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response = $this->actingAs($user)->patch(
            '/basicinformation/1/altnames/update?c_personid=1&c_sequence=1&c_alt_name_chn=舊別名&c_alt_name_type_code=1',
            [
                'c_sequence' => 1,
                'c_alt_name_chn' => '新別名',
                'c_alt_name_type_code' => '1',
                'c_source' => 0,
                '__proposal_comment' => '測試備註',
            ]
        );

        $response->assertStatus(302);

        $operation = DB::table('operations')
            ->where('resource', 'ALTNAME_DATA')
            ->where('op_type', 3)
            ->latest('id')
            ->first();

        $this->assertNotNull($operation);
        $resourceData = json_decode($operation->resource_data, true);
        $this->assertEquals('測試備註', $resourceData['__note'] ?? null);
    }

    #[Test]
    public function testUpdateQueryReplacesStrictModeVariantAndFlashesNotice(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'altname-legacy-update-variant@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 1, 'c_name_chn' => '測試人物']);
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '舊別名',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response = $this->actingAs($user)->patch(
            '/basicinformation/1/altnames/update?c_personid=1&c_sequence=1&c_alt_name_chn=舊別名&c_alt_name_type_code=1',
            [
                'c_sequence' => 1,
                'c_alt_name_chn' => '淸X',
                'c_alt_name_type_code' => '1',
                'c_source' => 0,
            ]
        );

        $response->assertStatus(302);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '清X',
        ]);
        $this->assertDatabaseMissing('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '淸X',
        ]);

        $flash = session('flash_notification', collect())->toArray();
        $messages = array_column($flash, 'message');
        $this->assertTrue(
            (bool) array_filter($messages, static fn ($m) => str_contains($m, '淸') && str_contains($m, '清')),
            '應有一則含異體字落地替換內容的 flash 訊息'
        );
    }

    #[Test]
    public function testUpdateQueryDoesNotReplaceStrictExcludedVariant(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'altname-legacy-update-excluded@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 1, 'c_name_chn' => '測試人物']);
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '舊別名',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $this->actingAs($user)->patch(
            '/basicinformation/1/altnames/update?c_personid=1&c_sequence=1&c_alt_name_chn=舊別名&c_alt_name_type_code=1',
            [
                'c_sequence' => 1,
                'c_alt_name_chn' => '峯X',
                'c_alt_name_type_code' => '1',
                'c_source' => 0,
            ]
        )->assertStatus(302);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '峯X',
        ]);
    }

    #[Test]
    public function testUpdateReplacesStrictModeVariantAndFlashesNotice(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'altname-legacy-update-path-variant@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 1, 'c_name_chn' => '測試人物']);
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '舊別名',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response = $this->actingAs($user)->patch('/basicinformation/1/altnames/1-舊別名-1', [
            'c_sequence' => 1,
            'c_alt_name_chn' => '淸X',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response->assertStatus(302);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '清X',
        ]);
        $this->assertDatabaseMissing('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '淸X',
        ]);

        $flash = session('flash_notification', collect())->toArray();
        $messages = array_column($flash, 'message');
        $this->assertTrue(
            (bool) array_filter($messages, static fn ($m) => str_contains($m, '淸') && str_contains($m, '清')),
            '應有一則含異體字落地替換內容的 flash 訊息'
        );
    }

    #[Test]
    public function testUpdateQueryDetectsPkConflictUsingReplacedValue(): void {
        // 替換後的新 c_alt_name_chn（淸X → 清X）與同一人物下另一筆既有別名衝突：
        // 既有的括號衝突偵測邏輯須用替換後的值判斷，而非替換前的原始輸入。
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'altname-legacy-update-conflict@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert(['c_personid' => 1, 'c_name_chn' => '測試人物']);
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '舊別名',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 2,
            'c_alt_name_chn' => '清X',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response = $this->actingAs($user)->patch(
            '/basicinformation/1/altnames/update?c_personid=1&c_sequence=1&c_alt_name_chn=舊別名&c_alt_name_type_code=1',
            [
                'c_sequence' => 1,
                'c_alt_name_chn' => '淸X',
                'c_alt_name_type_code' => '1',
                'c_source' => 0,
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('flash_notification');

        // 原始「舊別名」未被替換覆蓋（衝突擋下，未更新）。
        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '舊別名',
        ]);
    }

    #[Test]
    public function testUpdateProposalWithNullSequenceUsesNullPkCorrectly() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'proposal-null-sequence@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => null,
            'c_alt_name_chn' => '空序號別名',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response = $this->actingAs($user)->patch('/basicinformation/1/altnames/1-NULL-空序號別名-1', [
            'action' => 'proposal',
            'c_sequence' => null,
            'c_alt_name_chn' => '空序號別名-提案',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
            '__proposal_comment' => '空序號提案',
        ]);

        $response->assertStatus(302);

        $this->assertDatabaseHas('operations', [
            'resource' => 'ALTNAME_DATA',
            'op_type' => 9,
            'c_personid' => 1,
        ]);
    }

    /**
     * 測試 c_source 為 0 時的處理
     */
    #[Test]
    public function testEditHandlesZeroCSource() {
        // 創建用戶
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 創建 BIOG_MAIN 記錄
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        // 創建 TEXT_CODES 記錄，c_textid 為 0
        DB::table('TEXT_CODES')->insert([
            'c_textid' => 0,
            'c_title' => 'Zero Source',
            'c_title_chn' => '零來源',
        ]);

        // 創建 ALTNAME_DATA 記錄，c_source 為 0
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '別名測試',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response = $this->actingAs($user)
            ->get('/basicinformation/1/altnames/1-1-別名測試-1/edit');

        $response->assertStatus(200);
        $response->assertViewHas('row');
        $response->assertViewHas('text_str', '0 Zero Source 零來源');
    }

    /**
     * 測試使用查詢參數模式編輯 c_sequence 為 NULL 的記錄
     */
    #[Test]
    public function testEditQueryHandlesNullCSequence() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => null,
            'c_alt_name_chn' => '別名測試',
            'c_alt_name_type_code' => '1',
            'c_source' => null,
        ]);

        // 使用查詢參數模式（舊 URL 含 c_sequence=NULL，controller 會忽略多餘參數）
        $response = $this->actingAs($user)
            ->get('/basicinformation/1/altnames/edit?c_personid=1&c_alt_name_chn=別名測試&c_alt_name_type_code=1');

        $response->assertStatus(200);
        $response->assertViewHas('row');
    }

    /**
     * 測試更新 c_sequence 為 NULL 的記錄不會拋出 Undefined array key
     */
    #[Test]
    public function testUpdateQueryWithNullCSequenceDoesNotThrow() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => null,
            'c_alt_name_chn' => '別名測試',
            'c_alt_name_type_code' => '1',
            'c_source' => null,
        ]);

        // 透過查詢參數模式更新（c_sequence=NULL 在 URL 中）
        $response = $this->actingAs($user)
            ->patch('/basicinformation/1/altnames/update?c_personid=1&c_sequence=NULL&c_alt_name_chn=別名測試&c_alt_name_type_code=1', [
                'c_sequence' => '',
                'c_alt_name_chn' => '別名測試',
                'c_alt_name_type_code' => '1',
                'c_source' => '',
                'c_notes' => '更新備註',
            ]);

        // 應成功重定向而非拋出 ErrorException
        $response->assertRedirect();
        $response->assertSessionHas('flash_notification');

        // 重定向 URL 使用 3-key，不含 c_sequence
        $redirectUrl = $response->headers->get('Location');
        $this->assertStringContainsString('c_personid=1', $redirectUrl);
        $this->assertStringNotContainsString('c_sequence', $redirectUrl);

        // 跟隨重定向，確認編輯頁可正常載入（200）
        $followResponse = $this->actingAs($user)->get($redirectUrl);
        $followResponse->assertStatus(200);
        $followResponse->assertViewHas('row');

        // 驗證記錄已正確更新
        $record = DB::table('ALTNAME_DATA')
            ->where('c_personid', 1)
            ->where('c_alt_name_chn', '別名測試')
            ->first();

        $this->assertNotNull($record);
    }

    // ========================================================
    // 括號正規化相關測試
    // ========================================================

    /**
     * 新增別名時，全角括號應自動轉為半角（中文欄位），拼音欄位應加空格
     */
    #[Test]
    public function testStoreNormalizesFullwidthBrackets(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'bracket-store@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        $response = $this->actingAs($user)->post('/basicinformation/1/altnames', [
            'c_sequence' => 1,
            'c_alt_name_chn' => '升卿（一作陞卿）',
            'c_alt_name' => 'Shengqing(Yizuoshengqing)',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response->assertStatus(302);

        // 中文欄位：僅全角→半角，不加空格
        $record = DB::table('ALTNAME_DATA')
            ->where('c_personid', 1)
            ->first();

        $this->assertNotNull($record);
        $this->assertSame('升卿(一作陞卿)', $record->c_alt_name_chn);
        // 拼音欄位：全角→半角 + 空格
        $this->assertSame('Shengqing (Yizuoshengqing)', $record->c_alt_name);
    }

    /**
     * 更新別名時，拼音欄位的括號應正規化
     */
    #[Test]
    public function testUpdateQueryNormalizesPinyinBrackets(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'bracket-update@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '升卿(一作陞卿)',
            'c_alt_name' => 'Shengqing(Yizuoshengqing)',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response = $this->actingAs($user)->patch(
            '/basicinformation/1/altnames/update?c_personid=1&c_alt_name_chn=' . urlencode('升卿(一作陞卿)') . '&c_alt_name_type_code=1',
            [
                'c_sequence' => 1,
                'c_alt_name_chn' => '升卿(一作陞卿)',
                'c_alt_name' => 'Shengqing(Yizuoshengqing)',
                'c_alt_name_type_code' => '1',
                'c_source' => 0,
            ]
        );

        $response->assertRedirect();

        $record = DB::table('ALTNAME_DATA')
            ->where('c_personid', 1)
            ->first();

        $this->assertNotNull($record);
        $this->assertSame('Shengqing (Yizuoshengqing)', $record->c_alt_name);
    }

    /**
     * 更新別名時，正規化後若與既有同類型別名重複，應攔截並提示
     */
    #[Test]
    public function testUpdateQueryBlocksBracketConflict(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'bracket-conflict@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        // 已有一筆半角括號的記錄
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '升卿(一作陞卿)',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        // 再插入一筆全角括號的記錄（模擬歷史遺留資料）
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 2,
            'c_alt_name_chn' => '升卿（一作陞卿）',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        // 嘗試更新全角那筆的 c_source（不改 c_alt_name_chn）
        // 但正規化會把全角→半角，導致與既有半角記錄 PK 衝突
        $response = $this->actingAs($user)->patch(
            '/basicinformation/1/altnames/update?c_personid=1&c_alt_name_chn=' . urlencode('升卿（一作陞卿）') . '&c_alt_name_type_code=1',
            [
                'c_sequence' => 2,
                'c_alt_name_chn' => '升卿（一作陞卿）',
                'c_alt_name_type_code' => '1',
                'c_source' => 99,
            ]
        );

        // 應被攔截並重定向回去，附帶錯誤訊息
        $response->assertRedirect();
        $response->assertSessionHas('flash_notification');

        // 原始記錄不應被修改
        $this->assertEquals(2, DB::table('ALTNAME_DATA')->where('c_personid', 1)->count());
    }

    /**
     * 更新別名時，只改 c_alt_name_type_code 導致 PK 衝突也應攔截
     */
    #[Test]
    public function testUpdateQueryBlocksTypeCodeConflict(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'type-conflict@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        DB::table('ALTNAME_CODES')->insert([
            'c_name_type_code' => 4,
            'c_name_type_desc_chn' => '字',
        ]);

        DB::table('ALTNAME_CODES')->insert([
            'c_name_type_code' => 5,
            'c_name_type_desc_chn' => '號',
        ]);

        // 同名 type 4
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '子美',
            'c_alt_name_type_code' => '4',
            'c_source' => 0,
        ]);

        // 同名 type 5
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 2,
            'c_alt_name_chn' => '子美',
            'c_alt_name_type_code' => '5',
            'c_source' => 0,
        ]);

        // 把 type 4 改成 type 5 → 撞 PK
        $response = $this->actingAs($user)->patch(
            '/basicinformation/1/altnames/update?c_personid=1&c_alt_name_chn=' . urlencode('子美') . '&c_alt_name_type_code=4',
            [
                'c_sequence' => 1,
                'c_alt_name_chn' => '子美',
                'c_alt_name_type_code' => '5',
                'c_source' => 0,
            ]
        );

        $response->assertRedirect();
        $response->assertSessionHas('flash_notification');

        // 兩筆記錄都不應被修改
        $this->assertEquals(2, DB::table('ALTNAME_DATA')->where('c_personid', 1)->count());
        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1,
            'c_alt_name_chn' => '子美',
            'c_alt_name_type_code' => '4',
        ]);
    }

    /**
     * 新增別名時，正規化後若與既有記錄重複，應攔截
     */
    #[Test]
    public function testStoreBlocksBracketConflict(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'bracket-store-conflict@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1,
            'c_name_chn' => '測試人物',
        ]);

        // 已有半角括號記錄
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1,
            'c_sequence' => 1,
            'c_alt_name_chn' => '升卿(一作陞卿)',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        // 嘗試新增全角括號的同名記錄 → 正規化後 PK 相同
        $response = $this->actingAs($user)->post('/basicinformation/1/altnames', [
            'c_sequence' => 2,
            'c_alt_name_chn' => '升卿（一作陞卿）',
            'c_alt_name_type_code' => '1',
            'c_source' => 0,
        ]);

        $response->assertRedirect();
        // 不應新增成功
        $this->assertEquals(1, DB::table('ALTNAME_DATA')->where('c_personid', 1)->count());
    }
}
