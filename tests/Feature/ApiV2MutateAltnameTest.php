<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2MutateAltnameTest extends TestCase {
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
        $this->createAltnameTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ALTNAME_DATA');
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

    protected function createAltnameTable(): void {
        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->string('c_alt_name_chn', 255)->default('');
            $table->integer('c_alt_name_type_code')->default(0);
            $table->string('c_alt_name', 255)->nullable();
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_sequence')->default(0);
            $table->string('c_alt_name_pinyin', 255)->nullable();
            $table->string('c_alt_name_pinyin2', 255)->nullable();
            $table->string('c_alt_name_pinyin3', 255)->nullable();
            $table->string('c_alt_name_role', 50)->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code']);
        });
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function seedAltname(array $overrides = []): void {
        DB::table('ALTNAME_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
            'c_alt_name_type_code' => 4,
            'c_alt_name' => 'Zimei',
            'c_source' => 10,
            'c_pages' => '1-5',
            'c_notes' => null,
            'c_sequence' => 1,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'altname-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function altnamePayload(array $overrides = []): array {
        $payload = [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_alt_name_chn' => '子美',
                    'c_alt_name_type_code' => 4,
                ],
            ],
            'changes' => [
                'c_sequence' => 2,
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    // ── Direct Update Tests ─────────────────────────────────

    #[Test]
    public function testDirectAltnameUpdateSucceeds(): void {
        $user = $this->makeUser(email: 'altname-direct@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'changes' => ['c_notes' => '測試備註', 'c_sequence' => 3],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'altnames',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_alt_name_chn' => '子美',
                        'c_alt_name_type_code' => 4,
                    ],
                ],
            ]);

        $data = $response->json();
        $this->assertEqualsCanonicalizing(['c_notes', 'c_sequence'], $data['result']['updated_fields']);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
            'c_notes' => '測試備註',
            'c_sequence' => 3,
        ]);
    }

    #[Test]
    public function testDirectAltnameUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'altname-result@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'changes' => ['c_sequence' => 5],
        ]));

        $data = $response->json();
        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertEquals(5, $data['result']['row']['c_sequence']);
    }

    #[Test]
    public function testDirectAltnameUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'altname-op@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'changes' => ['c_sequence' => 2],
        ]));

        $this->assertDatabaseHas('operations', [
            'resource' => 'ALTNAME_DATA',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectAltnameUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'altname-audit@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'changes' => ['c_sequence' => 2],
        ]));

        $audit = DB::table('audit_log')->where('table_name', 'ALTNAME_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
    }

    #[Test]
    public function testDirectAltnameUpdateWithFullFields(): void {
        $user = $this->makeUser(email: 'altname-full@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'changes' => [
                'c_alt_name' => 'NewPinyin',
                'c_source' => 20,
                'c_pages' => '10-15',
                'c_notes' => '新備註',
            ],
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_alt_name' => 'NewPinyin',
            'c_source' => 20,
            'c_pages' => '10-15',
            'c_notes' => '新備註',
        ]);
    }

    // ── Proposal Update Tests ───────────────────────────────

    #[Test]
    public function testProposalAltnameUpdateCreatesPendingOperation(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'altname-proposal@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'mode' => 'proposal',
            'changes' => ['c_sequence' => 5],
            'meta' => ['comment' => '提案修改序號'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'altnames',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_sequence'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_sequence' => 1,
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'ALTNAME_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'ALTNAME_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('altnames', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修改序號', $resourceData['__proposal_meta']['comment']);
        $this->assertEquals(5, $resourceData['c_sequence']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'ALTNAME_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testAltnameUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'altname-404@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'target' => ['pk' => [
                'c_personid' => 1000,
                'c_alt_name_chn' => '不存在',
                'c_alt_name_type_code' => 4,
            ]],
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function testAltnameUpdateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'altname-mismatch@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testAltnameUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'altname-empty@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_alt_name_chn' => '子美',
                    'c_alt_name_type_code' => 4,
                ],
            ],
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function testAltnameUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'altname-disallowed@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'changes' => ['c_personid' => 9999],
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false]);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testAltnameUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'altname-nochange@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'changes' => ['c_sequence' => 1],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => ['changes' => ['no_effective_changes']],
            ]);
    }

    #[Test]
    public function testAltnameUpdateRejectsUnauthenticatedUser(): void {
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload());

        $response->assertStatus(401);
    }

    #[Test]
    public function testAltnameUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'altname-inactive@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload());

        $response->assertStatus(403);
    }

    #[Test]
    public function testAltnameDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'altname-crowd@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403);
    }

    // ── #56 M 寫入等價（update 路徑，純單表；altname 無鏡像/副表）──────

    #[Test]
    #[Group('legacy-parity')] // 旧版下线時連同 legacy update 路徑一併移除
    public function testAltnameUpdateWriteEquivalenceLegacyVsV2(): void {
        // #56 M（update，純單表）——復原-重做實驗：改 c_notes+c_sequence（避開受括號正規化影響的名字欄與改鍵）。
        // legacy 整包 update vs v2 partial；只送 v2 會改的欄即等價。
        $this->actingAs($this->makeUser(email: 'altname-mupd@example.com'));

        $pk = ['c_personid' => 1000, 'c_alt_name_chn' => '子美', 'c_alt_name_type_code' => 4];
        $seedInitial = function (): void {
            DB::table('ALTNAME_DATA')->delete();
            $this->seedAltname(['c_notes' => '初始備註', 'c_sequence' => 1, 'c_source' => 10, 'c_alt_name' => 'Zimei']);
        };
        $cols = ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code', 'c_alt_name', 'c_source', 'c_pages', 'c_notes', 'c_sequence'];
        $pick = function ($row) use ($cols): ?array {
            if (!$row) {
                return null;
            }
            $a = array_intersect_key((array) $row, array_flip($cols));
            ksort($a);

            return $a;
        };

        // ① 旧版 PUT（PK 走 query）。
        $seedInitial();
        $this->put('/basicinformation/1000/altnames/update?' . http_build_query($pk), [
            'c_notes' => '改後備註', 'c_sequence' => 3, 'action' => 'save',
        ])->assertStatus(302);
        $legacy = $pick(DB::table('ALTNAME_DATA')->where($pk)->first());

        // ② 復原初始 → ③ 新版改同一筆。
        $seedInitial();
        $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'changes' => ['c_notes' => '改後備註', 'c_sequence' => 3],
        ]))->assertOk()->assertJson(['ok' => true]);
        $v2 = $pick(DB::table('ALTNAME_DATA')->where($pk)->first());

        $this->assertNotNull($legacy, 'legacy 更新後列不存在');
        $this->assertNotNull($v2, 'v2 更新後列不存在');
        $this->assertSame('改後備註', $v2['c_notes'], 'v2 c_notes 應更新');
        $this->assertSame('改後備註', $legacy['c_notes'], 'legacy c_notes 應更新（鎖 legacy 確有寫入）');
        $this->assertSame(3, (int) $v2['c_sequence'], 'v2 c_sequence 應更新為 3');
        $this->assertSame($legacy, $v2, 'ALTNAME_DATA 落庫列 legacy vs v2 不等價');
    }

    #[Test]
    public function testAltnameUpdateAcceptsAlias(): void {
        $user = $this->makeUser(email: 'altname-alias@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'resource' => 'altname_data',
            'changes' => ['c_sequence' => 9],
        ]));

        $response->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'altnames']);
    }

    // ── PK Conflict Tests ────────────────────────────────────

    #[Test]
    public function testDirectAltnameUpdateWithPkCollisionReturns409(): void {
        $user = $this->makeUser(email: 'altname-conflict@example.com');
        $this->actingAs($user);
        $this->seedAltname();
        $this->seedAltname([
            'c_alt_name_chn' => '少陵野老',
            'c_alt_name_type_code' => 4,
            'c_source' => 5,
        ]);

        // Try to update the first row's name to the second row's name (PK collision)
        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'changes' => ['c_alt_name_chn' => '少陵野老'],
        ]));

        $response->assertStatus(409)
            ->assertJson(['ok' => false, 'errors' => ['target.pk' => ['conflict']]]);

        // Original data must be unchanged
        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
        ]);
    }

    #[Test]
    public function testProposalAltnameUpdateWithExistingTargetPkReturns409(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'altname-prop-conflict@example.com');
        $this->actingAs($user);
        $this->seedAltname();
        $this->seedAltname([
            'c_alt_name_chn' => '少陵野老',
            'c_alt_name_type_code' => 4,
            'c_source' => 5,
        ]);

        // Propose changing the first row's name to the second row's name (PK would collide)
        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'mode' => 'proposal',
            'changes' => ['c_alt_name_chn' => '少陵野老'],
        ]));

        $response->assertStatus(409)
            ->assertJson(['ok' => false, 'errors' => ['target.pk' => ['conflict']]]);

        // No proposal should have been written
        $this->assertDatabaseMissing('operations', ['resource' => 'ALTNAME_DATA']);
    }

    #[Test]
    public function testProposalAltnameUpdateDuplicatePendingProposalReturns409(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'altname-dup-prop@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $payload = $this->altnamePayload([
            'mode' => 'proposal',
            'changes' => ['c_sequence' => 5],
        ]);

        // First proposal succeeds
        $first = $this->postJson('/api/v2/mutate', $payload);
        $first->assertOk();

        // Second identical proposal is rejected as a duplicate
        $second = $this->postJson('/api/v2/mutate', $payload);
        $second->assertStatus(409)
            ->assertJson(['ok' => false, 'errors' => ['target.pk' => ['pending_proposal_exists']]]);

        // Only one proposal in the operations table
        $this->assertSame(1, DB::table('operations')->where('resource', 'ALTNAME_DATA')->count());
    }
}
