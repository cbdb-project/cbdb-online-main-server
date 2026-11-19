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
 * 入仕途徑代碼（ENTRY_CODES）的 v2 mutation API（docs/ENTRY_CODES_V2_WRITE_PLAN.md）。
 *
 * 回報：下游錄入代理要新增三個入仕途徑，但沒有任何 token API 能新建 ENTRY_CODES 記錄
 * （/api/v2/create 回 501，update 端也沒有登錄），唯一入口是 /codes UI。
 *
 * 本表與 ADMIN_CAT_CODES 一樣**沒有**稽核欄；兩個名稱欄是 `NOT NULL DEFAULT ''`。
 */
class ApiV2MutateEntryCodesTest extends TestCase {
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
        $this->createEntryCodes();
    }

    protected function tearDown(): void {
        foreach (['ENTRY_CODES', 'char_variant_map', 'audit_log', 'operations', 'users'] as $t) {
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

    /** 只有需要驗落地替換的案例才建。 */
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
            ['c_variant_char' => '稅', 'c_reference_char' => '税', 'c_strict_excluded' => 0],
        ]);
    }

    /** 型別比照 prod（2025_11_14 migration 之後兩個名稱欄是 NOT NULL DEFAULT ''），無稽核欄。 */
    protected function createEntryCodes(): void {
        DB::statement('CREATE TABLE "ENTRY_CODES" (
            "c_entry_code" smallint NOT NULL PRIMARY KEY,
            "c_entry_desc" varchar(255) NOT NULL DEFAULT \'\',
            "c_entry_desc_chn" varchar(255) NOT NULL DEFAULT \'\'
        )');
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'entrycodes@example.com'): User {
        return User::forceCreate([
            'name' => '入仕途徑編輯者',
            'email' => $email,
            'confirmation_token' => 'token-entrycodes',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    /** @param array<string,mixed> $pk @param array<string,mixed> $changes */
    protected function create(string $resource, array $pk, array $changes, string $mode = 'direct') {
        return $this->postJson('/api/v2/create', [
            'resource' => $resource,
            'person_id' => 0,
            'mode' => $mode,
            'target' => ['pk' => $pk],
            'changes' => $changes,
        ]);
    }

    #[Test]
    public function testCreateAutoAssignsNextCodeOnTableWithoutAuditColumns(): void {
        $this->actingAs($this->makeUser(email: 'entry-create@example.com'));
        DB::table('ENTRY_CODES')->insert([
            ['c_entry_code' => 36, 'c_entry_desc' => 'examination: jinshi', 'c_entry_desc_chn' => '科舉: 進士'],
            ['c_entry_code' => 349, 'c_entry_desc' => 'physician', 'c_entry_desc_chn' => '醫生/醫學生'],
        ]);

        $this->create('entry-codes', [], [
            'c_entry_desc' => 'recommendation: jingming xingxiu',
            'c_entry_desc_chn' => '經明行修',
        ])->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'entry-codes',
            'operation' => 'create',
            'result' => ['pk' => ['c_entry_code' => 350], 'status' => 'created'],
        ]);

        $this->assertDatabaseHas('ENTRY_CODES', [
            'c_entry_code' => 350,
            'c_entry_desc' => 'recommendation: jingming xingxiu',
            'c_entry_desc_chn' => '經明行修',
        ]);
        $this->assertDatabaseHas('audit_log', ['table_name' => 'ENTRY_CODES', 'operation' => 'INSERT']);
        $this->assertDatabaseHas('operations', [
            'resource' => 'ENTRY_CODES',
            'op_type' => Operation::TYPE_CREATE,
            'c_personid' => 0,
        ]);
    }

    #[Test]
    public function testEveryAliasResolvesToEntryCodesNotThePersonEntrySubresource(): void {
        // entry／entries／entry_data 是人物子資源 ENTRY_DATA 的別名；本表的別名不可與之撞名，
        // 也不可被誤分派到人物入仕處理器。
        $this->actingAs($this->makeUser(email: 'entry-alias@example.com'));

        foreach (['entry-codes' => 500, 'entry_codes' => 501, 'entrycodes' => 502] as $alias => $code) {
            $this->create($alias, ['c_entry_code' => $code], ['c_entry_desc_chn' => "別名 {$alias}"])
                ->assertOk()
                ->assertJson(['resource' => 'entry-codes', 'result' => ['pk' => ['c_entry_code' => $code]]]);
        }

        $this->assertSame(3, DB::table('ENTRY_CODES')->count());

        // update 端同一組別名也都要認得（兩份 config 的別名集合由 CodeTableWriteConfigDriftTest 把關）。
        foreach (['entry-codes', 'entry_codes', 'entrycodes'] as $i => $alias) {
            $this->postJson('/api/v2/mutate', [
                'resource' => $alias,
                'person_id' => 0,
                'mode' => 'direct',
                'operation' => 'update',
                'target' => ['pk' => ['c_entry_code' => 500 + $i]],
                'changes' => ['c_entry_desc' => "updated via {$alias}"],
            ])->assertOk();
        }

        $this->assertSame('updated via entrycodes', DB::table('ENTRY_CODES')->where('c_entry_code', 502)->value('c_entry_desc'));
    }

    #[Test]
    public function testCreateWithExplicitCodeAndDuplicateConflict(): void {
        $this->actingAs($this->makeUser(email: 'entry-explicit@example.com'));

        $this->create('entry-codes', ['c_entry_code' => 900], ['c_entry_desc_chn' => '懷才抱德'])
            ->assertOk()->assertJson(['result' => ['pk' => ['c_entry_code' => 900]]]);

        $this->create('entry-codes', ['c_entry_code' => 900], ['c_entry_desc_chn' => '重複'])
            ->assertStatus(409);

        $this->assertSame(1, DB::table('ENTRY_CODES')->count());
        $this->assertSame('懷才抱德', DB::table('ENTRY_CODES')->where('c_entry_code', 900)->value('c_entry_desc_chn'));
    }

    #[Test]
    public function testOmittedNameColumnFallsBackToEmptyStringDefault(): void {
        // 只送中文名時，英文描述吃資料庫預設值 ''，不是 500。
        $this->actingAs($this->makeUser(email: 'entry-default@example.com'));

        $this->create('entry-codes', ['c_entry_code' => 910], ['c_entry_desc_chn' => '稅戶人才'])->assertOk();

        $this->assertSame('', DB::table('ENTRY_CODES')->where('c_entry_code', 910)->value('c_entry_desc'));
    }

    #[Test]
    public function testNullNameIsRejectedWith422OnBothCreateAndUpdate(): void {
        // 兩欄皆 NOT NULL。斷言欄位級訊息而不只是 422：沒登記 not_null_fields 時資料庫層
        // 的 NOT NULL 例外會被 handler 兜底轉成 422 `changes: not_null_violation`，
        // 只看狀態碼的話這條測試證明不了登記有生效。
        $this->actingAs($this->makeUser(email: 'entry-null@example.com'));

        $this->create('entry-codes', ['c_entry_code' => 920], ['c_entry_desc_chn' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.c_entry_desc_chn.0', 'c_entry_desc_chn 不可為空');
        $this->assertSame(0, DB::table('ENTRY_CODES')->count());

        DB::table('ENTRY_CODES')->insert(['c_entry_code' => 921, 'c_entry_desc' => 'x', 'c_entry_desc_chn' => '原名']);
        $this->postJson('/api/v2/mutate', [
            'resource' => 'entry_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_entry_code' => 921]],
            'changes' => ['c_entry_desc' => null],
        ])->assertStatus(422)->assertJsonPath('errors.c_entry_desc.0', 'c_entry_desc 不可為空');

        $this->assertSame('x', DB::table('ENTRY_CODES')->where('c_entry_code', 921)->value('c_entry_desc'));
    }

    #[Test]
    public function testCreateRejectsOutOfRangeSmallintCode(): void {
        $this->actingAs($this->makeUser(email: 'entry-range@example.com'));

        $this->create('entry-codes', ['c_entry_code' => 40000], ['c_entry_desc_chn' => '太大'])
            ->assertStatus(422)
            ->assertJsonFragment(['target.pk.c_entry_code' => ['out_of_range:-32768..32767']]);

        $this->assertSame(0, DB::table('ENTRY_CODES')->count());
    }

    #[Test]
    public function testAutoAssignRefusesToOverflowTheSmallintRange(): void {
        // 最大碼已是 32767 時 max+1 = 32768：非 strict 的 MariaDB 會截斷回 32767、撞號回
        // 「並發衝突，請重試」而重試永遠一樣。要明確 422，並請呼叫端指定主鍵。
        $this->actingAs($this->makeUser(email: 'entry-autoassign-range@example.com'));
        DB::table('ENTRY_CODES')->insert(['c_entry_code' => 32767, 'c_entry_desc' => 'sentinel', 'c_entry_desc_chn' => '哨兵']);

        $this->create('entry-codes', [], ['c_entry_desc_chn' => '經明行修'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['target.pk.c_entry_code' => ['auto_assign_exhausted:-32768..32767']]);

        $this->assertSame(1, DB::table('ENTRY_CODES')->count());
        $this->assertDatabaseCount('operations', 0);
        $this->assertDatabaseCount('audit_log', 0);

        // 明確指定空號仍可新增。
        $this->create('entry-codes', ['c_entry_code' => 351], ['c_entry_desc_chn' => '經明行修'])->assertOk();
    }

    #[Test]
    public function testAutoAssignOnEmptyTableStartsAtOne(): void {
        $this->actingAs($this->makeUser(email: 'entry-autoassign-empty@example.com'));

        $this->create('entry-codes', [], ['c_entry_desc_chn' => '懷才抱德'])
            ->assertOk()->assertJson(['result' => ['pk' => ['c_entry_code' => 1]]]);
    }

    #[Test]
    public function testCreateRejectsFieldsOutsideTheWhitelist(): void {
        $this->actingAs($this->makeUser(email: 'entry-whitelist@example.com'));

        $this->create('entry-codes', ['c_entry_code' => 930], [
            'c_entry_desc_chn' => '白名單外',
            'c_notes' => '本表沒有這一欄',
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('ENTRY_CODES')->count());
    }

    #[Test]
    public function testUpdateAcceptsEveryCreatableFieldAndRecordsAudit(): void {
        $this->actingAs($this->makeUser(email: 'entry-update@example.com'));
        DB::table('ENTRY_CODES')->insert(['c_entry_code' => 940, 'c_entry_desc' => 'old', 'c_entry_desc_chn' => '舊名']);

        $changes = ['c_entry_desc' => 'recommendation: huaicai baode', 'c_entry_desc_chn' => '懷才抱德'];

        $this->postJson('/api/v2/mutate', [
            'resource' => 'entry-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_entry_code' => 940]],
            'changes' => $changes,
        ])->assertOk();

        $this->assertDatabaseHas('ENTRY_CODES', ['c_entry_code' => 940] + $changes);
        $this->assertDatabaseHas('audit_log', ['table_name' => 'ENTRY_CODES', 'operation' => 'UPDATE']);
        $this->assertDatabaseHas('operations', ['resource' => 'ENTRY_CODES', 'op_type' => Operation::TYPE_UPDATE]);
    }

    #[Test]
    public function testEmptyStringOverHttpIsRejectedLikeNull(): void {
        // CodeTableFieldValidator 本身允許文字型 NOT NULL 欄收 ''，但 HTTP 請求會先經全域
        // middleware ConvertEmptyStringsToNull 把 '' 變成 null，於是走 API 送 '' 等同送 null
        // → 422、原值不動。釘住實際對外行為，免得文件與測試又寫成「可以清空」。
        $this->actingAs($this->makeUser(email: 'entry-clear@example.com'));
        DB::table('ENTRY_CODES')->insert(['c_entry_code' => 941, 'c_entry_desc' => 'keep me', 'c_entry_desc_chn' => '名']);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'entry_codes',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_entry_code' => 941]],
            'changes' => ['c_entry_desc' => ''],
        ])->assertStatus(422)->assertJsonPath('errors.c_entry_desc.0', 'c_entry_desc 不可為空');

        $this->assertSame('keep me', DB::table('ENTRY_CODES')->where('c_entry_code', 941)->value('c_entry_desc'));
    }

    #[Test]
    public function testEnglishDescriptionIsNotPinyinNormalized(): void {
        // c_entry_desc 是英文描述，不在 §D-6 Tier 1：後端不可靜默把 v 轉成 ü。
        // 輸入用 Tier 1 規則一定會轉的 `lv`（`Lvov` 之類規則本來就不動，證明不了什麼）。
        $this->actingAs($this->makeUser(email: 'entry-tier@example.com'));

        $this->create('entry-codes', ['c_entry_code' => 950], ['c_entry_desc' => 'lv'])->assertOk();

        $this->assertSame('lv', DB::table('ENTRY_CODES')->where('c_entry_code', 950)->value('c_entry_desc'));
    }

    #[Test]
    public function testNeitherColumnIsRegisteredInAnyPinyinTier(): void {
        // Tier 2 只驅動 /codes 編輯器的 ü 彈窗，API 測不到；直接釘 config。
        // 列進任何 tier 都會改變 /codes UI 或觸發 cbdb:migrate-code-pinyin-v 掃描。
        $def = collect(config('code_table_mutations.tables'))->firstWhere('table', 'ENTRY_CODES');

        $this->assertNotNull($def);
        $this->assertSame([], $def['tier1_fields']);
        $this->assertSame([], $def['tier2_fields']);
    }

    #[Test]
    public function testChineseNameIsVariantReplacedWithNotice(): void {
        // AGENTS §1.3：中文名落庫前經異體字落地替換，且替換發生要回 notices。
        $this->createCharVariantMap();
        $this->actingAs($this->makeUser(email: 'entry-variant@example.com'));

        $response = $this->create('entry-codes', ['c_entry_code' => 960], ['c_entry_desc_chn' => '稅戶人才'])->assertOk();

        $this->assertDatabaseHas('ENTRY_CODES', ['c_entry_code' => 960, 'c_entry_desc_chn' => '税戶人才']);
        $this->assertNotEmpty($response->json('notices'), '替換發生了卻沒有回 notices（AGENTS §1.3）');
    }

    #[Test]
    public function testUpdateProposalIsQueuedWithoutTouchingTheRow(): void {
        // 代碼表的 update 可提案（待審核），不直接落庫。
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'entry-proposal@example.com'));
        DB::table('ENTRY_CODES')->insert(['c_entry_code' => 970, 'c_entry_desc' => 'x', 'c_entry_desc_chn' => '原名']);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'entry_codes',
            'person_id' => 0,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => ['pk' => ['c_entry_code' => 970]],
            'changes' => ['c_entry_desc_chn' => '提案名'],
        ])->assertOk();

        $this->assertSame('原名', DB::table('ENTRY_CODES')->where('c_entry_code', 970)->value('c_entry_desc_chn'));
        $this->assertDatabaseHas('operations', ['resource' => 'ENTRY_CODES', 'op_type' => Operation::TYPE_PROPOSAL_UPDATE]);
        $this->assertDatabaseCount('audit_log', 0);
    }

    #[Test]
    public function testDeleteStaysDisabled(): void {
        // 本表被 ENTRY_DATA 與 ENTRY_CODE_TYPE_REL 以外鍵引用，刪除維持全面停用。
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN, 'entry-delete@example.com'));
        DB::table('ENTRY_CODES')->insert(['c_entry_code' => 980, 'c_entry_desc' => 'x', 'c_entry_desc_chn' => '名']);

        $this->postJson('/api/v2/delete', [
            'resource' => 'entry-codes',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_entry_code' => 980]],
        ])->assertStatus(403);

        $this->assertDatabaseHas('ENTRY_CODES', ['c_entry_code' => 980]);
    }

    #[Test]
    public function testCreateForbiddenForInactiveUserAndProposalCreateUnsupported(): void {
        $this->actingAs($this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'entry-inactive@example.com'));
        $this->create('entry-codes', [], ['c_entry_desc_chn' => '不可'])->assertStatus(403);

        // 代碼表的新增只支援 direct；proposal 模式找不到 handler → 501。
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'entry-crowd@example.com'));
        $this->create('entry-codes', [], ['c_entry_desc_chn' => '提案'], 'proposal')->assertStatus(501);

        $this->assertSame(0, DB::table('ENTRY_CODES')->count());
    }
}
