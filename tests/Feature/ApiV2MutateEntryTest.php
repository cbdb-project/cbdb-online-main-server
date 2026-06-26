<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2MutateEntryTest extends TestCase {
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
        $this->createSanctumTables();
        $this->createOperationsTable();
        $this->createAuditLogTable();
        $this->createEntryTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ENTRY_DATA');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    // ── Table Setup ─────────────────────────────────────────

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

    protected function createSanctumTables(): void {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
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

    protected function createEntryTable(): void {
        Schema::create('ENTRY_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_entry_code')->default(0);
            $table->integer('c_sequence')->default(0);
            $table->integer('c_kin_code')->default(0);
            $table->integer('c_assoc_code')->default(0);
            $table->integer('c_kin_id')->default(0);
            $table->integer('c_year')->default(0);
            $table->integer('c_assoc_id')->default(0);
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->integer('c_entry_addr_id')->nullable();
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_entry_nh_id')->nullable(); // 對齊真實欄名（2026_01_22 rename c_nianhao_id->c_entry_nh_id）
            $table->integer('c_entry_nh_year')->nullable();
            $table->integer('c_entry_range')->nullable();
            $table->string('c_exam_rank', 255)->nullable();
            $table->integer('c_attempt_count')->nullable();
            $table->string('c_exam_field', 255)->nullable();
            $table->integer('c_parental_status_code')->nullable();
            $table->integer('c_age')->nullable();
            $table->string('c_posting_notes', 255)->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary([
                'c_personid', 'c_entry_code', 'c_sequence', 'c_kin_code',
                'c_assoc_code', 'c_kin_id', 'c_year', 'c_assoc_id',
                'c_inst_code', 'c_inst_name_code',
            ]);
        });
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function seedEntry(array $overrides = []): void {
        DB::table('ENTRY_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_entry_code' => 36,
            'c_sequence' => 1,
            'c_kin_code' => 0,
            'c_assoc_code' => 0,
            'c_kin_id' => 0,
            'c_year' => 1057,
            'c_assoc_id' => 0,
            'c_inst_code' => 0,
            'c_inst_name_code' => 0,
            'c_entry_addr_id' => 100,
            'c_source' => 10,
            'c_pages' => '5',
            'c_notes' => null,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'entry-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function entryPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'entries',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_entry_code' => 36,
                    'c_sequence' => 1,
                    'c_kin_code' => 0,
                    'c_assoc_code' => 0,
                    'c_kin_id' => 0,
                    'c_year' => 1057,
                    'c_assoc_id' => 0,
                    'c_inst_code' => 0,
                    'c_inst_name_code' => 0,
                ],
            ],
            'changes' => [
                'c_pages' => '10-15',
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    // ── Direct Update Tests ─────────────────────────────────

    #[Test]
    public function testUpdatePreservesHiddenInstitutionPk(): void {
        // 回歸：ENTRY_DATA 10-key PK 含 c_inst_code/c_inst_name_code（編輯器不顯示）。
        // 編輯一筆 institution PK 非零的記錄、只改 c_notes 時（changes 不含這兩個隱藏 PK 欄），
        // 後端 buildNewPk 須以 target.pk 保留原值，不可把 institution PK 改成 0。
        $user = $this->makeUser(email: 'entry-instpk@example.com');
        $this->actingAs($user);
        $this->seedEntry(['c_inst_code' => 500, 'c_inst_name_code' => 7, 'c_notes' => '原備註']);

        $response = $this->postJson('/api/v2/mutate', $this->entryPayload([
            'target' => ['pk' => ['c_inst_code' => 500, 'c_inst_name_code' => 7]],
            'changes' => ['c_notes' => '改後備註'],
        ]));

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('ENTRY_DATA', [
            'c_personid' => 1000,
            'c_entry_code' => 36,
            'c_inst_code' => 500,
            'c_inst_name_code' => 7,
            'c_notes' => '改後備註',
        ]);
        // institution PK 未被改成 0
        $this->assertSame(0, DB::table('ENTRY_DATA')->where('c_inst_code', 0)->count());
    }

    #[Test]
    public function testDirectEntryUpdatePersistsRestoredFieldsAndDoesNotNullOthers(): void {
        // 回歸（Task 27）：補回的入仕欄位（exam_rank/attempt_count/exam_field/parental_status/age/posting_notes）
        // 須能寫入；且只改 c_notes 時不可清空這些欄（防「保存即清空」資料流失）。
        $user = $this->makeUser(email: 'entry-restored@example.com');
        $this->actingAs($user);
        $this->seedEntry([
            'c_exam_rank' => '進士', 'c_attempt_count' => 2, 'c_exam_field' => '經義',
            'c_parental_status_code' => 1, 'c_age' => 30, 'c_posting_notes' => '原任官備註',
        ]);

        // (a) 直接更新補回欄位應寫入。
        $this->postJson('/api/v2/mutate', $this->entryPayload([
            'changes' => ['c_exam_rank' => '探花', 'c_age' => 28],
        ]))->assertOk();
        $this->assertDatabaseHas('ENTRY_DATA', [
            'c_personid' => 1000, 'c_entry_code' => 36, 'c_sequence' => 1,
            'c_exam_rank' => '探花', 'c_age' => 28, 'c_exam_field' => '經義', 'c_parental_status_code' => 1,
        ]);

        // (b) 只改 c_notes 後，補回欄位仍保留、未被清空。
        $this->postJson('/api/v2/mutate', $this->entryPayload([
            'changes' => ['c_notes' => '只改備註'],
        ]))->assertOk();
        $this->assertDatabaseHas('ENTRY_DATA', [
            'c_personid' => 1000, 'c_entry_code' => 36, 'c_sequence' => 1,
            'c_notes' => '只改備註', 'c_exam_rank' => '探花', 'c_age' => 28,
            'c_exam_field' => '經義', 'c_parental_status_code' => 1, 'c_attempt_count' => 2,
            'c_posting_notes' => '原任官備註',
        ]);
    }

    #[Test]
    public function testDirectEntryUpdateSucceeds(): void {
        $user = $this->makeUser(email: 'entry-direct@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/mutate', $this->entryPayload([
            'changes' => ['c_pages' => '10-15', 'c_notes' => '測試備註'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'entries',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_pages', 'c_notes'],
                ],
            ]);

        $this->assertDatabaseHas('ENTRY_DATA', [
            'c_personid' => 1000,
            'c_pages' => '10-15',
            'c_notes' => '測試備註',
        ]);
    }

    #[Test]
    public function testDirectEntryUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'entry-result@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/mutate', $this->entryPayload());
        $data = $response->json();

        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertSame('10-15', $data['result']['row']['c_pages']);
    }

    #[Test]
    public function testDirectEntryUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'entry-op@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $this->postJson('/api/v2/mutate', $this->entryPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'ENTRY_DATA',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectEntryUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'entry-audit@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $this->postJson('/api/v2/mutate', $this->entryPayload());

        $audit = DB::table('audit_log')->where('table_name', 'ENTRY_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
    }

    // ── Proposal Update Tests ───────────────────────────────

    #[Test]
    public function testProposalEntryUpdateCreatesPendingOperation(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'entry-proposal@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/mutate', $this->entryPayload([
            'mode' => 'proposal',
            'changes' => ['c_pages' => '20-25'],
            'meta' => ['comment' => '提案修改頁碼'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'entries',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_pages'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('ENTRY_DATA', [
            'c_personid' => 1000,
            'c_pages' => '5',
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'ENTRY_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'ENTRY_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('entries', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修改頁碼', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'ENTRY_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testEntryUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'entry-404@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/mutate', $this->entryPayload([
            'target' => ['pk' => [
                'c_personid' => 1000,
                'c_entry_code' => 999,
                'c_sequence' => 1,
                'c_kin_code' => 0,
                'c_assoc_code' => 0,
                'c_kin_id' => 0,
                'c_year' => 1057,
                'c_assoc_id' => 0,
                'c_inst_code' => 0,
                'c_inst_name_code' => 0,
            ]],
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function testEntryUpdateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'entry-mismatch@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/mutate', $this->entryPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testEntryUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'entry-empty@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'entries',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_entry_code' => 36,
                    'c_sequence' => 1,
                    'c_kin_code' => 0,
                    'c_assoc_code' => 0,
                    'c_kin_id' => 0,
                    'c_year' => 1057,
                    'c_assoc_id' => 0,
                    'c_inst_code' => 0,
                    'c_inst_name_code' => 0,
                ],
            ],
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function testEntryUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'entry-disallowed@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/mutate', $this->entryPayload([
            'changes' => ['c_personid' => 9999],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testEntryUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'entry-nochange@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/mutate', $this->entryPayload([
            'changes' => ['c_pages' => '5'],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => ['changes' => ['no_effective_changes']],
            ]);
    }

    #[Test]
    public function testEntryUpdateRejectsUnauthenticatedUser(): void {
        $this->seedEntry();
        $response = $this->postJson('/api/v2/mutate', $this->entryPayload());
        $response->assertStatus(401);
    }

    #[Test]
    public function testEntryUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'entry-inactive@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/mutate', $this->entryPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testEntryDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'entry-crowd@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/mutate', $this->entryPayload());
        $response->assertStatus(403);
    }

    // ── #56 M 寫入等價（update 路徑，純單表 10 段 PK；entries 無鏡像/副表）──────

    #[Test]
    public function testEntryCodeFieldsSentinelFullyIdempotent(): void {
        // 兩個非 PK 碼欄 c_source / c_entry_addr_id（legacy 哨兵 0=Unknown）所有空表示 → 0、合法值保留、不翻。≥10 案例。
        $this->actingAs($this->makeUser(email: 'entry-sentinel@example.com'));
        $T = 'ENTRY_DATA';
        $fields = ['c_source', 'c_entry_addr_id'];
        foreach ($fields as $f) {
            foreach ([null, '', -999, '0', 0] as $sent) {
                DB::table($T)->delete();
                $this->seedEntry([$f => 5, 'c_notes' => '初始']);
                $this->postJson('/api/v2/mutate', $this->entryPayload(['changes' => [$f => $sent, 'c_notes' => '改'.$f.var_export($sent, true)]]))->assertOk();
                $this->assertSame(0, (int) DB::table($T)->value($f), $f.' 送 '.var_export($sent, true).' 應規範化為 0');
                $this->assertNotNull(DB::table($T)->value($f), $f.' 不得為 null');
            }
        }
        DB::table($T)->delete();
        $this->seedEntry(['c_source' => 1, 'c_notes' => 'x']);
        $this->postJson('/api/v2/mutate', $this->entryPayload(['changes' => ['c_source' => 7, 'c_notes' => '合法值']]))->assertOk();
        $this->assertSame(7, (int) DB::table($T)->value('c_source'), '合法非 0 值不得被誤清');
    }

    #[Test]
    public function testEntryUpdateAcceptsAlias(): void {
        $user = $this->makeUser(email: 'entry-alias@example.com');
        $this->actingAs($user);
        $this->seedEntry();

        $response = $this->postJson('/api/v2/mutate', $this->entryPayload([
            'resource' => 'entry_data',
        ]));

        $response->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'entries']);
    }

    #[Test]
    public function testDirectEntryUpdatePersistsEraNianhaoField(): void {
        // 回歸：入仕年的年號代碼欄真實欄名為 c_entry_nh_id（2026_01_22 rename，舊名 c_nianhao_id）。
        // allowlist 若殘留舊名，更新年號將寫不進真實欄。
        $user = $this->makeUser(email: 'entry-era@example.com');
        $this->actingAs($user);
        $this->seedEntry(['c_entry_nh_id' => 1, 'c_entry_nh_year' => 2]);

        $this->postJson('/api/v2/mutate', $this->entryPayload([
            'changes' => ['c_entry_nh_id' => 9, 'c_entry_nh_year' => 4],
        ]))->assertOk();

        $this->assertDatabaseHas('ENTRY_DATA', [
            'c_personid' => 1000, 'c_entry_code' => 36, 'c_sequence' => 1,
            'c_entry_nh_id' => 9, 'c_entry_nh_year' => 4,
        ]);
    }
}
