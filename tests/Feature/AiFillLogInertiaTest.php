<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P1-2 admin/ai-fill-logs Inertia 變體（app.admin.ai-fill-logs）測試。
 */
class AiFillLogInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-ai-fill-logs-inertia';
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

        Schema::create('ai_fill_logs', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('c_personid')->nullable();
            $table->string('route_name', 255)->nullable();
            $table->string('route_url', 500)->nullable();
            $table->text('source_text')->nullable();
            $table->longText('ai_raw')->nullable();
            $table->longText('ai_matched')->nullable();
            $table->longText('user_submitted')->nullable();
            $table->boolean('success')->default(false);
            $table->string('error_message', 500)->nullable();
            $table->integer('execution_time_ms')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('category', 32)->nullable();
            $table->timestamps();
        });
    }

    private function makeSuperAdmin(): User {
        return User::forceCreate([
            'name' => 'Admin', 'email' => 'a@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    private function seedLog(int $userId, array $overrides = []): void {
        DB::table('ai_fill_logs')->insert(array_merge([
            'user_id' => $userId,
            'c_personid' => 1001,
            'route_name' => 'basicinformation.offices.index',
            'route_url' => '/basicinformation/1001/offices',
            'source_text' => '某甲於某年任某官',
            'ai_raw' => json_encode(['x' => 1]),
            // 使用非代碼欄位（c_firstyear），避免 resolveCodeLabels 觸發對 OFFICE_CODES
            // 等代碼表的查詢（測試不建立那些表）。
            'ai_matched' => json_encode(['statistics' => ['matched_count' => 1], 'matched_fields' => ['c_firstyear' => ['value' => '1050', 'text' => '1050']]]),
            'user_submitted' => json_encode(['c_firstyear' => '1050']),
            'success' => true,
            'execution_time_ms' => 1200,
            'category' => 'posting',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function it_renders_inertia_component_with_prepared_rows(): void {
        $admin = $this->makeSuperAdmin();
        $this->seedLog($admin->id);
        $this->seedLog($admin->id, ['category' => 'assoc', 'ai_matched' => json_encode(['matched_codes' => []]), 'user_submitted' => null]);

        $response = $this->actingAs($admin)->get(route('app.admin.ai-fill-logs'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AiFillLogs/Index')
            ->has('logs.data', 2)
            ->where('logs.meta.total', 2)
            ->has('users')
            ->has('filters')
            ->has('page_translations.admin')
            ->has('logs.data.0', fn (Assert $row) => $row
                ->has('id')
                ->has('category')
                ->has('source_text')
                // has_submission 切「已提交／未提交」徽章（`Pages/Admin/AiFillLogs/Index.tsx` 直接用它），
                // 而它正是「ai-fill-logs 全顯示 Not Submitted」那個回歸壞掉的那一格——在此之前零斷言。
                // fixture 本來就是「第一筆有 user_submitted、第二筆 null」，加兩行即可。
                ->where('has_submission', true)
                ->where('person_url', route('app.basicinformation.show', ['id' => 1001, 'tab' => 'postings'], false))
                ->has('comparison_rows')
                ->has('ai_matched_pretty')
                ->etc())
            ->where('logs.data.1.has_submission', false)
            ->where('logs.data.1.person_url', route('app.basicinformation.show', ['id' => 1001, 'tab' => 'associations'], false)));
    }

    #[Test]
    public function it_filters_by_category(): void {
        $admin = $this->makeSuperAdmin();
        $this->seedLog($admin->id, ['category' => 'posting']);
        $this->seedLog($admin->id, ['category' => 'status', 'ai_matched' => json_encode(['matched_codes' => []]), 'user_submitted' => null]);

        $this->actingAs($admin)->get(route('app.admin.ai-fill-logs', ['category' => 'status']))
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('filters.category', 'status'));
    }

    #[Test]
    public function it_omits_default_zero_only_fields_from_comparison(): void {
        $admin = $this->makeSuperAdmin();

        // AI 只匹配到 c_firstyear；用戶提交除了 c_firstyear 外還帶著表單預設 0
        // 的欄位（始年閏月／終年閏月／是否赴任）。這些純預設值列不應出現在比較表。
        $this->seedLog($admin->id, [
            'ai_matched' => json_encode([
                'statistics' => ['matched_count' => 1],
                'matched_fields' => ['c_firstyear' => ['value' => '1050', 'text' => '1050']],
            ]),
            'user_submitted' => json_encode([
                'c_firstyear' => '1050',
                'c_fy_intercalary' => 0,
                'c_ly_intercalary' => 0,
                'c_assume_office_code' => 0,
            ]),
        ]);

        $this->actingAs($admin)->get(route('app.admin.ai-fill-logs'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data.0.comparison_rows', 1)
                ->where('logs.data.0.comparison_rows.0.field_key', 'c_firstyear'));
    }

    #[Test]
    public function it_keeps_field_when_ai_suggests_but_user_left_default_zero(): void {
        $admin = $this->makeSuperAdmin();

        // AI 對 c_fy_month 有建議值，但用戶留下預設 0：此列仍應保留以呈現差異。
        $this->seedLog($admin->id, [
            'ai_matched' => json_encode([
                'statistics' => ['matched_count' => 1, 'suggested_count' => 1],
                'matched_fields' => ['c_firstyear' => ['value' => '1050', 'text' => '1050']],
                'suggested_fields' => ['c_fy_month' => ['value' => '5', 'text' => '5']],
            ]),
            'user_submitted' => json_encode([
                'c_firstyear' => '1050',
                'c_fy_month' => 0,
            ]),
        ]);

        $this->actingAs($admin)->get(route('app.admin.ai-fill-logs'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data.0.comparison_rows', 2)
                ->where('logs.data.0.comparison_rows.1.field_key', 'c_fy_month')
                ->where('logs.data.0.comparison_rows.1.ai_value', '5')
                // 用戶留預設 0 vs AI 建議 5：正規化後視為不相符，仍應呈現差異。
                ->where('logs.data.0.comparison_rows.1.matches', false));
    }

    #[Test]
    public function non_super_admin_gets_403(): void {
        $expert = User::forceCreate([
            'name' => 'Exp', 'email' => 'e@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_EXPERT,
        ]);

        $this->actingAs($expert)->get(route('app.admin.ai-fill-logs'))->assertForbidden();
    }
    // ── 自 AiFillLogTest 移植（Blade 下架環節 4a-1）────────────────

    /**
     * 訪客要被重導到登入頁。
     *
     * 移植自 `AiFillLogTest::test_guest_cannot_access_ai_fill_logs`。React 側原本只有
     * `non_super_admin_gets_403`（已登入但非 super admin），**沒有任何 guest 斷言**。
     * 值得留著：`app/admin/ai-fill-logs` 的 `auth` 來自 `routes/web.php` 的**外層 group**，
     * 路由自己只掛 `inertia`——哪天有人重排 group，這個保護會靜默消失。
     */
    #[Test]
    public function guest_is_redirected_to_login(): void {
        $this->get(route('app.admin.ai-fill-logs'))->assertRedirect(route('login'));
    }

    /**
     * `?search=` 要真的縮小結果集，而不是只讓頁面回 200。
     *
     * 移植自 `AiFillLogTest::test_admin_page_filters`（原本斷言 `assertSee('唐朝開元年間知縣')`
     * 與 `assertSee('暫無記錄')`，都是 Blade 文案）。React 側原本只有 `it_filters_by_category`，
     * 而 category 是單欄 `where`；search 是**跨 leftJoin 的三欄 OR**
     * （`ai_fill_logs.source_text`／`users.name`／`users.email`，見 `AiFillLogController:25-32`），
     * 複雜得多卻沒人守。
     *
     * 順手補上 legacy 從未測過的另兩個 OR 分支——原測試只打了 `source_text`。
     */
    #[Test]
    public function search_filter_matches_source_text_and_user_columns(): void {
        $admin = $this->makeSuperAdmin();
        $this->seedLog($admin->id, ['source_text' => '唐朝開元年間知縣']);

        $other = User::forceCreate([
            'name' => 'Zhang Yong',
            'email' => 'zhangyong@example.com',
            'password' => bcrypt('secret'),
            'confirmation_token' => 'tok-other',
            'is_active' => 1,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
        $this->seedLog($other->id, ['source_text' => '宋人某乙任某職']);

        // ① source_text 命中：只留第一筆。
        $this->assertSame(['唐朝開元年間知縣'], $this->searchSourceTexts($admin, '開元'));

        // ② users.name 命中（跨 leftJoin）：只留第二筆。
        $this->assertSame(['宋人某乙任某職'], $this->searchSourceTexts($admin, 'Zhang Yong'));

        // ③ users.email 命中：同樣只留第二筆。
        $this->assertSame(['宋人某乙任某職'], $this->searchSourceTexts($admin, 'zhangyong@example.com'));

        // ④ 查無資料時是空集，不是「忽略篩選回全部」。
        $this->assertSame([], $this->searchSourceTexts($admin, '不存在的文字'));
    }

    /** 打 `?search=` 並取回 `logs.data` 的 source_text 清單。 */
    private function searchSourceTexts(User $admin, string $search): array {
        $texts = [];

        $this->actingAs($admin)
            ->get(route('app.admin.ai-fill-logs', ['search' => $search]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$texts, $search) {
                $props = $page->where('filters.search', $search)->toArray()['props'];
                $texts = array_column($props['logs']['data'], 'source_text');
            });

        return $texts;
    }
}
