<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 测试 BasicInformation 相关页面能正常加载（不出现 500 错误）
 *
 * 使用 in-memory SQLite 数据库，灌入最小化测试数据
 * 只验证 HTTP 状态码，不检查具体内容
 */
class BasicInformationPagesLoadTest extends TestCase {
    protected $user;
    protected $testPersonId = 99999;

    protected function setUp(): void {
        parent::setUp();

        // 使用 in-memory SQLite 数据库
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 设置缓存和 session 为数组驱动
        config(['cache.default' => 'array']);
        config(['session.driver' => 'array']);

        // 创建测试所需的最小化表结构
        $this->createMinimalTables();

        // 创建测试用户（用于需要认证的页面）
        $this->user = factory(User::class)->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'is_active' => 1,
            'is_admin' => 0,
            'confirmation_token' => 'test_token_' . time(),
        ]);

        // 灌入测试数据
        $this->seedTestData();
    }

    /**
     * 创建最小化表结构
     */
    protected function createMinimalTables() {
        // 创建 users 表
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

        // 创建 BIOG_MAIN 表（最小化版本）
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn', 50)->nullable();
            $table->string('c_name', 50)->nullable();  // 拼音姓名
            $table->string('c_name_eng', 50)->nullable();
            $table->integer('c_dy')->nullable();  // 朝代
            $table->integer('c_index_year')->nullable();
            $table->integer('c_index_addr_id')->nullable();  // 指数地址ID
            $table->string('c_created_by', 50)->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by', 50)->nullable();
            $table->timestamp('c_modified_date')->nullable();
            $table->timestamps();
        });

        // 创建 BIOG_ADDR_DATA 表（地址表）
        Schema::create('BIOG_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_addr_id')->default(0);
            $table->integer('c_addr_type')->default(0);
            $table->integer('c_sequence')->default(0);
            $table->text('c_notes')->nullable();
            $table->timestamps();
            $table->primary(['c_personid', 'c_addr_id', 'c_addr_type', 'c_sequence']);
        });

        // 创建 ALTNAME_DATA 表（别名表）
        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->string('c_alt_name', 100)->nullable();
            $table->string('c_alt_name_chn', 100)->nullable();
            $table->integer('c_alt_name_type_code')->default(0);
            $table->integer('c_sequence')->default(0);
            $table->timestamps();
            $table->primary(['c_personid', 'c_alt_name_type_code', 'c_sequence']);
        });

        // 创建 BIOG_SOURCE_DATA 表（文本/出处表）
        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->text('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_main_source')->nullable();
            $table->integer('c_self_bio')->nullable();
            $table->timestamps();
            $table->primary(['c_personid', 'c_textid']);
        });

        // 创建 BIOG_TEXT_DATA 表（文本著作表）
        Schema::create('BIOG_TEXT_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->integer('c_role_id')->nullable();
            $table->timestamps();
            $table->primary(['c_personid', 'c_textid']);
        });

        // 创建 EVENTS_DATA 表（事件表，注意是 EVENTS_DATA 不是 BIOG_EVENT）
        Schema::create('EVENTS_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_event_code');
            $table->integer('c_sequence')->default(0);
            $table->text('c_notes')->nullable();
            $table->timestamps();
            $table->primary(['c_personid', 'c_event_code', 'c_sequence']);
        });

        // 创建 POSTED_TO_OFFICE_DATA 表（官职表）
        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id');
            $table->integer('c_office_id')->default(0);
            $table->integer('c_sequence')->nullable();
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_lastyear')->nullable();
            $table->text('c_notes')->nullable();
            $table->timestamps();
            $table->primary(['c_personid', 'c_posting_id', 'c_office_id']);
        });

        // 创建 POSTED_TO_ADDR_DATA 表（官职地址关联表）
        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id');
            $table->integer('c_office_id');
            $table->integer('c_addr_id')->nullable();
            $table->timestamps();
        });

        // 创建 ASSOC_DATA 表（社会关系表）
        Schema::create('ASSOC_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_id')->default(0);
            $table->integer('c_assoc_code')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_assoc_kin_code')->default(0);
            $table->integer('c_assoc_kin_id')->default(0);
            $table->string('c_text_title', 255)->nullable();
            $table->timestamps();
        });

        // 创建 ENTRY_DATA 表（入词表）
        Schema::create('ENTRY_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_entry_code');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_kin_code')->nullable();
            $table->integer('c_kin_id')->nullable();
            $table->integer('c_assoc_code')->nullable();
            $table->integer('c_assoc_id')->nullable();
            $table->integer('c_year')->nullable();
            $table->integer('c_inst_code')->nullable();
            $table->integer('c_inst_name_code')->nullable();
            $table->text('c_notes')->nullable();
            $table->timestamps();
            $table->primary(['c_personid', 'c_entry_code', 'c_sequence']);
        });

        // 创建 KIN_DATA 表（亲属表）
        Schema::create('KIN_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->text('c_notes')->nullable();
            $table->timestamps();
        });

        // 创建 STATUS_DATA 表（社会区分表）
        Schema::create('STATUS_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_status_code')->default(0);
            $table->integer('c_sequence')->default(0);
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_lastyear')->nullable();
            $table->text('c_notes')->nullable();
            $table->timestamps();
            $table->primary(['c_personid', 'c_status_code', 'c_sequence']);
        });

        // 创建 POSSESSION_DATA 表（财物表）
        Schema::create('POSSESSION_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_possession_act_code')->default(0);
            $table->integer('c_sequence')->default(0);
            $table->string('c_possession_desc', 200)->nullable();
            $table->string('c_possession_desc_chn', 200)->nullable();
            $table->integer('c_possession_record_id')->nullable();
            $table->text('c_notes')->nullable();
            $table->timestamps();
            $table->primary(['c_personid', 'c_possession_act_code', 'c_sequence']);
        });

        // 创建 BIOG_INST_DATA 表（社会机构表）
        Schema::create('BIOG_INST_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->integer('c_bi_role_code')->default(0);
            $table->integer('c_bi_begin_year')->nullable();
            $table->integer('c_bi_end_year')->nullable();
            $table->text('c_notes')->nullable();
            $table->timestamps();
            $table->primary(['c_personid', 'c_inst_code', 'c_inst_name_code', 'c_bi_role_code']);
        });

        // 创建 DYNASTIES 表（朝代表）
        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn', 50)->nullable();
            $table->string('c_dynasty', 50)->nullable();
            $table->integer('c_start')->nullable();
            $table->integer('c_end')->nullable();
        });

        // 创建 NIAN_HAO 表（年号表）
        Schema::create('NIAN_HAO', function (Blueprint $table) {
            $table->integer('c_nianhao_id')->primary();
            $table->string('c_nianhao_chn', 50)->nullable();
            $table->integer('c_dy')->nullable();
        });

        // 创建 YEAR_RANGE_CODES 表（年份范围表）
        Schema::create('YEAR_RANGE_CODES', function (Blueprint $table) {
            $table->integer('c_range_code')->primary();
            $table->string('c_range_chn', 50)->nullable();
        });

        // 创建 ADDR_CODES 表（地址代码表）
        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name_chn', 100)->nullable();
        });

        // 创建 BIOG_ADDR_CODES 表（地址类型代码表）
        Schema::create('BIOG_ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_type')->primary();
            $table->string('c_addr_desc_chn', 100)->nullable();
        });

        // 创建 ALTNAME_CODES 表（别名类型代码表）
        Schema::create('ALTNAME_CODES', function (Blueprint $table) {
            $table->integer('c_name_type_code')->primary();
            $table->string('c_name_type_desc_chn', 100)->nullable();
        });

        // 创建 TEXT_CODES 表（文本代码表）
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title_chn', 200)->nullable();
        });

        // 创建 TEXT_ROLE_CODES 表（文本角色代码表）
        Schema::create('TEXT_ROLE_CODES', function (Blueprint $table) {
            $table->integer('c_role_id')->primary();
            $table->string('c_role_desc_chn', 100)->nullable();
        });

        // 创建 OFFICE_CODES 表（官职代码表）
        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->string('c_office_chn', 200)->nullable();
        });

        // 创建 ASSOC_CODES 表（社会关系代码表）
        Schema::create('ASSOC_CODES', function (Blueprint $table) {
            $table->integer('c_assoc_code')->primary();
            $table->string('c_assoc_desc_chn', 100)->nullable();
        });

        // 创建 ENTRY_CODES 表（入词代码表）
        Schema::create('ENTRY_CODES', function (Blueprint $table) {
            $table->integer('c_entry_code')->primary();
            $table->string('c_entry_desc_chn', 100)->nullable();
        });

        // 创建 STATUS_CODES 表（社会区分代码表）
        Schema::create('STATUS_CODES', function (Blueprint $table) {
            $table->integer('c_status_code')->primary();
            $table->string('c_status_desc_chn', 100)->nullable();
        });

        // 创建 KINSHIP_CODES 表（亲属关系代码表）
        Schema::create('KINSHIP_CODES', function (Blueprint $table) {
            $table->integer('c_kincode')->primary();
            $table->string('c_kinrel_chn', 100)->nullable();
        });

        // 创建 INDEXYEAR_TYPE_CODES 表（指数年类型代码表）
        Schema::create('INDEXYEAR_TYPE_CODES', function (Blueprint $table) {
            $table->string('c_index_year_type_code', 10)->primary();
            $table->string('c_index_year_type_hz', 100)->nullable();
        });

        // 创建 EVENT_CODES 表（事件代码表）
        Schema::create('EVENT_CODES', function (Blueprint $table) {
            $table->integer('c_event_code')->primary();
            $table->string('c_event_desc_chn', 100)->nullable();
        });

        // 创建 POSSESSION_ACT_CODES 表（财物行为代码表）
        Schema::create('POSSESSION_ACT_CODES', function (Blueprint $table) {
            $table->integer('c_possession_act_code')->primary();
            $table->string('c_possession_act_desc_chn', 100)->nullable();
        });

        // 创建 BIOG_INST_CODES 表（社会机构角色代码表）
        Schema::create('BIOG_INST_CODES', function (Blueprint $table) {
            $table->integer('c_bi_role_code')->primary();
            $table->string('c_bi_role_desc_chn', 100)->nullable();
        });

        // 创建 SOCIAL_INSTITUTION_NAME_CODES 表（社会机构名称代码表）
        Schema::create('SOCIAL_INSTITUTION_NAME_CODES', function (Blueprint $table) {
            $table->integer('c_inst_name_code')->primary();
            $table->string('c_inst_name_chn', 255)->nullable();
            $table->string('c_inst_name', 255)->nullable();
            $table->timestamps();
        });

        // 创建 CBDB__NAME_FTS 表（倒排索引表，用于高效姓名搜索）
        Schema::create('CBDB__NAME_FTS', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->string('search_term', 200);
            $table->index('search_term');
        });
    }

    /**
     * 灌入测试数据
     */
    protected function seedTestData() {
        // 插入代码表基础数据
        \DB::table('DYNASTIES')->insert([
            'c_dy' => 1,
            'c_dynasty_chn' => '测试朝代',
            'c_dynasty' => 'Test Dynasty',
            'c_start' => 900,
            'c_end' => 1100,
        ]);

        \DB::table('NIAN_HAO')->insert([
            'c_nianhao_id' => 1,
            'c_nianhao_chn' => '测试年号',
            'c_dy' => 1,
        ]);

        \DB::table('YEAR_RANGE_CODES')->insert([
            'c_range_code' => 1,
            'c_range_chn' => '约',
        ]);

        \DB::table('ADDR_CODES')->insert([
            'c_addr_id' => 1,
            'c_name_chn' => '测试地址',
        ]);

        \DB::table('BIOG_ADDR_CODES')->insert([
            'c_addr_type' => 1,
            'c_addr_desc_chn' => '籍贯',
        ]);

        \DB::table('ALTNAME_CODES')->insert([
            'c_name_type_code' => 1,
            'c_name_type_desc_chn' => '字',
        ]);

        \DB::table('TEXT_CODES')->insert([
            'c_textid' => 1,
            'c_title_chn' => '测试文本',
        ]);

        \DB::table('OFFICE_CODES')->insert([
            'c_office_id' => 1,
            'c_office_chn' => '测试官职',
        ]);

        \DB::table('ASSOC_CODES')->insert([
            'c_assoc_code' => 1,
            'c_assoc_desc_chn' => '师生',
        ]);

        \DB::table('ENTRY_CODES')->insert([
            'c_entry_code' => 1,
            'c_entry_desc_chn' => '进士',
        ]);

        \DB::table('STATUS_CODES')->insert([
            'c_status_code' => 1,
            'c_status_desc_chn' => '士人',
        ]);

        \DB::table('KINSHIP_CODES')->insert([
            'c_kincode' => 1,
            'c_kinrel_chn' => '父',
        ]);

        \DB::table('BIOG_INST_CODES')->insert([
            'c_bi_role_code' => 1,
            'c_bi_role_desc_chn' => '成员',
        ]);

        \DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert([
            'c_inst_name_code' => 1,
            'c_inst_name_chn' => '测试社会机构',
            'c_inst_name' => 'Test Social Institution',
        ]);

        // 插入测试人物
        \DB::table('BIOG_MAIN')->insert([
            'c_personid' => $this->testPersonId,
            'c_name_chn' => '测试人物',
            'c_name' => 'Ceshi Renwu',
            'c_name_eng' => 'Test Person',
            'c_dy' => 1,
            'c_index_year' => 1000,
            'c_index_addr_id' => 1,
        ]);

        // 添加亲属人物记录（用于 kinship 关系）
        \DB::table('BIOG_MAIN')->insert([
            'c_personid' => 77777,
            'c_name_chn' => '亲属人物',
            'c_name' => 'Qinshu Renwu',
            'c_name_eng' => 'Relative Person',
            'c_dy' => 1,
            'c_index_year' => 1000,
            'c_index_addr_id' => 1,
        ]);

        // 1. 地址数据 (addresses)
        \DB::table('BIOG_ADDR_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_addr_id' => 1,
            'c_addr_type' => 1,
            'c_sequence' => 1,
            'c_notes' => '测试地址',
        ]);

        // 2. 别名数据 (altnames)
        \DB::table('ALTNAME_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_alt_name' => 'Test Altname',
            'c_alt_name_chn' => '测试别名',
            'c_alt_name_type_code' => 1,
            'c_sequence' => 1,
        ]);

        // 3. 文本/出处数据 (texts/sources)
        \DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_textid' => 1,
            'c_pages' => '页码测试',
            'c_notes' => '测试出处',
        ]);

        // 4. 官职数据 (offices)
        \DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_posting_id' => 1,
            'c_office_id' => 1,
            'c_sequence' => 1,
            'c_firstyear' => 1000,
            'c_lastyear' => 1010,
        ]);

        // 5. 社会关系数据 (assoc)
        \DB::table('ASSOC_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_assoc_id' => 88888,  // 关联人物ID
            'c_assoc_code' => 1,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '测试社会关系',
        ]);

        // 6. 入词数据 (entries)
        \DB::table('ENTRY_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_entry_code' => 1,
            'c_sequence' => 1,
            'c_notes' => '测试入词',
        ]);

        // 7. 事件数据 (events)
        \DB::table('EVENTS_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_event_code' => 1,
            'c_sequence' => 1,
            'c_notes' => '测试事件',
        ]);

        // 8. 亲属数据 (kinship)
        \DB::table('KIN_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_kin_id' => 77777,  // 亲属人物ID
            'c_kin_code' => 1,
            'c_notes' => '测试亲属关系',
        ]);

        // 9. 社会区分数据 (statuses)
        \DB::table('STATUS_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_status_code' => 1,
            'c_sequence' => 1,
            'c_notes' => '测试社会区分',
        ]);

        // 10. 财物数据 (possession)
        \DB::table('POSSESSION_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_possession_act_code' => 1,
            'c_sequence' => 1,
            'c_notes' => '测试财物',
        ]);

        // 11. 社会机构数据 (socialinst)
        \DB::table('BIOG_INST_DATA')->insert([
            'c_personid' => $this->testPersonId,
            'c_inst_code' => 1,
            'c_inst_name_code' => 1,
            'c_bi_role_code' => 1,
            'c_notes' => '测试社会机构',
        ]);
    }

    /**
     * 测试主页面：/basicinformation
     */
    #[Test]
    public function test_basicinformation_index_page_loads() {
        $response = $this->get('/basicinformation');
        $response->assertStatus(200);
    }

    /**
     * 测试创建页面：/basicinformation/create
     */
    #[Test]
    public function test_basicinformation_create_page_loads() {
        $response = $this->get('/basicinformation/create');
        $response->assertStatus(200);
    }

    /**
     * 测试只读页面：/basicinformation/{id}（未登录可訪問）
     */
    #[Test]
    public function test_basicinformation_show_page_loads() {
        $response = $this->get("/basicinformation/{$this->testPersonId}");
        $response->assertStatus(200);
    }

    /**
     * 测试编辑页面：/basicinformation/{id}/edit（未登录时重定向）
     */
    #[Test]
    public function test_basicinformation_edit_page_redirects_when_not_authenticated() {
        $response = $this->get("/basicinformation/{$this->testPersonId}/edit");
        $response->assertStatus(302);
        $response->assertRedirect("/basicinformation/{$this->testPersonId}");
    }

    /**
     * 测试编辑页面：/basicinformation/{id}/edit（登录后可訪問）
     */
    #[Test]
    public function test_basicinformation_edit_page_loads() {
        $response = $this->actingAs($this->user)->get("/basicinformation/{$this->testPersonId}/edit");
        $response->assertStatus(200);
    }

    /**
     * 测试地址子页面：/basicinformation/{id}/addresses
     */
    #[Test]
    public function test_basicinformation_addresses_index_loads() {
        $response = $this->get("/basicinformation/{$this->testPersonId}/addresses");
        $response->assertStatus(200);
    }

    /**
     * 测试别名子页面：/basicinformation/{id}/altnames
     */
    #[Test]
    public function test_basicinformation_altnames_index_loads() {
        $response = $this->get("/basicinformation/{$this->testPersonId}/altnames");
        $response->assertStatus(200);
    }

    /**
     * 测试文本子页面：/basicinformation/{id}/texts
     */
    #[Test]
    public function test_basicinformation_texts_index_loads() {
        $response = $this->get("/basicinformation/{$this->testPersonId}/texts");
        $response->assertStatus(200);
    }

    /**
     * 测试官职子页面：/basicinformation/{id}/offices
     */
    #[Test]
    public function test_basicinformation_offices_index_loads() {
        $response = $this->get("/basicinformation/{$this->testPersonId}/offices");
        $response->assertStatus(200);
    }

    /**
     * 测试社会关系子页面：/basicinformation/{id}/assoc
     */
    #[Test]
    public function test_basicinformation_assoc_index_loads() {
        $response = $this->get("/basicinformation/{$this->testPersonId}/assoc");
        $response->assertStatus(200);
    }

    /**
     * 测试入词子页面：/basicinformation/{id}/entries
     */
    #[Test]
    public function test_basicinformation_entries_index_loads() {
        $response = $this->get("/basicinformation/{$this->testPersonId}/entries");
        $response->assertStatus(200);
    }

    /**
     * 测试事件子页面：/basicinformation/{id}/events
     */
    #[Test]
    public function test_basicinformation_events_index_loads() {
        $response = $this->get("/basicinformation/{$this->testPersonId}/events");
        $response->assertStatus(200);
    }

    /**
     * 测试亲属子页面：/basicinformation/{id}/kinship
     */
    #[Test]
    public function test_basicinformation_kinship_index_loads() {
        $response = $this->get("/basicinformation/{$this->testPersonId}/kinship");
        $response->assertStatus(200);
    }

    /**
     * 测试社会区分子页面：/basicinformation/{id}/statuses
     */
    #[Test]
    public function test_basicinformation_statuses_index_loads() {
        $response = $this->get("/basicinformation/{$this->testPersonId}/statuses");
        $response->assertStatus(200);
    }

    /**
     * 测试财物子页面：/basicinformation/{id}/possession
     */
    #[Test]
    public function test_basicinformation_possession_index_loads() {
        $response = $this->get("/basicinformation/{$this->testPersonId}/possession");
        $response->assertStatus(200);
    }

    /**
     * 测试社会机构子页面：/basicinformation/{id}/socialinst
     */
    #[Test]
    public function test_basicinformation_socialinst_index_loads() {
        $response = $this->get("/basicinformation/{$this->testPersonId}/socialinst");
        $response->assertStatus(200);
    }

    /**
     * 测试出处子页面：/basicinformation/{id}/sources
     */
    #[Test]
    public function test_basicinformation_sources_index_loads() {
        $response = $this->get("/basicinformation/{$this->testPersonId}/sources");
        $response->assertStatus(200);
    }

    protected function tearDown(): void {
        parent::tearDown();
    }
}
