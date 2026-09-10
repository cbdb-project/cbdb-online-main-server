<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use App\Support\SelfReferencingTreeGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 官職類型層級樹（OFFICE_TYPE_TREE）的 v2 mutation API。
 *
 * 回報：「完全沒有寫入口徑」。原本只能走 /codes UI 或眾包端。
 *
 * 這是 config/code_table_writes.php 的**第一張文本主鍵**表，所以本測試的重點不是
 * 一般 CRUD，而是兩件「錯了就悄悄壞掉」的事：
 *
 * 1. **主鍵不可以 (int) 轉型**。`c_office_type_node_id` 是零填補的階層路徑字串
 *    （`06`、`0601`、`060102`）。轉型會把 `'06'` 變成 `6`，把節點建在錯誤的鍵上，
 *    而 `6` 本身也是個看起來合理的 id——回應說建好了、資料在別的地方。
 *    表用**原生 SQL** 建，這樣 SQLite 才會回報 `varchar` 而不是 Schema Builder 的
 *    產物（型別判斷錯了會讓整組守衛失效，所以下面第一支測試先斷言型別看得到）。
 *
 * 2. **成環**。`c_parent_id` 有自參照外鍵，所以「父節點必須存在」由資料庫保證；
 *    但**自己當自己的父節點**滿足外鍵、A→B 與 B→A 也各自滿足外鍵——成環之後
 *    `LIKE '<id>%'` 的前綴走訪與任何往上找根的迴圈都會壞掉（側欄與 picker 都吃這張表）。
 */
class ApiV2MutateOfficeTypeTreeTest extends TestCase {
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
        DB::statement('PRAGMA foreign_keys = ON');

