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
 * 行政類別代碼（ADMIN_CAT_CODES）的 v2 mutation API。
 *
 * 回報：「只能 update c_admin_cat_py；沒有 create」。要建一個新的行政類別只能走
 * /codes UI，機器化匯入地名時就卡在這裡（`ADDR_CODES.c_admin_cat_code` 帶外鍵，
 * 引用不存在的類別會被擋下）。
 *
 * 本表**沒有** c_created_by／c_modified_by 那組稽核欄（多數代碼表都沒有）。這是刻意
 * 不補的：v2 的每一次寫入都已經在 `operations` 與 `audit_log` 留下操作者與時間，補欄
 * 只會讓 /codes 列表多出四個永遠是空的欄。下面有一支測試把「沒有稽核欄也要能新增」
 * 釘住——`CodeTableCreateHandler` 按實際欄位蓋章，這條路徑不能因為缺欄而 500。
 */
class ApiV2MutateAdminCatCodesTest extends TestCase {
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

        $this->createSupportTables();
        $this->createAdminCatCodes();
    }

    protected function tearDown(): void {
        foreach (['ADMIN_CAT_CODES', 'char_variant_map', 'audit_log', 'operations', 'users'] as $t) {
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
    }

    /** 只有需要驗落地替換的案例才建（其餘案例刻意不建，確認缺表時不會炸）。 */
    protected function createCharVariantMap(): void {
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

    /** 型別比照 prod，且**刻意不加**稽核欄（prod 就是沒有）。 */
    protected function createAdminCatCodes(): void {
        DB::statement('CREATE TABLE "ADMIN_CAT_CODES" (
            "c_admin_cat_code" smallint NOT NULL PRIMARY KEY,
            "c_admin_cat_py" varchar(255) NULL,
            "c_admin_cat_hz" varchar(255) NULL,
            "c_admin_cat_trans" varchar(255) NULL,
            "c_notes" longtext NULL
        )');
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'admincat@example.com'): User {
        return User::forceCreate([
            'name' => '行政類別編輯者',
            'email' => $email,
            'confirmation_token' => 'token-admincat',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    #[Test]
    public function testCreateAutoAssignsIdOnTableWithoutAuditColumns(): void {
        $this->actingAs($this->makeUser(email: 'admincat-create@example.com'));
        DB::table('ADMIN_CAT_CODES')->insert(['c_admin_cat_code' => 40, 'c_admin_cat_py' => 'zhou', 'c_admin_cat_hz' => '州']);

        $this->postJson('/api/v2/create', [
            'resource' => 'admin-cat-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => [
                'c_admin_cat_py' => 'xian',
                'c_admin_cat_hz' => '縣',
                'c_admin_cat_trans' => 'county',
                'c_notes' => '測試用類別',
            ],
        ])->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'admin-cat-codes',
            'operation' => 'create',
            'result' => ['pk' => ['c_admin_cat_code' => 41], 'status' => 'created'],
        ]);

        $this->assertDatabaseHas('ADMIN_CAT_CODES', [
            'c_admin_cat_code' => 41,
            'c_admin_cat_py' => 'xian',
            'c_admin_cat_hz' => '縣',
            'c_admin_cat_trans' => 'county',
        ]);
        $this->assertDatabaseHas('audit_log', ['table_name' => 'ADMIN_CAT_CODES', 'operation' => 'INSERT']);
        $this->assertDatabaseHas('operations', ['resource' => 'ADMIN_CAT_CODES', 'op_type' => Operation::TYPE_CREATE]);
    }

    #[Test]
    public function testCreateWithExplicitIdAndDuplicateConflict(): void {
        $this->actingAs($this->makeUser(email: 'admincat-explicit@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'admin-cat-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_admin_cat_code' => 900]],
            'changes' => ['c_admin_cat_hz' => '鎮'],
        ])->assertOk()->assertJson(['result' => ['pk' => ['c_admin_cat_code' => 900]]]);

        $this->postJson('/api/v2/create', [
            'resource' => 'admin-cat-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_admin_cat_code' => 900]],
            'changes' => ['c_admin_cat_hz' => '重複'],
        ])->assertStatus(409);

        $this->assertSame(1, DB::table('ADMIN_CAT_CODES')->count());
    }

    #[Test]
    public function testCreateRejectsOutOfRangeSmallintId(): void {
        // c_admin_cat_code 是 smallint：非 strict 的 MariaDB 會把 40000 截斷成 32767，
        // 那一列就被建在呼叫端沒有指定的鍵上。
        $this->actingAs($this->makeUser(email: 'admincat-range@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'admin-cat-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_admin_cat_code' => 40000]],
            'changes' => ['c_admin_cat_hz' => '太大'],
        ])->assertStatus(422)
            ->assertJsonFragment(['target.pk.c_admin_cat_code' => ['out_of_range:-32768..32767']]);

        $this->assertSame(0, DB::table('ADMIN_CAT_CODES')->count());
    }

    #[Test]
    public function testUpdateAcceptsEveryCreatableField(): void {
        // 迴歸：白名單原本只有 c_admin_cat_py——漢字名、英譯、註記新增時填得進去、
        // 之後改不了。
        $this->actingAs($this->makeUser(email: 'admincat-update@example.com'));
        DB::table('ADMIN_CAT_CODES')->insert(['c_admin_cat_code' => 50, 'c_admin_cat_py' => 'old']);

        $changes = [
            'c_admin_cat_py' => 'fu',
            'c_admin_cat_hz' => '府',
            'c_admin_cat_trans' => 'prefecture',
            'c_notes' => '註記',
        ];

        $this->postJson('/api/v2/mutate', [
            'resource' => 'admin_cat_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_admin_cat_code' => 50]],
            'changes' => $changes,
        ])->assertOk();

        $this->assertDatabaseHas('ADMIN_CAT_CODES', ['c_admin_cat_code' => 50] + $changes);
    }

    #[Test]
    public function testLongNotesAreNotRejectedByThe255Cap(): void {
        // c_notes 是 longtext；沒登記 long_text_fields 的話會被基底的 255 上限誤擋。
        $this->actingAs($this->makeUser(email: 'admincat-notes@example.com'));
        DB::table('ADMIN_CAT_CODES')->insert(['c_admin_cat_code' => 51, 'c_admin_cat_py' => 'x']);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'admin_cat_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_admin_cat_code' => 51]],
            'changes' => ['c_notes' => str_repeat('註', 600)],
        ])->assertOk();

        $this->assertSame(600, mb_strlen((string) DB::table('ADMIN_CAT_CODES')->where('c_admin_cat_code', 51)->value('c_notes')));
    }

    #[Test]
    public function testPinyinTier1StillNormalizesVToUmlaut(): void {
        // 擴大白名單不可影響既有的 §D-6 行為：c_admin_cat_py 仍是 Tier 1（後端靜默轉）。
        $this->actingAs($this->makeUser(email: 'admincat-tier1@example.com'));
        DB::table('ADMIN_CAT_CODES')->insert(['c_admin_cat_code' => 52, 'c_admin_cat_hz' => '呂']);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'admin_cat_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_admin_cat_code' => 52]],
            'changes' => ['c_admin_cat_py' => 'lv'],
        ])->assertOk();

        $this->assertSame('lü', DB::table('ADMIN_CAT_CODES')->where('c_admin_cat_code', 52)->value('c_admin_cat_py'));
    }

    #[Test]
    public function testCreateAlsoNormalizesTier1PinyinLikeUpdateDoes(): void {
        // 迴歸：create 端原本**沒有** §D-6 的 v→ü 歸一化，於是同一個 `lv` 走
        // /api/v2/create 存 lv、走 /api/v2/mutate 或 /codes 存 lü——同一欄兩種寫法。
        // Tier 定義只有一份（config/code_table_mutations.php），create 端按表名去查。
        $this->actingAs($this->makeUser(email: 'admincat-create-tier1@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'admin-cat-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_admin_cat_code' => 600]],
            'changes' => ['c_admin_cat_py' => 'lv', 'c_admin_cat_hz' => '呂'],
        ])->assertOk();

        $this->assertSame('lü', DB::table('ADMIN_CAT_CODES')->where('c_admin_cat_code', 600)->value('c_admin_cat_py'));

        // 非 Tier 1 的欄位不轉（c_admin_cat_trans 是英譯，可能含西文）
        $this->postJson('/api/v2/create', [
            'resource' => 'admin-cat-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_admin_cat_code' => 601]],
            'changes' => ['c_admin_cat_trans' => 'Lvov'],
        ])->assertOk();

        $this->assertSame('Lvov', DB::table('ADMIN_CAT_CODES')->where('c_admin_cat_code', 601)->value('c_admin_cat_trans'));
    }

    #[Test]
    public function testChineseColumnIsVariantReplacedWithNotice(): void {
        // c_admin_cat_hz 是新開放的中文欄，必須經落地替換（AGENTS §1.3），
        // 且替換發生了要讓使用者知道。它只用於顯示、不是 label→code 查表鍵，
        // 所以替換不會打斷任何關聯（本表的 join 走數值 c_admin_cat_code）。
        $this->createCharVariantMap();
        $this->actingAs($this->makeUser(email: 'admincat-variant@example.com'));

        $response = $this->postJson('/api/v2/create', [
            'resource' => 'admin-cat-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_admin_cat_code' => 700]],
            'changes' => ['c_admin_cat_hz' => '淸州', 'c_notes' => '淸代設置'],
        ])->assertOk();

        $this->assertDatabaseHas('ADMIN_CAT_CODES', ['c_admin_cat_code' => 700, 'c_admin_cat_hz' => '清州', 'c_notes' => '清代設置']);
        $this->assertNotEmpty($response->json('notices'), '替換發生了卻沒有回 notices（AGENTS §1.3）');
    }

    #[Test]
    public function testDeleteStaysDisabled(): void {
        // 本表被 ADDR_CODES.c_admin_cat_code 與 ADMIN_CAT_CODE_TYPE_REL 以外鍵引用，
        // 刪除維持全面停用。
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN, 'admincat-delete@example.com'));
        DB::table('ADMIN_CAT_CODES')->insert(['c_admin_cat_code' => 53, 'c_admin_cat_py' => 'x']);

        $this->postJson('/api/v2/delete', [
            'resource' => 'admin-cat-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_admin_cat_code' => 53]],
        ])->assertStatus(403);

        $this->assertDatabaseHas('ADMIN_CAT_CODES', ['c_admin_cat_code' => 53]);
    }

    #[Test]
    public function testCreateForbiddenForInactiveUserAndProposalUnsupported(): void {
        $this->actingAs($this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'admincat-inactive@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'admin-cat-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => ['c_admin_cat_hz' => '不可'],
        ])->assertStatus(403);

        // 代碼表可以提案修改、但不能提案新增（create 只支援 direct）→ 501 找不到 handler
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'admincat-crowd@example.com'));
        $this->postJson('/api/v2/create', [
            'resource' => 'admin-cat-codes',
            'person_id' => 0,
            'mode' => 'proposal',
            'target' => ['pk' => []],
            'changes' => ['c_admin_cat_hz' => '提案'],
        ])->assertStatus(501);

        $this->assertSame(0, DB::table('ADMIN_CAT_CODES')->count());
    }
}
