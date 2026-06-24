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

class ApiV2MutateEventTest extends TestCase {
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
        $this->createEventTable();
        $this->createEventsAddrTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('EVENTS_ADDR');
        Schema::dropIfExists('EVENTS_DATA');
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

    protected function createEventTable(): void {
        Schema::create('EVENTS_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_event_code')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_pages', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_year')->nullable();
            $table->integer('c_month')->nullable();
            $table->integer('c_day')->nullable();
            $table->integer('c_day_ganzhi')->nullable();
            $table->integer('c_nh_code')->nullable();
            $table->integer('c_nh_year')->nullable();
            $table->integer('c_yr_range')->nullable();
            $table->integer('c_intercalary')->default(0);
            $table->integer('c_addr_id')->nullable();
            $table->longText('c_event')->nullable();
            $table->string('c_role', 255)->nullable();
            // 真實 EVENTS_DATA 含建檔/修改稽核欄；legacy eventUpdateById（提案核准路徑）會經
            // ToolsRepository::timestamp 寫入 c_modified_*，測試表須補齊 nullable 欄否則核准會靜默回滾。
            $table->string('c_created_by', 255)->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary(['c_personid', 'c_sequence', 'c_event_code']);
        });
    }

    protected function createEventsAddrTable(): void {
        Schema::create('EVENTS_ADDR', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->default(0);
            $table->integer('c_event_code')->default(0);
            $table->integer('c_addr_id')->default(0);
            $table->primary(['c_personid', 'c_sequence', 'c_event_code', 'c_addr_id']);
        });
    }

    protected function seedEventAddr(int $addrId, array $tuple = []): void {
        DB::table('EVENTS_ADDR')->insert(array_replace([
            'c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50, 'c_addr_id' => $addrId,
        ], $tuple));
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function seedEvent(array $overrides = []): void {
        DB::table('EVENTS_DATA')->insert(array_replace([
            'c_personid' => 1000,
            'c_sequence' => 1,
            'c_event_code' => 50,
            'c_source' => 10,
            'c_pages' => '1-5',
            'c_notes' => null,
            'c_year' => 1060,
        ], $overrides));
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'event-tester@example.com'): User {
        return User::create([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function eventPayload(array $overrides = []): array {
        $payload = [
            'resource' => 'events',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_sequence' => 1,
                    'c_event_code' => 50,
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
    public function testDirectEventUpdateSucceeds(): void {
        $user = $this->makeUser(email: 'event-direct@example.com');
        $this->actingAs($user);
        $this->seedEvent();

        $response = $this->postJson('/api/v2/mutate', $this->eventPayload([
            'changes' => ['c_notes' => '更新備註', 'c_pages' => '10-20'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'events',
                'mode' => 'direct',
                'operation' => 'update',
                'result' => [
                    'pk' => [
                        'c_personid' => 1000,
                        'c_sequence' => 1,
                        'c_event_code' => 50,
                    ],
                    'updated_fields' => ['c_notes', 'c_pages'],
                ],
            ]);

        $this->assertDatabaseHas('EVENTS_DATA', [
            'c_personid' => 1000,
            'c_notes' => '更新備註',
            'c_pages' => '10-20',
        ]);
    }

    #[Test]
    public function testDirectEventUpdatePersistsRoleAndDoesNotNullOtherFields(): void {
        // 回歸（Task 27）：補欄 c_role 必須能寫入；且只改單一欄位時，未送出的欄位
        // （c_role / c_year）不可被清成 null —— 防護先前發現的「保存即清空」資料流失 bug。
        $user = $this->makeUser(email: 'event-role@example.com');
        $this->actingAs($user);
        $this->seedEvent(['c_role' => '原始角色', 'c_year' => 1060]);

        // (a) 直接更新 c_role 應成功寫入。
        $this->postJson('/api/v2/mutate', $this->eventPayload([
            'changes' => ['c_role' => '新角色'],
        ]))->assertOk();
        $this->assertDatabaseHas('EVENTS_DATA', [
            'c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50,
            'c_role' => '新角色', 'c_year' => 1060,
        ]);

        // (b) 只改 c_notes（payload 不含 c_role/c_year）後，c_role/c_year 仍保留、未被清空。
        $this->postJson('/api/v2/mutate', $this->eventPayload([
            'changes' => ['c_notes' => '只改備註'],
        ]))->assertOk();
        $this->assertDatabaseHas('EVENTS_DATA', [
            'c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50,
            'c_notes' => '只改備註', 'c_role' => '新角色', 'c_year' => 1060,
        ]);
    }

    #[Test]
    public function testEventUpdateSyncsAddrSubtable(): void {
        // 改備註 + c_addr_id 一併送 → EVENTS_ADDR 同步為新集合（刪舊重插）。
        $this->actingAs($this->makeUser(email: 'event-addr-sync@example.com'));
        $this->seedEvent();
        $this->seedEventAddr(111); // 舊地址

        $this->postJson('/api/v2/mutate', $this->eventPayload([
            'changes' => ['c_notes' => '改後', 'c_addr_id' => [222, 333]],
        ]))->assertOk();

        $this->assertDatabaseMissing('EVENTS_ADDR', ['c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50, 'c_addr_id' => 111]);
        $this->assertDatabaseHas('EVENTS_ADDR', ['c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50, 'c_addr_id' => 222]);
        $this->assertDatabaseHas('EVENTS_ADDR', ['c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50, 'c_addr_id' => 333]);
    }

    #[Test]
    public function testEventUpdateAddrOnlyChange(): void {
        // 僅改地址（無其他欄）→ 走 address-only 路徑，EVENTS_ADDR 更新成功。
        $this->actingAs($this->makeUser(email: 'event-addr-only@example.com'));
        $this->seedEvent();
        $this->seedEventAddr(111);

        $this->postJson('/api/v2/mutate', $this->eventPayload([
            'changes' => ['c_addr_id' => [222]],
        ]))->assertOk();

        $this->assertDatabaseHas('EVENTS_ADDR', ['c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50, 'c_addr_id' => 222]);
        $this->assertDatabaseMissing('EVENTS_ADDR', ['c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50, 'c_addr_id' => 111]);
    }

    #[Test]
    public function testEventUpdatePkChangeMigratesAddresses(): void {
        // 改邏輯主鍵（c_sequence 1→2）而未送地址 → 既有地址遷移到新 tuple，不留舊 tuple 孤兒。
        $this->actingAs($this->makeUser(email: 'event-addr-migrate@example.com'));
        $this->seedEvent();
        $this->seedEventAddr(111);

        $this->postJson('/api/v2/mutate', $this->eventPayload([
            'changes' => ['c_sequence' => 2],
        ]))->assertOk();

        $this->assertDatabaseHas('EVENTS_ADDR', ['c_personid' => 1000, 'c_sequence' => 2, 'c_event_code' => 50, 'c_addr_id' => 111]);
        $this->assertDatabaseMissing('EVENTS_ADDR', ['c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50, 'c_addr_id' => 111]);
    }

    #[Test]
    public function testDirectEventUpdatePersistsDayGanzhi(): void {
        // React EventEditor 農曆干支日對應 c_day_ganzhi；該欄先前漏列於白名單（會被丟棄／422），
        // 已補回。確認更新可正確落庫（無欄位遺失）。
        $user = $this->makeUser(email: 'event-ganzhi@example.com');
        $this->actingAs($user);
        $this->seedEvent();

        $response = $this->postJson('/api/v2/mutate', $this->eventPayload([
            'changes' => ['c_day_ganzhi' => 7, 'c_day' => 5],
        ]));

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('EVENTS_DATA', [
            'c_personid' => 1000,
            'c_sequence' => 1,
            'c_event_code' => 50,
            'c_day_ganzhi' => 7,
            'c_day' => 5,
        ]);
    }

    #[Test]
    public function testDirectEventUpdateReturnsOperationIdAndRow(): void {
        $user = $this->makeUser(email: 'event-result@example.com');
        $this->actingAs($user);
        $this->seedEvent();

        $response = $this->postJson('/api/v2/mutate', $this->eventPayload());
        $data = $response->json();

        $this->assertNotNull($data['result']['operation_id']);
        $this->assertNotNull($data['result']['row']);
        $this->assertSame('新的備註', $data['result']['row']['c_notes']);
    }

    #[Test]
    public function testDirectEventUpdateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'event-op@example.com');
        $this->actingAs($user);
        $this->seedEvent();

        $this->postJson('/api/v2/mutate', $this->eventPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'EVENTS_DATA',
            'op_type' => Operation::TYPE_UPDATE,
        ]);
    }

    #[Test]
    public function testDirectEventUpdateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'event-audit@example.com');
        $this->actingAs($user);
        $this->seedEvent();

        $this->postJson('/api/v2/mutate', $this->eventPayload());

        $audit = DB::table('audit_log')->where('table_name', 'EVENTS_DATA')->first();
        $this->assertNotNull($audit);
        $this->assertSame('UPDATE', $audit->operation);
    }

    // ── Proposal Update Tests ───────────────────────────────

    #[Test]
    public function testProposalEventUpdateCreatesPendingOperation(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'event-proposal@example.com');
        $this->actingAs($user);
        $this->seedEvent();

        $response = $this->postJson('/api/v2/mutate', $this->eventPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '提案備註'],
            'meta' => ['comment' => '提案修改備註'],
        ]));

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'events',
                'mode' => 'proposal',
                'operation' => 'update',
                'result' => [
                    'updated_fields' => ['c_notes'],
                    'status' => 'proposal_updated',
                ],
            ]);

        // 原始資料未被修改
        $this->assertDatabaseHas('EVENTS_DATA', [
            'c_personid' => 1000,
            'c_notes' => null,
        ]);

        // 已寫入提案操作紀錄
        $this->assertDatabaseHas('operations', [
            'resource' => 'EVENTS_DATA',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
        ]);

        $operation = DB::table('operations')->where('resource', 'EVENTS_DATA')->first();
        $resourceData = json_decode($operation->resource_data, true);

        $this->assertSame('pending', $resourceData['__review_status']);
        $this->assertSame('update', $resourceData['__proposal_meta']['action']);
        $this->assertSame('events', $resourceData['__proposal_meta']['resource_type']);
        $this->assertSame('提案修改備註', $resourceData['__proposal_meta']['comment']);

        // 無 audit_log
        $this->assertDatabaseMissing('audit_log', ['table_name' => 'EVENTS_DATA']);
    }

    #[Test]
    public function testApprovingEventUpdateProposalSyncsAddrSubtable(): void {
        // 完整往返：眾包提案改地址 → __proposal_aux 帶 c_addr_id；管理員核准後 applyEventProposal
        // 重用 eventUpdateById（is_array 守衛）同步 EVENTS_ADDR（同 tuple 刪舊重插），而非掉進泛用單表 apply 漏掉地址。
        $proposer = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'event-prop-approve@example.com');
        $this->actingAs($proposer);
        $this->seedEvent();
        $this->seedEventAddr(111);

        $this->postJson('/api/v2/mutate', $this->eventPayload([
            'mode' => 'proposal',
            'changes' => ['c_notes' => '提案改地址', 'c_addr_id' => [222, 333]],
            'meta' => ['comment' => '提案：改地址'],
        ]))->assertOk();

        // 提案階段：原資料與副表皆未變動
        $this->assertDatabaseHas('EVENTS_ADDR', ['c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50, 'c_addr_id' => 111]);
        $this->assertDatabaseMissing('EVENTS_ADDR', ['c_addr_id' => 222]);

        $operation = Operation::query()
            ->where('resource', 'EVENTS_DATA')
            ->where('op_type', Operation::TYPE_PROPOSAL_UPDATE)
            ->firstOrFail();
        $aux = json_decode($operation->resource_data, true)['__proposal_aux'] ?? [];
        $this->assertSame([222, 333], $aux['c_addr_id'] ?? null);

        // 管理員核准 → 副表落庫
        $admin = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN, 'event-admin@example.com');
        $this->actingAs($admin);
        $this->post(route('operations.proposals.approve', $operation), ['review_comment' => '同意'])
            ->assertRedirect();

        $this->assertDatabaseMissing('EVENTS_ADDR', ['c_addr_id' => 111]);
        $this->assertDatabaseHas('EVENTS_ADDR', ['c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50, 'c_addr_id' => 222]);
        $this->assertDatabaseHas('EVENTS_ADDR', ['c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50, 'c_addr_id' => 333]);
        $this->assertDatabaseHas('EVENTS_DATA', ['c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50, 'c_notes' => '提案改地址']);
    }

    // ── Error Cases ─────────────────────────────────────────

    #[Test]
    public function testEventUpdateWithInvalidPkReturns404(): void {
        $user = $this->makeUser(email: 'event-404@example.com');
        $this->actingAs($user);
        $this->seedEvent();

        $response = $this->postJson('/api/v2/mutate', $this->eventPayload([
            'target' => ['pk' => [
                'c_personid' => 1000,
                'c_sequence' => 999,
                'c_event_code' => 50,
            ]],
        ]));

        $response->assertStatus(404);
    }

    #[Test]
    public function testEventUpdateWithPersonIdMismatchReturns422(): void {
        $user = $this->makeUser(email: 'event-mismatch@example.com');
        $this->actingAs($user);
        $this->seedEvent();

        $response = $this->postJson('/api/v2/mutate', $this->eventPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    #[Test]
    public function testEventUpdateWithEmptyChangesReturns422(): void {
        $user = $this->makeUser(email: 'event-empty@example.com');
        $this->actingAs($user);
        $this->seedEvent();

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'events',
            'person_id' => 1000,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => [
                'pk' => [
                    'c_personid' => 1000,
                    'c_sequence' => 1,
                    'c_event_code' => 50,
                ],
            ],
            'changes' => [],
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function testEventUpdateWithDisallowedFieldReturns422(): void {
        $user = $this->makeUser(email: 'event-disallowed@example.com');
        $this->actingAs($user);
        $this->seedEvent();

        $response = $this->postJson('/api/v2/mutate', $this->eventPayload([
            'changes' => ['c_personid' => 9999],
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('不允許', $response->json('message'));
    }

    #[Test]
    public function testEventUpdateWithNoEffectiveChangeReturns422(): void {
        $user = $this->makeUser(email: 'event-nochange@example.com');
        $this->actingAs($user);
        $this->seedEvent(['c_notes' => '已有備註']);

        $response = $this->postJson('/api/v2/mutate', $this->eventPayload([
            'changes' => ['c_notes' => '已有備註'],
        ]));

        $response->assertStatus(422)
            ->assertJson([
                'ok' => false,
                'errors' => ['changes' => ['no_effective_changes']],
            ]);
    }

    #[Test]
    public function testEventUpdateRejectsUnauthenticatedUser(): void {
        $this->seedEvent();
        $response = $this->postJson('/api/v2/mutate', $this->eventPayload());
        $response->assertStatus(401);
    }

    #[Test]
    public function testEventUpdateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'event-inactive@example.com');
        $this->actingAs($user);
        $this->seedEvent();

        $response = $this->postJson('/api/v2/mutate', $this->eventPayload());
        $response->assertStatus(403);
    }

    #[Test]
    public function testEventDirectUpdateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'event-crowd@example.com');
        $this->actingAs($user);
        $this->seedEvent();

        $response = $this->postJson('/api/v2/mutate', $this->eventPayload());
        $response->assertStatus(403);
    }

    // ── #56 M 寫入等價（update 路徑，地址副表 EVENTS_ADDR；events 無鏡像）──────

    #[Test]
    #[Group('legacy-parity')] // 旧版下线時連同 legacy update 路徑一併移除
    public function testEventUpdateWriteEquivalenceLegacyVsV2WithAddressResync(): void {
        // #56 M（update，副表）——復原-重做實驗：同一初始狀態下 ① 旧版改一筆（notes→改後、c_source 10→20、地址 130→200）→記錄 →
        // ② 復原初始 → ③ 新版改同一筆 → 對比主列 EVENTS_DATA 內容 + 地址副表 EVENTS_ADDR「重同步」結果（130 移除、200 新增）等價。
        // events 無互逆鏡像、不經 #66 衝突閘，故此 update-M 與 #66 決策無關。核心探針：副表「靜默不落庫 / 漏刪舊地址」分歧。
        $this->actingAs($this->makeUser(email: 'event-mupd@example.com'));

        $pk = ['c_personid' => 1000, 'c_sequence' => 1, 'c_event_code' => 50];
        $seedInitial = function (): void {
            DB::table('EVENTS_DATA')->delete();
            DB::table('EVENTS_ADDR')->delete();
            $this->seedEvent(['c_notes' => '初始備註', 'c_source' => 10, 'c_year' => 1060]);
            $this->seedEventAddr(130);
        };
        // 主列內容欄（含 PK 段、c_year；排除稽核時間欄與純量 c_addr_id——地址在副表）。
        $mainCols = ['c_personid', 'c_sequence', 'c_event_code', 'c_source', 'c_notes', 'c_year'];
        $pickMain = function ($row) use ($mainCols): ?array {
            if (!$row) {
                return null;
            }
            $a = array_intersect_key((array) $row, array_flip($mainCols));
            ksort($a);

            return $a;
        };
        $addrIds = fn (): array => DB::table('EVENTS_ADDR')
            ->where($pk)->orderBy('c_addr_id')->pluck('c_addr_id')->map(fn ($v) => (int) $v)->all();

        // ① 旧版改一筆：notes→改後、c_source 10→20、地址 130→200（PK 不變）。
        // legacy eventUpdateById 直接 (int) 取 c_intercalary（未 coalesce），須送；c_addr_id 為陣列才 resync EVENTS_ADDR。
        $seedInitial();
        $this->put('/basicinformation/1000/events/update?' . http_build_query($pk), array_merge($pk, [
            'c_source' => 20, 'c_notes' => '改後', 'c_intercalary' => 0, 'c_addr_id' => [200], 'action' => 'save',
        ]))->assertStatus(302); // legacy 成功後 redirect（非寫入成功充分證明；真正的鎖在下方內容/副表斷言）
        $this->assertSame(1, DB::table('EVENTS_DATA')->count(), 'legacy 更新後主列應仍為一筆');
        $this->assertSame(1, DB::table('EVENTS_ADDR')->count(), 'legacy 更新後地址副表應僅剩新地址一筆（舊地址須刪、無孤兒）');
        $legacyMain = $pickMain(DB::table('EVENTS_DATA')->where($pk)->first());
        $legacyAddr = $addrIds();

        // ② 復原初始 → ③ 新版改同一筆。
        $seedInitial();
        $this->postJson('/api/v2/mutate', $this->eventPayload([
            'changes' => ['c_notes' => '改後', 'c_source' => 20, 'c_addr_id' => [200]],
        ]))->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(1, DB::table('EVENTS_DATA')->count(), 'v2 更新後主列應仍為一筆');
        $this->assertSame(1, DB::table('EVENTS_ADDR')->count(), 'v2 更新後地址副表應僅剩新地址一筆（舊地址須刪、無孤兒）');
        $v2Main = $pickMain(DB::table('EVENTS_DATA')->where($pk)->first());
        $v2Addr = $addrIds();

        // 主列內容等價（排除稽核時間欄）；先確認改動真的落庫，再比兩版一致。
        $this->assertNotNull($legacyMain, 'legacy 更新後主列不存在');
        $this->assertNotNull($v2Main, 'v2 更新後主列不存在');
        $this->assertSame('改後', $v2Main['c_notes'], 'v2 主列 notes 應更新為改後');
        $this->assertSame(20, (int) $v2Main['c_source'], 'v2 主列 c_source 應更新為 20（partial update 確有落庫）');
        $this->assertSame($legacyMain, $v2Main, '主列 legacy vs v2 更新結果不等價');
        // 地址副表重同步等價（兩版皆應為 [200]：舊地址 130 移除、新地址 200 新增）。
        $this->assertSame([200], $legacyAddr, 'legacy 地址重同步結果應為 [200]');
        $this->assertSame($legacyAddr, $v2Addr, '地址副表重同步 legacy vs v2 不等價');
    }

    #[Test]
    public function testEventUpdateAcceptsAlias(): void {
        $user = $this->makeUser(email: 'event-alias@example.com');
        $this->actingAs($user);
        $this->seedEvent();

        $response = $this->postJson('/api/v2/mutate', $this->eventPayload([
            'resource' => 'event',
        ]));

        $response->assertOk()
            ->assertJson(['ok' => true, 'resource' => 'events']);
    }
}
