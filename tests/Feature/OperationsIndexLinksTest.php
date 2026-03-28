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
        $user = User::create([
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
        $user = User::create([
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
        $user = User::create([
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
        $user = User::create([
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
        $user = User::create([
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
        $user = User::create([
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
        $user = User::create([
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
        $response->assertSee('/basicinformation/101/kinship/edit?c_personid=101&amp;c_kin_id=202&amp;c_kin_code=1', false);
        $response->assertSee('/basicinformation/202/kinship/edit?c_personid=202&amp;c_kin_id=101&amp;c_kin_code=3', false);
        $response->assertSee('c_personid：101<br', false);
        $response->assertSee('c_personid：101');
        $response->assertSee('c_kin_code：3');
    }

    #[Test]
    public function test_operations_index_renders_person_specific_resource_links_for_assoc_data(): void {
        $user = User::create([
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
        $response->assertSee('/basicinformation/101/assoc/edit?c_personid=101&amp;c_assoc_code=301&amp;c_assoc_id=202', false);
        $response->assertSee('/basicinformation/202/assoc/edit?c_personid=202&amp;c_assoc_code=302&amp;c_assoc_id=101', false);
        $response->assertSee('c_assoc_code：301');
        $response->assertSee('c_assoc_code：302');
    }

    #[Test]
    public function test_operations_index_does_not_render_person_specific_resource_links_for_deleted_kin_data(): void {
        $user = User::create([
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
        $response->assertDontSee('/basicinformation/101/kinship/edit?c_personid=101&amp;c_kin_id=202&amp;c_kin_code=1', false);
        $response->assertDontSee('/basicinformation/202/kinship/edit?c_personid=202&amp;c_kin_id=101&amp;c_kin_code=3', false);
        $response->assertSee('無資源頁面');
    }
}
