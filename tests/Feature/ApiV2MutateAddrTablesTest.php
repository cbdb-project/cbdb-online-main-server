<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 地名表的 v2 mutation API（回應「API 無法新增地名表記錄」）。
 *
 * 涵蓋兩張表：
 *  - ADDR_CODES（單一數值主鍵、可自動分配 id）：create 與整列 update。
 *    原本只有 update 端、且只開放 c_name 一欄；新增完全沒有入口。
 *  - ADDR_BELONGS_DATA（四欄複合主鍵）：create 與 update。原本連 update 都沒有，
 *    是 CodeTableCreateHandler 第一張複合主鍵的登錄表，故此處刻意驗「主鍵給不齊要 422」
 *    與「複合主鍵精準命中一列」兩件事。
 */
class ApiV2MutateAddrTablesTest extends TestCase {
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

        $this->createUsersTable();
        $this->createOperationsTable();
        $this->createAuditLogTable();
        $this->createAddrTables();
    }

    protected function tearDown(): void {
        foreach (['ADDR_BELONGS_DATA', 'ADDR_CODES', 'audit_log', 'operations', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    protected function createUsersTable(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->integer('is_active')->default(0);
            $table->integer('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function createOperationsTable(): void {
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
    }

    protected function createAuditLogTable(): void {
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
    }

    /** 欄位（含型別與 nullable）比照 prod：稽核欄由 2026_09_10 的 migration 補齊。 */
    protected function createAddrTables(): void {
        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
            $table->smallInteger('c_firstyear')->nullable();
            $table->smallInteger('c_lastyear')->nullable();
            $table->string('c_admin_type')->nullable();
            $table->smallInteger('c_admin_cat_code')->default(0);
            $table->double('x_coord')->nullable();
            $table->double('y_coord')->nullable();
            $table->integer('CHGIS_PT_ID')->nullable();
            $table->longText('c_notes')->nullable();
            $table->string('c_alt_names')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });

        Schema::create('ADDR_BELONGS_DATA', function (Blueprint $table) {
            $table->integer('c_addr_id');
            $table->integer('c_belongs_to');
            $table->smallInteger('c_firstyear');
            $table->smallInteger('c_lastyear');
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->string('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary(['c_addr_id', 'c_belongs_to', 'c_firstyear', 'c_lastyear']);
        });
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'addr-tester@example.com'): User {
        return User::forceCreate([
            'name' => 'addr tester',
            'email' => $email,
            'confirmation_token' => 'token-addr',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    // ── ADDR_CODES：新增 ────────────────────────────────────

    #[Test]
    public function testAddrCodesCreateAutoAssignsIdAndAudits(): void {
        $this->actingAs($this->makeUser(email: 'addr-create@example.com'));
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 100, 'c_name' => 'Existing', 'c_admin_cat_code' => 0]);

        $response = $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            // 主鍵由服務端分配時仍須帶空的 target.pk（API.md §4.4 的既有契約）
            'target' => ['pk' => []],
            'changes' => [
                'c_name' => 'Xinzhou',
                'c_name_chn' => '新州',
                'c_firstyear' => 960,
                'c_lastyear' => 1279,
                'c_admin_type' => 'zhou',
                'x_coord' => 112.5,
                'y_coord' => 34.25,
                'c_notes' => '測試用地名',
            ],
        ]);

        $response->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'addr-codes',
            'operation' => 'create',
            'result' => ['pk' => ['c_addr_id' => 101], 'status' => 'created'],
        ]);

        $this->assertDatabaseHas('ADDR_CODES', [
            'c_addr_id' => 101,
            'c_name' => 'Xinzhou',
            'c_name_chn' => '新州',
            'c_firstyear' => 960,
            'c_admin_type' => 'zhou',
        ]);
        // 稽核欄由 ToolsRepository::timestamp() 蓋章——ADDR_CODES 補上這 4 欄正是新增能成功的前提
        $row = DB::table('ADDR_CODES')->where('c_addr_id', 101)->first();
        $this->assertNotNull($row->c_created_by);
        $this->assertNotNull($row->c_created_date);

        $this->assertDatabaseHas('audit_log', ['table_name' => 'ADDR_CODES', 'operation' => 'INSERT']);
        $this->assertDatabaseHas('operations', ['resource' => 'ADDR_CODES', 'op_type' => Operation::TYPE_CREATE]);
    }

    #[Test]
    public function testAddrCodesCreateAcceptsExplicitIdAndRejectsDuplicate(): void {
        $this->actingAs($this->makeUser(email: 'addr-create-explicit@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 5000]],
            'changes' => ['c_name_chn' => '臨安府'],
        ])->assertOk()->assertJson(['result' => ['pk' => ['c_addr_id' => 5000]]]);

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 5000]],
            'changes' => ['c_name_chn' => '重複'],
        ])->assertStatus(409);

        $this->assertSame(1, DB::table('ADDR_CODES')->where('c_addr_id', 5000)->count());
    }

    #[Test]
    public function testAddrCodesCreateRejectsWrongTypesAndNullNotNullColumn(): void {
        $this->actingAs($this->makeUser(email: 'addr-create-types@example.com'));

        // c_firstyear 是整數欄：可以送整數（上面已驗），送陣列等非純量一律 422
        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => ['c_name' => 'X', 'c_firstyear' => ['bad']],
        ])->assertStatus(422);

        // c_admin_cat_code 是 NOT NULL：可以整個不送（吃預設 0），但明確送 null 要 422
        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => ['c_name' => 'Y', 'c_admin_cat_code' => null],
        ])->assertStatus(422);

        // c_name 是 varchar(255)：超長要 422 而不是資料庫層截斷／報錯
        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => ['c_name' => str_repeat('a', 256)],
        ])->assertStatus(422);

        // 但 c_notes 是 longtext，不該被 255 上限誤擋
        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => ['c_name' => 'LongNotes', 'c_notes' => str_repeat('註', 600)],
        ])->assertOk();

        $this->assertSame(1, DB::table('ADDR_CODES')->count());
    }

    #[Test]
    public function testAddrCodesCreateRejectsUnknownField(): void {
        $this->actingAs($this->makeUser(email: 'addr-create-unknown@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => ['c_name' => 'X', 'c_created_by' => '偽造署名'],
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('ADDR_CODES')->count());
    }

    #[Test]
    public function testAddrCodesCreateForbiddenForInactiveUser(): void {
        $this->actingAs($this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'addr-create-inactive@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => ['c_name' => 'Nope'],
        ])->assertStatus(403);

        $this->assertSame(0, DB::table('ADDR_CODES')->count());
    }

    // ── ADDR_CODES：更新 ────────────────────────────────────

    #[Test]
    public function testAddrCodesUpdateAcceptsEveryCreatableField(): void {
        // 迴歸：白名單原本只有 c_name——新增填得進去、之後改不了（AGENTS §4「必填欄位
        // create／update 一致」的同一類問題）。這裡逐欄驗兩端對稱。
        $this->actingAs($this->makeUser(email: 'addr-update@example.com'));
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 200, 'c_name' => 'Old', 'c_admin_cat_code' => 0]);

        $changes = [
            'c_name' => 'New',
            'c_name_chn' => '新名',
            'c_alt_names' => '別名',
            'c_firstyear' => 1000,
            'c_lastyear' => 1100,
            'c_admin_type' => 'fu',
            'c_admin_cat_code' => 3,
            'x_coord' => 120.0,
            'y_coord' => 30.5,
            'CHGIS_PT_ID' => 12345,
            'c_notes' => '註記',
        ];

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 200]],
            'changes' => $changes,
        ])->assertOk();

        $this->assertDatabaseHas('ADDR_CODES', ['c_addr_id' => 200] + array_diff_key($changes, array_flip(['x_coord', 'y_coord'])));
        $row = DB::table('ADDR_CODES')->where('c_addr_id', 200)->first();
        $this->assertSame(120.0, (float) $row->x_coord);
        $this->assertSame(30.5, (float) $row->y_coord);
    }

    #[Test]
    public function testAddrCodesUpdateRejectsNullOnNotNullColumn(): void {
        $this->actingAs($this->makeUser(email: 'addr-update-null@example.com'));
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 201, 'c_name' => 'Keep', 'c_admin_cat_code' => 7]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 201]],
            'changes' => ['c_admin_cat_code' => null],
        ])->assertStatus(422);

        $this->assertDatabaseHas('ADDR_CODES', ['c_addr_id' => 201, 'c_admin_cat_code' => 7]);
    }

    #[Test]
    public function testAddrCodesUpdateStillRejectsPrimaryKeyChange(): void {
        $this->actingAs($this->makeUser(email: 'addr-update-pk@example.com'));
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 202, 'c_name' => 'Keep', 'c_admin_cat_code' => 0]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 202]],
            'changes' => ['c_addr_id' => 999],
        ])->assertStatus(422);

        $this->assertDatabaseHas('ADDR_CODES', ['c_addr_id' => 202]);
        $this->assertDatabaseMissing('ADDR_CODES', ['c_addr_id' => 999]);
    }

    // ── ADDR_BELONGS_DATA：複合主鍵新增／更新 ────────────────

    #[Test]
    public function testAddrBelongsCreateRequiresEveryKeyColumn(): void {
        $this->actingAs($this->makeUser(email: 'belongs-missing-key@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-belongs-data',
            'person_id' => 0,
            'mode' => 'direct',
            // 缺 c_lastyear
            'target' => ['pk' => ['c_addr_id' => 1, 'c_belongs_to' => 2, 'c_firstyear' => 900]],
            'changes' => ['c_pages' => '3'],
        ])->assertStatus(422)
            ->assertJsonFragment(['target.pk.c_lastyear' => ['required']]);

        $this->assertSame(0, DB::table('ADDR_BELONGS_DATA')->count());
    }

    #[Test]
    public function testAddrBelongsCreateAndCompositeKeyUpdate(): void {
        $this->actingAs($this->makeUser(email: 'belongs-create@example.com'));
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 10, 'c_name' => 'Child', 'c_admin_cat_code' => 0],
            ['c_addr_id' => 20, 'c_name' => 'Parent', 'c_admin_cat_code' => 0],
        ]);

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-belongs-data',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 10, 'c_belongs_to' => 20, 'c_firstyear' => 960, 'c_lastyear' => 1279]],
            'changes' => ['c_pages' => '12-15', 'c_notes' => '宋代隸屬'],
        ])->assertOk()->assertJson([
            'resource' => 'addr-belongs-data',
            'result' => [
                'pk' => ['c_addr_id' => 10, 'c_belongs_to' => 20, 'c_firstyear' => 960, 'c_lastyear' => 1279],
                'status' => 'created',
            ],
        ]);

        // 另一段年份的隸屬記錄：主鍵不同，必須能並存
        $this->postJson('/api/v2/create', [
            'resource' => 'addr-belongs-data',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 10, 'c_belongs_to' => 20, 'c_firstyear' => 1280, 'c_lastyear' => 1368]],
            'changes' => ['c_pages' => '90'],
        ])->assertOk();

        $this->assertSame(2, DB::table('ADDR_BELONGS_DATA')->count());

        // 複合主鍵 update 必須精準命中一列
        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_belongs_data',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 10, 'c_belongs_to' => 20, 'c_firstyear' => 960, 'c_lastyear' => 1279]],
            'changes' => ['c_pages' => '16-18'],
        ])->assertOk();

        $this->assertDatabaseHas('ADDR_BELONGS_DATA', ['c_firstyear' => 960, 'c_pages' => '16-18']);
        $this->assertDatabaseHas('ADDR_BELONGS_DATA', ['c_firstyear' => 1280, 'c_pages' => '90']);
    }

    #[Test]
    public function testAddrBelongsCreateRejectsDuplicateKey(): void {
        $this->actingAs($this->makeUser(email: 'belongs-dup@example.com'));
        DB::table('ADDR_BELONGS_DATA')->insert([
            'c_addr_id' => 10, 'c_belongs_to' => 20, 'c_firstyear' => 960, 'c_lastyear' => 1279,
        ]);

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-belongs-data',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 10, 'c_belongs_to' => 20, 'c_firstyear' => 960, 'c_lastyear' => 1279]],
            'changes' => ['c_pages' => '1'],
        ])->assertStatus(409);

        $this->assertSame(1, DB::table('ADDR_BELONGS_DATA')->count());
    }

    #[Test]
    public function testAddrBelongsCreateRejectsNonNumericPrimaryKey(): void {
        // `(int) 'abc'` 會靜默變成 0，而 0 在 CBDB 的年份欄是合法值——不擋就會憑空生出
        // 一列鍵值錯誤、事後看不出異常的記錄。
        $this->actingAs($this->makeUser(email: 'belongs-badkey@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-belongs-data',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 10, 'c_belongs_to' => 20, 'c_firstyear' => 'abc', 'c_lastyear' => 1279]],
            'changes' => ['c_pages' => '1'],
        ])->assertStatus(422)
            ->assertJsonFragment(['target.pk.c_firstyear' => ['numeric']]);

        $this->assertSame(0, DB::table('ADDR_BELONGS_DATA')->count());
    }

    #[Test]
    public function testAddrBelongsUpdateOnUnknownPkReturns404(): void {
        $this->actingAs($this->makeUser(email: 'belongs-404@example.com'));

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_belongs_data',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 1, 'c_belongs_to' => 2, 'c_firstyear' => 3, 'c_lastyear' => 4]],
            'changes' => ['c_pages' => 'x'],
        ])->assertStatus(404);
    }
}
