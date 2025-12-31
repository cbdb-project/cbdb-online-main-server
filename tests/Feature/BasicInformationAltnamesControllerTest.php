<?php

namespace Tests\Feature;

use App\Models\TextCode;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BasicInformationAltnamesControllerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

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
            $table->string('c_alt_name_chn');
            $table->string('c_alt_name_type_code');
            $table->integer('c_source')->nullable();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
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

        // 插入測試用的別名類型代碼
        DB::table('ALTNAME_CODES')->insert([
            'c_name_type_code' => 1,
            'c_name_type_desc_chn' => '測試別名類型',
        ]);
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('ALTNAME_CODES');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    /**
     * 測試當 ALTNAME_DATA 記錄不存在時返回 404
     */
    #[Test]
    public function testEditReturns404WhenAltnameNotFound() {
        // 創建用戶
        $user = User::create([
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
        $user = User::create([
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
        $user = User::create([
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
        $user = User::create([
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

    /**
     * 測試 c_source 為 0 時的處理
     */
    #[Test]
    public function testEditHandlesZeroCSource() {
        // 創建用戶
        $user = User::create([
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
}
