<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use App\Services\CharVariantMapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsPinyinDictionary;
use Tests\TestCase;

class ApiV2CreateBiogMainTest extends TestCase {
    use SeedsPinyinDictionary;

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
        $this->createBiogMainTable();
        $this->createPinyinTable();
        $this->createCharVariantMapTable();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('pinyin');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    /**
     * 與 database/migrations/2026_07_15_000000_create_char_variant_map_table.php
     * 相同的 7 筆種子資料，供 BiogMainRepository::store() 的異體字落地替換測試使用。
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

    protected function createBiogMainTable(): void {
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->string('c_name_proper')->nullable();
            $table->string('c_name_rm')->nullable();
            $table->string('c_surname_chn')->nullable();
            $table->string('c_mingzi_chn')->nullable();
            $table->string('c_surname')->nullable();
            $table->string('c_mingzi')->nullable();
            $table->string('c_surname_proper')->nullable();
            $table->string('c_mingzi_proper')->nullable();
            $table->string('c_surname_rm')->nullable();
            $table->string('c_mingzi_rm')->nullable();
            $table->integer('c_female')->nullable();
            $table->integer('c_by_intercalary')->default(0);
            $table->integer('c_dy_intercalary')->default(0);
            $table->integer('c_index_year')->nullable();
            $table->integer('c_dy')->nullable();
            $table->integer('c_death_age')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_tribe')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function createPinyinTable(): void {
        Schema::create('pinyin', function (Blueprint $table) {
            $table->increments('id');
            $table->string('c_chn');
            $table->string('c_pinyin')->nullable();
            $table->tinyInteger('c_lastname')->default(0);
            $table->unique(['c_chn', 'c_lastname']);
        });

        // 提供常見姓氏拼音，讓 auto_pinyin 能拆出姓名
        DB::table('pinyin')->insert([
            ['c_chn' => '張', 'c_pinyin' => 'zhang', 'c_lastname' => 1],
            ['c_chn' => '李', 'c_pinyin' => 'li', 'c_lastname' => 1],
        ]);

        // 名字部分（mingzi）走一般轉換路徑，需要真實字典資料才能跟現行
        // Pinyin::$dic 行為一致（見 docs/PINYIN_TABLE_CONSOLIDATION_PLAN.md 步驟4）。
        $this->seedPinyinDictionary();
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'create-biog-tester@example.com'): User {
        return User::forceCreate([
            'name' => 'tester',
            'email' => $email,
            'confirmation_token' => 'token-123',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    protected function seedBiogMain(int $personId = 1000, array $overrides = []): void {
        DB::table('BIOG_MAIN')->insert(array_replace([
            'c_personid' => $personId,
            'c_name_chn' => '既有人物',
            'c_name' => 'Existing',
            'c_created_by' => 'seed',
            'c_created_date' => now(),
        ], $overrides));
    }

    protected function createPayload(array $overrides = []): array {
        return array_replace_recursive([
            'resource' => 'basicinformation',
            'person_id' => 2000,
            'mode' => 'direct',
            'target' => [
                'pk' => [
                    'c_personid' => 2000,
                ],
            ],
            'changes' => [
                'c_personid' => 2000,
                'c_name_chn' => '張三',
                'c_female' => 0,
                'c_index_year' => 1050,
            ],
        ], $overrides);
    }

    // ── Direct Create Tests ─────────────────────────────────

    #[Test]
    public function testDirectBiogMainCreateSucceeds(): void {
        $user = $this->makeUser(email: 'create-biog-direct@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'resource' => 'basicinformation',
                'mode' => 'direct',
                'operation' => 'create',
                'result' => [
                    'pk' => ['c_personid' => 2000],
                ],
            ]);

        $this->assertNotNull($response->json('result.row'));

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 2000,
            'c_name_chn' => '張三',
        ]);
    }

    #[Test]
    public function testDirectBiogMainCreateWritesOperationRecord(): void {
        $user = $this->makeUser(email: 'create-biog-op@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $this->assertDatabaseHas('operations', [
            'resource' => 'BIOG_MAIN',
            'c_personid' => 2000,
            'op_type' => Operation::TYPE_CREATE,
        ]);
    }

    #[Test]
    public function testDirectBiogMainCreateWritesAuditLog(): void {
        $user = $this->makeUser(email: 'create-biog-audit@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v2/create', $this->createPayload());

        $audit = DB::table('audit_log')->where('table_name', 'BIOG_MAIN')->first();
        $this->assertNotNull($audit);
        $this->assertSame('INSERT', $audit->operation);
    }

    #[Test]
    public function testDirectBiogMainCreateSucceedsWhenFtsTableMissing(): void {
        // CBDB__NAME_FTS 不存在時不應崩潰（reindex 被跳過）
        $this->assertFalse(Schema::hasTable('CBDB__NAME_FTS'));

        $user = $this->makeUser(email: 'create-biog-nofts@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertOk()->assertJson(['ok' => true]);
    }

    // ── Variant-Character Landing Replacement (strict mode) ─

    #[Test]
    public function testDirectBiogMainCreateDoesNotReplaceStrictExcludedVariant(): void {
        $user = $this->makeUser(email: 'create-biog-variant-excluded@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_surname_chn' => '張', 'c_mingzi_chn' => '峯'],
        ]));

        $response->assertOk();
        $this->assertArrayNotHasKey('notices', $response->json());

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 2000,
            'c_surname_chn' => '張',
            'c_mingzi_chn' => '峯',
            'c_name_chn' => '張峯',
        ]);
    }

    #[Test]
    public function testDirectBiogMainCreateReplacesStrictModeVariantAndReturnsNotice(): void {
        $user = $this->makeUser(email: 'create-biog-variant-replace@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_surname_chn' => '張', 'c_mingzi_chn' => '淸'],
        ]));

        $response->assertOk();
        $body = $response->json();
        $this->assertArrayHasKey('notices', $body);
        $this->assertStringContainsString('淸', $body['notices'][0]);
        $this->assertStringContainsString('清', $body['notices'][0]);

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 2000,
            'c_surname_chn' => '張',
            'c_mingzi_chn' => '清',
            'c_name_chn' => '張清',
        ]);
    }

    /**
     * 同一列裡兩套規則並存：姓名欄 strict（`峯` 保留原形），其餘文本欄 lenient（`峯`→`峰`）。
     *
     * 這是 plan S3 把三處手掛姓名欄改成整列 `replaceRow('BIOG_MAIN')` 的核心回歸——
     * 舊碼只替換 c_surname_chn／c_mingzi_chn，c_notes／c_tribe 等文本欄全漏。
     */
    #[Test]
    public function testDirectBiogMainCreateAppliesStrictToNameAndLenientToOtherTextColumns(): void {
        $this->actingAs($this->makeUser(email: 'create-biog-variant-mixed@example.com'));

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => [
                'c_surname_chn' => '張',
                'c_mingzi_chn' => '峯',
                'c_notes' => '峯下人',
                'c_tribe' => '峯部',
            ],
        ]))->assertOk();

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 2000,
            'c_mingzi_chn' => '峯',      // strict：峯 不替換
            'c_name_chn' => '張峯',
            'c_notes' => '峰下人',       // lenient：峯→峰
            'c_tribe' => '峰部',
        ]);
        $this->assertNotEmpty($response->json('notices'), 'lenient 欄發生替換也要回通知');
    }

    #[Test]
    public function testDirectBiogMainCreateWithSurnamePartsIgnoresClientSuppliedNameChn(): void {
        // 前端同時送 c_surname_chn/c_mingzi_chn 與一個不一致的 c_name_chn：
        // 後端須無條件用替換後的分欄重組，覆蓋前端送來的值。
        $user = $this->makeUser(email: 'create-biog-variant-override@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => [
                'c_surname_chn' => '張',
                'c_mingzi_chn' => '淸',
                'c_name_chn' => '完全不同的名字',
            ],
        ]));

        $response->assertOk();

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 2000,
            'c_name_chn' => '張清',
        ]);
    }

    #[Test]
    public function testDirectBiogMainCreateWithOnlyNameChnDoesNotClearItWhenPartsAreAbsent(): void {
        // 迴歸測試：只送 c_name_chn、完全不送 c_surname_chn/c_mingzi_chn（無明確姓氏
        // 可拆分的歷史人物），c_name_chn 本身仍要落地替換，但不能被「不存在的兩個
        // 分欄相加」覆寫成空字串。
        $user = $this->makeUser(email: 'create-biog-name-only@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'changes' => ['c_name_chn' => '淸公'],
        ]));

        $response->assertOk();
        $body = $response->json();
        $this->assertArrayHasKey('notices', $body);

        $this->assertDatabaseHas('BIOG_MAIN', [
            'c_personid' => 2000,
            'c_name_chn' => '清公',
        ]);
    }

    // ── Validation Error Cases ──────────────────────────────

    #[Test]
    public function testCreateRejectsDuplicatePersonId(): void {
        $user = $this->makeUser(email: 'create-biog-dup@example.com');
        $this->actingAs($user);
        $this->seedBiogMain(2000);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['c_personid' => ['exists']]]);
    }

    #[Test]
    public function testCreateRejectsZeroPersonId(): void {
        $user = $this->makeUser(email: 'create-biog-zero@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'person_id' => 0,
            'target' => ['pk' => ['c_personid' => 0]],
            'changes' => ['c_personid' => 0],
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['c_personid' => ['required']]]);
    }

    #[Test]
    public function testCreateRejectsTooLargePersonId(): void {
        $user = $this->makeUser(email: 'create-biog-large@example.com');
        $this->actingAs($user);
        // 既有最大 personid = 1000；2000 - 1000 <= 10000 OK，故設定一筆讓 max 很小
        $this->seedBiogMain(5);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'person_id' => 50000,
            'target' => ['pk' => ['c_personid' => 50000]],
            'changes' => ['c_personid' => 50000, 'c_name_chn' => '張三'],
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['c_personid' => ['too_large']]]);
    }

    #[Test]
    public function testCreateRejectsPersonIdMismatch(): void {
        $user = $this->makeUser(email: 'create-biog-mismatch@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'person_id' => 9999,
        ]));

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'errors' => ['person_id' => ['mismatch']]]);
    }

    // ── Proposal Mode (501) ─────────────────────────────────

    #[Test]
    public function testProposalModeReturns501(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-biog-proposal@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'proposal',
        ]));

        $response->assertStatus(501)
            ->assertJson(['ok' => false]);
    }

    // ── Auth / Permission Tests ─────────────────────────────

    #[Test]
    public function testCreateRejectsUnauthenticatedUser(): void {
        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(401);
    }

    #[Test]
    public function testCreateRejectsInactiveUser(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'create-biog-inactive@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload());

        $response->assertStatus(403);
    }

    #[Test]
    public function testDirectCreateRejectsCrowdsourcingUser(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'create-biog-crowd@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v2/create', $this->createPayload([
            'mode' => 'direct',
        ]));

        $response->assertStatus(403);
    }
}
