<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 「社會機構實體」update／delete mutation（resource=social-institution）回歸測試。
 *
 * 驗證 SocialInstituteUpdateHandler / SocialInstituteDeleteHandler / SocialInstituteImportService
 * 的聚合語義：實體識別＝c_inst_code 單鍵、名稱去重解析、改名護欄（被引用回 409）、
 * ADDR 集合對賬、刪除護欄（四張人物表引用計數）、名碼不回收。
 */
class ApiV2MutateSocialInstituteEntityTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('app.env', 'testing');
        $this->app['env'] = 'testing';
        config()->set('prometheus.enabled', false);
        config()->set('prometheus.storage_adapter', 'memory');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('confirmation_token')->nullable();
            $table->integer('is_active')->default(0);
            $table->integer('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->integer('c_personid')->default(0);
            $table->integer('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->longText('resource_data')->nullable();
            $table->longText('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->string('operation_id', 64);
            $table->text('row_pk');
            $table->string('row_pk_text', 512)->nullable();
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
        });
        Schema::create('SOCIAL_INSTITUTION_NAME_CODES', function (Blueprint $table) {
            $table->integer('c_inst_name_code')->primary();
            $table->string('c_inst_name_hz')->nullable();
            $table->string('c_inst_name_py')->nullable();
        });
        Schema::create('SOCIAL_INSTITUTION_CODES', function (Blueprint $table) {
            $table->integer('c_inst_name_code');
            $table->integer('c_inst_code');
            $table->integer('c_inst_type_code')->nullable();
            $table->integer('c_inst_begin_year')->nullable();
            $table->integer('c_by_nianhao_code')->nullable();
            $table->integer('c_by_nianhao_year')->nullable();
            $table->integer('c_by_year_range')->nullable();
            $table->integer('c_inst_begin_dy')->nullable();
            $table->integer('c_inst_floruit_dy')->nullable();
            $table->integer('c_inst_first_known_year')->nullable();
            $table->integer('c_inst_end_year')->nullable();
            $table->integer('c_ey_nianhao_code')->nullable();
            $table->integer('c_ey_nianhao_year')->nullable();
            $table->integer('c_ey_year_range')->nullable();
            $table->integer('c_inst_end_dy')->nullable();
            $table->integer('c_inst_last_known_year')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->primary(['c_inst_code', 'c_inst_name_code']);
        });
        Schema::create('SOCIAL_INSTITUTION_ADDR', function (Blueprint $table) {
            $table->integer('c_inst_name_code');
            $table->integer('c_inst_code');
            $table->integer('c_inst_addr_type_code');
            $table->integer('c_inst_addr_begin_year')->nullable();
            $table->integer('c_inst_addr_end_year')->nullable();
            $table->integer('c_inst_addr_id');
            $table->double('inst_xcoord');
            $table->double('inst_ycoord');
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });
        Schema::create('SOCIAL_INSTITUTION_TYPES', function (Blueprint $table) {
            $table->integer('c_inst_type_code')->primary();
            $table->string('c_inst_type_hz')->nullable();
            $table->string('c_inst_type_py')->nullable();
        });
        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
        });
        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
        });
        Schema::create('NIAN_HAO', function (Blueprint $table) {
            $table->integer('c_nianhao_id')->primary();
        });
        Schema::create('YEAR_RANGE_CODES', function (Blueprint $table) {
            $table->integer('c_range_code')->primary();
        });
        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn')->nullable();
            $table->string('c_pinyin')->nullable();
            $table->integer('c_lastname')->default(0);
        });
        // 刪除／改名護欄：referenceCount() 數這四張人物表。
        foreach (['BIOG_INST_DATA', 'ENTRY_DATA', 'ASSOC_DATA', 'POSTED_TO_OFFICE_DATA'] as $t) {
            Schema::create($t, function (Blueprint $table) {
                $table->integer('c_personid');
                $table->integer('c_inst_code');
                $table->integer('c_inst_name_code');
            });
        }

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 15, 'c_dynasty_chn' => '宋'],
            ['c_dy' => 19, 'c_dynasty_chn' => '明'],
        ]);
        DB::table('SOCIAL_INSTITUTION_TYPES')->insert([
            ['c_inst_type_code' => 1, 'c_inst_type_hz' => '書院', 'c_inst_type_py' => 'shuyuan'],
            ['c_inst_type_code' => 2, 'c_inst_type_hz' => '寺廟', 'c_inst_type_py' => 'simiao'],
        ]);
        DB::table('TEXT_CODES')->insert([['c_textid' => 7596], ['c_textid' => 8000]]);
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 101, 'c_name_chn' => '杭州'],
            ['c_addr_id' => 102, 'c_name_chn' => '蘇州'],
        ]);

        // 既有機構：inst_code=10、名碼=5（白鹿洞書院），一列地址。
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert([
            ['c_inst_name_code' => 5, 'c_inst_name_hz' => '白鹿洞書院', 'c_inst_name_py' => 'bailudong shuyuan'],
            ['c_inst_name_code' => 6, 'c_inst_name_hz' => '嶽麓書院', 'c_inst_name_py' => 'yuelu shuyuan'],
        ]);
        DB::table('SOCIAL_INSTITUTION_CODES')->insert([
            'c_inst_name_code' => 5, 'c_inst_code' => 10, 'c_inst_type_code' => 1,
            'c_inst_begin_dy' => 15, 'c_inst_floruit_dy' => 15, 'c_source' => 7596,
        ]);
        DB::table('SOCIAL_INSTITUTION_ADDR')->insert([
            'c_inst_name_code' => 5, 'c_inst_code' => 10, 'c_inst_addr_type_code' => 1,
            'c_inst_addr_id' => 101, 'inst_xcoord' => 0, 'inst_ycoord' => 0, 'c_source' => 7596,
        ]);
    }

    protected function tearDown(): void {
        foreach ([
            'POSTED_TO_OFFICE_DATA', 'ASSOC_DATA', 'ENTRY_DATA', 'BIOG_INST_DATA', 'pinyin',
            'YEAR_RANGE_CODES', 'NIAN_HAO', 'ADDR_CODES', 'TEXT_CODES', 'DYNASTIES',
            'SOCIAL_INSTITUTION_TYPES', 'SOCIAL_INSTITUTION_ADDR', 'SOCIAL_INSTITUTION_CODES',
            'SOCIAL_INSTITUTION_NAME_CODES', 'audit_log', 'operations', 'users',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    protected function makeUser(string $email = 'si@example.com'): User {
        return User::create([
            'name' => 'SI Tester',
            'email' => $email,
            'confirmation_token' => 'tok',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);
    }

    protected function updatePayload(array $changes = []): array {
        return [
            'resource' => 'social-institution',
            'operation' => 'update',
            'person_id' => 0,
            'target' => ['pk' => ['c_inst_code' => 10]],
            'changes' => array_merge([
                'name' => '白鹿洞書院',
                'type_code' => 1,
                'dynasty_code' => 15,
                'source_id' => 7596,
                'addresses' => [['addr_id' => 101]],
            ], $changes),
        ];
    }

    // ── update ──────────────────────────────

    #[Test]
    public function testUpdateOverwritesColumnsAndKeepsNameCode(): void {
        $this->actingAs($this->makeUser(email: 'si-upd@example.com'));

        $res = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'type_code' => 2,
            'begin_year' => 940,
            'end_dy' => 19,
            'notes' => '南唐建',
        ]));

        $res->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'social-institution',
            'operation' => 'update',
            'result' => ['pk' => ['c_inst_code' => 10], 'status' => 'updated', 'name_changed' => false],
        ]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', [
            'c_inst_code' => 10, 'c_inst_name_code' => 5, 'c_inst_type_code' => 2,
            'c_inst_begin_year' => 940, 'c_inst_end_dy' => 19, 'c_notes' => '南唐建',
        ]);
    }

    #[Test]
    public function testUpdateReconcilesAddressRows(): void {
        $this->actingAs($this->makeUser(email: 'si-addr@example.com'));

        // 101 同鍵改值（補起始年）、新增 102、無其他列 → 對賬結果兩列。
        $res = $this->postJson('/api/v2/mutate', $this->updatePayload([
            'addresses' => [
                ['addr_id' => 101, 'begin_year' => 940],
                ['addr_id' => 102, 'addr_type_code' => 1],
            ],
        ]));

        $res->assertOk()->assertJson(['result' => ['addr_added' => 1, 'addr_removed' => 0]]);
        $this->assertSame(2, DB::table('SOCIAL_INSTITUTION_ADDR')->where('c_inst_code', 10)->count());
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => 10, 'c_inst_addr_id' => 101, 'c_inst_addr_begin_year' => 940]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => 10, 'c_inst_addr_id' => 102]);
    }

    #[Test]
    public function testRenameUnreferencedReusesExistingNameCodeAndSyncsAddr(): void {
        $this->actingAs($this->makeUser(email: 'si-rename@example.com'));

        // 改名為既有名「嶽麓書院」→ 複用名碼 6（去重）、不新增 NAME_CODES；ADDR 名碼同步。
        $res = $this->postJson('/api/v2/mutate', $this->updatePayload(['name' => '嶽麓書院']));

        $res->assertOk()->assertJson(['result' => ['name_changed' => true, 'row' => ['c_inst_name_code' => 6]]]);
        $this->assertSame(2, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->count());
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_inst_name_code' => 6]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => 10, 'c_inst_name_code' => 6]);
        // 舊名碼不回收。
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_code' => 5]);
    }

    #[Test]
    public function testRenameToNewNameCreatesNameCode(): void {
        $this->actingAs($this->makeUser(email: 'si-rename-new@example.com'));

        $res = $this->postJson('/api/v2/mutate', $this->updatePayload(['name' => '石鼓書院']));

        $res->assertOk()->assertJson(['result' => ['name_changed' => true, 'row' => ['c_inst_name_code' => 7]]]);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_code' => 7, 'c_inst_name_hz' => '石鼓書院']);
    }

    #[Test]
    public function testRenameBlockedWhileReferenced(): void {
        DB::table('BIOG_INST_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser(email: 'si-rename-blocked@example.com'));

        $res = $this->postJson('/api/v2/mutate', $this->updatePayload(['name' => '嶽麓書院']));

        $res->assertStatus(409);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10, 'c_inst_name_code' => 5]);
    }

    #[Test]
    public function testUpdateOtherFieldsAllowedWhileReferenced(): void {
        DB::table('ENTRY_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser(email: 'si-upd-ref@example.com'));

        // 同名（名碼不變）僅改其他欄位 → 不受改名護欄影響。
        $this->postJson('/api/v2/mutate', $this->updatePayload(['type_code' => 2]))
            ->assertOk()
            ->assertJson(['result' => ['name_changed' => false]]);
    }

    #[Test]
    public function testUpdateValidation(): void {
        $this->actingAs($this->makeUser(email: 'si-upd-422@example.com'));

        // 缺地址列
        $this->postJson('/api/v2/mutate', $this->updatePayload(['addresses' => []]))->assertStatus(422);
        // 不存在的地址
        $this->postJson('/api/v2/mutate', $this->updatePayload(['addresses' => [['addr_id' => 999]]]))->assertStatus(422);
        // 不存在的年號碼
        $this->postJson('/api/v2/mutate', $this->updatePayload(['by_nianhao_code' => 424242]))->assertStatus(422);
        // 不存在的機構
        $payload = $this->updatePayload();
        $payload['target']['pk']['c_inst_code'] = 999;
        $this->postJson('/api/v2/mutate', $payload)->assertStatus(404);
    }

    // ── delete ──────────────────────────────

    #[Test]
    public function testDeleteRemovesCodesAndAddrButKeepsNameCode(): void {
        $this->actingAs($this->makeUser(email: 'si-del@example.com'));

        $res = $this->postJson('/api/v2/delete', [
            'resource' => 'social-institution',
            'person_id' => 0,
            'target' => ['pk' => ['c_inst_code' => 10]],
        ]);

        $res->assertOk()->assertJson([
            'ok' => true,
            'result' => ['pk' => ['c_inst_code' => 10], 'status' => 'deleted', 'addr_deleted' => 1],
        ]);
        $this->assertDatabaseMissing('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10]);
        $this->assertDatabaseMissing('SOCIAL_INSTITUTION_ADDR', ['c_inst_code' => 10]);
        // 名碼不回收。
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_code' => 5]);
    }

    #[Test]
    public function testDeleteBlockedWhileReferencedByAnyOfFourTables(): void {
        DB::table('POSTED_TO_OFFICE_DATA')->insert(['c_personid' => 1, 'c_inst_code' => 10, 'c_inst_name_code' => 5]);
        $this->actingAs($this->makeUser(email: 'si-del-blocked@example.com'));

        $this->postJson('/api/v2/delete', [
            'resource' => 'social-institution',
            'person_id' => 0,
            'target' => ['pk' => ['c_inst_code' => 10]],
        ])->assertStatus(409);
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', ['c_inst_code' => 10]);
    }
}
