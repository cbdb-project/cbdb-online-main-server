<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationsIndexLinksTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('app.env', 'testing');
        $this->app['env'] = 'testing';
        config()->set('prometheus.enabled', false);
        config()->set('prometheus.storage_adapter', 'memory');

        // 使用 SQLite 内存数据库
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 创建测试所需的最小化表结构
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('confirmation_token')->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_admin')->default(0);
            $table->timestamps();
        });

        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid')->nullable();
            $table->tinyInteger('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->tinyInteger('crowdsourcing_status')->default(0);
            $table->timestamps();
        });

        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->timestamps();
        });

        Schema::create('POSTED_TO_OFFICE_DATA', function ($table) {
            $table->integer('c_office_id');
            $table->integer('c_posting_id');
            $table->integer('c_personid')->nullable();
            $table->integer('c_fy_intercalary')->nullable();
        });

        Schema::create('NIAN_HAO', function ($table) {
            $table->integer('c_nianhao_id')->primary();
            $table->string('c_nianhao_chn')->nullable();
        });

        // 複合主鍵的代碼表：resource_id 以 query-string 格式儲存，而 codes 編輯頁的 path id
        // 只認 '_._'。用來釘住「操作紀錄的查閱連結必須真的打得開」。
        Schema::create('MERGED_PERSON_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_merged_from_personid');
            $table->text('c_notes')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->primary(['c_personid', 'c_merged_from_personid']);
        });

        Schema::create('KIN_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id');
            $table->integer('c_kin_code');
        });

        Schema::create('ASSOC_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_code');
            $table->integer('c_assoc_id');
            $table->integer('c_kin_code');
            $table->integer('c_kin_id');
            $table->integer('c_assoc_kin_code');
            $table->integer('c_assoc_kin_id');
            $table->string('c_text_title')->nullable();
            $table->integer('c_assoc_first_year')->nullable();
        });

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
    }

    #[Test]
    public function test_operations_index_generates_links_for_non_person_code_resources() {
        // 创建测试用戶
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 创建不涉及人物的 TEXT_CODES 操作记录
        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE_FULL, // 整體覆寫
            'resource' => 'TEXT_CODES',
            'resource_id' => '68942',
            'resource_data' => json_encode(['c_textid' => 68942, 'c_text_name' => 'Test Text']),
            'resource_original' => json_encode(['c_textid' => 68942, 'c_text_name' => 'Old Text']),
            'crowdsourcing_status' => 0,
        ]);

        // 访问 operations 页面
        $response = $this->actingAs($user)->get('/operations');

        $response->assertStatus(200);

        // 验证页面包含正确的链接
        $response->assertSee('/codes/TEXT_CODES/68942/edit', false);
        $response->assertSee('查閱');
        $response->assertDontSee('>68942</a>', false);
        $response->assertSee('overflow-wrap: anywhere;', false);
        $response->assertSee('(本修改不涉及人物)');
    }

    #[Test]
    public function test_code_resource_link_generation_logic() {
        // 测试 codes 表配置是否正确
        $codeTableKeys = array_keys(config('codes.tables', []));
        $codeTables = array_map('strtoupper', $codeTableKeys);

        // 验证常见的代码表都在配置中
        $this->assertTrue(in_array('TEXT_CODES', $codeTables));
        $this->assertTrue(in_array('OFFICE_CODES', $codeTables));
        $this->assertTrue(in_array('OFFICE_CODE_TYPE_REL', $codeTables));
        $this->assertTrue(in_array('ADDR_CODES', $codeTables));
        $this->assertTrue(in_array('ALTNAME_DATA', $codeTables));

        // 验证链接生成逻辑
        $resourceId = '803819';
        $resource = 'OFFICE_CODES';
        $isCodeResource = in_array(strtoupper($resource), $codeTables);

        $this->assertTrue($isCodeResource);

        // 验证可以生成正确的路由
        $expectedLink = route('codes.edit', ['table_name' => $resource, 'id' => $resourceId], false);
        $this->assertEquals('/codes/OFFICE_CODES/803819/edit', $expectedLink);
    }

    #[Test]
    public function test_operations_index_normalizes_single_key_query_string_resource_id_for_code_links(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'nianhao-link@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'NIAN_HAO',
            'resource_id' => 'c_nianhao_id=464',
            'resource_data' => json_encode(['c_nianhao_id' => 464, 'c_nianhao_chn' => '測試年號']),
            'resource_original' => json_encode(['c_nianhao_id' => 464, 'c_nianhao_chn' => '舊年號']),
            'crowdsourcing_status' => 0,
        ]);

        \DB::table('NIAN_HAO')->insert([
            'c_nianhao_id' => 464,
            'c_nianhao_chn' => '測試年號',
        ]);

        $response = $this->actingAs($user)->get('/operations');

        $response->assertStatus(200);
        $response->assertSee('/codes/NIAN_HAO/464/edit', false);
        $response->assertDontSee('/codes/NIAN_HAO/c_nianhao_id=464/edit', false);
    }

    /**
     * 複合主鍵代碼表（MERGED_PERSON_DATA）的查閱連結必須真的打得開。
     *
     * mutation handler 一律把 resource_id 存成 query-string 格式
     * （c_personid=108625&c_merged_from_personid=404794，見 CompositePrimaryKey::buildStoredResourceId），
     * 而 codes 編輯頁的 path id 歷來只認 '_._'。單主鍵的表已有
     * normalizeSingleKeyResourceIdForCodeRoute() 兜住，複合主鍵則整段字串被當成第一個主鍵欄的值，
     * 於是點「查閱」只會得到「找不到該筆資料」。
     */
    #[Test]
    public function test_composite_key_code_resource_view_link_actually_opens_the_row(): void {
        $user = User::forceCreate([
            'name' => 'Hongsu Wang',
            'email' => 'merged-person-link@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        \DB::table('BIOG_MAIN')->insert([
            'c_personid' => 108625, 'c_name_chn' => '曾源友', 'c_name' => 'Zeng Yuanyou',
        ]);
        // 兩列共用同一個 c_personid，且「不是目標」的那一列先插入：只有真的用上第二段主鍵才會
        // 開出 404794。否則（例如只以 c_personid 查詢）會撈到 500001，測試就會當場失敗而不是
        // 靜默地變成一個什麼都沒驗到的 200。
        \DB::table('MERGED_PERSON_DATA')->insert([
            ['c_personid' => 108625, 'c_merged_from_personid' => 500001],
            ['c_personid' => 108625, 'c_merged_from_personid' => 404794],
        ]);

        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 108625,
            'op_type' => Operation::TYPE_CREATE,
            'resource' => 'MERGED_PERSON_DATA',
            'resource_id' => 'c_personid=108625&c_merged_from_personid=404794',
            'resource_data' => json_encode(['c_personid' => 108625, 'c_merged_from_personid' => 404794]),
            'crowdsourcing_status' => 0,
        ]);

        $link = null;
        $this->actingAs($user)
            ->get('/app/operations')
            ->assertOk()
            ->assertInertia(function ($page) use (&$link) {
                $link = $page->toArray()['props']['lists'][0]['resource_link'];
            });

        // 連結本身就是 bug 的一半：resource_id 原樣進了 path（單主鍵才會被正規化）。
        $this->assertSame(
            '/codes/MERGED_PERSON_DATA/c_personid=108625&c_merged_from_personid=404794/edit',
            $link
        );

        // 另一半：那個 path id 必須真的能開出對應的列，而不是被重導回上一頁並
        // flash「找不到該筆資料」。同時確認用上了第二段主鍵（開出 404794、不是 500001）。
        $this->actingAs($user)->get($link)
            ->assertOk()
            ->assertSessionMissing('flash_notification')
            ->assertSee('404794')
            ->assertDontSee('500001');
    }

    #[Test]
    public function test_person_specific_link_priority_logic() {
        // 测试链接优先级逻辑：人物特定链接优先于代码表链接

        $codeTableKeys = array_keys(config('codes.tables', []));
        $codeTables = array_map('strtoupper', $codeTableKeys);

        // 模拟一个涉及人物的 ALTNAME_DATA 操作
        $resource = 'ALTNAME_DATA';
        $resourceId = '115470-0-馬可·波羅-17';
        $personId = 115470;
        $opType = 3;

        $isCodeResource = in_array(strtoupper($resource), $codeTables);
        $this->assertTrue($isCodeResource, 'ALTNAME_DATA should be a code resource');

        // 模拟视图逻辑
        $hasPersonLink = $personId && $personId != 0;
        $resourceSpecificLink = null;

        if ($hasPersonLink) {
            $resourceSpecificLink = "/basicinformation/{$personId}/altnames/{$resourceId}/edit";
        }

        $resourceLink = null;
        // 优先使用人物相关的特定资源链接
        if ($hasPersonLink && $resourceSpecificLink) {
            $resourceLink = $resourceSpecificLink;
        }
        // 对于代码表资源，如果没有特定资源链接，则使用 codes 路由
        elseif ($isCodeResource && $opType != 4) {
            $resourceLink = route('codes.edit', ['table_name' => $resource, 'id' => $resourceId], false);
        }

        // 验证应该使用人物特定链接
        $this->assertEquals('/basicinformation/115470/altnames/115470-0-馬可·波羅-17/edit', $resourceLink);
        $this->assertStringNotContainsString('/codes/ALTNAME_DATA/', $resourceLink);
    }

    #[Test]
    public function test_operations_index_does_not_generate_links_for_deleted_operations() {
        // 创建测试用戶
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        // 创建删除类型的操作记录 (op_type = 4)
        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => 4, // 刪除
            'resource' => 'TEXT_CODES',
            'resource_id' => '68942',
            'resource_data' => json_encode(['c_textid' => 68942, 'c_text_name' => 'Deleted Text']),
            'resource_original' => null,
            'crowdsourcing_status' => 0,
        ]);

        // 访问 operations 页面
        $response = $this->actingAs($user)->get('/operations');

        $response->assertStatus(200);

        // 验证删除操作不生成編輯链接
        $response->assertDontSee('/codes/TEXT_CODES/68942/edit', false);
        $response->assertSee('無資源頁面');
        // 但应该显示 resource_id
        $response->assertSee('68942');
    }

    #[Test]
    public function test_operations_index_hides_editor_full_name_for_guests(): void {
        $user = User::forceCreate([
            'name' => 'Hidden Editor',
            'email' => 'guest-hidden@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'guest-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'TEXT_CODES',
            'resource_id' => '68942',
            'resource_data' => json_encode(['c_textid' => 68942, 'c_text_name' => 'Guest Visible']),
            'resource_original' => json_encode(['c_textid' => 68942, 'c_text_name' => 'Old Text']),
            'crowdsourcing_status' => 0,
        ]);

        $response = $this->get('/operations');

        $response->assertStatus(200);
        $response->assertSee('User ' . $user->id);
        $response->assertDontSee('Hidden Editor');
    }

    #[Test]
    public function test_operations_index_shows_multiple_audit_records_for_one_operation() {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'audit@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => 'c_office_id=10&c_posting_id=1',
            'resource_data' => json_encode(['c_office_id' => 10, 'c_posting_id' => 1, 'c_fy_intercalary' => 1]),
            'resource_original' => json_encode(['c_office_id' => 10, 'c_posting_id' => 1, 'c_fy_intercalary' => 0]),
            'crowdsourcing_status' => 0,
        ]);

        \Illuminate\Support\Facades\DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_office_id' => 10,
            'c_posting_id' => 1,
            'c_personid' => 123,
            'c_fy_intercalary' => 1,
        ]);

        $operationId = (string) $operation->id;
        $now = now()->format('Y-m-d H:i:s');

        \Illuminate\Support\Facades\DB::table('audit_log')->insert([
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'POSTED_TO_OFFICE_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => $operationId,
                'row_pk' => json_encode(['c_office_id' => 10, 'c_posting_id' => 1], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_office_id=10&c_posting_id=1',
                'old_data' => json_encode(['c_fy_intercalary' => 0], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_fy_intercalary' => 1], JSON_UNESCAPED_UNICODE),
            ],
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'POSTED_TO_ADDR_DATA',
                'operation' => 'INSERT',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => $operationId,
                'row_pk' => json_encode(['c_personid' => 123, 'c_posting_id' => 1, 'c_office_id' => 10, 'c_addr_id' => 99], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=123&c_posting_id=1&c_office_id=10&c_addr_id=99',
                'old_data' => null,
                'new_data' => json_encode(['c_personid' => 123, 'c_posting_id' => 1, 'c_office_id' => 10, 'c_addr_id' => 99], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $response = $this->actingAs($user)->get('/operations');

        $response->assertStatus(200);
        $response->assertSee('審計記錄（2 筆）');
        $response->assertSee('POSTED_TO_OFFICE_DATA');
        $response->assertSee('POSTED_TO_ADDR_DATA');
    }

    #[Test]
    public function test_operations_index_renders_composite_rows_for_multi_person_operation(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'multi-person@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        \Illuminate\Support\Facades\DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 101, 'c_name_chn' => '甲', 'c_name' => 'Jia'],
            ['c_personid' => 202, 'c_name_chn' => '乙', 'c_name' => 'Yi'],
        ]);

        \Illuminate\Support\Facades\DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_office_id' => 10,
            'c_posting_id' => 1,
            'c_personid' => 101,
            'c_fy_intercalary' => 0,
        ]);

        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 101,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'POSTED_TO_OFFICE_DATA',
            'resource_id' => 'c_office_id=10&c_posting_id=1',
            'resource_data' => json_encode(['c_office_id' => 10, 'c_posting_id' => 1, 'c_fy_intercalary' => 1], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode(['c_office_id' => 10, 'c_posting_id' => 1, 'c_fy_intercalary' => 0], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ]);

        $now = now()->format('Y-m-d H:i:s');
        \Illuminate\Support\Facades\DB::table('audit_log')->insert([
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'KIN_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=101&c_kin_id=202&c_kin_code=1',
                'old_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 2], JSON_UNESCAPED_UNICODE),
            ],
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'KIN_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=202&c_kin_id=101&c_kin_code=3',
                'old_data' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 4], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $response = $this->actingAs($user)->get('/operations');

        $response->assertStatus(200);
        $response->assertSee('/basicinformation/101/edit', false);
        $response->assertSee('/basicinformation/202/edit', false);
        $response->assertSee('rowspan="2"', false);
        $response->assertSee('主操作');
        $response->assertSee('連動');
    }

    #[Test]
    public function test_operations_index_renders_person_specific_resource_links_for_kin_data(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'kin-links@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        \Illuminate\Support\Facades\DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 101, 'c_name_chn' => '甲', 'c_name' => 'Jia'],
            ['c_personid' => 202, 'c_name_chn' => '乙', 'c_name' => 'Yi'],
        ]);

        \Illuminate\Support\Facades\DB::table('KIN_DATA')->insert([
            ['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1],
            ['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3],
        ]);

        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 101,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'KIN_DATA',
            'resource_id' => 'c_personid=101&c_kin_id=202&c_kin_code=1',
            'resource_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 2], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ]);

        $now = now()->format('Y-m-d H:i:s');
        \Illuminate\Support\Facades\DB::table('audit_log')->insert([
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'KIN_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=101&c_kin_id=202&c_kin_code=1',
                'old_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 2], JSON_UNESCAPED_UNICODE),
            ],
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'KIN_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=202&c_kin_id=101&c_kin_code=3',
                'old_data' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 4], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $response = $this->actingAs($user)->get('/operations');

        $response->assertStatus(200);
        // flag-aware（測試環境 kinship editor flag=new）：operations 索引導向 React /app edit-v2（帶完整 PK query）。
        $response->assertSee('/app/basicinformation/101/kinship/edit-v2?c_personid=101&amp;c_kin_id=202&amp;c_kin_code=1', false);
        $response->assertSee('/app/basicinformation/202/kinship/edit-v2?c_personid=202&amp;c_kin_id=101&amp;c_kin_code=3', false);
        $response->assertSee('c_personid：101<br', false);
        $response->assertSee('c_personid：101');
        $response->assertSee('c_kin_code：3');
    }

    #[Test]
    public function test_operations_index_renders_person_specific_resource_links_for_assoc_data(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'assoc-links@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        \Illuminate\Support\Facades\DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 101, 'c_name_chn' => '甲', 'c_name' => 'Jia'],
            ['c_personid' => 202, 'c_name_chn' => '乙', 'c_name' => 'Yi'],
        ]);

        \Illuminate\Support\Facades\DB::table('ASSOC_DATA')->insert([
            [
                'c_personid' => 101,
                'c_assoc_code' => 301,
                'c_assoc_id' => 202,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_kin_code' => 0,
                'c_assoc_kin_id' => 0,
                'c_text_title' => '測試文獻',
                'c_assoc_first_year' => 1100,
            ],
            [
                'c_personid' => 202,
                'c_assoc_code' => 302,
                'c_assoc_id' => 101,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_kin_code' => 0,
                'c_assoc_kin_id' => 0,
                'c_text_title' => '測試文獻',
                'c_assoc_first_year' => 1100,
            ],
        ]);

        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 101,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ASSOC_DATA',
            'resource_id' => 'c_personid=101&c_assoc_code=301&c_assoc_id=202&c_kin_code=0&c_kin_id=0&c_assoc_kin_code=0&c_assoc_kin_id=0&c_text_title=%E6%B8%AC%E8%A9%A6%E6%96%87%E7%8D%BB&c_assoc_first_year=1100',
            'resource_data' => json_encode([
                'c_personid' => 101,
                'c_assoc_code' => 301,
                'c_assoc_id' => 202,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_kin_code' => 0,
                'c_assoc_kin_id' => 0,
                'c_text_title' => '測試文獻',
                'c_assoc_first_year' => 1101,
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 101,
                'c_assoc_code' => 301,
                'c_assoc_id' => 202,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_kin_code' => 0,
                'c_assoc_kin_id' => 0,
                'c_text_title' => '測試文獻',
                'c_assoc_first_year' => 1100,
            ], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ]);

        $now = now()->format('Y-m-d H:i:s');
        \Illuminate\Support\Facades\DB::table('audit_log')->insert([
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'ASSOC_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode([
                    'c_personid' => 101,
                    'c_assoc_code' => 301,
                    'c_assoc_id' => 202,
                    'c_kin_code' => 0,
                    'c_kin_id' => 0,
                    'c_assoc_kin_code' => 0,
                    'c_assoc_kin_id' => 0,
                    'c_text_title' => '測試文獻',
                    'c_assoc_first_year' => 1100,
                ], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=101&c_assoc_code=301&c_assoc_id=202&c_kin_code=0&c_kin_id=0&c_assoc_kin_code=0&c_assoc_kin_id=0&c_text_title=%E6%B8%AC%E8%A9%A6%E6%96%87%E7%8D%BB&c_assoc_first_year=1100',
                'old_data' => json_encode(['c_assoc_first_year' => 1100], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_assoc_first_year' => 1101], JSON_UNESCAPED_UNICODE),
            ],
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'ASSOC_DATA',
                'operation' => 'UPDATE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode([
                    'c_personid' => 202,
                    'c_assoc_code' => 302,
                    'c_assoc_id' => 101,
                    'c_kin_code' => 0,
                    'c_kin_id' => 0,
                    'c_assoc_kin_code' => 0,
                    'c_assoc_kin_id' => 0,
                    'c_text_title' => '測試文獻',
                    'c_assoc_first_year' => 1100,
                ], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=202&c_assoc_code=302&c_assoc_id=101&c_kin_code=0&c_kin_id=0&c_assoc_kin_code=0&c_assoc_kin_id=0&c_text_title=%E6%B8%AC%E8%A9%A6%E6%96%87%E7%8D%BB&c_assoc_first_year=1100',
                'old_data' => json_encode(['c_assoc_first_year' => 1100], JSON_UNESCAPED_UNICODE),
                'new_data' => json_encode(['c_assoc_first_year' => 1101], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $response = $this->actingAs($user)->get('/operations');

        $response->assertStatus(200);
        // flag-aware（測試環境 assoc editor flag=new）：operations 索引導向 React /app edit-v2（帶完整 PK query）。
        $response->assertSee('/app/basicinformation/101/assoc/edit-v2?c_personid=101&amp;c_assoc_code=301&amp;c_assoc_id=202', false);
        $response->assertSee('/app/basicinformation/202/assoc/edit-v2?c_personid=202&amp;c_assoc_code=302&amp;c_assoc_id=101', false);
        $response->assertSee('c_assoc_code：301');
        $response->assertSee('c_assoc_code：302');
    }

    #[Test]
    public function test_operations_index_does_not_render_person_specific_resource_links_for_deleted_kin_data(): void {
        $user = User::forceCreate([
            'name' => 'Test User',
            'email' => 'deleted-kin-links@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'test-token',
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        \Illuminate\Support\Facades\DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 101, 'c_name_chn' => '甲', 'c_name' => 'Jia'],
            ['c_personid' => 202, 'c_name_chn' => '乙', 'c_name' => 'Yi'],
        ]);

        $operation = Operation::create([
            'user_id' => $user->id,
            'c_personid' => 101,
            'op_type' => Operation::TYPE_DELETE,
            'resource' => 'KIN_DATA',
            'resource_id' => 'c_personid=101&c_kin_id=202&c_kin_code=1',
            'resource_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
            'resource_original' => null,
            'crowdsourcing_status' => 0,
        ]);

        $now = now()->format('Y-m-d H:i:s');
        \Illuminate\Support\Facades\DB::table('audit_log')->insert([
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'KIN_DATA',
                'operation' => 'DELETE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=101&c_kin_id=202&c_kin_code=1',
                'old_data' => json_encode(['c_personid' => 101, 'c_kin_id' => 202, 'c_kin_code' => 1], JSON_UNESCAPED_UNICODE),
                'new_data' => null,
            ],
            [
                'occurred_at' => $now,
                'created_at' => $now,
                'table_name' => 'KIN_DATA',
                'operation' => 'DELETE',
                'actor_type' => 'user',
                'actor_id' => (string) $user->id,
                'operation_id' => (string) $operation->id,
                'row_pk' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3], JSON_UNESCAPED_UNICODE),
                'row_pk_text' => 'c_personid=202&c_kin_id=101&c_kin_code=3',
                'old_data' => json_encode(['c_personid' => 202, 'c_kin_id' => 101, 'c_kin_code' => 3], JSON_UNESCAPED_UNICODE),
                'new_data' => null,
            ],
        ]);

        $response = $this->actingAs($user)->get('/operations');

        $response->assertStatus(200);
        // 刪除操作不應產生任何編輯連結（含 flag-aware 的 /app edit-v2）。
        $response->assertDontSee('/app/basicinformation/101/kinship/edit-v2?c_personid=101&amp;c_kin_id=202&amp;c_kin_code=1', false);
        $response->assertDontSee('/app/basicinformation/202/kinship/edit-v2?c_personid=202&amp;c_kin_id=101&amp;c_kin_code=3', false);
        $response->assertSee('無資源頁面');
    }
}
