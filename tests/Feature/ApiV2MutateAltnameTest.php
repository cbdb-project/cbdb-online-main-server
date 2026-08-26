<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use App\Services\CharVariantMapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $this->createCharVariantMapTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    /**
     * 與 database/migrations/2026_07_15_000000_create_char_variant_map_table.php
     * 相同的 7 筆種子資料，供 AltnameMutationHandler 的異體字落地替換測試使用。
     */
    protected function createCharVariantMapTable(): void {
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(1);
            $table->string('c_notes', 255)->nullable();
            $table->timestamps();

            $table->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });

        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '愼', 'c_reference_char' => '慎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '槀', 'c_reference_char' => '稿', 'c_strict_excluded' => 0],
            ['c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
            ['c_variant_char' => '靑', 'c_reference_char' => '青', 'c_strict_excluded' => 0],
            ['c_variant_char' => '頴', 'c_reference_char' => '穎', 'c_strict_excluded' => 0],
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
            ['c_variant_char' => '厰', 'c_reference_char' => '廠', 'c_strict_excluded' => 0],
        ]);
        CharVariantMapService::reset();
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
        return User::forceCreate([
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

    // ── Variant-Character Landing Replacement (strict mode) ─

    #[Test]
    public function testDirectAltnameUpdateDoesNotReplaceStrictExcludedVariant(): void {
        $user = $this->makeUser(email: 'altname-variant-excluded@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'changes' => ['c_alt_name_chn' => '峯X'],
        ]))->assertOk();

        $this->assertArrayNotHasKey('notices', $response->json());
        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_alt_name_chn' => '峯X',
        ]);
    }

    #[Test]
    public function testDirectAltnameUpdateReplacesStrictModeVariantAndReturnsNotice(): void {
        $user = $this->makeUser(email: 'altname-variant-replace@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'changes' => ['c_alt_name_chn' => '淸X'],
        ]))->assertOk();

        $body = $response->json();
        $this->assertArrayHasKey('notices', $body);
        $this->assertStringContainsString('淸', $body['notices'][0]);
        $this->assertStringContainsString('清', $body['notices'][0]);
        // 替換後的值成為新 PK 的一部分。
        $this->assertSame('清X', $body['result']['pk']['c_alt_name_chn']);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_alt_name_chn' => '清X',
        ]);
        $this->assertDatabaseMissing('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_alt_name_chn' => '淸X',
        ]);
    }

    #[Test]
    public function testDirectAltnameUpdateDetectsPkConflictUsingReplacedValue(): void {
        // 替換後的新 c_alt_name_chn（淸X → 清X）與同一人物下另一筆既有別名衝突：
        // 既有 PK 衝突偵測須用替換後的值判斷，而非替換前的原始輸入。
        $user = $this->makeUser(email: 'altname-variant-conflict@example.com');
        $this->actingAs($user);
        $this->seedAltname();
        $this->seedAltname([
            'c_alt_name_chn' => '清X',
            'c_alt_name_type_code' => 4,
            'c_source' => 5,
        ]);

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'changes' => ['c_alt_name_chn' => '淸X'],
        ]));

        $response->assertStatus(409)
            ->assertJson(['ok' => false, 'errors' => ['target.pk' => ['conflict']]]);

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
        ]);
    }

    #[Test]
    public function testProposalAltnameUpdateReplacesStrictModeVariantAndReturnsNotice(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'altname-variant-proposal-update@example.com');
        $this->actingAs($user);
        $this->seedAltname();

        $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'mode' => 'proposal',
            'changes' => ['c_alt_name_chn' => '淸X'],
            'meta' => ['comment' => '提案修改別名（異體字）'],
        ]))->assertOk();

        $body = $response->json();
        $this->assertArrayHasKey('notices', $body);
        $this->assertStringContainsString('淸', $body['notices'][0]);
        $this->assertStringContainsString('清', $body['notices'][0]);

        $operation = DB::table('operations')
            ->where('resource', 'ALTNAME_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_UPDATE)
            ->first();
        $resourceData = json_decode($operation->resource_data, true);
        $this->assertSame('清X', $resourceData['c_alt_name_chn']);

        // 原始資料表不應被直接改動
        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
        ]);
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

    /**
     * ALTNAME_DATA 沒有後端 Tier 1 拼音欄：唯一的別名羅馬字欄是 c_alt_name，它可能含
     * 西文別名（Denver 之類），一律交前端 Tier 2 互動確認，後端**不轉**。
     */
    #[Test]
    public function testDirectAltnameUpdateDoesNotConvertAltNameVToUmlaut(): void {
        $this->actingAs($this->makeUser(email: 'altname-umlaut@example.com'));
        $this->seedAltname();

        $this->postJson('/api/v2/mutate', $this->altnamePayload([
            'changes' => ['c_alt_name' => 'Lv Meng'],
        ]))->assertOk();

        $this->assertDatabaseHas('ALTNAME_DATA', [
            'c_personid' => 1000,
            'c_alt_name_chn' => '子美',
            'c_alt_name' => 'Lv Meng',
        ]);
    }

    /**
     * ALTNAME_DATA 沒有 c_alt_name_pinyin／_pinyin2／_pinyin3／c_alt_name_role 這幾欄
     * （baseline migration 就是 12 欄）。白名單外的欄位必須擋在 422，不可以放行到 SQL 層炸成 500。
     */
    #[Test]
    public function testAltnameUpdateRejectsColumnsThatDoNotExist(): void {
        $this->actingAs($this->makeUser(email: 'altname-phantom@example.com'));
        $this->seedAltname();

        foreach (['c_alt_name_pinyin', 'c_alt_name_pinyin2', 'c_alt_name_pinyin3', 'c_alt_name_role'] as $column) {
            $response = $this->postJson('/api/v2/mutate', $this->altnamePayload([
                'changes' => [$column => 'x'],
            ]));

            $response->assertStatus(422);
            $this->assertStringContainsString($column, (string) $response->json('errors.changes.0'));
        }
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
    public function testAltnameCodeFieldSentinelFullyIdempotent(): void {
        // c_source（legacy 哨兵 0=Unknown）所有空表示 null/''/-999/'0'/0 → 0、合法值保留、來回不翻。≥10 案例。
        $this->actingAs($this->makeUser(email: 'altname-sentinel@example.com'));
        $T = 'ALTNAME_DATA';
        $f = 'c_source';
        foreach ([null, '', -999, '0', 0] as $sent) {
            DB::table($T)->delete();
            $this->seedAltname([$f => 5, 'c_notes' => '初始']);
            $this->postJson('/api/v2/mutate', $this->altnamePayload(['changes' => [$f => $sent, 'c_notes' => '改'.var_export($sent, true)]]))->assertOk();
            $this->assertSame(0, (int) DB::table($T)->value($f), $f.' 送 '.var_export($sent, true).' 應規範化為 0');
            $this->assertNotNull(DB::table($T)->value($f), $f.' 不得為 null');
        }
        DB::table($T)->delete();
        $this->seedAltname([$f => 1, 'c_notes' => 'x']);
        $this->postJson('/api/v2/mutate', $this->altnamePayload(['changes' => [$f => 7, 'c_notes' => '合法值']]))->assertOk();
        $this->assertSame(7, (int) DB::table($T)->value($f), '合法非 0 值不得被誤清');
        foreach ([null, '', -999, 0] as $i => $sent) {
            $this->postJson('/api/v2/mutate', $this->altnamePayload(['changes' => [$f => $sent, 'c_notes' => '再'.$i]]))->assertOk();
            $this->assertSame(0, (int) DB::table($T)->value($f), '幂等重送仍為 0（第'.$i.'輪）');
        }
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
