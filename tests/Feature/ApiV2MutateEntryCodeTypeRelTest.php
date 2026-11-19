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
 * 入仕途徑↔類型關聯（ENTRY_CODE_TYPE_REL）的 v2 create／delete／get
 * （docs/ENTRY_CODE_TYPE_REL_V2_WRITE_PLAN.md）。
 *
 * 純關聯表：兩欄複合主鍵（smallint c_entry_code＋varchar c_entry_type），沒有其他欄。
 * c_entry_type 是帶前導零的字串（'08'），任何 (int) 轉型都會掛到錯的類型上。
 * 本表是唯一開放刪除的代碼表（全庫沒有外鍵引用它）。
 */
class ApiV2MutateEntryCodeTypeRelTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('app.env', 'testing');
        $this->app['env'] = 'testing';
        config()->set('prometheus.enabled', false);
        config()->set('prometheus.storage_adapter', 'memory');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        $this->createSupportTables();
        $this->createEntryTables();
    }

    protected function tearDown(): void {
        foreach (['ENTRY_CODE_TYPE_REL', 'ENTRY_TYPES', 'ENTRY_CODES', 'audit_log', 'operations', 'users'] as $t) {
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

    /**
     * 型別與外鍵比照 prod（2025_01_01 import schema）。
     *
     * 兩個 c_entry_type 欄刻意用 `COLLATE NOCASE`：prod 是 utf8mb4_general_ci（不分大小寫），
     * SQLite 預設的 BINARY 會把「SQL 比較命中、但值不完全相同」的情形整個藏起來，
     * 精確比對的守衛就沒有東西可測。（尾隨空白那一半測不到：HTTP 輸入先經全域 TrimStrings。）
     */
    protected function createEntryTables(): void {
        DB::statement('CREATE TABLE "ENTRY_CODES" (
            "c_entry_code" smallint NOT NULL PRIMARY KEY,
            "c_entry_desc" varchar(255) NOT NULL DEFAULT \'\',
            "c_entry_desc_chn" varchar(255) NOT NULL DEFAULT \'\'
        )');
        DB::statement('CREATE TABLE "ENTRY_TYPES" (
            "c_entry_type" varchar(255) NOT NULL COLLATE NOCASE PRIMARY KEY,
            "c_entry_type_desc" varchar(255) NOT NULL DEFAULT \'\',
            "c_entry_type_desc_chn" varchar(255) NOT NULL DEFAULT \'\',
            "c_entry_type_parent_id" varchar(255) NULL,
            "c_entry_type_level" smallint NULL,
            "c_entry_type_sortorder" smallint NULL
        )');
        DB::statement('CREATE TABLE "ENTRY_CODE_TYPE_REL" (
            "c_entry_code" smallint NOT NULL,
            "c_entry_type" varchar(255) NOT NULL COLLATE NOCASE,
            PRIMARY KEY ("c_entry_code", "c_entry_type"),
            FOREIGN KEY ("c_entry_code") REFERENCES "ENTRY_CODES" ("c_entry_code") ON DELETE RESTRICT ON UPDATE CASCADE,
            FOREIGN KEY ("c_entry_type") REFERENCES "ENTRY_TYPES" ("c_entry_type") ON DELETE RESTRICT ON UPDATE CASCADE
        )');

        DB::table('ENTRY_CODES')->insert([
            ['c_entry_code' => 0, 'c_entry_desc' => 'unknown', 'c_entry_desc_chn' => '未詳'],
            ['c_entry_code' => 36, 'c_entry_desc' => 'examination: jinshi', 'c_entry_desc_chn' => '科舉: 進士'],
            ['c_entry_code' => 400, 'c_entry_desc' => 'jingming xingxiu', 'c_entry_desc_chn' => '經明行修'],
        ]);
        DB::table('ENTRY_TYPES')->insert([
            ['c_entry_type' => '04', 'c_entry_type_desc' => 'examination', 'c_entry_type_desc_chn' => '科舉', 'c_entry_type_parent_id' => '0'],
            ['c_entry_type' => '08', 'c_entry_type_desc' => 'recommendation', 'c_entry_type_desc_chn' => '薦舉', 'c_entry_type_parent_id' => '0'],
            // 只為精確比對測試而設的含字母類型（真實資料的類型碼都是數字）。
            ['c_entry_type' => 'xa', 'c_entry_type_desc' => 'collation probe', 'c_entry_type_desc_chn' => '定序探針', 'c_entry_type_parent_id' => '0'],
        ]);
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'entryrel@example.com'): User {
        return User::forceCreate([
            'name' => '入仕關聯編輯者',
            'email' => $email,
            'confirmation_token' => 'token-' . $email,
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    /** @param array<string,mixed> $pk @param array<string,mixed> $changes */
    protected function create(array $pk, array $changes = [], string $mode = 'direct', string $resource = 'entry-code-type-rel') {
        return $this->postJson('/api/v2/create', [
            'resource' => $resource,
            'person_id' => 0,
            'mode' => $mode,
            'target' => ['pk' => $pk],
            'changes' => $changes,
        ]);
    }

    /** @param array<string,mixed> $pk */
    protected function deleteRel(array $pk, string $resource = 'entry-code-type-rel', string $mode = 'direct') {
        return $this->postJson('/api/v2/delete', [
            'resource' => $resource,
            'person_id' => 0,
            'mode' => $mode,
            'target' => ['pk' => $pk],
        ]);
    }

    /** @param array<string,mixed> $pk */
    protected function getRel(array $pk) {
        return $this->postJson('/api/v2/get', [
            'resource' => 'entry-code-type-rel',
            'person_id' => 0,
            'target' => ['pk' => $pk],
        ]);
    }

    protected function relRows(): array {
        return DB::table('ENTRY_CODE_TYPE_REL')->orderBy('c_entry_code')->orderBy('c_entry_type')
            ->get()->map(fn ($r) => [(int) $r->c_entry_code, (string) $r->c_entry_type])->all();
    }

    #[Test]
    public function testCreateAttachesCodeToTypeKeepingLeadingZero(): void {
        $this->actingAs($this->makeUser());

        $response = $this->create(['c_entry_code' => 400, 'c_entry_type' => '08']);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('resource', 'entry-code-type-rel')
            ->assertJsonPath('result.status', 'created')
            ->assertJsonPath('result.pk.c_entry_code', 400)
            ->assertJsonPath('result.pk.c_entry_type', '08');
        $this->assertSame([[400, '08']], $this->relRows());

        $operation = Operation::query()->latest('id')->first();
        $this->assertSame(Operation::TYPE_CREATE, (int) $operation->op_type);
        $this->assertSame('ENTRY_CODE_TYPE_REL', $operation->resource);
        $this->assertSame('c_entry_code=400&c_entry_type=08', $operation->resource_id);
        $this->assertSame(1, DB::table('audit_log')->where('table_name', 'ENTRY_CODE_TYPE_REL')->where('operation', 'INSERT')->count());
    }

    #[Test]
    public function testAliasesResolveAndEntrySubresourceAliasesAreNotHijacked(): void {
        $this->actingAs($this->makeUser());

        $this->create(['c_entry_code' => 36, 'c_entry_type' => '04'], [], 'direct', 'entry_code_type_rel')->assertOk();
        $this->create(['c_entry_code' => 400, 'c_entry_type' => '04'], [], 'direct', 'entrycodetyperel')->assertOk();
        $this->assertSame([[36, '04'], [400, '04']], $this->relRows());

        // `entry-codes` 仍然指向 ENTRY_CODES 本身，不會落到關聯表。
        $this->create(['c_entry_code' => 401], ['c_entry_desc' => 'x', 'c_entry_desc_chn' => '甲'], 'direct', 'entry-codes')
            ->assertOk()->assertJsonPath('resource', 'entry-codes');
        $this->assertSame([[36, '04'], [400, '04']], $this->relRows());
    }

    #[Test]
    public function testUnknownEntryCodeIsRejectedWithFieldLevelError(): void {
        $this->actingAs($this->makeUser());

        $this->create(['c_entry_code' => 999, 'c_entry_type' => '08'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['target.pk.c_entry_code' => ['not_found:ENTRY_CODES']]);
        $this->assertSame([], $this->relRows());
        $this->assertSame(0, Operation::query()->count());
    }

    #[Test]
    public function testUnknownEntryTypeIsRejectedWithFieldLevelError(): void {
        $this->actingAs($this->makeUser());

        $this->create(['c_entry_code' => 400, 'c_entry_type' => '99'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['target.pk.c_entry_type' => ['not_found:ENTRY_TYPES']]);
        $this->assertSame([], $this->relRows());
    }

    #[Test]
    public function testBothReferencesMissingReportsBothColumns(): void {
        $this->actingAs($this->makeUser());

        $errors = $this->create(['c_entry_code' => 999, 'c_entry_type' => '99'])
            ->assertStatus(422)->json('errors');
        $this->assertSame(['not_found:ENTRY_CODES'], $errors['target.pk.c_entry_code'] ?? null);
        $this->assertSame(['not_found:ENTRY_TYPES'], $errors['target.pk.c_entry_type'] ?? null);
    }

    #[Test]
    public function testEntryTypeSentAsNumberDoesNotMatchZeroPaddedType(): void {
        $this->actingAs($this->makeUser());

        // JSON 數字 8 → 字串 '8'，不是 '08'：絕不可轉型後「剛好」掛到 08 上。
        $this->create(['c_entry_code' => 400, 'c_entry_type' => 8])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['target.pk.c_entry_type' => ['not_found:ENTRY_TYPES']]);
        $this->assertSame([], $this->relRows());
    }

    #[Test]
    public function testEntryTypeMustMatchStoredValueExactly(): void {
        $this->actingAs($this->makeUser());

        // prod 的 general_ci 不分大小寫（也 PAD SPACE）：SQL 比較下 'XA' 等於 'xa'、外鍵也會
        // 放行，於是建出一列鍵值與被參照列不同形的關聯。應用層要精確比對。
        $this->create(['c_entry_code' => 400, 'c_entry_type' => 'XA'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['target.pk.c_entry_type' => ['not_found:ENTRY_TYPES']]);
        $this->assertSame([], $this->relRows());

        $this->create(['c_entry_code' => 400, 'c_entry_type' => 'xa'])->assertOk();
        $this->assertSame([[400, 'xa']], $this->relRows());
    }

    #[Test]
    public function testSurroundingWhitespaceIsTrimmedBeforeReachingTheHandler(): void {
        $this->actingAs($this->makeUser());

        // 全域 TrimStrings middleware 先把 '08 ' 修成 '08'：記錄這個既有行為，
        // 免得有人以為 API 會存下帶空白的鍵。
        $this->create(['c_entry_code' => 400, 'c_entry_type' => ' 08 '])
            ->assertOk()
            ->assertJsonPath('result.pk.c_entry_type', '08');
        $this->assertSame([[400, '08']], $this->relRows());
    }

    #[Test]
    public function testMissingKeyColumnsAreRequired(): void {
        $this->actingAs($this->makeUser());

        $errors = $this->create(['c_entry_code' => 400])->assertStatus(422)->json('errors');
        $this->assertSame(['required'], $errors['target.pk.c_entry_type'] ?? null);

        $errors = $this->create(['c_entry_type' => '08'])->assertStatus(422)->json('errors');
        $this->assertSame(['required'], $errors['target.pk.c_entry_code'] ?? null);
        $this->assertSame([], $this->relRows());
    }

    #[Test]
    public function testNonNumericEntryCodeIsRejected(): void {
        $this->actingAs($this->makeUser());

        $this->create(['c_entry_code' => 'abc', 'c_entry_type' => '08'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['target.pk.c_entry_code' => ['numeric']]);
    }

    #[Test]
    public function testDuplicateRelationReturns409(): void {
        $this->actingAs($this->makeUser());
        DB::table('ENTRY_CODE_TYPE_REL')->insert(['c_entry_code' => 400, 'c_entry_type' => '08']);

        $this->create(['c_entry_code' => 400, 'c_entry_type' => '08'])
            ->assertStatus(409)
            ->assertJsonPath('errors', ['target.pk' => ['conflict']]);
        $this->assertSame([[400, '08']], $this->relRows());
    }

    #[Test]
    public function testNonKeyFieldsAreRejected(): void {
        $this->actingAs($this->makeUser());

        $this->create(['c_entry_code' => 400, 'c_entry_type' => '08'], ['c_notes' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('errors.changes.0', 'disallowed_fields: c_notes');
        $this->assertSame([], $this->relRows());
    }

    #[Test]
    public function testProposalModeAndUpdateAreNotSupported(): void {
        $this->actingAs($this->makeUser());
        DB::table('ENTRY_CODE_TYPE_REL')->insert(['c_entry_code' => 400, 'c_entry_type' => '08']);

        $this->create(['c_entry_code' => 36, 'c_entry_type' => '04'], [], 'proposal')->assertStatus(501);
        $this->deleteRel(['c_entry_code' => 400, 'c_entry_type' => '08'], 'entry-code-type-rel', 'proposal')->assertStatus(501);
        $this->postJson('/api/v2/mutate', [
            'resource' => 'entry-code-type-rel',
            'mode' => 'direct',
            'operation' => 'update',
            'person_id' => 0,
            'target' => ['pk' => ['c_entry_code' => 400, 'c_entry_type' => '08']],
            'changes' => ['c_entry_type' => '04'],
        ])->assertStatus(501);
        $this->assertSame([[400, '08']], $this->relRows());
    }

    #[Test]
    public function testInactiveUserCannotCreateOrDelete(): void {
        $this->actingAs($this->makeUser(User::STATUS_INACTIVE));
        DB::table('ENTRY_CODE_TYPE_REL')->insert(['c_entry_code' => 400, 'c_entry_type' => '08']);

        $this->create(['c_entry_code' => 36, 'c_entry_type' => '04'])->assertStatus(403);
        $this->deleteRel(['c_entry_code' => 400, 'c_entry_type' => '08'])->assertStatus(403);
        $this->assertSame([[400, '08']], $this->relRows());
    }

    #[Test]
    public function testCrowdsourcingUserCannotDelete(): void {
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING));
        DB::table('ENTRY_CODE_TYPE_REL')->insert(['c_entry_code' => 400, 'c_entry_type' => '08']);

        $this->deleteRel(['c_entry_code' => 400, 'c_entry_type' => '08'])->assertStatus(403);
        $this->assertSame([[400, '08']], $this->relRows());
        $this->assertSame(0, Operation::query()->count());
    }

    #[Test]
    public function testDeleteRemovesRelationAndRecordsOperationAndAudit(): void {
        $this->actingAs($this->makeUser());
        DB::table('ENTRY_CODE_TYPE_REL')->insert([
            ['c_entry_code' => 400, 'c_entry_type' => '08'],
            ['c_entry_code' => 400, 'c_entry_type' => '04'],
        ]);

        $this->deleteRel(['c_entry_code' => '400', 'c_entry_type' => '08'])
            ->assertOk()
            ->assertJsonPath('result.status', 'deleted')
            ->assertJsonPath('result.pk.c_entry_code', 400)
            ->assertJsonPath('result.pk.c_entry_type', '08')
            ->assertJsonPath('result.row.c_entry_type', '08');

        // 只刪指名那一列；被參照的代碼與類型完好。
        $this->assertSame([[400, '04']], $this->relRows());
        $this->assertSame(3, DB::table('ENTRY_CODES')->count());
        $this->assertSame(3, DB::table('ENTRY_TYPES')->count());

        $operation = Operation::query()->latest('id')->first();
        $this->assertSame(Operation::TYPE_DELETE, (int) $operation->op_type);
        $this->assertSame('ENTRY_CODE_TYPE_REL', $operation->resource);
        $this->assertSame('c_entry_code=400&c_entry_type=08', $operation->resource_id);
        $snapshot = json_decode((string) $operation->resource_data, true);
        $this->assertSame('08', (string) $snapshot['c_entry_type']);

        $audit = DB::table('audit_log')->where('table_name', 'ENTRY_CODE_TYPE_REL')->where('operation', 'DELETE')->first();
        $this->assertNotNull($audit);
        $this->assertSame((string) $operation->id, (string) $audit->operation_id);
        $this->assertNull($audit->new_data);
    }

    #[Test]
    public function testDeleteOfMissingRelationReturns404(): void {
        $this->actingAs($this->makeUser());

        $this->deleteRel(['c_entry_code' => 400, 'c_entry_type' => '08'])->assertStatus(404);
        $this->assertSame(0, Operation::query()->count());
    }

    #[Test]
    public function testDeleteRequiresExactTextKey(): void {
        $this->actingAs($this->makeUser());
        DB::table('ENTRY_CODE_TYPE_REL')->insert(['c_entry_code' => 400, 'c_entry_type' => '08']);

        // 數字 8 與 '8' 都不是 '08'；不可刪到呼叫端沒有指名的列。
        $this->deleteRel(['c_entry_code' => 400, 'c_entry_type' => 8])->assertStatus(404);
        $this->deleteRel(['c_entry_code' => 400, 'c_entry_type' => '8'])->assertStatus(404);
        $this->assertSame([[400, '08']], $this->relRows());

        // 定序不分大小寫時 SQL 會命中 'xa'，但呼叫端指名的是 'XA'——不可刪，也不可把
        // 'XA' 記進 operations／audit_log。
        DB::table('ENTRY_CODE_TYPE_REL')->insert(['c_entry_code' => 400, 'c_entry_type' => 'xa']);
        $this->deleteRel(['c_entry_code' => 400, 'c_entry_type' => 'XA'])->assertStatus(404);
        $this->assertSame([[400, '08'], [400, 'xa']], $this->relRows());
        $this->assertSame(0, Operation::query()->count());
    }

    #[Test]
    public function testDeleteValidatesKeys(): void {
        $this->actingAs($this->makeUser());
        DB::table('ENTRY_CODE_TYPE_REL')->insert(['c_entry_code' => 0, 'c_entry_type' => '08']);

        // `(int) 'abc'` 會變成 0——而 0 這列真的存在。
        $this->deleteRel(['c_entry_code' => 'abc', 'c_entry_type' => '08'])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['target.pk.c_entry_code' => ['numeric']]);
        $errors = $this->deleteRel(['c_entry_code' => 400])->assertStatus(422)->json('errors');
        $this->assertSame(['required'], $errors['target.pk.c_entry_type'] ?? null);
        $this->deleteRel(['c_entry_code' => 400, 'c_entry_type' => ['08']])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['target.pk.c_entry_type' => ['string']]);
        $this->assertSame([[0, '08']], $this->relRows());
    }

    #[Test]
    public function testOtherCodeTablesStillRefuseDelete(): void {
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN));

        $this->deleteRel(['c_entry_code' => 400], 'entry-codes')
            ->assertStatus(403)
            ->assertJsonPath('errors.resource.0', 'entry-codes');
        $this->assertSame(3, DB::table('ENTRY_CODES')->count());
    }

    #[Test]
    public function testDeleteCanBeRestoredFromOperations(): void {
        $editor = $this->makeUser();
        $this->actingAs($editor);
        DB::table('ENTRY_CODE_TYPE_REL')->insert(['c_entry_code' => 400, 'c_entry_type' => '08']);
        $this->deleteRel(['c_entry_code' => 400, 'c_entry_type' => '08'])->assertOk();
        $this->assertSame([], $this->relRows());
        $operationId = Operation::query()->latest('id')->value('id');

        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN, 'entryrel-admin@example.com'));
        $this->post(route('operations.restore', ['operation' => $operationId]))->assertRedirect();

        $this->assertSame([[400, '08']], $this->relRows());
    }

    #[Test]
    public function testCreateThenDeleteThenRecreateRoundTrips(): void {
        $this->actingAs($this->makeUser());

        $this->create(['c_entry_code' => 400, 'c_entry_type' => '08'])->assertOk();
        $this->deleteRel(['c_entry_code' => 400, 'c_entry_type' => '08'])->assertOk();
        $this->create(['c_entry_code' => 400, 'c_entry_type' => '08'])->assertOk();

        $this->assertSame([[400, '08']], $this->relRows());
        $this->assertSame(
            [Operation::TYPE_CREATE, Operation::TYPE_DELETE, Operation::TYPE_CREATE],
            Operation::query()->orderBy('id')->pluck('op_type')->map(fn ($t) => (int) $t)->all()
        );
    }

    #[Test]
    public function testBatchMutateCanAttachAndDetach(): void {
        $this->actingAs($this->makeUser());
        DB::table('ENTRY_CODE_TYPE_REL')->insert(['c_entry_code' => 400, 'c_entry_type' => '04']);

        $this->postJson('/api/v2/batch_mutate', [
            'atomic' => true,
            'items' => [
                [
                    'resource' => 'entry-code-type-rel', 'mode' => 'direct', 'operation' => 'create',
                    'person_id' => 0, 'target' => ['pk' => ['c_entry_code' => 400, 'c_entry_type' => '08']],
                    'changes' => [],
                ],
                [
                    'resource' => 'entry-code-type-rel', 'mode' => 'direct', 'operation' => 'delete',
                    'person_id' => 0, 'target' => ['pk' => ['c_entry_code' => 400, 'c_entry_type' => '04']],
                ],
            ],
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame([[400, '08']], $this->relRows());
    }

    #[Test]
    public function testAtomicBatchRollsBackEarlierDeleteWhenLaterItemFails(): void {
        $this->actingAs($this->makeUser());
        DB::table('ENTRY_CODE_TYPE_REL')->insert(['c_entry_code' => 400, 'c_entry_type' => '04']);

        $this->postJson('/api/v2/batch_mutate', [
            'atomic' => true,
            'items' => [
                [
                    'resource' => 'entry-code-type-rel', 'mode' => 'direct', 'operation' => 'delete',
                    'person_id' => 0, 'target' => ['pk' => ['c_entry_code' => 400, 'c_entry_type' => '04']],
                ],
                [
                    // 參照不存在的類型 → 422，整批必須回滾。
                    'resource' => 'entry-code-type-rel', 'mode' => 'direct', 'operation' => 'create',
                    'person_id' => 0, 'target' => ['pk' => ['c_entry_code' => 400, 'c_entry_type' => '99']],
                    'changes' => [],
                ],
            ],
        ])->assertStatus(409)->assertJsonPath('failed_index', 1);

        $this->assertSame([[400, '04']], $this->relRows());
        $this->assertSame(0, Operation::query()->count());
        $this->assertSame(0, DB::table('audit_log')->count());
    }

    #[Test]
    public function testGetReturnsRelationOrNotFound(): void {
        $this->actingAs($this->makeUser());
        DB::table('ENTRY_CODE_TYPE_REL')->insert(['c_entry_code' => 400, 'c_entry_type' => '08']);

        $this->getRel(['c_entry_code' => 400, 'c_entry_type' => '08'])
            ->assertOk()
            ->assertJsonPath('resource', 'entry-code-type-rel')
            ->assertJsonPath('result.row.c_entry_type', '08');
        $this->getRel(['c_entry_code' => 400, 'c_entry_type' => '04'])->assertStatus(404);
        $this->getRel(['c_entry_code' => 400])->assertStatus(422);
    }

    #[Test]
    public function testGetMatchesTextKeyExactly(): void {
        $this->actingAs($this->makeUser());
        DB::table('ENTRY_CODE_TYPE_REL')->insert([
            ['c_entry_code' => 400, 'c_entry_type' => '08'],
            ['c_entry_code' => 400, 'c_entry_type' => 'xa'],
        ]);

        // MariaDB 下 '08' = 8 為真：get 不可回 200 而 delete 回 404。
        $this->getRel(['c_entry_code' => 400, 'c_entry_type' => 8])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['target.pk.c_entry_type' => ['string']]);
        // 定序不分大小寫時 SQL 命中 'xa'，但指名的是 'XA'。
        $this->getRel(['c_entry_code' => 400, 'c_entry_type' => 'XA'])->assertStatus(404);
        $this->getRel(['c_entry_code' => 400, 'c_entry_type' => 'xa'])->assertOk();
    }

    #[Test]
    public function testDeleteBindsTextKeyAsString(): void {
        // SQLite 的 TEXT affinity 會把綁定的 int 8 轉成 '8'，掩蓋 MariaDB 上 8 = '08' 的數值比較；
        // 所以斷言機制（綁定型別）而不是輸出。
        $this->actingAs($this->makeUser());
        DB::table('ENTRY_CODE_TYPE_REL')->insert(['c_entry_code' => 400, 'c_entry_type' => '08']);

        $bindings = [];
        DB::listen(function ($query) use (&$bindings) {
            if (str_contains($query->sql, 'ENTRY_CODE_TYPE_REL') && str_contains($query->sql, 'c_entry_type')) {
                $bindings[] = $query->bindings;
            }
        });

        $this->deleteRel(['c_entry_code' => 400, 'c_entry_type' => 8])->assertStatus(404);

        $this->assertNotEmpty($bindings);
        foreach ($bindings as $set) {
            $this->assertContains('8', $set, true);
            $this->assertNotContains(8, $set, true);
        }
    }

    #[Test]
    public function testEveryOtherCodeTableAliasStillRefusesDelete(): void {
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN));

        $checked = 0;
        foreach (config('code_table_writes.tables') as $def) {
            if (!empty($def['allow_delete'])) {
                continue;
            }
            foreach ($def['aliases'] as $alias) {
                $checked++;
                $this->deleteRel(['id' => 1], $alias)->assertStatus(403);
            }
        }
        $this->assertGreaterThan(10, $checked);
        $this->assertSame(0, Operation::query()->count());
    }

    #[Test]
    public function testGetRequiresActiveUser(): void {
        $this->getRel(['c_entry_code' => 400, 'c_entry_type' => '08'])->assertStatus(401);
    }
}
