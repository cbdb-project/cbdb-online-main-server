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
        // 创建测试用户
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
        // 创建测试用户
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

        // 验证删除操作不生成编辑链接
        $response->assertDontSee('/codes/TEXT_CODES/68942/edit', false);
        // 但应该显示 resource_id
        $response->assertSee('68942');
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
}
