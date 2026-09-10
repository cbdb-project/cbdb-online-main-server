<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 地名表寫入的資料完整性行為：外鍵、異體字落地替換、稽核欄。
 *
 * 與 {@see ApiV2MutateAddrTablesTest} 分開的理由是**建表方式不同**：那支用不帶外鍵的
 * 合成表驗一般 CRUD；這支必須**真的宣告外鍵並開啟 SQLite 的 PRAGMA foreign_keys**，
 * 否則 `CodeTableCreateHandler::isForeignKeyViolation()` 一行都跑不到——外鍵錯誤轉 422
 * 的契約會變成「寫了但從沒驗過」，訊息字串打錯也照樣綠。
 */
class ApiV2MutateAddrIntegrityTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('app.env', 'testing');
        $this->app['env'] = 'testing';
        config()->set('prometheus.enabled', false);
        config()->set('prometheus.storage_adapter', 'memory');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        // 預設 SQLite 連線不強制外鍵，不開的話下面的 FK 宣告只是裝飾。
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA foreign_keys = ON');

        $this->createSupportTables();
        $this->createAddrTables();
        $this->createCharVariantMapTable();
    }

    protected function tearDown(): void {
        foreach (['ADDR_BELONGS_DATA', 'ADDR_CODES', 'TEXT_CODES', 'char_variant_map', 'audit_log', 'operations', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    protected function createSupportTables(): void {
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

        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title')->nullable();
        });
    }

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

        // 外鍵比照 prod（import_cbdb_schema 的 ADDR_BELONGS_DATA_ibfk_1..3）
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
            $table->foreign('c_addr_id')->references('c_addr_id')->on('ADDR_CODES')->restrictOnDelete();
            $table->foreign('c_belongs_to')->references('c_addr_id')->on('ADDR_CODES')->restrictOnDelete();
            $table->foreign('c_source')->references('c_textid')->on('TEXT_CODES')->restrictOnDelete();
        });
    }

    protected function createCharVariantMapTable(): void {
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10)->unique();
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(0);
            $table->string('c_notes')->nullable();
            $table->timestamps();
        });

        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
        ]);
    }

    protected function makeUser(string $email): User {
        return User::forceCreate([
            'name' => '地名編輯者',
            'email' => $email,
            'confirmation_token' => 'token-addr-integrity',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);
    }

    // ── 外鍵 ────────────────────────────────────────────────

    #[Test]
    public function testUnknownParentAddressReturns422NotServerError(): void {
        $this->actingAs($this->makeUser('addr-fk-parent@example.com'));
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 1, 'c_name' => 'Child', 'c_admin_cat_code' => 0]);

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-belongs-data',
            'person_id' => 0,
            'mode' => 'direct',
            // c_belongs_to = 999 不存在於 ADDR_CODES
            'target' => ['pk' => ['c_addr_id' => 1, 'c_belongs_to' => 999, 'c_firstyear' => 900, 'c_lastyear' => 1000]],
            'changes' => ['c_pages' => '1'],
        ])->assertStatus(422)
            ->assertJsonFragment(['changes' => ['foreign_key_violation']]);

        $this->assertSame(0, DB::table('ADDR_BELONGS_DATA')->count());
    }

    #[Test]
    public function testUnknownSourceTextReturns422(): void {
        $this->actingAs($this->makeUser('addr-fk-source@example.com'));
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 1, 'c_name' => 'Child', 'c_admin_cat_code' => 0],
            ['c_addr_id' => 2, 'c_name' => 'Parent', 'c_admin_cat_code' => 0],
        ]);

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-belongs-data',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 1, 'c_belongs_to' => 2, 'c_firstyear' => 900, 'c_lastyear' => 1000]],
            'changes' => ['c_source' => 8888],
        ])->assertStatus(422)
            ->assertJsonFragment(['changes' => ['foreign_key_violation']]);

        $this->assertSame(0, DB::table('ADDR_BELONGS_DATA')->count());
    }

    #[Test]
    public function testValidForeignKeysStillSucceed(): void {
        // 反面對照：確認上面兩支不是因為別的原因才 422（外鍵齊備時必須寫得進去）
        $this->actingAs($this->makeUser('addr-fk-ok@example.com'));
        DB::table('TEXT_CODES')->insert(['c_textid' => 77, 'c_title' => 'Song shi']);
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 1, 'c_name' => 'Child', 'c_admin_cat_code' => 0],
            ['c_addr_id' => 2, 'c_name' => 'Parent', 'c_admin_cat_code' => 0],
        ]);

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-belongs-data',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_addr_id' => 1, 'c_belongs_to' => 2, 'c_firstyear' => 900, 'c_lastyear' => 1000]],
            'changes' => ['c_source' => 77, 'c_pages' => '5'],
        ])->assertOk();

        $this->assertDatabaseHas('ADDR_BELONGS_DATA', ['c_addr_id' => 1, 'c_belongs_to' => 2, 'c_source' => 77]);
    }

    #[Test]
    public function testUpdateWithUnknownForeignKeyReturns422NotServerError(): void {
        // 迴歸：外鍵轉 422 原本只做在 create 端。update 端一樣有外鍵欄
        // （這次新開放的 c_admin_cat_code／c_source），漏掉的話 MariaDB 1452 會冒成 500，
        // SQLite 則被誤判成 409「請重試」——一個永遠不會成功的重試迴圈。
        $this->actingAs($this->makeUser('addr-update-fk@example.com'));
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 1, 'c_name' => 'Child', 'c_admin_cat_code' => 0],
            ['c_addr_id' => 2, 'c_name' => 'Parent', 'c_admin_cat_code' => 0],
        ]);
        DB::table('ADDR_BELONGS_DATA')->insert([
            'c_addr_id' => 1, 'c_belongs_to' => 2, 'c_firstyear' => 900, 'c_lastyear' => 1000,
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_belongs_data',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 1, 'c_belongs_to' => 2, 'c_firstyear' => 900, 'c_lastyear' => 1000]],
            'changes' => ['c_source' => 8888],
        ])->assertStatus(422)
            ->assertJsonFragment(['changes' => ['foreign_key_violation']]);

        $this->assertDatabaseHas('ADDR_BELONGS_DATA', ['c_addr_id' => 1, 'c_source' => null]);
    }

    #[Test]
    public function testIntegralFloatIsAcceptedForIntegerColumnsOnBothEnds(): void {
        // JSON 序列化器常把整數送成 1200.0；兩端都要收，且落庫要是整數。
        $this->actingAs($this->makeUser('addr-float-int@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => ['c_name' => 'FloatYear', 'c_firstyear' => 1200.0],
        ])->assertOk();
        $created = DB::table('ADDR_CODES')->where('c_name', 'FloatYear')->first();
        $this->assertSame(1200, (int) $created->c_firstyear);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => (int) $created->c_addr_id]],
            'changes' => ['c_lastyear' => 1279.0],
        ])->assertOk();
        $this->assertSame(1279, (int) DB::table('ADDR_CODES')->where('c_addr_id', $created->c_addr_id)->value('c_lastyear'));

        // 真的帶小數則兩端都 422（靜默截斷年份比報錯糟）
        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => (int) $created->c_addr_id]],
            'changes' => ['c_lastyear' => 1279.5],
        ])->assertStatus(422);
    }

    #[Test]
    public function testTextColumnAcceptsJsonNumberOnCreateButNotOnUpdate(): void {
        // **刻意的不對稱**，兩邊都釘住：
        //  - create 在加上校驗之前完全沒有型別檢查，外部 token 客戶端把頁碼寫成 JSON
        //    數字一直是能用的，改判 422 是破壞性變更 → 沿用資料庫原本的隱式轉型。
        //  - update 從第一天就要求 string|null，而且「integer_fields 的放寬不得波及
        //    未登記欄位」是刻意驗過的護欄（見 ApiV2MutateCodeTablesTest 的干支案例），
        //    不為了對稱而拆。
        // 這個差異寫在 API.md 與 CodeTableFieldValidator::normalize() 的類註裡。
        $this->actingAs($this->makeUser('addr-number-text@example.com'));
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 1, 'c_name' => 'Child', 'c_admin_cat_code' => 0],
            ['c_addr_id' => 2, 'c_name' => 'Parent', 'c_admin_cat_code' => 0],
        ]);

        $pk = ['c_addr_id' => 1, 'c_belongs_to' => 2, 'c_firstyear' => 900, 'c_lastyear' => 1000];

        $this->postJson('/api/v2/create', [
            'resource' => 'addr-belongs-data',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => $pk],
            'changes' => ['c_pages' => 12],
        ])->assertOk();
        $this->assertSame('12', DB::table('ADDR_BELONGS_DATA')->where('c_addr_id', 1)->value('c_pages'));

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_belongs_data',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => $pk],
            'changes' => ['c_pages' => 34],
        ])->assertStatus(422);
        $this->assertSame('12', DB::table('ADDR_BELONGS_DATA')->where('c_addr_id', 1)->value('c_pages'));

        // 字串形式的同一個值在 update 端當然可以
        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_belongs_data',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => $pk],
            'changes' => ['c_pages' => '34'],
        ])->assertOk();
        $this->assertSame('34', DB::table('ADDR_BELONGS_DATA')->where('c_addr_id', 1)->value('c_pages'));
    }

    #[Test]
    public function testNonNumericStringIsRejectedForNumericColumns(): void {
        // 本專案的 config/database.php 設 strict => false，MariaDB 會把 "not-a-year"
        // **靜默轉成 0**——而 0 在年份／座標都是合法值，事後看不出那是壞資料。
        // strict 的部署則是 1366 一路冒成 500。兩種都不能接受，要在 422 擋下。
        $this->actingAs($this->makeUser('addr-bad-numeric@example.com'));
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 500, 'c_name' => 'Keep', 'c_firstyear' => 960, 'c_admin_cat_code' => 0]);

        foreach ([
            ['c_firstyear' => 'not-a-year'],
            ['c_firstyear' => '12foo'],
            ['x_coord' => 'east'],
            ['CHGIS_PT_ID' => '1e5'],
        ] as $changes) {
            $this->postJson('/api/v2/mutate', [
                'resource' => 'addr_codes',
                'person_id' => 0,
                'mode' => 'direct',
                'operation' => 'update',
                'target' => ['pk' => ['c_addr_id' => 500]],
                'changes' => $changes,
            ])->assertStatus(422);

            $this->postJson('/api/v2/create', [
                'resource' => 'addr-codes',
                'person_id' => 0,
                'mode' => 'direct',
                'target' => ['pk' => []],
                'changes' => $changes + ['c_name' => 'Bad'],
            ])->assertStatus(422);
        }

        // 原列未被動到，也沒有多出任何新列
        $this->assertDatabaseHas('ADDR_CODES', ['c_addr_id' => 500, 'c_firstyear' => 960]);
        $this->assertSame(1, DB::table('ADDR_CODES')->count());

        // 反面對照：數值字串是合法的（前端表單送的就是字串）
        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 500]],
            'changes' => ['c_firstyear' => '1279', 'x_coord' => '-112.5'],
        ])->assertOk();
        $this->assertSame(1279, (int) DB::table('ADDR_CODES')->where('c_addr_id', 500)->value('c_firstyear'));
    }

    #[Test]
    public function testEmptyStringClearsNullableNumericColumnInsteadOfWritingZero(): void {
        // 表單清空欄位送的是 ''；直接落庫在非 strict 的 MariaDB 會變成 0，
        // 而 0 是合法年份——「清空」被靜默曲解成「填 0」。
        $this->actingAs($this->makeUser('addr-empty-numeric@example.com'));
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 501, 'c_name' => 'Keep', 'c_firstyear' => 960, 'c_admin_cat_code' => 0]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 501]],
            'changes' => ['c_firstyear' => ''],
        ])->assertOk();

        $this->assertNull(DB::table('ADDR_CODES')->where('c_addr_id', 501)->value('c_firstyear'));
    }

    // ── 異體字落地替換（AGENTS §1.3）────────────────────────

    #[Test]
    public function testChineseColumnsAreVariantReplacedOnCreateWithNotice(): void {
        // 新開放的中文欄（c_name_chn／c_alt_names／c_notes）都必須經落地替換，
        // 且替換發生了要讓使用者知道。
        $this->actingAs($this->makeUser('addr-variant-create@example.com'));

        $response = $this->postJson('/api/v2/create', [
            'resource' => 'addr-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => [
                'c_name' => 'Qingzhou',
                'c_name_chn' => '淸州',
                'c_alt_names' => '淸邑',
                'c_notes' => '淸代設州',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('ADDR_CODES', [
            'c_name_chn' => '清州',
            'c_alt_names' => '清邑',
            'c_notes' => '清代設州',
        ]);
        $this->assertNotEmpty($response->json('notices'), '替換發生了卻沒有回 notices（AGENTS §1.3）');

        // 羅馬字欄不該被碰
        $this->assertDatabaseHas('ADDR_CODES', ['c_name' => 'Qingzhou']);
    }

    #[Test]
    public function testChineseColumnsAreVariantReplacedOnUpdate(): void {
        $this->actingAs($this->makeUser('addr-variant-update@example.com'));
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 300, 'c_name' => 'X', 'c_admin_cat_code' => 0]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 300]],
            'changes' => ['c_name_chn' => '淸河'],
        ])->assertOk();

        $this->assertDatabaseHas('ADDR_CODES', ['c_addr_id' => 300, 'c_name_chn' => '清河']);
    }

    // ── 稽核欄（AGENTS §1.2）────────────────────────────────

    #[Test]
    public function testUpdateStampsModifiedColumns(): void {
        // 迴歸：代碼表 update 路徑原本從不蓋 c_modified_*，同一列改走 /codes 表單卻會蓋，
        // 於是 c_modified_by 會依「最後是哪個介面改的」而時對時錯。
        $this->actingAs($this->makeUser('addr-modified@example.com'));
        DB::table('ADDR_CODES')->insert([
            'c_addr_id' => 400, 'c_name' => 'Before', 'c_admin_cat_code' => 0,
            'c_modified_by' => '前一位編輯者', 'c_modified_date' => '2020-01-01 00:00:00',
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 400]],
            'changes' => ['c_name' => 'After'],
        ])->assertOk();

        $row = DB::table('ADDR_CODES')->where('c_addr_id', 400)->first();
        $this->assertSame('地名編輯者', $row->c_modified_by);
        $this->assertNotSame('2020-01-01 00:00:00', $row->c_modified_date);
    }

    #[Test]
    public function testUpdateDoesNotOverwriteCreatedColumns(): void {
        $this->actingAs($this->makeUser('addr-created-kept@example.com'));
        DB::table('ADDR_CODES')->insert([
            'c_addr_id' => 401, 'c_name' => 'Before', 'c_admin_cat_code' => 0,
            'c_created_by' => '原始建檔者', 'c_created_date' => '2019-05-05 00:00:00',
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'addr_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_addr_id' => 401]],
            'changes' => ['c_name' => 'After'],
        ])->assertOk();

        $row = DB::table('ADDR_CODES')->where('c_addr_id', 401)->first();
        $this->assertSame('原始建檔者', $row->c_created_by);
    }
}
