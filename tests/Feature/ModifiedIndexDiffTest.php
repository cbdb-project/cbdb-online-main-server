<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModifiedIndexDiffTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

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

        $tempBase = sys_get_temp_dir() . '/laravel-modified-views-' . uniqid();
        $layoutsDir = $tempBase . '/layouts';
        $modifiedDir = $tempBase . '/modified';
        mkdir($layoutsDir, 0777, true);
        mkdir($modifiedDir, 0777, true);

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
            $modifiedDir . '/index.blade.php',
            <<<BLADE
@extends('layouts.dashboard')

@section('content')
    <h1>最近修改紀錄</h1>
@endsection
BLADE
        );

        $compiledPath = sys_get_temp_dir() . '/laravel-views-' . uniqid();
        mkdir($compiledPath, 0777, true);
        config()->set('view.paths', [$tempBase]);
        config()->set('view.compiled', $compiledPath);
        app('view')->setFinder(new \Illuminate\View\FileViewFinder(app('files'), [$tempBase]));
    }

    protected function tearDown(): void {
        Schema::dropIfExists('OFFICE_TYPE_TREE');
        Schema::dropIfExists('OFFICE_CODE_TYPE_REL');
        Schema::dropIfExists('OFFICE_CODES');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    protected function actingAsAdmin(): User {
        $user = User::create([
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
    public function test_modified_index_handles_missing_relation_records(): void {
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
            'crowdsourcing_status' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->get('/modified');
        $response->assertStatus(200)->assertSee('最近修改紀錄');
    }

    #[Test]
    public function test_modified_index_handles_empty_result_set(): void {
        $this->actingAsAdmin();

        $response = $this->get('/modified');
        $response->assertStatus(200)->assertSee('最近修改紀錄');
    }
}