        $this->createSupportTables();
        $this->createOfficeTypeTree();
        $this->seedTree();
    }

    protected function tearDown(): void {
        foreach (['OFFICE_TYPE_TREE', 'char_variant_map', 'audit_log', 'operations', 'users'] as $t) {
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
     * 原生 DDL：型別字串與自參照外鍵都比照 prod
     * （`OFFICE_TYPE_TREE_ibfk_1: c_parent_id -> OFFICE_TYPE_TREE.c_office_type_node_id`）。
     */
    protected function createOfficeTypeTree(): void {
        DB::statement('CREATE TABLE "OFFICE_TYPE_TREE" (
            "c_office_type_node_id" varchar(255) NOT NULL PRIMARY KEY,
            "c_office_type_desc" varchar(255) NULL,
            "c_office_type_desc_chn" varchar(255) NULL,
            "c_parent_id" varchar(255) NULL REFERENCES "OFFICE_TYPE_TREE"("c_office_type_node_id")
        )');
    }

    /** 比照 prod 的形狀：零填補、每層兩碼。 */
    protected function seedTree(): void {
        DB::table('OFFICE_TYPE_TREE')->insert([
            ['c_office_type_node_id' => '0', 'c_office_type_desc' => 'All Offices Categories', 'c_office_type_desc_chn' => '所有門類', 'c_parent_id' => '0'],
            ['c_office_type_node_id' => '06', 'c_office_type_desc' => 'Tang Dynasty', 'c_office_type_desc_chn' => '唐朝', 'c_parent_id' => '0'],
            ['c_office_type_node_id' => '0601', 'c_office_type_desc' => 'Imperial Family', 'c_office_type_desc_chn' => '帝后制度類', 'c_parent_id' => '06'],
            ['c_office_type_node_id' => '060102', 'c_office_type_desc' => 'Imperial Offices', 'c_office_type_desc_chn' => '帝后門', 'c_parent_id' => '0601'],
        ]);
    }

    protected function createCharVariantMap(): void {
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10)->unique();
            $table->string('c_reference_char', 10);
            $table->tinyInteger('c_strict_excluded')->default(0);
            $table->string('c_notes')->nullable();
            $table->timestamps();
        });

        DB::table('char_variant_map')->insert([
            ['c_variant_char' => '淸', 'c_reference_char' => '清', 'c_strict_excluded' => 0],
        ]);
    }

    protected function makeUser(int $status = User::STATUS_ACTIVE, int $role = User::ROLE_REGULAR, string $email = 'officetree@example.com'): User {
        return User::forceCreate([
            'name' => '官職類型編輯者',
            'email' => $email,
            'confirmation_token' => 'token-officetree',
            'is_active' => $status,
            'is_admin' => $role,
        ]);
    }

    // ── 前提：型別真的看得到 ────────────────────────────────

    #[Test]
    public function testSchemaTypeIsVisibleAsTextKey(): void {
        // 整組文本主鍵守衛都建立在「schema 回報 varchar」之上。若建表方式改回
        // Schema Builder（SQLite 會建成 varchar 沒問題，但長度資訊會不同），
        // 這裡會先紅，而不是讓下面的案例假綠。
        $types = [];
        foreach (Schema::getColumns('OFFICE_TYPE_TREE') as $column) {
            $types[$column['name']] = strtolower($column['type_name']);
        }

        $this->assertSame('varchar', $types['c_office_type_node_id']);
        $this->assertSame('varchar', $types['c_parent_id']);
    }

    // ── 文本主鍵 ────────────────────────────────────────────

    #[Test]
    public function testCreatePreservesZeroPaddedStringKeyExactly(): void {
        $this->actingAs($this->makeUser(email: 'officetree-create@example.com'));

        $response = $this->postJson('/api/v2/create', [
            'resource' => 'office-type-tree',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_office_type_node_id' => '06010304']],
            'changes' => [
                'c_office_type_desc' => 'Palace Kitchen',
                'c_office_type_desc_chn' => '尚食局',
                'c_parent_id' => '060102',
            ],
        ]);

        $response->assertOk()->assertJson([
            'ok' => true,
            'resource' => 'office-type-tree',
            'operation' => 'create',
            'result' => ['pk' => ['c_office_type_node_id' => '06010304'], 'status' => 'created'],
        ]);
        // 回應的主鍵必須是字串 '06010304'，不是數字 6010304
        $this->assertSame('06010304', $response->json('result.pk.c_office_type_node_id'));

        $this->assertDatabaseHas('OFFICE_TYPE_TREE', [
            'c_office_type_node_id' => '06010304',
            'c_office_type_desc_chn' => '尚食局',
            'c_parent_id' => '060102',
        ]);
        // 前導零沒有被吃掉——(int) 轉型會建出 '6010304'
        $this->assertDatabaseMissing('OFFICE_TYPE_TREE', ['c_office_type_node_id' => '6010304']);

        $this->assertDatabaseHas('audit_log', ['table_name' => 'OFFICE_TYPE_TREE', 'operation' => 'INSERT']);
        $this->assertDatabaseHas('operations', ['resource' => 'OFFICE_TYPE_TREE', 'op_type' => Operation::TYPE_CREATE]);
    }

    #[Test]
    public function testCreateRejectsDuplicateStringKey(): void {
        $this->actingAs($this->makeUser(email: 'officetree-dup@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'office-type-tree',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_office_type_node_id' => '0601']],
            'changes' => ['c_office_type_desc_chn' => '重複', 'c_parent_id' => '06'],
        ])->assertStatus(409);

        // 既有列未被覆寫
        $this->assertSame('帝后制度類', DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '0601')->value('c_office_type_desc_chn'));
    }

    #[Test]
    public function testCreateRejectsOverlongKey(): void {
        $this->actingAs($this->makeUser(email: 'officetree-long@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'office-type-tree',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_office_type_node_id' => str_repeat('0', 256)]],
            'changes' => ['c_parent_id' => '0'],
        ])->assertStatus(422)
            ->assertJsonFragment(['target.pk.c_office_type_node_id' => ['max:255']]);

        $this->assertSame(4, DB::table('OFFICE_TYPE_TREE')->count());
    }

    #[Test]
    public function testCreateRequiresTheKeyBecauseItIsNotAutoAssigned(): void {
        // 路徑字串的「下一個 id」沒有意義，也不該由服務端猜。
        $this->actingAs($this->makeUser(email: 'officetree-nokey@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'office-type-tree',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => []],
            'changes' => ['c_office_type_desc_chn' => '沒給鍵'],
        ])->assertStatus(422)
            ->assertJsonFragment(['target.pk.c_office_type_node_id' => ['required']]);

        $this->assertSame(4, DB::table('OFFICE_TYPE_TREE')->count());
    }

    // ── 樹的完整性 ──────────────────────────────────────────

    #[Test]
    public function testCreateRejectsSelfParent(): void {
        // 自我引用滿足外鍵（InnoDB 對自參照外鍵是在列插入後才檢查），資料庫擋不住。
        $this->actingAs($this->makeUser(email: 'officetree-self@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'office-type-tree',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_office_type_node_id' => '0699']],
            'changes' => ['c_parent_id' => '0699', 'c_office_type_desc_chn' => '自己的父節點'],
        ])->assertStatus(422)
            ->assertJsonFragment(['changes' => ['tree_cycle']]);

        $this->assertDatabaseMissing('OFFICE_TYPE_TREE', ['c_office_type_node_id' => '0699']);
    }

    #[Test]
    public function testCreateWithUnknownParentReturns422(): void {
        $this->actingAs($this->makeUser(email: 'officetree-noparent@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'office-type-tree',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_office_type_node_id' => '0698']],
            'changes' => ['c_parent_id' => '999999', 'c_office_type_desc_chn' => '孤兒'],
        ])->assertStatus(422)
            ->assertJsonFragment(['changes' => ['foreign_key_violation']]);

        $this->assertDatabaseMissing('OFFICE_TYPE_TREE', ['c_office_type_node_id' => '0698']);
    }

    #[Test]
    public function testUpdateRejectsReparentingUnderOwnDescendant(): void {
        // 把 '06' 掛到它自己的子孫 '060102' 之下 → 06 → 060102 → 0601 → 06 成環。
        // 兩邊都滿足外鍵，只有應用層擋得住。
        $this->actingAs($this->makeUser(email: 'officetree-cycle@example.com'));

        $this->postJson('/api/v2/mutate', [
            'resource' => 'office_type_tree',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_office_type_node_id' => '06']],
            'changes' => ['c_parent_id' => '060102'],
        ])->assertStatus(422)
            ->assertJsonFragment(['changes' => ['tree_cycle']]);

        $this->assertSame('0', DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '06')->value('c_parent_id'));
    }

    #[Test]
    public function testUpdateRejectsSelfParent(): void {
        $this->actingAs($this->makeUser(email: 'officetree-selfupdate@example.com'));

        $this->postJson('/api/v2/mutate', [
            'resource' => 'office_type_tree',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_office_type_node_id' => '0601']],
            'changes' => ['c_parent_id' => '0601'],
        ])->assertStatus(422)
            ->assertJsonFragment(['changes' => ['tree_cycle']]);

        $this->assertSame('06', DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '0601')->value('c_parent_id'));
    }

    #[Test]
    public function testLegitimateReparentingSucceeds(): void {
        // 反面對照：搬到一個不在自己子樹裡的節點之下是合法操作，守衛不可過度收緊。
        $this->actingAs($this->makeUser(email: 'officetree-reparent@example.com'));

        $this->postJson('/api/v2/mutate', [
            'resource' => 'office_type_tree',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_office_type_node_id' => '060102']],
            'changes' => ['c_parent_id' => '06'],
        ])->assertOk();

        $this->assertSame('06', DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '060102')->value('c_parent_id'));
    }

    #[Test]
    public function testRootNodeIsItsOwnParentAndThatIsNotTreatedAsACycle(): void {
        // 迴歸：這張表以「自己是自己的上層」表示根（prod 的 '0' 的 c_parent_id 就是 '0'）。
        // 環路守衛第一版把它當成環，於是**每一個**祖先鏈通到根的節點——也就是全部——
        // 在新增或改上層時都被誤擋。這支測試釘住兩件事：
        //   (a) 掛在根之下的新節點可以建立；
        //   (b) 把根節點自己的上層原樣存回去（等於沒改）不會被守衛擋下。
        $this->actingAs($this->makeUser(email: 'officetree-root@example.com'));

        $this->assertSame('0', DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '0')->value('c_parent_id'), '前提：根節點以自我引用表示');

        // (a) 走訪 0699 → 06 → 0 →（0 的父是自己＝根）應判定為無環
        $this->postJson('/api/v2/create', [
            'resource' => 'office-type-tree',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_office_type_node_id' => '07']],
            'changes' => ['c_parent_id' => '0', 'c_office_type_desc_chn' => '五代'],
        ])->assertOk();
        $this->assertDatabaseHas('OFFICE_TYPE_TREE', ['c_office_type_node_id' => '07', 'c_parent_id' => '0']);

        // (b) 根節點原樣送回：上層沒有實際改變 → 不驗環（會走到「無實質修改」而 422）
        $this->postJson('/api/v2/mutate', [
            'resource' => 'office_type_tree',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_office_type_node_id' => '0']],
            'changes' => ['c_parent_id' => '0'],
        ])->assertStatus(422)->assertJsonFragment(['changes' => ['no_effective_changes']]);

        // 而且是「無實質修改」而不是「成環」——訊息不同、語義不同
        $this->assertSame('0', DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '0')->value('c_parent_id'));
    }

    #[Test]
    public function testDeepChainToRootIsAccepted(): void {
        // 反面對照：合法的深層掛載（走訪好幾層才碰到根）不可被誤擋。
        $this->actingAs($this->makeUser(email: 'officetree-deep@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'office-type-tree',
            'person_id' => 0,
            'mode' => 'direct',
            // 060102 → 0601 → 06 → 0（根）
            'target' => ['pk' => ['c_office_type_node_id' => '06010206']],
            'changes' => ['c_parent_id' => '060102', 'c_office_type_desc_chn' => '內命婦'],
        ])->assertOk();

        $this->assertDatabaseHas('OFFICE_TYPE_TREE', ['c_office_type_node_id' => '06010206', 'c_parent_id' => '060102']);
    }

    // ── 一般欄位 ────────────────────────────────────────────

    #[Test]
    public function testUpdateDescriptions(): void {
        $this->actingAs($this->makeUser(email: 'officetree-desc@example.com'));

        $this->postJson('/api/v2/mutate', [
            'resource' => 'office_type_tree',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_office_type_node_id' => '060102']],
            'changes' => ['c_office_type_desc' => 'Imperial Household', 'c_office_type_desc_chn' => '帝后門類'],
        ])->assertOk();

        $this->assertDatabaseHas('OFFICE_TYPE_TREE', [
            'c_office_type_node_id' => '060102',
            'c_office_type_desc' => 'Imperial Household',
            'c_office_type_desc_chn' => '帝后門類',
        ]);
    }

    #[Test]
    public function testUpdateRejectsChangingThePrimaryKey(): void {
        // 改主鍵＝把節點搬到樹的另一個位置，須另行新增／刪除。
        $this->actingAs($this->makeUser(email: 'officetree-pk@example.com'));

        $this->postJson('/api/v2/mutate', [
            'resource' => 'office_type_tree',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_office_type_node_id' => '060102']],
            'changes' => ['c_office_type_node_id' => '060199'],
        ])->assertStatus(422)
            // 斷言到具體原因：只驗 422 的話，哪天因為別的理由（no_supported_fields 之類）
            // 而 422，這支測試會為了錯誤的原因通過。
            ->assertJsonFragment(['changes' => ['disallowed_fields: c_office_type_node_id']]);

        $this->assertDatabaseHas('OFFICE_TYPE_TREE', ['c_office_type_node_id' => '060102']);
        $this->assertDatabaseMissing('OFFICE_TYPE_TREE', ['c_office_type_node_id' => '060199']);
    }

    #[Test]
    public function testChineseDescriptionIsVariantReplacedButKeysAreNot(): void {
        // c_office_type_desc_chn 是內容欄 → 替換；主鍵與 c_parent_id 是跨表 join 的
        // 代碼鍵、在 VariantReplaceScope 的排除清單內 → **不可**被替換，否則
        // OFFICE_CODE_TYPE_REL 的關聯會斷（單邊歸一等於只改 join 的一邊）。
        $this->createCharVariantMap();
        $this->actingAs($this->makeUser(email: 'officetree-variant@example.com'));

        $response = $this->postJson('/api/v2/create', [
            'resource' => 'office-type-tree',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_office_type_node_id' => '060105']],
            'changes' => ['c_office_type_desc_chn' => '淸吏司', 'c_parent_id' => '0601'],
        ])->assertOk();

        $this->assertDatabaseHas('OFFICE_TYPE_TREE', ['c_office_type_node_id' => '060105', 'c_office_type_desc_chn' => '清吏司']);
        $this->assertNotEmpty($response->json('notices'), '替換發生了卻沒有回 notices（AGENTS §1.3）');
    }

    #[Test]
    public function testUpdateWithNumericPrimaryKeyRecordsTheKeyAsAString(): void {
        // 這支才是 fix 的 load-bearing 測試：節點 id **就是** '601'，送整數 601。
        // 沒有把主鍵轉成字串的話，$pk 裡是 int 601，於是
        // resource_id／audit_log.row_pk／result.pk 全記成數字——assertSame 會抓到型別。
        // （在 MariaDB 上還會更糟：where(col, 601) 會命中 '0601'，改到別的列。）
        $this->actingAs($this->makeUser(email: 'officetree-numeric-string@example.com'));
        DB::table('OFFICE_TYPE_TREE')->insert([
            'c_office_type_node_id' => '601', 'c_office_type_desc_chn' => '數字鍵節點', 'c_parent_id' => '0',
        ]);

        $response = $this->postJson('/api/v2/mutate', [
            'resource' => 'office_type_tree',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_office_type_node_id' => 601]],
            'changes' => ['c_office_type_desc_chn' => '改過'],
        ])->assertOk();

        $this->assertSame('601', $response->json('result.pk.c_office_type_node_id'), 'result.pk 必須是字串');

        $operation = DB::table('operations')->where('resource', 'OFFICE_TYPE_TREE')->latest('id')->first();
        $this->assertSame('c_office_type_node_id=601', $operation->resource_id);

        $audit = DB::table('audit_log')->where('table_name', 'OFFICE_TYPE_TREE')->latest('id')->first();
        $this->assertSame(['c_office_type_node_id' => '601'], json_decode($audit->row_pk, true), 'audit_log.row_pk 必須是字串');
    }

    #[Test]
    public function testUpdateWithNumericPrimaryKeyDoesNotPoisonTheRecordedKey(): void {
        // 迴歸（真實庫才看得到的一類）：MariaDB 比較 varchar 與數字時會把欄位轉成數字，
        // 所以 where('c_office_type_node_id', 601) 會**命中 '0601' 那一列**（已對 prod 實測）。
        // 沒有把主鍵轉成字串的話，UPDATE 改到對的列，但 resource_id、audit_log.row_pk
        // 與回應的 result.pk 全部記成 601——一個沒有任何列擁有的鍵：operations 頁解不出
        // 現況，呼叫端照回應的鍵做後續操作一律 404。
        // SQLite 不做這種轉型，所以這裡驗的是「送數字會被當成字串 '601' → 找不到 → 404」，
        // 兩邊資料庫都得到一致且安全的結果。
        $this->actingAs($this->makeUser(email: 'officetree-numeric-pk@example.com'));

        $this->postJson('/api/v2/mutate', [
            'resource' => 'office_type_tree',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_office_type_node_id' => 601]],
            'changes' => ['c_office_type_desc_chn' => '不該寫進 0601'],
        ])->assertStatus(404);

        // '0601' 那一列完全沒被動到
        $this->assertSame('帝后制度類', DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '0601')->value('c_office_type_desc_chn'));
        $this->assertDatabaseMissing('operations', ['resource' => 'OFFICE_TYPE_TREE']);
    }

    #[Test]
    public function testUpdateRejectsWritingIntoAPreexistingCycle(): void {
        // 資料庫裡可能已經有歷史成環的資料（本 repo 沒有程式維護 c_parent_id，
        // 所以不能假設它一定是樹）。在成環的分支上再掛東西一律拒絕——守衛無法保證
        // 這次修改是安全的，而且會讓既有問題更難修。
        $this->actingAs($this->makeUser(email: 'officetree-existing-cycle@example.com'));

        // 造一個與被編輯節點無關的環：A → B → A
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('OFFICE_TYPE_TREE')->insert([
            ['c_office_type_node_id' => '90', 'c_office_type_desc_chn' => '環A', 'c_parent_id' => '91'],
            ['c_office_type_node_id' => '91', 'c_office_type_desc_chn' => '環B', 'c_parent_id' => '90'],
        ]);
        DB::statement('PRAGMA foreign_keys = ON');

        $this->postJson('/api/v2/mutate', [
            'resource' => 'office_type_tree',
            'person_id' => 0,
            'mode' => 'direct',
            'operation' => 'update',
            'target' => ['pk' => ['c_office_type_node_id' => '060102']],
            'changes' => ['c_parent_id' => '90'],
        ])->assertStatus(422)
            ->assertJsonFragment(['changes' => ['tree_cycle']]);

        $this->assertSame('0601', DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '060102')->value('c_parent_id'));
    }

    #[Test]
    public function testSelfParentIsRejectedEvenWithTrailingWhitespace(): void {
        // 資料庫 collation 是 utf8mb4_general_ci 且 PAD SPACE，`'0601 ' = '0601'` 在 DB 端
        // 成立。守衛若用 PHP === 比原值，送 '0601 ' 會被判成「不是自己」而放行，
        // 落庫後那一列在資料庫眼中就是自己的父節點。
        // （全域 TrimStrings middleware 也會擋，但守衛本身不該依賴它——它是 protected、
        // 可被匯入服務直接呼叫。這裡直接呼叫判定本體來驗。）
        $error = SelfReferencingTreeGuard::findCycle('OFFICE_TYPE_TREE', 'c_office_type_node_id', 'c_parent_id', '0601', '0601 ');
        $this->assertNotNull($error, '前後空白的自我引用必須被判為成環');

        $this->assertNotNull(
            SelfReferencingTreeGuard::findCycle('OFFICE_TYPE_TREE', 'c_office_type_node_id', 'c_parent_id', 'AB', 'ab'),
            'collation 不分大小寫，大小寫不同的自我引用同樣要擋'
        );
    }

    #[Test]
    public function testEqualityOnlyUnderCollationIsStillTreatedAsSelfReference(): void {
        // 這是第三輪 codex 抓到的 blocking bug：原本的回退是「拿欄位問兩次、看有沒有列
        // 同時滿足」，而那個寫法**漏掉 create**——新節點的列還不存在，於是「找不到列」
        // 被當成「兩個值不同」而放行，然後 InnoDB 的自參照外鍵在插入後以
        // case/accent-insensitive 的比對把父鍵解析到剛插進去的那一列，環就成了。
        // 已對真實庫實測 `'À' = 'a'` 在 utf8mb4_general_ci 之下為真。
        //
        // 本測試用 SQLite 的 `COLLATE NOCASE` 重現同一個結構（Laravel 的
        // Schema::getColumns() 在 SQLite 也回報 collation，所以走的是同一段程式碼）：
        // 大小寫不同、PHP 的保守 casefold 之外的等價，都必須經資料庫判定。
        Schema::dropIfExists('OFFICE_TYPE_TREE');
        DB::statement('CREATE TABLE "OFFICE_TYPE_TREE" (
            "c_office_type_node_id" varchar(255) NOT NULL COLLATE NOCASE PRIMARY KEY,
            "c_office_type_desc" varchar(255) NULL,
            "c_office_type_desc_chn" varchar(255) NULL,
            "c_parent_id" varchar(255) NULL COLLATE NOCASE REFERENCES "OFFICE_TYPE_TREE"("c_office_type_node_id")
        )');
        DB::table('OFFICE_TYPE_TREE')->insert([
            ['c_office_type_node_id' => 'ROOT', 'c_office_type_desc_chn' => '根', 'c_parent_id' => 'ROOT'],
        ]);

        // 前提：這一欄的 collation 讀得到，否則整段守衛會退回 PHP 精確比對而失去意義
        $collation = null;
        foreach (Schema::getColumns('OFFICE_TYPE_TREE') as $column) {
            if ($column['name'] === 'c_office_type_node_id') {
                $collation = $column['collation'] ?? null;
            }
        }
        $this->assertSame('nocase', $collation, '前提：Schema 必須回報欄位 collation');

        // 節點 'NodeA' 與父 'nodea' 在這個 collation 之下是同一個鍵 → 必須擋，
        // 而且**在該列還不存在時**就要擋（這正是 create 的情境）。
        $this->assertNotNull(
            SelfReferencingTreeGuard::findCycle('OFFICE_TYPE_TREE', 'c_office_type_node_id', 'c_parent_id', 'NodeA', 'nodea'),
            '只在 collation 之下相等的鍵，在該列尚不存在時也必須被判為自我引用'
        );

        // 反面對照：真正不同的鍵不可被擋
        $this->assertNull(
            SelfReferencingTreeGuard::findCycle('OFFICE_TYPE_TREE', 'c_office_type_node_id', 'c_parent_id', 'NodeA', 'ROOT')
        );
    }

    #[Test]
    public function testLeadingWhitespaceIsNotTreatedAsTheSameKey(): void {
        // PAD SPACE 只忽略**尾端**空格，所以 PHP 側的短路只能 rtrim。
        // 用 trim 的話 `' 0601'` 與 `'0601'` 會被誤判成同一個鍵——把一個合法的寫入
        // 擋成自我引用（第二輪 codex 抓到的方向：守衛過度收緊）。
        $this->assertNull(
            SelfReferencingTreeGuard::findCycle('OFFICE_TYPE_TREE', 'c_office_type_node_id', 'c_parent_id', '0601', ' 0601'),
            '前導空白在資料庫眼中是不同的鍵，不可短路成自我引用'
        );

        // 尾端空格則相反：資料庫視為相同，必須擋
        $this->assertNotNull(
            SelfReferencingTreeGuard::findCycle('OFFICE_TYPE_TREE', 'c_office_type_node_id', 'c_parent_id', '0601', '0601  '),
            '尾端空格在 PAD SPACE 之下等於同一個鍵，必須擋'
        );
    }

    #[Test]
    public function testTreeParentColumnIsResolvedFromEitherConfigAlone(): void {
        // 核准與還原這兩條路徑不屬於任何一份 config，所以查詢必須**兩份都認**。
        // OFFICE_TYPE_TREE 剛好兩份都有登錄，只驗它的話「只查一份」的實作也會通過
        // ——所以這裡用只存在於單一份 config 的臨時定義來驗兩個方向。
        $this->assertSame('c_parent_id', SelfReferencingTreeGuard::parentColumnFor('OFFICE_TYPE_TREE'));
        $this->assertNull(SelfReferencingTreeGuard::parentColumnFor('ADDR_CODES'));

        config()->set('code_table_writes.tables.ONLY_IN_WRITES', [
            'resource' => 'only-in-writes', 'aliases' => ['only-in-writes'], 'table' => 'ONLY_IN_WRITES',
            'key_columns' => ['id'], 'allowed_fields' => [], 'tree_parent_column' => 'c_up_a',
        ]);
        config()->set('code_table_mutations.tables', array_merge(
            (array) config('code_table_mutations.tables'),
            [['resource' => 'only-in-mutations', 'table' => 'ONLY_IN_MUTATIONS', 'aliases' => ['only-in-mutations'],
                'key_columns' => ['id'], 'allowed_fields' => [], 'tier1_fields' => [], 'tier2_fields' => [],
                'tree_parent_column' => 'c_up_b']]
        ));

        $this->assertSame('c_up_a', SelfReferencingTreeGuard::parentColumnFor('ONLY_IN_WRITES'), '只登錄在 code_table_writes 的表也要查得到');
        $this->assertSame('c_up_b', SelfReferencingTreeGuard::parentColumnFor('ONLY_IN_MUTATIONS'), '只登錄在 code_table_mutations 的表也要查得到');
    }

    #[Test]
    public function testRowWriteGuardComparesAgainstTheLiveRowNotTheProposalSnapshot(): void {
        // 迴歸（review 抓到的 blocking bug）：核准路徑原本把「提案當時的快照」當成現況去
        // 比對，而代碼表提案存的是整列合併結果——c_parent_id 一定在 payload 裡、核准時
        // 一定會被寫回。於是「上層沒變就不驗」的短路命中的正好是最危險的情境：
        //   t0 樹 0←06←0601；提案只改 0601 的說明（payload 帶 c_parent_id='06'）
        //   t1 有人把 0601 搬到 0 之下（合法）
        //   t2 有人把 06 搬到 0601 之下（此刻仍合法）
        //   t3 核准那個舊提案 → payload 的 '06' 等於快照的 '06' → 短路 → 寫下 0601→06，
        //      而 06→0601 已在庫裡：環成立。
        // 用現況比對就會走進走訪並擋下來。
        $snapshotAtProposalTime = ['c_office_type_node_id' => '0601', 'c_parent_id' => '06'];
        $payload = ['c_parent_id' => '06', 'c_office_type_desc_chn' => '只改說明'];

        // t1 + t2：樹在提案躺著的期間被動過
        DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '0601')->update(['c_parent_id' => '0']);
        DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '06')->update(['c_parent_id' => '0601']);
        $liveRow = (array) DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '0601')->first();

        // 拿快照比對 → 短路放行（這就是 bug）
        $this->assertNull(
            SelfReferencingTreeGuard::findCycleForRowWrite('OFFICE_TYPE_TREE', ['c_office_type_node_id'], $payload, $snapshotAtProposalTime),
            '前提：拿提案快照比對時短路會放行——所以呼叫端必須傳現況'
        );

        // 拿現況比對 → 擋下
        $this->assertNotNull(
            SelfReferencingTreeGuard::findCycleForRowWrite('OFFICE_TYPE_TREE', ['c_office_type_node_id'], $payload, $liveRow),
            '拿現況比對必須偵測到環'
        );
    }

    #[Test]
    public function testRowWriteGuardStillSkipsWhenTheParentTrulyDidNotChange(): void {
        // 反面對照：上層真的沒變（含根節點自我引用）時必須短路，否則核准一個只改說明的
        // 提案、或還原一個舊快照，都會被誤擋。
        $rootRow = (array) DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '0')->first();
        $this->assertNull(SelfReferencingTreeGuard::findCycleForRowWrite(
            'OFFICE_TYPE_TREE',
            ['c_office_type_node_id'],
            ['c_parent_id' => '0', 'c_office_type_desc_chn' => '所有門類（改字）'],
            $rootRow
        ), '根節點的上層等於自己是既有慣例，原樣寫回不可被擋');
    }

    #[Test]
    public function testApprovingAStaleProposalCannotCommitACycle(): void {
        // 端到端釘住 review 抓到的 blocking bug。純呼叫守衛的單元測試擋不住它——
        // bug 在**呼叫端傳了哪個參數**（提案快照 vs 現況），所以必須真的走一次核准。
        //
        //   t0 樹 0←06←0601；提案只改 0601 的說明（代碼表提案存整列，payload 會帶
        //      c_parent_id='06'，核准時一定會寫回）
        //   t1 有人把 0601 搬到 0 之下（合法）
        //   t2 有人把 06 搬到 0601 之下（此刻仍合法）
        //   t3 核准那個舊提案 → 若拿快照比對，payload 的 '06' 等於快照的 '06'、短路跳過
        //      → 寫下 0601→06，而 06→0601 已在庫裡：環成立。
        $proposer = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'officetree-stale-proposer@example.com');
        $this->actingAs($proposer);

        $this->postJson('/api/v2/mutate', [
            'resource' => 'office_type_tree',
            'person_id' => 0,
            'mode' => 'proposal',
            'operation' => 'update',
            'target' => ['pk' => ['c_office_type_node_id' => '0601']],
            'changes' => ['c_office_type_desc_chn' => '帝后制度類（提案改字）'],
            'meta' => ['comment' => '只改說明'],
        ])->assertOk();

        $operation = DB::table('operations')->where('resource', 'OFFICE_TYPE_TREE')->latest('id')->first();
        $payload = json_decode((string) $operation->resource_data, true);
        $this->assertSame('06', $payload['c_parent_id'] ?? null, '前提：代碼表提案存整列，payload 一定帶著 c_parent_id');

        // t1 + t2：提案躺著的期間，樹被動了兩次（兩步各自合法）
        $editor = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_REGULAR, 'officetree-stale-editor@example.com');
        $this->actingAs($editor);
        $this->postJson('/api/v2/mutate', [
            'resource' => 'office_type_tree', 'person_id' => 0, 'mode' => 'direct', 'operation' => 'update',
            'target' => ['pk' => ['c_office_type_node_id' => '0601']],
            'changes' => ['c_parent_id' => '0'],
        ])->assertOk();
        $this->postJson('/api/v2/mutate', [
            'resource' => 'office_type_tree', 'person_id' => 0, 'mode' => 'direct', 'operation' => 'update',
            'target' => ['pk' => ['c_office_type_node_id' => '06']],
            'changes' => ['c_parent_id' => '0601'],
        ])->assertOk();

        // t3：核准舊提案——必須被擋下
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN, 'officetree-stale-approver@example.com'));
        $this->post(route('operations.proposals.approve', $operation->id), ['review_comment' => '同意']);

        // 樹沒有成環：0601 仍掛在根之下（沒有被寫回 '06'）
        $this->assertSame('0', DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '0601')->value('c_parent_id'));
        $this->assertSame('0601', DB::table('OFFICE_TYPE_TREE')->where('c_office_type_node_id', '06')->value('c_parent_id'));

        // 提案沒有被標成已核准
        $this->assertNotSame(
            'approved',
            json_decode((string) DB::table('operations')->where('id', $operation->id)->value('resource_data'), true)['__review_status'] ?? null
        );
    }

    // ── 授權與刪除 ──────────────────────────────────────────

    #[Test]
    public function testDeleteStaysDisabled(): void {
        // 本表被 OFFICE_CODE_TYPE_REL.c_office_tree_id 與自身的 c_parent_id 引用。
        $this->actingAs($this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN, 'officetree-delete@example.com'));

        $this->postJson('/api/v2/delete', [
            'resource' => 'office-type-tree',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_office_type_node_id' => '060102']],
        ])->assertStatus(403);

        $this->assertDatabaseHas('OFFICE_TYPE_TREE', ['c_office_type_node_id' => '060102']);
    }

    #[Test]
    public function testCreateForbiddenForInactiveUser(): void {
        $this->actingAs($this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'officetree-inactive@example.com'));

        $this->postJson('/api/v2/create', [
            'resource' => 'office-type-tree',
            'person_id' => 0,
            'mode' => 'direct',
            'target' => ['pk' => ['c_office_type_node_id' => '0697']],
            'changes' => ['c_parent_id' => '06'],
        ])->assertStatus(403);

        $this->assertSame(4, DB::table('OFFICE_TYPE_TREE')->count());
    }
}
