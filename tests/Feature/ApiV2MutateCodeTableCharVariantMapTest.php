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

/**
 * code 表 mutation（resource=char-variant-map/char_variant_map → char_variant_map）回歸測試。
 * 見 docs/CHAR_VARIANT_MAP_CALL_SITE_WIRING_PLAN.md 步驟 7。
 *
 * 驗證 config 驅動的 CodeTableCreate/DeleteHandler、ConfigCodeTableMutationHandler：
 * - create 顯式主鍵 / 自動分配（max+1）/ c_variant_char 唯一鍵撞號回合理錯誤（非 500）
 * - update 可修改 allowed_fields
 * - delete 恆 403（全域碼表刪除閘門，非本表特例）
 */
class ApiV2MutateCodeTableCharVariantMapTest extends TestCase {
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

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
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
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(1);
            $table->string('c_notes', 255)->nullable();
            $table->timestamps();
            // 見 database/migrations/2026_07_17_000000_add_audit_columns_to_char_variant_map_table.php：
            // CodeTableCreateHandler 透過 ToolsRepository::timestamp() 無條件寫入這 4 欄。
            $table->string('c_created_by', 255)->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->dateTime('c_modified_date')->nullable();

            $table->unique('c_variant_char', 'char_variant_map_c_variant_char_unique');
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('char_variant_map');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'cvm@example.com'): User {
        return User::forceCreate([
            'name' => 'Code Tester',
            'email' => $email,
            'confirmation_token' => 'tok',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    #[Test]
    public function testCreateWithExplicitId(): void {
        $this->actingAs($this->makeUser(email: 'cvm-explicit@example.com'));

        $res = $this->postJson('/api/v2/create', [
            'resource' => 'char-variant-map',
            'person_id' => 0,
            'target' => ['pk' => ['id' => 500]],
            'changes' => ['c_variant_char' => '試', 'c_reference_char' => '试', 'c_strict_excluded' => 1],
        ]);

        $res->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'char-variant-map',
            'operation' => 'create',
            'result' => ['pk' => ['id' => 500]],
        ]);
        $this->assertDatabaseHas('char_variant_map', ['id' => 500, 'c_variant_char' => '試']);
        $this->assertSame(1, DB::table('operations')->where('resource', 'char_variant_map')->count());
        $this->assertSame(1, DB::table('audit_log')->where('table_name', 'char_variant_map')->count());
    }

    #[Test]
    public function testCreateAutoAssignsNextId(): void {
        $this->actingAs($this->makeUser(email: 'cvm-auto@example.com'));
        DB::table('char_variant_map')->insert(['id' => 10, 'c_variant_char' => '甲', 'c_reference_char' => '乙', 'c_strict_excluded' => 1]);

        $res = $this->postJson('/api/v2/create', [
            'resource' => 'char_variant_map',
            'person_id' => 0,
            'target' => ['pk' => []],
            'changes' => ['c_variant_char' => '丙', 'c_reference_char' => '丁', 'c_strict_excluded' => 1],
        ]);

        $res->assertOk()->assertJson(['ok' => true, 'result' => ['pk' => ['id' => 11]]]);
        $this->assertDatabaseHas('char_variant_map', ['id' => 11, 'c_variant_char' => '丙']);
    }

    #[Test]
    public function testCreateDuplicateVariantCharReturns409NotServerError(): void {
        // c_variant_char 有唯一鍵，重複值須被友善攔下（409），不能讓資料庫層 QueryException 冒出成 500。
        $this->actingAs($this->makeUser(email: 'cvm-dup@example.com'));
        DB::table('char_variant_map')->insert(['id' => 20, 'c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0]);

        $this->postJson('/api/v2/create', [
            'resource' => 'charvariantmap',
            'person_id' => 0,
            'target' => ['pk' => ['id' => 21]],
            'changes' => ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
        ])->assertStatus(409);

        $this->assertSame(1, DB::table('char_variant_map')->where('c_variant_char', '淸')->count());
    }

    #[Test]
    public function testDisallowedFieldReturns422(): void {
        $this->actingAs($this->makeUser(email: 'cvm-bad@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'char-variant-map',
            'person_id' => 0,
            'target' => ['pk' => ['id' => 30]],
            'changes' => ['c_variant_char' => '試', 'c_not_a_column' => 'y'],
        ])->assertStatus(422);
        $this->assertSame(0, DB::table('char_variant_map')->count());
    }

    #[Test]
    public function testUpdateModifiesAllowedField(): void {
        $this->actingAs($this->makeUser(email: 'cvm-update@example.com'));
        DB::table('char_variant_map')->insert(['id' => 40, 'c_variant_char' => '厰', 'c_reference_char' => '廠', 'c_strict_excluded' => 0]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'char_variant_map',
            'person_id' => 0,
            'operation' => 'update',
            'target' => ['pk' => ['id' => 40]],
            'changes' => ['c_notes' => '補充說明'],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('char_variant_map', ['id' => 40, 'c_notes' => '補充說明']);
        $this->assertSame(1, DB::table('audit_log')->where('table_name', 'char_variant_map')->where('operation', 'UPDATE')->count());
    }

    #[Test]
    public function testUpdateAcceptsIntegerValueForStrictExcludedFlag(): void {
        // c_strict_excluded 是整數旗標欄（本表是第一個註冊進來的整數欄），
        // AbstractCodeTableMutationHandler::validateFields() 原本只接受 string|null，
        // JSON 呼叫端送整數 0/1 會被誤擋 422；已放寬為同時接受 int。
        $this->actingAs($this->makeUser(email: 'cvm-update-int@example.com'));
        DB::table('char_variant_map')->insert(['id' => 41, 'c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'char_variant_map',
            'person_id' => 0,
            'operation' => 'update',
            'target' => ['pk' => ['id' => 41]],
            'changes' => ['c_strict_excluded' => 0],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('char_variant_map', ['id' => 41, 'c_strict_excluded' => 0]);
    }

    #[Test]
    public function testUpdateDuplicateVariantCharReturns409NotServerError(): void {
        // c_variant_char 唯一鍵：更新撞到既有值須回 409，不能讓 QueryException 冒出成 500。
        $this->actingAs($this->makeUser(email: 'cvm-update-dup@example.com'));
        DB::table('char_variant_map')->insert(['id' => 42, 'c_variant_char' => '槀', 'c_reference_char' => '稿', 'c_strict_excluded' => 0]);
        DB::table('char_variant_map')->insert(['id' => 43, 'c_variant_char' => '靑', 'c_reference_char' => '青', 'c_strict_excluded' => 0]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'char_variant_map',
            'person_id' => 0,
            'operation' => 'update',
            'target' => ['pk' => ['id' => 43]],
            'changes' => ['c_variant_char' => '槀'],
        ])->assertStatus(409);

        $this->assertDatabaseHas('char_variant_map', ['id' => 43, 'c_variant_char' => '靑']);
    }

    /**
     * 結構守衛必須掛在**落庫層**（handler），不是只掛在 Codes UI controller：
     * `char_variant_map` 登記在 config/code_table_mutations.php，用 API 就能寫。
     *
     * 成環的對照（表裡已有 `峯→峰`，再送 `峰→峯`）若讓它落庫，`resolveMap()` 的
     * `dropCycleEdges()` 會把環上**兩條**邊一起丟掉、只留一行 Log::error ⇒ 這組字的
     * 落地替換在全站靜默停止。S3 之後這個洞的影響面是所有人物寫入路徑。
     */
    #[Test]
    public function testCreateCyclicMappingIsRejectedByApi(): void {
        $this->actingAs($this->makeUser(email: 'cvm-cycle@example.com'));
        DB::table('char_variant_map')->insert(['id' => 60, 'c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1]);

        $res = $this->postJson('/api/v2/create', [
            'resource' => 'char_variant_map',
            'person_id' => 0,
            'target' => ['pk' => ['id' => 61]],
            'changes' => ['c_variant_char' => '峰', 'c_reference_char' => '峯', 'c_strict_excluded' => 1],
        ]);

        $res->assertStatus(422);
        $this->assertSame(1, DB::table('char_variant_map')->count(), '成環的對照不得落庫');
    }

    /** 多字元對照同樣要在落庫前擋下（會被 resolveMap() 靜默丟棄）。 */
    #[Test]
    public function testCreateMultiCodepointMappingIsRejectedByApi(): void {
        $this->actingAs($this->makeUser(email: 'cvm-multi@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'char_variant_map',
            'person_id' => 0,
            'target' => ['pk' => ['id' => 70]],
            'changes' => ['c_variant_char' => '甲乙', 'c_reference_char' => '丙', 'c_strict_excluded' => 1],
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('char_variant_map')->count());
    }

    /** 更新成環同樣要擋（排除自己那一列後仍成環）。 */
    #[Test]
    public function testUpdateIntoCycleIsRejectedByApi(): void {
        $this->actingAs($this->makeUser(email: 'cvm-cycle-upd@example.com'));
        DB::table('char_variant_map')->insert([
            ['id' => 80, 'c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1],
            ['id' => 81, 'c_variant_char' => '靑', 'c_reference_char' => '青', 'c_strict_excluded' => 1],
        ]);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'char_variant_map',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['id' => 81]],
            'changes' => ['c_variant_char' => '峰', 'c_reference_char' => '峯'],
        ])->assertStatus(422);

        $this->assertDatabaseHas('char_variant_map', ['id' => 81, 'c_variant_char' => '靑']);
    }

    /** 落庫成功後必須重置對照表快取，否則新對照在該 process 的剩餘生命週期內不生效。 */
    #[Test]
    public function testCreateResetsVariantMapCacheSoNewMappingTakesEffect(): void {
        $this->actingAs($this->makeUser(email: 'cvm-reset@example.com'));

        // 先讓服務把（此時為空的）對照表快取起來。
        $this->assertSame('龴', CharVariantMapService::replaceLenient('龴')['text']);

        $this->postJson('/api/v2/create', [
            'resource' => 'char_variant_map',
            'person_id' => 0,
            'target' => ['pk' => ['id' => 90]],
            'changes' => ['c_variant_char' => '龴', 'c_reference_char' => '甲', 'c_strict_excluded' => 0],
        ])->assertOk();

        $this->assertSame('甲', CharVariantMapService::replaceLenient('龴')['text'], '新對照應立即生效（快取已重置）');
    }

    /**
     * codex round 2：**歷史待審提案**（不經現行 submission guard 建立）核准時，
     * 通用 applyCreateProposal() 也必須驗結構——否則成環的對照落庫後
     * dropCycleEdges() 會把整組邊丟掉，該組字的替換全站靜默停止。
     */
    #[Test]
    public function testApprovingLegacyCyclicCreateProposalIsRejected(): void {
        DB::table('char_variant_map')->insert(['id' => 100, 'c_variant_char' => '峯', 'c_reference_char' => '峰', 'c_strict_excluded' => 1]);

        $proposer = $this->makeUser(email: 'cvm-legacy-proposer@example.com');
        $operationId = DB::table('operations')->insertGetId([
            'user_id' => $proposer->id,
            'c_personid' => 0,
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource' => 'char_variant_map',
            'resource_id' => '101',
            'resource_data' => json_encode([
                'id' => 101,
                'c_variant_char' => '峰',
                'c_reference_char' => '峯',
                'c_strict_excluded' => 1,
                '__review_status' => 'pending',
                '__key_columns' => ['id'],
                '__proposal_meta' => [
                    'action' => 'create',
                    'table' => 'char_variant_map',
                    'submitted_by' => $proposer->name,
                    'submitted_by_id' => $proposer->id,
                ],
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN, 'cvm-legacy-admin@example.com'));
        $this->post(route('operations.proposals.approve', $operationId), ['review_comment' => '同意']);

        $this->assertSame(1, DB::table('char_variant_map')->count(), '成環的對照不得因核准而落庫');
        $this->assertDatabaseMissing('char_variant_map', ['id' => 101]);
    }

    #[Test]
    public function testDeleteIsDisabled(): void {
        // 安全：碼表刪除已停用（防級聯刪除人物資料）——回 403、不刪列、不寫 DELETE 審計。
        // 這是全域閘門，char_variant_map 註冊進 code_table_writes.php 後同樣受此規則約束，
        // 不是本表特例、也不應為了讓本表可刪除而鬆綁這道閘門。
        $this->actingAs($this->makeUser(email: 'cvm-del@example.com'));
        DB::table('char_variant_map')->insert(['id' => 50, 'c_variant_char' => '待刪', 'c_reference_char' => '刪除', 'c_strict_excluded' => 1]);

        $this->postJson('/api/v2/delete', [
            'resource' => 'char-variant-map',
            'person_id' => 0,
            'target' => ['pk' => ['id' => 50]],
        ])->assertStatus(403);

        $this->assertSame(1, DB::table('char_variant_map')->count());
        $this->assertSame(0, DB::table('audit_log')->where('operation', 'DELETE')->count());
    }
}
