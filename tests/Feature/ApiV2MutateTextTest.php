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

class ApiV2MutateTextTest extends TestCase {
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
        $this->createTextTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_TEXT_DATA');
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

    protected function createTextTable(): void {
        Schema::create('BIOG_TEXT_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid')->default(0);
            $table->integer('c_role_id')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->text('c_supplement')->nullable();
            $table->integer('c_text_year')->nullable();
            $table->string('c_created_by', 255)->nullable();
            $table->string('c_created_date', 255)->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->string('c_modified_date', 255)->nullable();
            $table->primary(['c_personid', 'c_textid', 'c_role_id']);
        });
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function seedText(array $overrides = []): void {
        DB::table('BIOG_TEXT_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_textid' => 200,
            'c_role_id' => 1,
            'c_source' => 10,
            'c_pages' => '1-5',
            'c_notes' => null,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'text-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function textPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'texts',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_textid' => 200,
                    'c_role_id' => 1,
                ],
            ],
            'changes' => [
                'c_notes' => '新的備註',
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    // ── Direct Update Tests ─────────────────────────────────

    #[Test]
    public function testDirectTextUpdateCanRekeyTextid(): void {
        // 回歸（人物編輯重做）：著述編輯器允許在編輯模式改主鍵（c_textid／c_role_id），
        // 後端據 changes 對舊列改鍵。驗證改 c_textid 後舊列消失、新列以新 PK 存在且非 PK 欄位保留。
        $user = $this->makeUser(email: 'text-rekey@example.com');
        $this->actingAs($user);
        $this->seedText(['c_textid' => 200, 'c_role_id' => 1, 'c_pages' => '1-5']);

        $this->postJson('/api/v2/mutate', $this->textPayload([
            'changes' => ['c_textid' => 300],
        ]))->assertOk();

        $this->assertDatabaseMissing('BIOG_TEXT_DATA', [
            'c_personid' => 1000, 'c_textid' => 200, 'c_role_id' => 1,
        ]);
        $this->assertDatabaseHas('BIOG_TEXT_DATA', [
            'c_personid' => 1000, 'c_textid' => 300, 'c_role_id' => 1, 'c_pages' => '1-5',
        ]);
    }

    #[Test]
    public function testDirectTextUpdateClearingSourceNormalizesToSentinelZero(): void {
        // 回歸（人物編輯重做）：清空出處（c_source）時 React 編輯器送 null，後端須對齊 legacy
        // emptyToSentinel 正規化為 0（Unknown），不可寫成 NULL（real schema c_source NOT NULL default 0）。
        $user = $this->makeUser(email: 'text-clearsource@example.com');
        $this->actingAs($user);
        $this->seedText(['c_source' => 10]);

        $this->postJson('/api/v2/mutate', $this->textPayload([
            'changes' => ['c_source' => null],
        ]))->assertOk();

        $this->assertDatabaseHas('BIOG_TEXT_DATA', [
            'c_personid' => 1000, 'c_textid' => 200, 'c_role_id' => 1,
            'c_source' => 0,
        ]);
    }

    #[Test]
    public function testDirectTextUpdateSucceeds(): void {
        $user = $this->makeUser(email: 'text-direct@example.com');
        $this->actingAs($user);
        $this->seedText();

        $response = $this->postJson('/api/v2/mutate', $this->textPayload([
            'changes' => ['c_notes' => '更新備註', 'c_pages' => '10-20'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'texts',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_textid' => 200,
                        'c_role_id' => 1,
                    ],
                    'updated_fields' => ['c_notes', 'c_pages'],
                ],
            ]);

        $this->assertDatabaseHas('BIOG_TEXT_DATA', [
            'c_personid' => 1000,
            'c_notes' => '更新備註',
            'c_pages' => '10-20',
        ]);
    }

    #[Test]
    public function testDirectTextUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'text-result@example.com');
        $this->actingAs($user);
        $this->seedText();

        $response = $this->postJson('/api/v2/mutate', $this->textPayload());
        $data = $response->json();

        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertSame('新的備註', $data['result']['row']['c_notes']);
    }

    #[Test]
    public function testDirectTextUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'text-op@example.com');
        $this->actingAs($user);
        $this->seedText();

        $this->postJson('/api/v2/mutate', $this->textPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_TEXT_DATA',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectTextUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'text-audit@example.com');
        $this->actingAs($user);
        $this->seedText();

        $this->postJson('/api/v2/mutate', $this->textPayload());

        $audit = DB::table('audit_log')->where('table_name', 'BIOG_TEXT_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
    }

    // ── Proposal Update Tests ───────────────────────────────

    #[Test]
    public function testProposalTextUpdateCreatesPendingOperation(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'text-proposal@example.com');
        $this->actingAs($user);
        $this->seedText();

        $response = $this->postJson('/api/v2/mutate', $this->textPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '提案備註'],
            'meta' => ['comment' => '提案修改備註'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'texts',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_notes'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('BIOG_TEXT_DATA', [
            'c_personid' => 1000,
            'c_notes' => null,
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_TEXT_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'BIOG_TEXT_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('texts', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修改備註', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'BIOG_TEXT_DATA']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testTextUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'text-404@example.com');
        $this->actingAs($user);
        $this->seedText();

        $response = $this->postJson('/api/v2/mutate', $this->textPayload([
            'target' => ['pk' => [
                'c_personid' => 1000,
                'c_textid' => 999,
                'c_role_id' => 1,
            ]],
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function testTextUpdateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'text-mismatch@example.com');
        $this->actingAs($user);
        $this->seedText();

        $response = $this->postJson('/api/v2/mutate', $this->textPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testTextUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'text-empty@example.com');
        $this->actingAs($user);
        $this->seedText();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'texts',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_textid' => 200,
                    'c_role_id' => 1,
                ],
            ],
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function testTextUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'text-disallowed@example.com');
        $this->actingAs($user);
        $this->seedText();

        $response = $this->postJson('/api/v2/mutate', $this->textPayload([
            'changes' => ['c_personid' => 9999],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testTextUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'text-nochange@example.com');
        $this->actingAs($user);
        $this->seedText(['c_notes' => '已有備註']);

        $response = $this->postJson('/api/v2/mutate', $this->textPayload([
            'changes' => ['c_notes' => '已有備註'],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => ['changes' => ['no_effective_changes']],
            ]);
    }

    #[Test]
    public function testTextUpdateRejectsUnauthenticatedUser(): void {
        $this->seedText();
        $response = $this->postJson('/api/v2/mutate', $this->textPayload());
        $response->assertStatus(401);
    }

    #[Test]
    public function testTextUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'text-inactive@example.com');
        $this->actingAs($user);
        $this->seedText();

        $response = $this->postJson('/api/v2/mutate', $this->textPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testTextDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'text-crowd@example.com');
        $this->actingAs($user);
        $this->seedText();

        $response = $this->postJson('/api/v2/mutate', $this->textPayload());
        $response->assertStatus(403);
    }

    // ── #56 M 寫入等價（update 路徑，純單表；texts 無鏡像/副表）──────

    #[Test]
    #[Group('legacy-parity')] // 旧版下线時連同 legacy update 路徑一併移除
    public function testTextUpdateWriteEquivalenceLegacyVsV2(): void {
        // #56 M（update，純單表）——復原-重做實驗：同一初始狀態下 ① 旧版 PUT 改 c_notes+c_pages →記錄 →
        // ② 復原初始 → ③ 新版 /api/v2/mutate 改同一筆 → 對比 BIOG_TEXT_DATA 落庫列等價。texts 無鏡像/副表，
        // 純單表整包 update（legacy）vs partial update（v2）；只送 v2 會改的欄即等價（多送欄會被 legacy 寫入而 v2 沒寫）。
        $this->actingAs($this->makeUser(email: 'text-mupd@example.com'));

        $pk = ['c_personid' => 1000, 'c_textid' => 200, 'c_role_id' => 1];
        $seedInitial = function (): void {
            DB::table('BIOG_TEXT_DATA')->delete();
            $this->seedText(['c_notes' => '初始備註', 'c_pages' => '1-5', 'c_source' => 10]);
        };
        $cols = ['c_personid', 'c_textid', 'c_role_id', 'c_source', 'c_pages', 'c_notes'];
        $pick = function ($row) use ($cols): ?array {
            if (!$row) {
                return null;
            }
            $a = array_intersect_key((array) $row, array_flip($cols));
            ksort($a);

            return $a;
        };

        // ① 旧版 PUT（PK 走 query；body 僅送 v2 會改的兩欄，避免整包多寫）。
        // 註：legacy 對 c_textid/c_role_id 做 emptyToSentinel，但靠 query string 餵 $request->input 取回原 PK 值，
        // 故 PK 不漂移、assertNotNull($legacy) 成立——此為隱性依賴（若改純 path PK 會悄悄退化）。
        $seedInitial();
        $this->put('/basicinformation/1000/texts/update?' . http_build_query($pk), [
            'c_notes' => '改後備註', 'c_pages' => '10-20', 'action' => 'save',
        ])->assertStatus(302);
        $legacy = $pick(DB::table('BIOG_TEXT_DATA')->where($pk)->first());

        // ② 復原初始 → ③ 新版改同一筆。
        $seedInitial();
        $this->postJson('/api/v2/mutate', $this->textPayload([
            'changes' => ['c_notes' => '改後備註', 'c_pages' => '10-20'],
        ]))->assertOk()->assertJson(['ok' => true]);
        $v2 = $pick(DB::table('BIOG_TEXT_DATA')->where($pk)->first());

        $this->assertNotNull($legacy, 'legacy 更新後列不存在');
        $this->assertNotNull($v2, 'v2 更新後列不存在');
        $this->assertSame('改後備註', $v2['c_notes'], 'v2 c_notes 應更新');
        $this->assertSame('改後備註', $legacy['c_notes'], 'legacy c_notes 應更新（鎖 legacy 確有寫入）');
        $this->assertSame('10-20', $v2['c_pages'], 'v2 c_pages 應更新');
        $this->assertSame($legacy, $v2, 'BIOG_TEXT_DATA 落庫列 legacy vs v2 不等價');
    }

    #[Test]
    public function testTextUpdateAcceptsAlias(): void {
        $user = $this->makeUser(email: 'text-alias@example.com');
        $this->actingAs($user);
        $this->seedText();

        $response = $this->postJson('/api/v2/mutate', $this->textPayload([
            'resource' => 'text',
        ]));

        $response->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'texts']);
    }
}
