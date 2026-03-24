<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2MutateTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

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
        $this->createSourceTable();
        $this->createTextCodesTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('BIOG_SOURCE_DATA');
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
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
            $table->integer('c_sequence')->nullable();
            $table->string('c_alt_name_chn');
            $table->string('c_alt_name')->nullable();
            $table->integer('c_alt_name_type_code');
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function createSourceTable(): void {
        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->string('c_pages');
            $table->text('c_notes')->nullable();
            $table->integer('c_main_source')->default(0);
            $table->integer('c_self_bio')->default(0);
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function createTextCodesTable(): void {
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title')->nullable();
            $table->string('c_title_chn')->nullable();
        });
    }

    protected function seedTextCode(int $textId = 99999): void {
        DB::table('TEXT_CODES')->insert([
            'c_textid' => $textId,
            'c_title' => 'Test Source',
            'c_title_chn' => '測試出處',
        ]);
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function sourceCreatePayload(array $overrides = []): array {
        $payload = [
            'resource' => 'sources',
            'person_id' => 138841,
            'mode' => 'proposal',
            'operation' => 'create',
            'target' => [
                'pk' => [
                    'c_personid' => 138841,
                    'c_textid' => 99999,
                    'c_pages' => '張忠墓誌',
                ],
            ],
            'changes' => [
                'c_personid' => 138841,
                'c_textid' => 99999,
                'c_pages' => '張忠墓誌',
                'c_notes' => '來自浙江大學圖書館中國歷代墓誌數據庫',
                'c_main_source' => 0,
                'c_self_bio' => 0,
            ],
            'meta' => [
                'comment' => '由 API 自動提交',
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    #[Test]
    public function testSessionAuthenticatedUserCanMutateAltnameSequenceViaApiV2Mutate() {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 11,
            'c_sequence' => 1,
            'c_alt_name_chn' => '子止',
            'c_alt_name' => 'Zi Zhi',
            'c_alt_name_type_code' => 4,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 11,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 11,
                    'c_alt_name_chn' => '子止',
                    'c_alt_name_type_code' => 4,
                ],
            ],
            'changes' => [
                'c_sequence' => 2,
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'altnames',
                'mode' => 'direct',
                'operation' => 'update',
            ]);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 11,
            'c_alt_name_chn' => '子止',
            'c_alt_name_type_code' => 4,
            'c_sequence' => 2,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'ALTNAME_DATA',
            'c_personid' => 11,
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testRejectsAltnameMutationWhenPersonIdDoesNotMatchTargetPk() {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 11,
            'c_sequence' => 1,
            'c_alt_name_chn' => '子止',
            'c_alt_name' => 'Zi Zhi',
            'c_alt_name_type_code' => 4,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'altnames',
            'person_id' => 12,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 11,
                    'c_alt_name_chn' => '子止',
                    'c_alt_name_type_code' => 4,
                ],
            ],
            'changes' => [
                'c_sequence' => 9,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
            ]);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 11,
            'c_alt_name_chn' => '子止',
            'c_alt_name_type_code' => 4,
            'c_sequence' => 1,
        ]);
    }

    #[Test]
    public function testSourceProposalCreateWithBearerTokenAuthentication() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'token-user@example.com');
        $token = $user->createToken('api-test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v2/mutate', $this->sourceCreatePayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'sources',
                'mode' => 'proposal',
                'operation' => 'create',
                'result' => [
                    'pk' => [
                        'c_personid' => 138841,
                        'c_textid' => 99999,
                        'c_pages' => '張忠墓誌',
                    ],
                    'status' => 'proposal_created',
                ],
            ]);

        $this->assertDatabaseMissing('BIOG_SOURCE_DATA', [
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '張忠墓誌',
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_SOURCE_DATA',
            'c_personid' => 138841,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'BIOG_SOURCE_DATA')->where('op_type', Operation::TYPE_PROPOSAL_CREATE)->first();
        $payload = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $payload['__review_status']);
        $this->assertSame('sources', $payload['__proposal_meta']['resource_type']);
        $this->assertSame('由 API 自動提交', $payload['__proposal_meta']['comment']);
    }

    #[Test]
    public function testSourceProposalCreateDoesNotRequireChangesPersonId() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'no-change-person@example.com');
        $this->actingAs($user);

        $payload = $this->sourceCreatePayload();
        unset($payload['changes']['c_personid']);

        $response = $this->postJson('/api/v2/mutate', $payload);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'sources',
                'mode' => 'proposal',
                'operation' => 'create',
            ]);

        $operation = DB::table('operations')->where('resource', 'BIOG_SOURCE_DATA')->where('op_type', Operation::TYPE_PROPOSAL_CREATE)->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame(138841, $resourceData['c_personid']);
    }

    #[Test]
    public function testSourceProposalCreateAllowsEmptyPages() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'empty-pages@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload([
            'target' => [
                'pk' => [
                    'c_pages' => '',
                ],
            ],
            'changes' => [
                'c_pages' => '',
            ],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'result' => [
                    'pk' => [
                        'c_pages' => '',
                    ],
                    'status' => 'proposal_created',
                ],
            ]);

        $operation = DB::table('operations')->where('resource', 'BIOG_SOURCE_DATA')->where('op_type', Operation::TYPE_PROPOSAL_CREATE)->first();
        $this->assertSame('c_personid=138841&c_textid=99999&c_pages=', $operation->resource_id);
    }

    #[Test]
    public function testSourceProposalCreateDetectsLegacyNullEncodedPendingProposalForEmptyPages() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'legacy-empty-pages@example.com');
        $this->actingAs($user);

        DB::table('operations')->insert([
            'user_id' => $user->id,
            'c_personid' => 138841,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'BIOG_SOURCE_DATA',
            'resource_id' => 'c_personid=138841&c_textid=99999&c_pages=NULL',
            'resource_data' => json_encode([
                'c_personid' => 138841,
                'c_textid' => 99999,
                'c_pages' => '',
                'c_notes' => '舊提案',
                '__key_columns' => ['c_personid', 'c_textid', 'c_pages'],
                '__review_status' => 'pending',
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => null,
            'crowdsourcing_status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload([
            'target' => [
                'pk' => [
                    'c_pages' => '',
                ],
            ],
            'changes' => [
                'c_pages' => '',
            ],
        ]));

        $response->assertStatus(409)
            ->assertJson([
                'ok' => false,
                'message' => '相同主鍵已有待審核提案',
            ]);
    }

    #[Test]
    public function testSourceProposalCreateRequiresAuthentication() {
        $this->seedTextCode();

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload());

        $response->assertStatus(401)
            ->assertJson([
                'ok' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    #[Test]
    public function testSourceProposalCreateRejectsDuplicateCompositePrimaryKey() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'duplicate@example.com');
        $this->actingAs($user);

        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '張忠墓誌',
            'c_notes' => '既有資料',
            'c_main_source' => 0,
            'c_self_bio' => 0,
        ]);

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload());

        $response->assertStatus(409)
            ->assertJson([
                'ok' => false,
                'message' => 'BIOG_SOURCE_DATA 記錄已存在',
            ]);
    }

    #[Test]
    public function testSourceProposalCreateTreatsSelect2ZeroTextIdAliasAsDuplicateCompositePrimaryKey() {
        $this->seedTextCode(0);
        $user = $this->makeUser(email: 'zero-text-duplicate@example.com');
        $this->actingAs($user);

        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 138841,
            'c_textid' => 0,
            'c_pages' => '張忠墓誌',
            'c_notes' => '既有資料',
            'c_main_source' => 0,
            'c_self_bio' => 0,
        ]);

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload([
            'target' => [
                'pk' => [
                    'c_textid' => -999,
                ],
            ],
            'changes' => [
                'c_textid' => -999,
            ],
        ]));

        $response->assertStatus(409)
            ->assertJson([
                'ok' => false,
                'message' => 'BIOG_SOURCE_DATA 記錄已存在',
            ]);
    }

    #[Test]
    public function testSourceProposalCreateRejectsUnknownTextId() {
        $user = $this->makeUser(email: 'invalid-text@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload([
            'target' => [
                'pk' => [
                    'c_textid' => 100001,
                ],
            ],
            'changes' => [
                'c_textid' => 100001,
            ],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'message' => '參數校驗失敗',
            ]);
    }

    #[Test]
    public function testDirectSourceCreateRequiresDirectWritePermission() {
        $this->seedTextCode();
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'crowd@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/mutate', $this->sourceCreatePayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403)
            ->assertJson([
                'ok' => false,
            ]);
    }

    #[Test]
    public function testDirectSourceUpdateWritesDataAndOperationRecord() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'direct-update@example.com');
        $this->actingAs($user);

        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '張忠墓誌',
            'c_notes' => '舊備註',
            'c_main_source' => 0,
            'c_self_bio' => 0,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 138841,
                    'c_textid' => 99999,
                    'c_pages' => '張忠墓誌',
                ],
            ],
            'changes' => [
                'c_personid' => 138841,
                'c_notes' => '新備註',
                'c_main_source' => 1,
                'c_self_bio' => 0,
            ],
            'meta' => [
                'comment' => '直接更新',
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'sources',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'status' => 'updated',
                ],
            ]);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '張忠墓誌',
            'c_notes' => '新備註',
            'c_main_source' => 1,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_SOURCE_DATA',
            'c_personid' => 138841,
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectSourceUpdateSupportsSelect2ZeroTextIdAliasWithoutChangesPersonId() {
        $this->seedTextCode(0);
        $user = $this->makeUser(email: 'zero-text-update@example.com');
        $this->actingAs($user);

        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 138841,
            'c_textid' => 0,
            'c_pages' => '',
            'c_notes' => '舊備註',
            'c_main_source' => 0,
            'c_self_bio' => 0,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 138841,
                    'c_textid' => -999,
                    'c_pages' => '',
                ],
            ],
            'changes' => [
                'c_notes' => '新備註',
                'c_main_source' => 1,
                'c_self_bio' => 0,
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'result' => [
                    'status' => 'updated',
                    'pk' => [
                        'c_personid' => 138841,
                        'c_textid' => 0,
                        'c_pages' => '',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 138841,
            'c_textid' => 0,
            'c_pages' => '',
            'c_notes' => '新備註',
            'c_main_source' => 1,
        ]);
    }

    #[Test]
    public function testDirectSourceUpdateAcceptsNormalizedKeyAliasInChanges() {
        $this->seedTextCode(0);
        $user = $this->makeUser(email: 'zero-text-full-key@example.com');
        $this->actingAs($user);

        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 138841,
            'c_textid' => 0,
            'c_pages' => '',
            'c_notes' => '舊備註',
            'c_main_source' => 0,
            'c_self_bio' => 0,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 138841,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 138841,
                    'c_textid' => 0,
                    'c_pages' => '',
                ],
            ],
            'changes' => [
                'c_personid' => 138841,
                'c_textid' => -999,
                'c_pages' => '',
                'c_notes' => '新備註',
                'c_main_source' => 1,
                'c_self_bio' => 0,
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'result' => [
                    'status' => 'updated',
                ],
            ]);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 138841,
            'c_textid' => 0,
            'c_pages' => '',
            'c_notes' => '新備註',
            'c_main_source' => 1,
        ]);
    }

    #[Test]
    public function testSourceProposalUpdateCreatesPendingOperationWithoutChangingData() {
        $this->seedTextCode();
        $user = $this->makeUser(email: 'proposal-update@example.com');
        $this->actingAs($user);

        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '張忠墓誌',
            'c_notes' => '舊備註',
            'c_main_source' => 0,
            'c_self_bio' => 0,
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'sources',
            'person_id' => 138841,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 138841,
                    'c_textid' => 99999,
                    'c_pages' => '張忠墓誌',
                ],
            ],
            'changes' => [
                'c_personid' => 138841,
                'c_notes' => '提案中的新備註',
                'c_main_source' => 1,
                'c_self_bio' => 0,
            ],
            'meta' => [
                'comment' => '提案更新',
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'sources',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'status' => 'proposal_updated',
                ],
            ]);

        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 138841,
            'c_textid' => 99999,
            'c_pages' => '張忠墓誌',
            'c_notes' => '舊備註',
            'c_main_source' => 0,
        ]);

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_SOURCE_DATA',
            'c_personid' => 138841,
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);
    }

    #[Test]
    public function testInvalidBearerTokenIsRejectedBeforeMutationHandling() {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token',
        ])->postJson('/api/v2/mutate', $this->sourceCreatePayload());

        $response->assertStatus(401);
        $this->assertStringContainsString('Invalid API token', $response->getContent());
    }
}
