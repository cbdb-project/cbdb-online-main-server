<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2CreateAltnameTest extends TestCase {
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

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'create-altname-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function createPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'altnames',
            'person_id' => 1000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_alt_name_chn' => '少陵',
                    'c_alt_name_type_code' => 5,
                ],
            ],
            'changes' => [
                'c_source' => 20,
                'c_pages' => '10-15',
                'c_notes' => '新增別名',
                'c_sequence' => 2,
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    #[Test]
    public function testDirectAltnameCreateNormalizesPinyinVToUmlaut(): void {
        $this->actingAs($this->makeUser(email: 'altname-create-umlaut@example.com'));

        // Tier 1：c_alt_name_pinyin/2/3 靜默轉；Tier 2：c_alt_name 交前端、後端不轉。
        $this->postJson('/api/v2/create', $this->createPayload([
            'target' => ['pk' => ['c_alt_name_chn' => '呂名']],
            'changes' => [
                'c_alt_name' => 'Lv Meng',
                'c_alt_name_pinyin' => 'lv',
                'c_alt_name_pinyin2' => 'Nve',
                'c_alt_name_pinyin3' => 'Silva',
            ],
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_alt_name_chn' => '呂名',
            'c_alt_name_pinyin' => 'lü',   // Tier 1 靜默轉
            'c_alt_name_pinyin2' => 'Nüe',
            'c_alt_name_pinyin3' => 'Silva', // 西文 no-op
            'c_alt_name' => 'Lv Meng',     // 後端刻意不轉（Tier 2 前端負責）
        ]);
    }

    #[Test]
    public function testAltnameCreateCodeFieldSentinelFullyIdempotent(): void {
        // #71：create c_source 完全幂等——null/''/'-999'/-999/'0'/0 落庫皆 0、永不寫 null/''；合法非 0 保留。
        // 每案用不同 c_alt_name_chn 取獨立 PK。≥10 案例。
        $this->actingAs($this->makeUser(email: 'altname-create-sentinel@example.com'));
        $T = 'ALTNAME_DATA';
        foreach ([null, '', '-999', -999, '0', 0] as $i => $sent) {
            $chn = '哨名'.$i;
            $this->postJson('/api/v2/create', $this->createPayload([
                'target' => ['pk' => ['c_alt_name_chn' => $chn]],
                'changes' => ['c_source' => $sent],
            ]))->assertOk()->assertJson(['ok' => true]);
            $stored = DB::table($T)->where(['c_personid' => 1000, 'c_alt_name_chn' => $chn, 'c_alt_name_type_code' => 5])->value('c_source');
            $this->assertNotNull($stored, 'c_source 送 '.var_export($sent, true).' 不得為 null');
            $this->assertSame('0', (string) $stored, 'c_source 送 '.var_export($sent, true).' 應規範化為 0');
        }
        foreach ([5, 7, 999, 42] as $i => $sent) {
            $chn = '合名'.$i;
            $this->postJson('/api/v2/create', $this->createPayload([
                'target' => ['pk' => ['c_alt_name_chn' => $chn]],
                'changes' => ['c_source' => $sent],
            ]))->assertOk();
            $stored = DB::table($T)->where(['c_personid' => 1000, 'c_alt_name_chn' => $chn, 'c_alt_name_type_code' => 5])->value('c_source');
            $this->assertSame($sent, (int) $stored, '合法非 0 值不得被誤清：'.$sent);
        }
    }

    // ── Direct Create Tests ─────────────────────────────────

    #[Test]
    public function testDirectAltnameCreateSucceeds(): void {
        $user = $this->makeUser(email: 'create-direct@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'altnames',
                'mode' => 'direct',
                'operation' => 'create',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_alt_name_chn' => '少陵',
                        'c_alt_name_type_code' => 5,
                    ],
                ],
            ]);

        $data = $response->json();
        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_alt_name_chn' => '少陵',
            'c_alt_name_type_code' => 5,
            'c_source' => 20,
            'c_pages' => '10-15',
            'c_notes' => '新增別名',
            'c_sequence' => 2,
        ]);
    }

    #[Test]
    public function testDirectAltnameCreateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'create-op@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'ALTNAME_DATA',
            'op_type' => Operation::TYPE_CREATE,
        ]);
    }

    #[Test]
    public function testDirectAltnameCreateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'create-audit@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $audit = DB::table('audit_log')->where('table_name', 'ALTNAME_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
    }

    #[Test]
    public function testDirectAltnameCreatePopulatesTimestampFields(): void {
        $user = $this->makeUser(email: 'create-ts@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $row = DB::table('ALTNAME_DATA')->where('c_alt_name_chn', '少陵')->first();
        $this->assertNotNull($row);
        $this->assertSame('tester', $row->c_created_by);
        $this->assertNotNull($row->c_created_date);
        $this->assertNull($row->c_modified_by);
        $this->assertNull($row->c_modified_date);
    }

    #[Test]
    public function testDirectAltnameCreateWithSentinelKeyNormalizesAndReadsBack(): void {
        $user = $this->makeUser(email: 'create-sentinel@example.com');
        $this->actingAs($user);

        // c_alt_name_type_code = -999 會被正規化為 '0'
        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_alt_name_chn' => '測試名',
                    'c_alt_name_type_code' => -999,
                ],
            ],
        ]));

        $response->assertOk();

        // 回傳的 PK 應該是正規化後的值
        $data = $response->json();
        $this->assertEquals(0, $data['result']['pk']['c_alt_name_type_code']);

        // row readback 應包含實際資料
        $this->assertNotEmpty($data['result']['row']);

        // 資料庫中的記錄應使用正規化後的 PK
        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_alt_name_chn' => '測試名',
            'c_alt_name_type_code' => 0,
        ]);

        // audit_log 中的 row_pk 應包含正規化後的值
        $audit = DB::table('audit_log')->where('table_name', 'ALTNAME_DATA')->first();
        $this->assertNotNull($audit);
        $rowPk = json_decode($audit->row_pk, true);
        $this->assertEquals(0, $rowPk['c_alt_name_type_code']);
    }

    // ── Proposal Create Tests ───────────────────────────────

    #[Test]
    public function testProposalAltnameCreateSucceeds(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-proposal@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增別名'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'altnames',
                'mode' => 'proposal',
                'operation' => 'create',
                'result' => [
                    'status' => 'proposal_created',
                ],
            ]);

        // 原始資料表不應有新增的資料
        $this->assertDatabaseMissing('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_alt_name_chn' => '少陵',
            'c_alt_name_type_code' => 5,
        ]);
    }

    #[Test]
    public function testProposalAltnameCreateWritesProposalMeta(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-prop-meta@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
            'meta' => ['comment' => '提案新增別名'],
        ]));

        $this->assertDatabaseHas('operations', [
            'resource' => 'ALTNAME_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'ALTNAME_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('create', $resourceData['__proposal_meta']['action']);
        $this->assertSame('altnames', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案新增別名', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'ALTNAME_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testCreateDuplicateTargetPkReturns409(): void {
        $user = $this->makeUser(email: 'create-dup@example.com');
        $this->actingAs($user);

        // 先建立一筆與 createPayload PK 相同的資料
        $this->seedAltname([
            'c_alt_name_chn' => '少陵',
            'c_alt_name_type_code' => 5,
        ]);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(409)
            ->assertJson(['ok' => false]);
    }

    #[Test]
    public function testCreateDuplicatePendingProposalReturns409(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-dup-prop@example.com');
        $this->actingAs($user);

        $payload = $this->createPayload([
            'mode' => 'proposal',
        ]);

        // 第一次提案成功
        $first = $this->postJson('/api/v2/create', $payload);
        $first->assertOk();

        // 第二次相同提案被拒絕
        $second = $this->postJson('/api/v2/create', $payload);
        $second->assertStatus(409)
            ->assertJson(['ok' => false, 'errors' => ['target.pk' => ['pending_proposal_exists']]]);

        // 只有一筆提案
        $this->assertSame(1, DB::table('operations')->where('resource', 'ALTNAME_DATA')->count());
    }

    #[Test]
    public function testCreateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'create-mismatch@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    // ── Auth / Permission Tests ─────────────────────────────

    #[Test]
    public function testCreateRejectsUnauthenticatedUser(): void {
        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(401);
    }

    #[Test]
    public function testCreateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'create-inactive@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(403);
    }

    #[Test]
    public function testDirectCreateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-crowd@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403);
    }
}
