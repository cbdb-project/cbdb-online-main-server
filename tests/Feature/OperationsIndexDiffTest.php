<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OperationsIndexDiffTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        $tempBase = sys_get_temp_dir() . '/laravel-test-views-' . uniqid();
        $layoutsDir = $tempBase . '/layouts';
        $operationsDir = $tempBase . '/operations';
        if (!is_dir($layoutsDir)) {
            mkdir($layoutsDir, 0777, true);
        }
        if (!is_dir($operationsDir)) {
            mkdir($operationsDir, 0777, true);
        }

        file_put_contents(
            $layoutsDir . '/dashboard.blade.php',
            <<<BLADE
<!doctype html>
<html lang="zh-Hant">
<head><meta charset="utf-8"><title>Test Layout</title></head>
<body>
@yield('content')
</body>
</html>
BLADE
        );

        file_put_contents(
            $operationsDir . '/index.blade.php',
            <<<BLADE
@extends('layouts.dashboard')

@section('content')
    <h1>最近編輯列表</h1>
@endsection
BLADE
        );

        $compiledPath = sys_get_temp_dir() . '/laravel-views-' . uniqid();
        mkdir($compiledPath, 0777, true);

        config()->set('view.paths', [$tempBase]);
        config()->set('view.compiled', $compiledPath);
        app('view')->setFinder(new \Illuminate\View\FileViewFinder(app('files'), [$tempBase]));

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid')->default(0);
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->longText('resource_data');
            $table->longText('resource_original')->nullable();
            $table->timestamps();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->smallInteger('rate')->default(0);
        });

        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->string('c_office_chn')->nullable();
        });

        Schema::create('OFFICE_CODE_TYPE_REL', function (Blueprint $table) {
            $table->integer('c_office_id');
            $table->string('c_office_tree_id');
        });

        Schema::create('OFFICE_TYPE_TREE', function (Blueprint $table) {
            $table->string('c_office_type_node_id')->primary();
            $table->string('c_office_type_desc_chn')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('OFFICE_TYPE_TREE');
        Schema::dropIfExists('OFFICE_CODE_TYPE_REL');
        Schema::dropIfExists('OFFICE_CODES');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    /**
     * 建立 ALTNAME_DATA 測試表並插入測試資料
     */
    protected function createAltnameTable(): void {
        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->nullable();
            $table->integer('c_alt_name_type_code');
            $table->string('c_alt_name')->nullable();
            $table->string('c_alt_name_chn')->nullable();
        });
    }

    protected function actingAsAdmin(): User {
        $user = User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
            'is_active' => 1,
            'is_admin' => 1,
        ]);

        $this->actingAs($user);

        return $user;
    }

    #[Test]
    public function test_operations_index_handles_missing_relation_records(): void {
        $this->actingAsAdmin();

        Operation::create([
            'user_id' => 1,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_CREATE,
            'resource' => 'OFFICE_CODE_TYPE_REL',
            'resource_id' => '803818-200501',
            'resource_data' => json_encode([
                'c_office_id' => 803818,
                'c_office_tree_id' => '200501',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->get('/operations');
        $response->assertStatus(200)->assertSee('最近編輯列表');
    }

    #[Test]
    public function test_operations_index_handles_empty_result_set(): void {
        $this->actingAsAdmin();

        $response = $this->get('/operations');
        $response->assertStatus(200)->assertSee('最近編輯列表');
    }

    // -------------------------------------------------------
    // ALTNAME 3-key 舊格式分支差異比對測試 (#834)
    // -------------------------------------------------------

    #[Test]
    public function test_operations_index_altname_3key_dash_format_diff(): void {
        // 驗證 index() 中 ALTNAME_DATA 舊格式 switch/case 的 3-key dash 路徑
        // 能正確查到 DB 資料列並計算差異比對
        $this->actingAsAdmin();
        $this->createAltnameTable();

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 123,
            'c_sequence' => 2,
            'c_alt_name_type_code' => 10,
            'c_alt_name' => 'Zi Jing',
            'c_alt_name_chn' => '張三',
        ]);

        // 3-key dash format: c_personid-c_alt_name_chn-c_alt_name_type_code
        Operation::create([
            'user_id' => 1,
            'c_personid' => 123,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '123-張三-10',
            'resource_data' => json_encode([
                'c_personid' => 123,
                'c_sequence' => 2,
                'c_alt_name_chn' => '張三',
                'c_alt_name_type_code' => 10,
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 123,
                'c_sequence' => 1,
                'c_alt_name_chn' => '張三',
                'c_alt_name_type_code' => 10,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->get('/operations');
        $response->assertStatus(200);

        // 驗證 controller 正確查到 DB 資料並計算差異
        $lists = $response->viewData('lists');
        $this->assertNotEmpty($lists);
        $diff = $lists[0]->getAttribute('resource_diff');
        $this->assertNotNull($diff, '3-key dash 格式應能查到 DB 資料並產生差異比對');
    }

    #[Test]
    public function test_operations_index_altname_3key_dot_format_diff(): void {
        // 驗證 index() 中 ALTNAME_DATA 舊格式 switch/case 的 3-key _._  路徑
        $this->actingAsAdmin();
        $this->createAltnameTable();

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 456,
            'c_sequence' => 0,
            'c_alt_name_type_code' => 5,
            'c_alt_name' => 'Hao',
            'c_alt_name_chn' => '測試',
        ]);

        // 3-key _._  format: c_personid_._c_alt_name_chn_._c_alt_name_type_code
        Operation::create([
            'user_id' => 1,
            'c_personid' => 456,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '456_._測試_._5',
            'resource_data' => json_encode([
                'c_personid' => 456,
                'c_sequence' => 0,
                'c_alt_name_chn' => '測試',
                'c_alt_name_type_code' => 5,
                'c_alt_name' => 'Hao',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 456,
                'c_sequence' => 0,
                'c_alt_name_chn' => '測試',
                'c_alt_name_type_code' => 5,
                'c_alt_name' => 'Old',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->get('/operations');
        $response->assertStatus(200);

        $lists = $response->viewData('lists');
        $this->assertNotEmpty($lists);
        $diff = $lists[0]->getAttribute('resource_diff');
        $this->assertNotNull($diff, '3-key _._  格式應能查到 DB 資料並產生差異比對');
    }

    #[Test]
    public function test_operations_index_altname_3key_dash_with_encoded_minus_diff(): void {
        // 驗證 c_alt_name_chn 含負號時，3-key dash 格式仍能正確比對
        $this->actingAsAdmin();
        $this->createAltnameTable();

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 789,
            'c_sequence' => 1,
            'c_alt_name_type_code' => 3,
            'c_alt_name' => 'Test',
            'c_alt_name_chn' => '張-三',
        ]);

        // 「張-三」在 dash 格式中編碼為「張(minus)三」
        Operation::create([
            'user_id' => 1,
            'c_personid' => 789,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '789-張(minus)三-3',
            'resource_data' => json_encode([
                'c_personid' => 789,
                'c_sequence' => 1,
                'c_alt_name_chn' => '張-三',
                'c_alt_name_type_code' => 3,
                'c_alt_name' => 'Test',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 789,
                'c_sequence' => 1,
                'c_alt_name_chn' => '張-三',
                'c_alt_name_type_code' => 3,
                'c_alt_name' => 'Old',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->get('/operations');
        $response->assertStatus(200);

        $lists = $response->viewData('lists');
        $this->assertNotEmpty($lists);
        $diff = $lists[0]->getAttribute('resource_diff');
        $this->assertNotNull($diff, '3-key dash 含 (minus) 編碼應能查到 DB 資料並產生差異比對');
    }

    #[Test]
    public function test_operations_index_altname_4key_legacy_still_works(): void {
        // 確認既有 4-key 舊格式不受 3-key 遷移影響
        $this->actingAsAdmin();
        $this->createAltnameTable();

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 100,
            'c_sequence' => 1,
            'c_alt_name_type_code' => 10,
            'c_alt_name' => 'Ming',
            'c_alt_name_chn' => '李四',
        ]);

        // 4-key dash format: c_personid-c_sequence-c_alt_name_chn-c_alt_name_type_code
        Operation::create([
            'user_id' => 1,
            'c_personid' => 100,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => '100-1-李四-10',
            'resource_data' => json_encode([
                'c_personid' => 100,
                'c_sequence' => 1,
                'c_alt_name_chn' => '李四',
                'c_alt_name_type_code' => 10,
                'c_alt_name' => 'Ming',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([
                'c_personid' => 100,
                'c_sequence' => 1,
                'c_alt_name_chn' => '李四',
                'c_alt_name_type_code' => 10,
                'c_alt_name' => 'Old',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->get('/operations');
        $response->assertStatus(200);

        $lists = $response->viewData('lists');
        $this->assertNotEmpty($lists);
        $diff = $lists[0]->getAttribute('resource_diff');
        $this->assertNotNull($diff, '既有 4-key 格式應繼續正常運作');
    }
}
