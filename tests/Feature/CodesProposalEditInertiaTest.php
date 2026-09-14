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
 * P2-5 codes/proposal-edit Inertia 變體（app.codes.proposals.edit/update）測試。
 */
class CodesProposalEditInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-codes-proposal-edit';
        if (!is_dir($compiledViewPath)) {
            mkdir($compiledViewPath, 0777, true);
        }
        config(['view.compiled' => $compiledViewPath]);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config(['codes.tables' => [
            'TEST_PROP_CODES' => '測試代碼',
            // 複合主鍵的測試表：見 composite_key_create_proposal_renders_both_key_columns()。
            'TEST_PROP_COMPOSITE' => '測試複合主鍵代碼',
        ]]);

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

        Schema::create('TEST_PROP_CODES', function ($table) {
            $table->integer('code_id')->primary();
            $table->string('description')->nullable();
        });

        Schema::create('TEST_PROP_COMPOSITE', function ($table) {
            $table->string('code_id', 10);
            $table->string('code_sub', 10);
            $table->string('description')->nullable();
            $table->primary(['code_id', 'code_sub']);
        });

        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('c_personid')->default(0);
            $table->string('resource')->nullable();
            $table->text('resource_id')->nullable();
            $table->string('op_type')->nullable();
            $table->longText('resource_data')->nullable();
            $table->longText('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });
    }

    private function activeUser(): User {
        return User::forceCreate([
            'name' => 'U', 'email' => 'u@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    private function seedProposal(int $userId, array $payloadOverrides = []): int {
        $payload = array_merge([
            'code_id' => 5,
            'description' => 'proposed value',
            '__proposal_meta' => ['submitted_by_id' => $userId, 'comment' => 'please add'],
            '__key_columns' => ['code_id'],
            '__review_status' => 'pending',
        ], $payloadOverrides);

        return (int) DB::table('operations')->insertGetId([
            'user_id' => $userId,
            'c_personid' => 0,
            'resource' => 'TEST_PROP_CODES',
            'resource_id' => '5',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource_data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function proposal_edit_renders_with_values(): void {
        $user = $this->activeUser();
        $opId = $this->seedProposal($user->id);

        $this->actingAs($user)
            ->get(route('app.codes.proposals.edit', ['table_name' => 'TEST_PROP_CODES', 'operation' => $opId]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/ProposalEdit')
                ->where('table', 'TEST_PROP_CODES')
                ->where('values.code_id', 5)
                ->where('values.description', 'proposed value')
                ->where('operation_id', $opId)
                ->has('urls.update'));
    }

    /**
     * ── 2026-09-15（Blade 下架環節 4b-2c-1）─────────────────────────
     *
     * 從 `CodesControllerTest::testProposalOwnerCanViewEditFormForCreateProposal` 移植。
     * 原測試**不打 HTTP**：它 new 了一個匿名子類覆蓋 `findOperationOrAbort()`，直接呼叫
     * `$controller->proposalEdit(...)` 並斷言 `$view->getName() === 'codes.proposal-edit'`
     * ——那個斷言本身就是 Blade 專屬的，整個做法也繞過了路由與授權。
     *
     * 同檔的 `proposal_edit_renders_with_values()` 已覆蓋 React 版，但它與原測試差**兩件事**，
     * 所以不能直接刪掉了事：
     *  ① 原測試是 **create 提案**（`TYPE_PROPOSAL_CREATE`，`resource_original` 為空），
     *     既有那條是 **update 提案**——create 的 `values` 只能從 `resource_data` 還原，
     *     沒有現成的資料列可回退；
     *  ② 原測試的表是**複合主鍵**（`code_id` + `code_sub`），既有那條是單欄主鍵——
     *     少了它就沒有任何測試證明「兩個鍵欄都會出現在 `values` 裡」。
     *
     * 鑑別力（改壞生產碼→跑 `--filter Codes` 共 352 條→還原，兩次都**只有本條紅**）：
     *  - `'key_columns' => array_slice($payload['__key_columns'] ?? …, 0, 1)`（只還原第一個鍵欄）
     *    ⇒ 全庫只有本條抓得到——`proposal_edit_renders_with_values()` 用單欄主鍵，
     *    `array_slice(…, 0, 1)` 對它是 no-op。
     *  - `'is_create_proposal' => false` ⇒ 同樣只有本條抓得到（全 repo 沒有別的地方斷言這個 prop）。
     *
     * ⚠️ **第一版我只斷言 `values.*`，那不足以守住「複合主鍵」**：`values` 是照
     * `Schema::getColumnListing()` **逐欄**填的，跟 `__key_columns` 還原得對不對是兩件事
     * ——測試名字承諾了 body 沒做到的事（review 查出）。上面那兩條 `->where()` 才是重點。
     */
    #[Test]
    public function composite_key_create_proposal_renders_both_key_columns(): void {
        $user = $this->activeUser();

        $opId = (int) DB::table('operations')->insertGetId([
            'user_id' => $user->id,
            'c_personid' => 0,
            'resource' => 'TEST_PROP_COMPOSITE',
            'resource_id' => 'PX_._01',
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource_data' => json_encode([
                'code_id' => 'PX',
                'code_sub' => '01',
                'description' => 'Proposal',
                '__key_columns' => ['code_id', 'code_sub'],
                '__review_status' => 'pending',
                '__proposal_meta' => ['submitted_by_id' => $user->id],
            ], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode([]),
            'crowdsourcing_status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('app.codes.proposals.edit', ['table_name' => 'TEST_PROP_COMPOSITE', 'operation' => $opId]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/ProposalEdit')
                ->where('table', 'TEST_PROP_COMPOSITE')
                ->where('values.code_id', 'PX')
                ->where('values.code_sub', '01')
                ->where('values.description', 'Proposal')
                // 🔴 這兩條才是「複合主鍵」真正被守住的地方。`values` 是照
                // `Schema::getColumnListing()` 逐欄填的，所以光斷言 values 證明不了
                // `__key_columns` 有沒有被正確還原——那是另一個 prop。
                ->where('key_columns', ['code_id', 'code_sub'])
                ->where('is_create_proposal', true));
    }

    #[Test]
    public function proposal_update_persists_and_redirects(): void {
        $user = $this->activeUser();
        $opId = $this->seedProposal($user->id);

        $this->actingAs($user)
            ->patch(route('app.codes.proposals.update', ['table_name' => 'TEST_PROP_CODES', 'operation' => $opId]), [
                'code_id' => 5,
                'description' => 'edited proposal',
            ])
            ->assertRedirect(route('app.operations.index', ['proposals_only' => 1]));

        $op = DB::table('operations')->find($opId);
        $this->assertStringContainsString('edited proposal', $op->resource_data);
    }

    #[Test]
    public function proposal_cancel_marks_cancelled_and_redirects(): void {
        $user = $this->activeUser();
        $opId = $this->seedProposal($user->id);

        $this->actingAs($user)
            ->delete(route('app.codes.proposals.cancel', ['table_name' => 'TEST_PROP_CODES', 'operation' => $opId]), ['reason' => '撤回測試'])
            ->assertRedirect(route('app.operations.index', ['proposals_only' => 1]));

        $op = DB::table('operations')->find($opId);
        $this->assertStringContainsString('cancelled', $op->resource_data);
    }

    #[Test]
    public function non_submitter_cannot_edit(): void {
        $owner = $this->activeUser();
        $opId = $this->seedProposal($owner->id);
        $other = User::forceCreate([
            'name' => 'O', 'email' => 'o@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->actingAs($other)
            ->get(route('app.codes.proposals.edit', ['table_name' => 'TEST_PROP_CODES', 'operation' => $opId]))
            ->assertForbidden();
    }
}
