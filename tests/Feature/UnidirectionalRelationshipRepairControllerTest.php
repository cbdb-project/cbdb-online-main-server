<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnidirectionalRelationshipRepairControllerTest extends TestCase {
    protected $adminUser;
    protected $regularUser;

    protected function setUp(): void {
        parent::setUp();

        // 使用 in-memory SQLite 数据库
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true, // 启用外键约束
        ]);

        // 设置缓存和 session 为数组驱动
        config(['cache.default' => 'array']);
        config(['session.driver' => 'array']);

        // 重新连接以确保使用 SQLite
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        // 验证我们正在使用 SQLite（安全检查）
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            throw new \RuntimeException("测试必须使用 SQLite 数据库，当前使用的是: {$driver}");
        }

        // 启用 SQLite 外键约束
        DB::statement('PRAGMA foreign_keys = ON');

        // 创建测试所需的表结构
        $this->createMinimalTables();

        // 創建管理員用戶（使用 factory）
        $this->adminUser = factory(User::class)->create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'is_admin' => 1,
            'is_active' => 1,
            'confirmation_token' => 'test_token_admin_' . time(),
        ]);

        // 創建普通用戶（使用 factory）
        $this->regularUser = factory(User::class)->create([
            'name' => 'Regular User',
            'email' => 'regular@test.com',
            'is_admin' => 0,
            'is_active' => 1,
            'confirmation_token' => 'test_token_regular_' . time(),
        ]);
    }

    protected function tearDown(): void {
        // 按照依赖关系顺序删除表（先删除有外键约束的表）
        Schema::dropIfExists('operations');
        Schema::dropIfExists('ASSOC_DATA');
        Schema::dropIfExists('KIN_DATA');
        Schema::dropIfExists('ASSOC_CODES');
        Schema::dropIfExists('KINSHIP_CODES');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function createMinimalTables(): void {
        // 创建 users 表
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->json('settings')->nullable();
            $table->string('avatar')->default('avatar0.png');
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_admin')->default(0);
            $table->smallInteger('is_active')->default(0);
            $table->string('c_user_privilege')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // 创建 BIOG_MAIN 表（简化版）
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
            $table->timestamps();
        });

        // 创建 KINSHIP_CODES 表（用于外键约束）
        Schema::create('KINSHIP_CODES', function (Blueprint $table) {
            $table->integer('c_kincode')->primary();
            $table->string('c_kin_rel_name')->nullable();
            $table->string('c_kin_rel_name_chn')->nullable();
            $table->integer('c_kin_pair1')->nullable();
            $table->integer('c_kin_pair2')->nullable();
        });

        // 插入测试用的关系代码
        DB::table('KINSHIP_CODES')->insert([
            ['c_kincode' => 2, 'c_kin_rel_name' => 'Father', 'c_kin_rel_name_chn' => '父'],
            ['c_kincode' => 3, 'c_kin_rel_name' => 'Mother', 'c_kin_rel_name_chn' => '母'],
            ['c_kincode' => 301, 'c_kin_rel_name' => 'Son', 'c_kin_rel_name_chn' => '子'],
            ['c_kincode' => 303, 'c_kin_rel_name' => 'Daughter', 'c_kin_rel_name_chn' => '女'],
        ]);

        // 创建 ASSOC_CODES 表（用于外键约束）
        Schema::create('ASSOC_CODES', function (Blueprint $table) {
            $table->integer('c_assoc_code')->primary();
            $table->string('c_assoc_desc')->nullable();
            $table->string('c_assoc_desc_chn')->nullable();
        });

        // 插入测试用的社会关系代码
        DB::table('ASSOC_CODES')->insert([
            ['c_assoc_code' => 4, 'c_assoc_desc' => 'Teacher', 'c_assoc_desc_chn' => '師'],
            ['c_assoc_code' => 5, 'c_assoc_desc' => 'Student', 'c_assoc_desc_chn' => '弟子'],
            ['c_assoc_code' => 7, 'c_assoc_desc' => 'Friend', 'c_assoc_desc_chn' => '友'],
            ['c_assoc_code' => 8, 'c_assoc_desc' => 'Friend', 'c_assoc_desc_chn' => '友'],
        ]);

        // 创建 KIN_DATA 表（简化版，包含外键约束）
        Schema::create('KIN_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id');
            $table->integer('c_kin_code');
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_autogen_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
            $table->primary(['c_kin_code', 'c_kin_id', 'c_personid']);

            // 添加外键约束
            $table->foreign('c_kin_code')->references('c_kincode')->on('KINSHIP_CODES');
            $table->foreign('c_personid')->references('c_personid')->on('BIOG_MAIN');
            $table->foreign('c_kin_id')->references('c_personid')->on('BIOG_MAIN');
        });

        // 创建 operations 表（用於操作紀錄）
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid');
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->text('resource_data');
            $table->text('resource_original')->nullable();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->timestamps();
        });

        // 创建 ASSOC_DATA 表（简化版，包含外键约束）
        Schema::create('ASSOC_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_id');
            $table->integer('c_assoc_code');
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_assoc_kin_code')->default(0);
            $table->integer('c_assoc_kin_id')->default(0);
            $table->string('c_text_title')->default('');
            $table->integer('c_assoc_first_year')->default(-9999);
            $table->integer('c_assoc_last_year')->nullable();
            $table->integer('c_assoc_fy_nh_code')->nullable();
            $table->integer('c_assoc_fy_nh_year')->nullable();
            $table->integer('c_assoc_fy_range')->nullable();
            $table->integer('c_assoc_ly_nh_code')->nullable();
            $table->integer('c_assoc_ly_nh_year')->nullable();
            $table->integer('c_assoc_ly_range')->nullable();
            $table->integer('c_assoc_fy_intercalary')->nullable();
            $table->integer('c_assoc_fy_month')->nullable();
            $table->integer('c_assoc_fy_day')->nullable();
            $table->integer('c_assoc_fy_day_gz')->nullable();
            $table->integer('c_assoc_ly_intercalary')->nullable();
            $table->integer('c_assoc_ly_month')->nullable();
            $table->integer('c_assoc_ly_day')->nullable();
            $table->integer('c_assoc_ly_day_gz')->nullable();
            $table->integer('c_addr_id')->nullable();
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->integer('c_litgenre_code')->nullable();
            $table->integer('c_occasion_code')->nullable();
            $table->integer('c_topic_code')->nullable();
            $table->integer('c_assoc_claimer_id')->nullable();
            $table->integer('c_tertiary_personid')->nullable();
            $table->text('c_tertiary_type_notes')->nullable();
            $table->integer('c_assoc_count')->default(1);
            $table->integer('c_sequence')->default(0);
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();

            // 添加外键约束
            $table->foreign('c_assoc_code')->references('c_assoc_code')->on('ASSOC_CODES');
            $table->foreign('c_personid')->references('c_personid')->on('BIOG_MAIN');
            $table->foreign('c_assoc_id')->references('c_personid')->on('BIOG_MAIN');
        });
    }

    #[Test]
    public function guest_cannot_access_repair_page() {
        $response = $this->get(route('admin.unidirectional-relationship-repair'));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function regular_user_cannot_access_repair_page() {
        $response = $this->actingAs($this->regularUser)
            ->get(route('admin.unidirectional-relationship-repair'));

        $response->assertStatus(403);
    }

    #[Test]
    public function admin_can_access_repair_page() {
        $response = $this->actingAs($this->adminUser)
            ->get(route('admin.unidirectional-relationship-repair'));

        $response->assertStatus(200);
        $response->assertSee('單向關係修復');
        $response->assertSee('親屬關係修復');
        $response->assertSee('社會關係修復');
    }

    #[Test]
    public function kinship_repair_validates_required_fields() {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['c_personid', 'c_kin_id', 'c_kin_code', 'new_c_kin_code']);
    }

    #[Test]
    public function kinship_repair_validates_entity_existence() {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => 99999,
                'c_kin_id' => 1,
                'c_kin_code' => 2,
                'new_c_kin_code' => 301,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['dependencies']);
    }

    #[Test]
    public function kinship_repair_returns_error_when_record_not_found() {
        // 創建測試人物
        $person1 = $this->createTestPerson();
        $person2 = $this->createTestPerson();

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
                'new_c_kin_code' => 301,
            ]);

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => '未找到符合條件的親屬關係記錄。',
        ]);
    }

    #[Test]
    public function kinship_repair_returns_error_when_reverse_already_exists() {
        // 創建測試人物
        $person1 = $this->createTestPerson();
        $person2 = $this->createTestPerson();

        // 創建原始關係：person1 是 person2 的親屬（關係代碼 2）
        DB::table('KIN_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 2,
        ]);

        // 創建反向關係：person2 是 person1 的親屬（關係代碼 303）
        DB::table('KIN_DATA')->insert([
            'c_personid' => $person2->c_personid,
            'c_kin_id' => $person1->c_personid,
            'c_kin_code' => 303,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
                'new_c_kin_code' => 303,
            ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => '反向關係已存在，無需創建。',
        ]);
    }

    #[Test]
    public function kinship_repair_successfully_creates_reverse_relationship() {
        // 創建測試人物
        $person1 = $this->createTestPerson();
        $person2 = $this->createTestPerson();

        // 創建原始關係：person1 是 person2 的親屬（關係代碼 2）
        DB::table('KIN_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 2,
            'c_source' => 100,
            'c_pages' => '10-15',
            'c_notes' => '原始備註',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
                'new_c_kin_code' => 303,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => '親屬關係修復成功！已創建反向關係記錄。',
            'original' => [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
            ],
            'created' => [
                'c_personid' => $person2->c_personid,
                'c_kin_id' => $person1->c_personid,
                'c_kin_code' => 303,
            ],
        ]);

        // 驗證反向關係已創建
        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => $person2->c_personid,
            'c_kin_id' => $person1->c_personid,
            'c_kin_code' => 303,
            'c_source' => 100,
            'c_pages' => '10-15',
            'c_notes' => '原始備註',
        ]);

        // 驗證反向記錄的 c_autogen_notes 與原始記錄保持一致
        // 這很重要，因為刪除邏輯依賴 c_autogen_notes 來匹配反向關係
        $originalRecord = DB::table('KIN_DATA')
            ->where('c_personid', $person1->c_personid)
            ->where('c_kin_id', $person2->c_personid)
            ->where('c_kin_code', 2)
            ->first();

        $reverseRecord = DB::table('KIN_DATA')
            ->where('c_personid', $person2->c_personid)
            ->where('c_kin_id', $person1->c_personid)
            ->where('c_kin_code', 303)
            ->first();

        $this->assertEquals($originalRecord->c_autogen_notes, $reverseRecord->c_autogen_notes);
        $this->assertNotEmpty($reverseRecord->c_created_by);
        $this->assertNotEmpty($reverseRecord->c_created_date);

        // 驗證操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'KIN_DATA',
            'resource_id' => $person2->c_personid . '-' . $person1->c_personid . '-303',
        ]);
    }

    #[Test]
    public function assoc_repair_validates_required_fields() {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.assoc'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['c_personid', 'c_assoc_id', 'c_assoc_code', 'new_c_assoc_code']);
    }

    #[Test]
    public function assoc_repair_validates_entity_existence() {
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.assoc'), [
                'c_personid' => 99999,
                'c_assoc_id' => 1,
                'c_assoc_code' => 4,
                'new_c_assoc_code' => 5,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['dependencies']);
    }

    #[Test]
    public function assoc_repair_returns_error_when_record_not_found() {
        // 創建測試人物
        $person1 = $this->createTestPerson();
        $person2 = $this->createTestPerson();

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.assoc'), [
                'c_personid' => $person1->c_personid,
                'c_assoc_id' => $person2->c_personid,
                'c_assoc_code' => 4,
                'new_c_assoc_code' => 5,
            ]);

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => '未找到符合條件的社會關係記錄。',
        ]);
    }

    #[Test]
    public function assoc_repair_successfully_creates_reverse_relationship() {
        // 創建測試人物
        $person1 = $this->createTestPerson();
        $person2 = $this->createTestPerson();

        // 創建原始社會關係
        DB::table('ASSOC_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_assoc_id' => $person2->c_personid,
            'c_assoc_code' => 4,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '測試文獻',
            'c_assoc_first_year' => 1000,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
            'c_source' => 200,
            'c_pages' => '20-25',
            'c_notes' => '社會關係備註',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.assoc'), [
                'c_personid' => $person1->c_personid,
                'c_assoc_id' => $person2->c_personid,
                'c_assoc_code' => 4,
                'new_c_assoc_code' => 5,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => '社會關係修復成功！已創建反向關係記錄。',
            'original' => [
                'c_personid' => $person1->c_personid,
                'c_assoc_id' => $person2->c_personid,
                'c_assoc_code' => 4,
            ],
            'created' => [
                'c_personid' => $person2->c_personid,
                'c_assoc_id' => $person1->c_personid,
                'c_assoc_code' => 5,
            ],
        ]);

        // 驗證反向關係已創建
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => $person2->c_personid,
            'c_assoc_id' => $person1->c_personid,
            'c_assoc_code' => 5,
            'c_text_title' => '測試文獻',
            'c_assoc_first_year' => 1000,
            'c_source' => 200,
            'c_pages' => '20-25',
            'c_notes' => '社會關係備註',
        ]);

        // 驗證創建資訊
        $reverseRecord = DB::table('ASSOC_DATA')
            ->where('c_personid', $person2->c_personid)
            ->where('c_assoc_id', $person1->c_personid)
            ->where('c_assoc_code', 5)
            ->first();

        $this->assertNotEmpty($reverseRecord->c_created_by);
        $this->assertNotEmpty($reverseRecord->c_created_date);

        // 驗證操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'ASSOC_DATA',
            'resource_id' => $person2->c_personid . '-5-' . $person1->c_personid . '-0-0-0-0-測試文獻',
        ]);
    }

    protected function createTestPerson() {
        static $personId = 1;
        $id = $personId++;

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => $id,
            'c_name' => 'Test Person ' . $id,
            'c_name_chn' => '测试人物' . $id,
        ]);

        return (object)['c_personid' => $id];
    }

    #[Test]
    public function kinship_repair_uses_database_transaction() {
        // 創建測試人物
        $person1 = $this->createTestPerson();
        $person2 = $this->createTestPerson();

        // 創建原始關係
        DB::table('KIN_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 3,
        ]);

        // 模擬資料庫錯誤（使用不存在的關係代碼，會導致外鍵約束失敗）
        // 注意：此測試假設 KINSHIP_CODES 表有外鍵約束
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 3,
                'new_c_kin_code' => 99999, // 不存在的關係代碼
            ]);

        // 應該返回驗證錯誤而非觸發交易中的外鍵例外
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['dependencies']);

        // 驗證沒有創建任何新記錄（事務回滾）
        $this->assertDatabaseMissing('KIN_DATA', [
            'c_personid' => $person2->c_personid,
            'c_kin_id' => $person1->c_personid,
            'c_kin_code' => 99999,
        ]);
    }

    #[Test]
    public function kinship_repair_handles_null_autogen_notes() {
        // 創建測試人物
        $person1 = $this->createTestPerson();
        $person2 = $this->createTestPerson();

        // 創建原始關係，c_autogen_notes 為 NULL
        DB::table('KIN_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 2,
            'c_autogen_notes' => null,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
                'new_c_kin_code' => 303,
            ]);

        $response->assertStatus(200);

        // 驗證反向關係的 c_autogen_notes 也為 NULL
        $reverseRecord = DB::table('KIN_DATA')
            ->where('c_personid', $person2->c_personid)
            ->where('c_kin_id', $person1->c_personid)
            ->where('c_kin_code', 303)
            ->first();

        $this->assertNull($reverseRecord->c_autogen_notes);
    }

    #[Test]
    public function kinship_repair_preserves_autogen_notes_value() {
        // 創建測試人物
        $person1 = $this->createTestPerson();
        $person2 = $this->createTestPerson();

        $customNotes = '自定義的 autogen 備註';

        // 創建原始關係，帶有自定義 c_autogen_notes
        DB::table('KIN_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 2,
            'c_autogen_notes' => $customNotes,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
                'new_c_kin_code' => 303,
            ]);

        $response->assertStatus(200);

        // 驗證反向關係完全複製了原始的 c_autogen_notes
        $reverseRecord = DB::table('KIN_DATA')
            ->where('c_personid', $person2->c_personid)
            ->where('c_kin_id', $person1->c_personid)
            ->where('c_kin_code', 303)
            ->first();

        $this->assertEquals($customNotes, $reverseRecord->c_autogen_notes);
    }

    #[Test]
    public function assoc_repair_checks_duplicate_with_all_key_fields() {
        // 創建測試人物
        $person1 = $this->createTestPerson();
        $person2 = $this->createTestPerson();

        // 創建原始社會關係
        DB::table('ASSOC_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_assoc_id' => $person2->c_personid,
            'c_assoc_code' => 4,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '測試文獻',
            'c_assoc_first_year' => 1000,
            'c_assoc_count' => 2,
            'c_sequence' => 1,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
        ]);

        // 創建反向關係（包含所有邏輯主鍵欄位）
        DB::table('ASSOC_DATA')->insert([
            'c_personid' => $person2->c_personid,
            'c_assoc_id' => $person1->c_personid,
            'c_assoc_code' => 5,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '測試文獻',
            'c_assoc_first_year' => 1000,
            'c_assoc_count' => 2,
            'c_sequence' => 1,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
        ]);

        // 嘗試再次修復應該被阻止
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.assoc'), [
                'c_personid' => $person1->c_personid,
                'c_assoc_id' => $person2->c_personid,
                'c_assoc_code' => 4,
                'new_c_assoc_code' => 5,
            ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => '反向關係已存在，無需創建。',
        ]);
    }

    #[Test]
    public function kinship_repair_enables_bidirectional_delete() {
        // 創建測試人物
        $person1 = $this->createTestPerson();
        $person2 = $this->createTestPerson();

        // 創建原始單向關係：person1 是 person2 的親屬（關係代碼 2）
        DB::table('KIN_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 2,
            'c_source' => 100,
            'c_autogen_notes' => '測試備註',
        ]);

        // 使用修復工具創建反向關係
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
                'new_c_kin_code' => 303,
            ]);

        $response->assertStatus(200);

        // 驗證兩條記錄都存在
        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 2,
        ]);
        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => $person2->c_personid,
            'c_kin_id' => $person1->c_personid,
            'c_kin_code' => 303,
        ]);

        // 需要添加 KINSHIP_CODES 的反向關係配置
        DB::table('KINSHIP_CODES')->where('c_kincode', 2)->update([
            'c_kin_pair1' => 303,
        ]);
        DB::table('KINSHIP_CODES')->where('c_kincode', 303)->update([
            'c_kin_pair1' => 2,
        ]);

        // 使用 BiogMainRepository 刪除原始關係
        $repository = app(\App\Repositories\BiogMainRepository::class);
        $resourceId = "{$person1->c_personid}-{$person2->c_personid}-2";

        // 模擬認證用戶
        $this->actingAs($this->adminUser);

        $repository->kinshipDeleteById($resourceId, null);

        // 驗證兩條記錄都被刪除（雙向刪除）
        $this->assertDatabaseMissing('KIN_DATA', [
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 2,
        ]);
        $this->assertDatabaseMissing('KIN_DATA', [
            'c_personid' => $person2->c_personid,
            'c_kin_id' => $person1->c_personid,
            'c_kin_code' => 303,
        ]);
    }

    #[Test]
    public function kinship_repair_reverse_delete_also_works() {
        // 創建測試人物
        $person1 = $this->createTestPerson();
        $person2 = $this->createTestPerson();

        // 創建原始單向關係
        DB::table('KIN_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 2,
            'c_source' => 100,
            'c_autogen_notes' => null, // 測試 NULL 的情況
        ]);

        // 使用修復工具創建反向關係
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.kinship'), [
                'c_personid' => $person1->c_personid,
                'c_kin_id' => $person2->c_personid,
                'c_kin_code' => 2,
                'new_c_kin_code' => 303,
            ]);

        $response->assertStatus(200);

        // 配置反向關係代碼
        DB::table('KINSHIP_CODES')->where('c_kincode', 2)->update([
            'c_kin_pair1' => 303,
        ]);
        DB::table('KINSHIP_CODES')->where('c_kincode', 303)->update([
            'c_kin_pair1' => 2,
        ]);

        // 從反向關係側刪除
        $repository = app(\App\Repositories\BiogMainRepository::class);
        $resourceId = "{$person2->c_personid}-{$person1->c_personid}-303";

        $this->actingAs($this->adminUser);

        $repository->kinshipDeleteById($resourceId, null);

        // 驗證兩條記錄都被刪除
        $this->assertDatabaseMissing('KIN_DATA', [
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 2,
        ]);
        $this->assertDatabaseMissing('KIN_DATA', [
            'c_personid' => $person2->c_personid,
            'c_kin_id' => $person1->c_personid,
            'c_kin_code' => 303,
        ]);
    }

    #[Test]
    public function assoc_repair_enables_bidirectional_delete() {
        // 創建測試人物
        $person1 = $this->createTestPerson();
        $person2 = $this->createTestPerson();

        // 創建原始單向社會關係
        DB::table('ASSOC_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_assoc_id' => $person2->c_personid,
            'c_assoc_code' => 4,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '測試文獻',
            'c_assoc_first_year' => 1000,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
            'c_source' => 200,
        ]);

        // 使用修復工具創建反向關係
        $response = $this->actingAs($this->adminUser)
            ->postJson(route('admin.unidirectional-relationship-repair.assoc'), [
                'c_personid' => $person1->c_personid,
                'c_assoc_id' => $person2->c_personid,
                'c_assoc_code' => 4,
                'new_c_assoc_code' => 5,
            ]);

        $response->assertStatus(200);

        // 驗證兩條記錄都存在
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => $person1->c_personid,
            'c_assoc_id' => $person2->c_personid,
            'c_assoc_code' => 4,
        ]);
        $this->assertDatabaseHas('ASSOC_DATA', [
            'c_personid' => $person2->c_personid,
            'c_assoc_id' => $person1->c_personid,
            'c_assoc_code' => 5,
        ]);

        // 使用 BiogMainRepository 刪除原始關係
        $repository = app(\App\Repositories\BiogMainRepository::class);
        $resourceId = "{$person1->c_personid}-4-{$person2->c_personid}-0-0-0-0-測試文獻";

        $this->actingAs($this->adminUser);

        $repository->assocDeleteById($resourceId, $person1->c_personid);

        // 驗證兩條記錄都被刪除（雙向刪除）
        $this->assertDatabaseMissing('ASSOC_DATA', [
            'c_personid' => $person1->c_personid,
            'c_assoc_id' => $person2->c_personid,
            'c_assoc_code' => 4,
        ]);
        $this->assertDatabaseMissing('ASSOC_DATA', [
            'c_personid' => $person2->c_personid,
            'c_assoc_id' => $person1->c_personid,
            'c_assoc_code' => 5,
        ]);
    }

    #[Test]
    public function kinship_without_repair_cannot_bidirectional_delete() {
        // 創建測試人物
        $person1 = $this->createTestPerson();
        $person2 = $this->createTestPerson();

        // 手動創建兩條獨立的關係（不是通過修復工具創建）
        // 這樣它們的 c_autogen_notes 不一致，無法雙向刪除
        DB::table('KIN_DATA')->insert([
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 2,
            'c_source' => 100,
            'c_autogen_notes' => '原始備註',
        ]);

        DB::table('KIN_DATA')->insert([
            'c_personid' => $person2->c_personid,
            'c_kin_id' => $person1->c_personid,
            'c_kin_code' => 303,
            'c_source' => 100,
            'c_autogen_notes' => '不同的備註', // 不一致的 autogen_notes
        ]);

        // 配置反向關係代碼
        DB::table('KINSHIP_CODES')->where('c_kincode', 2)->update([
            'c_kin_pair1' => 303,
        ]);
        DB::table('KINSHIP_CODES')->where('c_kincode', 303)->update([
            'c_kin_pair1' => 2,
        ]);

        // 刪除第一條記錄
        $repository = app(\App\Repositories\BiogMainRepository::class);
        $resourceId = "{$person1->c_personid}-{$person2->c_personid}-2";

        $this->actingAs($this->adminUser);

        $repository->kinshipDeleteById($resourceId, null);

        // 驗證只有第一條記錄被刪除，第二條仍然存在（因為 c_autogen_notes 不匹配）
        $this->assertDatabaseMissing('KIN_DATA', [
            'c_personid' => $person1->c_personid,
            'c_kin_id' => $person2->c_personid,
            'c_kin_code' => 2,
        ]);

        // 反向關係仍然存在（單向刪除）
        $this->assertDatabaseHas('KIN_DATA', [
            'c_personid' => $person2->c_personid,
            'c_kin_id' => $person1->c_personid,
            'c_kin_code' => 303,
        ]);
    }
}
