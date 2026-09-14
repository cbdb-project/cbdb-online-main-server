<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/app/operations` 的篩選：每一條都驗**結果集是否符合該篩選的契約**，不是只驗頁面回 200。
 *
 * 注意契約本身有兩種方向：多數條目是「要真的縮小結果集」，但空字串 editor、非法 `op_type`、
 * 以及 proposals 模式忽略 `op_type` 這三條的契約**恰恰是不縮小**（非法值要等同無篩選，
 * 而不是回空集——那是很容易寫錯的一邊）。
 *
 * 覆蓋 `buildOperationsListing()` 的 13 個查詢分支：editor 的數字／文字／空字串三態、
 * op_type 的多選／非法值／proposals 模式忽略、history 過濾的四種認領路徑（含鏡像列經
 * `audit_log.row_pk` 與舊資料經 `resource_id` LIKE 的回退）、預設隱藏提案、
 * status+editor 交集、以及提案備註的標籤語義。
 *
 * ── 2026-09-14（Blade 下架環節 4a-2）─────────────────────────────
 *
 * 本檔原本打 legacy `/operations` 並讀 `viewData('lists')`，現改打 `/app/operations`
 * 並讀 Inertia prop。`buildOperationsListing()` 本來就是新舊共用，所以不變量完全不動。
 *
 * 🔴 **一個 prop 差異**：React 列**沒有 `user_id`**（`serializeOperationRow()` 只輸出
 * `user_name`，而且訪客時故意降級成 `'User {id}'`）。所以 editor 篩選改斷言 **operation id**
 * ——那比原本的 user_id 更精確：它直接釘住「哪幾列存活」，而不只是「存活的列屬於誰」。
 */
class OperationsIndexFilterTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('confirmation_token')->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_admin')->default(0);
            $table->timestamps();
        });

        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid')->nullable();
            $table->tinyInteger('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->tinyInteger('crowdsourcing_status')->default(0);
            $table->timestamps();
        });

        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
            $table->timestamps();
        });

        Schema::create('ALTNAME_DATA', function ($table) {
            $table->integer('c_personid');
            $table->string('c_alt_name_chn')->nullable();
            $table->integer('c_alt_name_type_code')->nullable();
        });

        Schema::create('KIN_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id');
            $table->integer('c_kin_code');
        });

        Schema::create('ASSOC_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_code');
            $table->integer('c_assoc_id');
            $table->integer('c_kin_code');
            $table->integer('c_kin_id');
            $table->integer('c_assoc_kin_code');
            $table->integer('c_assoc_kin_id');
            $table->string('c_text_title')->nullable();
            $table->integer('c_assoc_first_year')->nullable();
        });

        Schema::create('audit_log', function ($table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->char('operation_id', 26);
            $table->json('row_pk');
            $table->string('row_pk_text', 512);
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
        });
    }

    private function makeUser(string $name, string $email, array $overrides = []): User {
        return User::forceCreate(array_merge([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'confirmation_token' => 'token',
            'is_active' => 1,
            'is_admin' => 1,
        ], $overrides));
    }

    private function makeOperation(User $user, int $opType, array $overrides = []): Operation {
        return Operation::create(array_merge([
            'user_id' => $user->id,
            'c_personid' => 0,
            'op_type' => $opType,
            'resource' => 'BIOG_MAIN',
            'resource_id' => '1',
            'resource_data' => json_encode(['c_name_chn' => '測試'], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode(['c_name_chn' => '原始'], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ], $overrides));
    }

    /**
     * 打 `/app/operations` 並取回 `lists` prop。
     *
     * @return array<int, array<string, mixed>>
     */
    private function appLists(User $actor, string $query = ''): array {
        $lists = null;

        $this->actingAs($actor)
            ->get('/app/operations' . $query)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$lists) {
                $lists = $page->component('Admin/Operations/Index')->toArray()['props']['lists'];
            });

        return $lists;
    }

    /** `lists` 裡所有 operation id（排序後，讓斷言與列表順序無關）。 */
    private function idsOf(array $lists): array {
        $ids = array_map('intval', array_column($lists, 'id'));
        sort($ids);

        return $ids;
    }

    /** 打 `/app/operations` 並取回 `filters` prop。 */
    private function appFilters(User $actor, string $query = ''): array {
        $filters = null;

        $this->actingAs($actor)
            ->get('/app/operations' . $query)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$filters) {
                $filters = $page->toArray()['props']['filters'];
            });

        return $filters;
    }

    /** 把一組 id 排序成與 idsOf() 可比的形狀。 */
    private function sortedIds(array $ids): array {
        $ids = array_map('intval', $ids);
        sort($ids);

        return $ids;
    }

    /** `lists` 裡所有 op_type。 */
    private function opTypesOf(array $lists): array {
        return array_map('intval', array_column($lists, 'op_type'));
    }

    // ── editor 篩選 ──

    #[Test]
    public function editor_numeric_input_filters_by_user_id(): void {
        $alice = $this->makeUser('Alice', 'alice@example.com');
        $bob = $this->makeUser('Bob', 'bob@example.com');

        $aliceOp = $this->makeOperation($alice, Operation::TYPE_CREATE);
        $bobOp = $this->makeOperation($bob, Operation::TYPE_CREATE);

        // 數字輸入走 user_id 精確比對（`ctype_digit` 分支）。
        $ids = $this->idsOf($this->appLists($alice, '?editor=' . $bob->id));
        $this->assertSame([(int) $bobOp->id], $ids, 'editor=<id> 應只留該使用者的操作');
        $this->assertNotContains((int) $aliceOp->id, $ids);
    }

    #[Test]
    public function editor_text_input_filters_by_name_like(): void {
        $alice = $this->makeUser('Alice Wang', 'alice@example.com');
        $bob = $this->makeUser('Bob Chen', 'bob@example.com');

        $aliceOp = $this->makeOperation($alice, Operation::TYPE_CREATE);
        $bobOp = $this->makeOperation($bob, Operation::TYPE_CREATE);

        // 文字輸入走 `name like`（與上面的數字分支是 `ctype_digit` 二分，只測一邊會漏）。
        $ids = $this->idsOf($this->appLists($alice, '?editor=Wang'));
        $this->assertSame([(int) $aliceOp->id], $ids, 'editor=<文字> 應走姓名模糊比對');
        $this->assertNotContains((int) $bobOp->id, $ids);
    }

    #[Test]
    public function editor_empty_string_shows_all_results(): void {
        $alice = $this->makeUser('Alice', 'alice@example.com');
        $bob = $this->makeUser('Bob', 'bob@example.com');

        $aliceOp = $this->makeOperation($alice, Operation::TYPE_CREATE);
        $bobOp = $this->makeOperation($bob, Operation::TYPE_UPDATE);

        // 空字串 ≠ 「篩掉全部」。
        //
        // ⚠️ **這條斷言的極限要講清楚**：它記錄的是**契約**，抓不到 guard 退化。
        // 若 `if ($editorFilter !== '')` 這道 guard 被拿掉，空字串會走
        // `name like '%%'`——在任何有名字的使用者上結果都與「不加條件」相同，
        // 所以測試照樣綠（codex 實測確認）。要真的抓到那個退化得靠「名字為 NULL 的
        // 使用者」之類的脆弱 fixture，不值得。
        $this->assertSame(
            $this->sortedIds([$aliceOp->id, $bobOp->id]),
            $this->idsOf($this->appLists($alice, '?editor=')),
            'editor 為空字串時應顯示全部'
        );
        // 至少把參數回吐釘住（前端靠它保持輸入框內容）。
        $this->assertSame('', $this->appFilters($alice, '?editor=')['editor'] ?? null);

        // 對照組：`?editor=0` ⇒ `ctype_digit('0')` 為真 ⇒ `where user_id = 0` ⇒ **必須是空集**。
        // 這條**確實**證明數字分支還活著（把 `where` 拿掉就會紅），
        // 補的是上面那條蓋不到的那一半。
        $this->assertSame(
            [],
            $this->idsOf($this->appLists($alice, '?editor=0')),
            'editor=0 應走數字分支並得到空集（沒有 user_id=0 的操作）'
        );
    }

    // ── op_type 篩選 ──

    #[Test]
    public function op_type_filters_by_selected_types(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $this->makeOperation($user, Operation::TYPE_CREATE);
        $this->makeOperation($user, Operation::TYPE_UPDATE);
        $this->makeOperation($user, Operation::TYPE_DELETE);

        // 只篩選「新增」和「刪除」
        $lists = $this->appLists($user, '?op_type[]=1&op_type[]=4');
        $opTypes = $this->opTypesOf($lists);
        $this->assertCount(2, $opTypes);
        $this->assertContains(Operation::TYPE_CREATE, $opTypes);
        $this->assertContains(Operation::TYPE_DELETE, $opTypes);
        $this->assertNotContains(Operation::TYPE_UPDATE, $opTypes);
    }

    #[Test]
    public function op_type_rejects_invalid_values(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $this->makeOperation($user, Operation::TYPE_CREATE);
        $this->makeOperation($user, Operation::TYPE_UPDATE);

        // 傳入非法值 99，應被過濾掉，等同無篩選
        $lists = $this->appLists($user, '?op_type[]=99');
        $opTypes = $this->opTypesOf($lists);
        $this->assertCount(2, $opTypes);
    }

    #[Test]
    public function op_type_filter_ignored_in_proposals_mode(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $this->makeOperation($user, Operation::TYPE_PROPOSAL_CREATE, [
            'resource_data' => json_encode([
                'c_name_chn' => '提案',
                '__review_status' => 'pending',
                '__proposal_meta' => ['submitted_by' => 'Admin', 'submitted_at' => now()->format('Y-m-d H:i:s')],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // proposals 模式下傳入 op_type=1 應被忽略，提案仍然顯示
        $lists = $this->appLists($user, '?proposals_only=1&op_type[]=1');
        $opTypes = $this->opTypesOf($lists);
        $this->assertCount(1, $opTypes);
        $this->assertSame(Operation::TYPE_PROPOSAL_CREATE, $opTypes[0]);
    }

    #[Test]
    public function history_filter_matches_current_person_page_by_resource_and_person(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $matching = $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 1001,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => 'c_personid=1001&c_alt_name_chn=%E6%B8%AC%E8%A9%A6&c_alt_name_type_code=1',
        ]);
        $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 1001,
            'resource' => 'BIOG_TEXT_DATA',
        ]);
        $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 2002,
            'resource' => 'ALTNAME_DATA',
        ]);

        $lists = $this->appLists($user, '?c_personid=1001&history_page=altnames');
        $this->assertSame([(int) $matching->id], $this->idsOf($lists));
    }

    #[Test]
    public function history_filter_matches_mirrored_person_changes_via_audit_log(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $matching = $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 2002,
            'resource' => 'KIN_DATA',
            'resource_id' => 'c_personid=2002&c_kin_id=1001&c_kin_code=300',
        ]);
        $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 3003,
            'resource' => 'KIN_DATA',
            'resource_id' => 'c_personid=3003&c_kin_id=4004&c_kin_code=300',
        ]);

        DB::table('audit_log')->insert([
            'occurred_at' => now(),
            'created_at' => now(),
            'table_name' => 'KIN_DATA',
            'operation' => 'UPDATE',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'operation_id' => (string) $matching->id,
            'row_pk' => json_encode([
                'c_kin_id' => 2002,
                'c_kin_code' => 300,
            ], JSON_UNESCAPED_UNICODE),
            'row_pk_text' => 'c_personid=1001&c_kin_id=2002&c_kin_code=300',
            'old_data' => json_encode([
                'c_kin_id' => 2002,
                'c_kin_code' => 300,
            ], JSON_UNESCAPED_UNICODE),
            'new_data' => json_encode([
                'c_kin_id' => 2002,
                'c_kin_code' => 301,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $lists = $this->appLists($user, '?c_personid=1001&history_page=kinship');
        $this->assertSame([(int) $matching->id], $this->idsOf($lists));
    }

    #[Test]
    public function history_filter_matches_legacy_mirrored_kinship_changes_via_resource_id(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $matching = $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 2002,
            'resource' => 'KIN_DATA',
            'resource_id' => 'c_personid=2002&c_kin_id=1001&c_kin_code=300',
        ]);
        $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 3003,
            'resource' => 'KIN_DATA',
            'resource_id' => 'c_personid=3003&c_kin_id=4004&c_kin_code=300',
        ]);

        $lists = $this->appLists($user, '?c_personid=1001&history_page=kinship');
        $this->assertSame([(int) $matching->id], $this->idsOf($lists));
    }

    #[Test]
    public function history_filter_matches_legacy_mirrored_assoc_changes_via_resource_id(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $matching = $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 2002,
            'resource' => 'ASSOC_DATA',
            'resource_id' => 'c_personid=2002&c_assoc_code=301&c_assoc_id=1001&c_kin_code=0&c_kin_id=1001&c_assoc_kin_code=0&c_assoc_kin_id=1001&c_text_title=%E6%B8%AC%E8%A9%A6%E6%96%87%E7%8D%BB&c_assoc_first_year=1100',
        ]);
        $this->makeOperation($user, Operation::TYPE_UPDATE, [
            'c_personid' => 3003,
            'resource' => 'ASSOC_DATA',
            'resource_id' => 'c_personid=3003&c_assoc_code=301&c_assoc_id=4004&c_kin_code=0&c_kin_id=4004&c_assoc_kin_code=0&c_assoc_kin_id=4004&c_text_title=%E5%85%B6%E4%BB%96%E6%96%87%E7%8D%BB&c_assoc_first_year=1100',
        ]);

        $lists = $this->appLists($user, '?c_personid=1001&history_page=assoc');
        $this->assertSame([(int) $matching->id], $this->idsOf($lists));
    }

    #[Test]
    public function default_operations_index_hides_proposals(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        $this->makeOperation($user, Operation::TYPE_CREATE, [
            'resource_data' => json_encode(['c_name_chn' => '一般操作'], JSON_UNESCAPED_UNICODE),
        ]);
        $this->makeOperation($user, Operation::TYPE_PROPOSAL_CREATE, [
            'resource_data' => json_encode([
                'c_name_chn' => '提案操作',
                '__review_status' => 'pending',
                '__proposal_meta' => ['submitted_by' => 'Admin', 'submitted_at' => now()->format('Y-m-d H:i:s')],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $lists = $this->appLists($user, '');
        $opTypes = $this->opTypesOf($lists);
        $this->assertCount(1, $opTypes);
        $this->assertSame(Operation::TYPE_CREATE, $opTypes[0]);
    }

    // ── proposals 模式 status + editor 組合 ──

    #[Test]
    public function proposals_status_and_editor_filter_work_together(): void {
        $alice = $this->makeUser('Alice', 'alice@example.com');
        $bob = $this->makeUser('Bob', 'bob@example.com');

        // Alice 的 pending 提案
        $this->makeOperation($alice, Operation::TYPE_PROPOSAL_CREATE, [
            'resource_data' => json_encode([
                'c_name_chn' => 'Alice提案',
                '__review_status' => 'pending',
                '__proposal_meta' => ['submitted_by' => 'Alice', 'submitted_at' => now()->format('Y-m-d H:i:s')],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // Bob 的 pending 提案
        $this->makeOperation($bob, Operation::TYPE_PROPOSAL_UPDATE, [
            'resource_data' => json_encode([
                'c_name_chn' => 'Bob提案',
                '__review_status' => 'pending',
                '__proposal_meta' => ['submitted_by' => 'Bob', 'submitted_at' => now()->format('Y-m-d H:i:s')],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // Alice 的 approved 提案
        $this->makeOperation($alice, Operation::TYPE_PROPOSAL_CREATE, [
            'resource_data' => json_encode([
                'c_name_chn' => 'Alice已核准',
                '__review_status' => 'approved',
                '__proposal_meta' => ['submitted_by' => 'Alice', 'submitted_at' => now()->format('Y-m-d H:i:s')],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // 篩選 Alice 的 pending 提案
        $lists = $this->appLists($alice, '?proposals_only=1&status[]=pending&editor=Alice');

        // 3 筆提案（Alice pending／Bob pending／Alice approved）要收斂成 1 筆。
        $this->assertCount(1, $lists, 'status 與 editor 應是交集而非聯集');
        // React 列沒有 user_id，改用 user_name 與 review_status 兩個 prop 釘住「是哪一筆」。
        $this->assertSame('Alice', $lists[0]['user_name'] ?? null);
        $this->assertSame('pending', $lists[0]['review_status'] ?? null);
    }

    #[Test]
    public function proposals_view_labels_direct_note_as_proposal_note(): void {
        $user = $this->makeUser('Admin', 'admin@example.com');

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1001,
            'c_name_chn' => '測試人物',
            'c_name' => 'Test Person',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->makeOperation($user, Operation::TYPE_PROPOSAL_UPDATE, [
            'c_personid' => 1001,
            'resource' => 'BIOG_MAIN',
            'resource_id' => 'c_personid=1001',
            'resource_data' => json_encode([
                'c_name_chn' => '提案人物',
                '__note' => '這是提案說明',
                '__review_status' => 'pending',
                '__proposal_meta' => [
                    'submitted_by' => 'Admin',
                    'submitted_by_id' => $user->id,
                    'submitted_at' => now()->format('Y-m-d H:i:s'),
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $lists = $this->appLists($user, '?proposals_only=1');

        // 提案列的備註標籤語義：是「提案說明」而不是「修改說明」。
        // 原本用 assertSeeText 驗 Blade 文案；不變量其實在 primary_note_label
        // 與 operation_notes[].label 兩個 prop（OperationsController:1023-1024）。
        $this->assertCount(1, $lists);
        $this->assertSame(__('operations.proposal_desc'), $lists[0]['primary_note_label'] ?? null);
        $this->assertNotSame(__('operations.modification_desc'), $lists[0]['primary_note_label'] ?? null);

        $noteLabels = array_column($lists[0]['operation_notes'] ?? [], 'label');
        $this->assertContains(__('operations.proposal_desc'), $noteLabels);
        $this->assertNotContains(__('operations.modification_desc'), $noteLabels);

        // 備註內容本身要傳下去（否則標籤對了但使用者看不到說明）。
        $this->assertContains('這是提案說明', array_column($lists[0]['operation_notes'] ?? [], 'content'));
    }
}
