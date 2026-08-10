<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\QueryProfile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\AlwaysProp;
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SQL 查詢 profile 的明細閘門。
 *
 * 先前 AppServiceProvider 無條件註冊 DB::listen，每個 request（含 artisan 匯入、訪客瀏覽）
 * 都把每筆查詢的 SQL 與 bindings 累進記憶體——而 React layout 根本沒渲染這份資料，
 * 等於照樣收集卻沒人看得到。
 *
 * 閘的是「**保留明細**」而不是「整個收集」：舊版 layouts/dashboard-v3.blade.php:346 的
 * 「本次查詢共 N 筆」摘要行**沒有任何權限閘**（訪客也看得到），只有「查看詳細」與 modal
 * 才限管理員（:348／:369）。故筆數與耗時一律累計，只有 SQL／bindings 限管理員。
 *
 * ⚠️ 判斷跑在 DB::listen 回呼裡，絕不能呼叫會觸發使用者查詢的 Auth::check()／Auth::user()
 * （那會在解析使用者的查詢裡再次觸發解析）。故用 hasResolvedGuards() + hasUser()，
 * 兩者都只是屬性檢查。
 *
 * 已知代價（刻意的，勿誤判為回歸）：使用者被解析「之前」的查詢（session 讀取、撈 users）
 * 沒有明細，故管理員看到的明細列數會略少於總筆數，總筆數也因此不與舊版 Blade 的數字直接可比。
 */
class QueryProfileGateTest extends TestCase {
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

        Schema::create('probe', function ($table) {
            $table->increments('id');
            $table->string('label')->nullable();
        });
        DB::table('probe')->insert(['label' => 'x']);
    }

    private function makeUser(int $isAdmin): User {
        return User::forceCreate([
            'name' => 'U' . $isAdmin, 'email' => "u{$isAdmin}@example.com", 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => $isAdmin,
        ]);
    }

    /** 直接觸發一次查詢，讓 DB::listen 的閘門跑到。 */
    private function runQuery(): void {
        DB::table('probe')->count();
    }

    /** 已保留明細的筆數。 */
    private function detailCount(): int {
        return count(app(QueryProfile::class)->summary()['queries']);
    }

    #[Test]
    public function counts_for_guests_but_keeps_no_details(): void {
        // 舊版 Blade 的「本次查詢共 N 筆」那一行**沒有權限閘**，訪客也看得到
        // （layouts/dashboard-v3.blade.php:346），所以筆數要照算；昂貴且僅管理員可見的
        // 是每筆 SQL／bindings，只有那部分不留。
        $this->runQuery();

        $this->assertGreaterThan(0, app(QueryProfile::class)->count(), '筆數對所有人都要算（對齊舊版摘要行）');
        $this->assertSame(0, $this->detailCount(), '訪客不該保留任何 SQL 明細');
    }

    #[Test]
    public function keeps_no_details_for_a_regular_user(): void {
        $this->actingAs($this->makeUser(User::ROLE_REGULAR));
        $this->runQuery();

        $this->assertGreaterThan(0, app(QueryProfile::class)->count());
        $this->assertSame(0, $this->detailCount(), '一般使用者不該保留 SQL 明細');
    }

    #[Test]
    public function keeps_no_details_for_a_crowdsourcing_user(): void {
        $this->actingAs($this->makeUser(User::ROLE_CROWDSOURCING));
        $this->runQuery();

        $this->assertGreaterThan(0, app(QueryProfile::class)->count());
        $this->assertSame(0, $this->detailCount(), '眾包使用者不該保留 SQL 明細');
    }

    #[Test]
    public function keeps_details_for_a_super_admin(): void {
        $this->actingAs($this->makeUser(User::ROLE_SUPER_ADMIN));
        $this->runQuery();

        $this->assertGreaterThan(0, $this->detailCount(), '管理員的查詢應保留明細');
    }

    #[Test]
    public function keeps_details_for_an_expert_because_isAdmin_covers_that_role(): void {
        // User::isAdmin() = EXPERT 或 SUPER_ADMIN，與舊版 Blade layout 的
        // Auth::user()->isAdmin() 閘門一致（不是只有超級管理員）。
        $this->actingAs($this->makeUser(User::ROLE_EXPERT));
        $this->runQuery();

        $this->assertGreaterThan(0, $this->detailCount(), '專家帳號亦屬 isAdmin，應保留明細');
    }

    #[Test]
    public function the_shared_prop_gives_non_admins_the_count_without_any_sql(): void {
        // 這是「顯示端自己也要授權」的鎖：即使收集端的閘門被放寬（它長在全域 DB::listen 回呼裡、
        // 還包著 try/catch），原始 SQL 與 bind 值也不該出現在非管理員的 data-page JSON。
        $this->actingAs($this->makeUser(User::ROLE_REGULAR));
        $this->runQuery();

        $resolved = ((new HandleInertiaRequests())->share(request())['query_profile'])();

        $this->assertNotNull($resolved, '摘要行要給所有人（對齊舊版 Blade）');
        $this->assertGreaterThan(0, $resolved['count']);
        $this->assertSame([], $resolved['queries'], '非管理員的回應不該帶任何 SQL');
        $this->assertFalse($resolved['truncated'], '沒有明細不算「被截斷」');
    }

    #[Test]
    public function the_gate_does_not_recurse_while_the_user_is_resolved_from_the_session(): void {
        // 這條路徑最容易出事：閘門若呼叫會查資料庫的 Auth API（Auth::check()／Auth::user()），
        // 就會在「解析使用者」那句 select 的監聽器裡再次觸發解析 → 無限遞迴。
        //
        // ⚠️ 不能用 actingAs()：那是直接把 User 物件 setUser() 進 guard，`$this->user` 一開始
        //    就不是 null，永遠走不到真正的解析路徑（先前這個測試就是這樣，把閘門換成
        //    `Auth::check() && Auth::user()->isAdmin()` 照樣綠燈）。這裡改為只在 session 裡放
        //    login key、忘掉已解析的 guard，強迫 SessionGuard::user() 真的去 retrieveById()。
        $admin = $this->makeUser(User::ROLE_SUPER_ADMIN);

        $sessionKey = Auth::guard('web')->getName();
        session([$sessionKey => $admin->id]);
        Auth::forgetGuards();

        // 解析之前先跑一筆：此時還沒有人登入，明細不該被保留。
        // （這一句同時讓本測試對「把閘門拿掉、無條件保留」也會紅，而不只是抓遞迴。）
        $this->runQuery();
        $this->assertSame(0, $this->detailCount(), '尚未解析出使用者時不該保留明細');

        // 這一行會發出「撈 users」那句 select，DB::listen 於是在 $this->user 仍為 null 時被呼叫。
        $this->assertSame($admin->id, Auth::id(), '使用者必須是從 session 真正解析出來的');

        // 解析完成之後的查詢才有明細（解析當下那句沒有，這是已知代價）。
        $this->runQuery();
        $this->assertGreaterThan(0, $this->detailCount(), '解析出管理員之後應開始保留明細');
    }

    #[Test]
    public function the_shared_prop_is_lazy_so_it_counts_the_controller_queries(): void {
        // 這是實際踩過的 bug：inertia-laravel 的 Middleware::handle 在 `$next($request)`
        // **之前**就呼叫 share()，此刻控制器一筆查詢都還沒跑。直接呼叫 queryProfile()
        // 求值，摘要就永遠只有 session／撈使用者那兩筆（畫面上看起來有東西、數字卻是錯的）。
        // 必須交出 closure，讓 Inertia 在 toResponse()（控制器跑完後）才求值。
        $this->actingAs($this->makeUser(User::ROLE_SUPER_ADMIN));

        $shared = (new HandleInertiaRequests())->share(request());
        $this->assertArrayHasKey('query_profile', $shared);
        $this->assertInstanceOf(
            AlwaysProp::class,
            $shared['query_profile'],
            'query_profile 必須是延後求值的 prop，直接求值會漏掉控制器的查詢'
        );

        // share() 之後才發生的查詢；延後求值才看得到它。
        $before = app(QueryProfile::class)->count();
        $this->runQuery();
        $this->runQuery();
        $resolved = ($shared['query_profile'])();

        $this->assertNotNull($resolved, '有查詢時不該回 null');
        $this->assertGreaterThan($before, $resolved['count'], '延後求值必須含 share() 之後的查詢');
        $this->assertSame(app(QueryProfile::class)->count(), $resolved['count']);
    }

    #[Test]
    public function the_shared_prop_survives_a_real_partial_reload(): void {
        // AlwaysProp（而非單純 closure）：局部重載只回傳 `only` 指定的 props，其餘 shared props
        // 會被丟掉、前端沿用舊值——除錯輔助顯示上一次請求的筆數比不顯示更誤導。
        //
        // 這裡走真正的 Inertia 局部重載（X-Inertia-Partial-Data 指定**別的** prop），
        // 而不是只斷言型別；把 AlwaysProp 換成普通 closure，這個測試就會紅。
        // 'inertia' 是具名 middleware（app/Http/Kernel.php:78），不在 web group 裡——
        // 少了它就不會有任何 shared props，這個測試會因為「連 errors 都沒有」而假性失敗。
        Route::middleware(['web', 'inertia'])->get('/__query-profile-partial-probe', function () {
            DB::table('probe')->count();

            return Inertia::render('Probe', ['other' => 'x']);
        });

        $this->actingAs($this->makeUser(User::ROLE_SUPER_ADMIN));

        // 版本必須和 middleware 算出來的一致，否則 Inertia 對 GET 會回 409（要求整頁重載）。
        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new HandleInertiaRequests())->version(request()),
            'X-Inertia-Partial-Component' => 'Probe',
            'X-Inertia-Partial-Data' => 'other',
        ])->get('/__query-profile-partial-probe');

        $response->assertOk();
        $props = $response->json('props');
        $this->assertArrayHasKey('other', $props);
        $this->assertArrayNotHasKey('nav', $props, '局部重載本來就會丟掉一般 shared props');
        $this->assertArrayHasKey('query_profile', $props, '查詢明細必須繞過局部重載過濾');
        $this->assertGreaterThan(0, $props['query_profile']['count']);

        // 但局部重載**不夾帶明細**：Inertia 會把整份 props 存進 window.history.state，
        // 而切換分頁這類局部重載在管理員操作中極頻繁，每次都帶上百句 SQL 與 bind 值不划算。
        $this->assertSame([], $props['query_profile']['queries'], '局部重載不該夾帶 SQL 明細');
        $this->assertStringNotContainsString('"sql"', $response->getContent(), '回應內容不該出現任何 SQL');
    }

    #[Test]
    public function details_omitted_is_only_true_for_someone_who_could_otherwise_see_them(): void {
        // 前端會在 details_omitted 時沿用上一次的明細。若非管理員的回應也把它設成 true，
        // 同一個 React 殼在使用者登出／被降權之後就會繼續顯示先前管理員的 SQL。
        // 故非管理員一律 false，前端因此會清掉舊明細。
        $this->actingAs($this->makeUser(User::ROLE_REGULAR));
        $this->runQuery();

        $plain = ((new HandleInertiaRequests())->share(request())['query_profile'])();

        // 一般使用者即使帶著 partial header 也不該是 true（他本來就看不到明細）。
        request()->headers->set('X-Inertia-Partial-Data', 'other');
        request()->headers->set('X-Inertia-Partial-Component', 'Probe');
        $partialWithHeaders = ((new HandleInertiaRequests())->share(request())['query_profile'])();

        $this->assertFalse($plain['details_omitted']);
        $this->assertFalse($partialWithHeaders['details_omitted'], '非管理員永遠不得為 true');
        $this->assertSame([], $partialWithHeaders['queries']);
    }

    #[Test]
    public function details_omitted_is_true_for_an_admin_on_a_partial_reload(): void {
        $this->actingAs($this->makeUser(User::ROLE_SUPER_ADMIN));
        $this->runQuery();

        request()->headers->set('X-Inertia-Partial-Data', 'other');
        request()->headers->set('X-Inertia-Partial-Component', 'Probe');
        $resolved = ((new HandleInertiaRequests())->share(request())['query_profile'])();

        $this->assertTrue($resolved['details_omitted'], '管理員的局部重載要讓前端沿用舊明細');
        $this->assertSame([], $resolved['queries'], '局部重載不夾帶明細');
        $this->assertGreaterThan(0, $resolved['count'], '摘要仍要更新');
    }

    #[Test]
    public function a_full_inertia_visit_does_carry_the_details_for_an_admin(): void {
        // 與上一個測試互為對照：整頁載入（非局部重載）才給明細，否則「不夾帶」會變成
        // 「永遠拿不到」，整個功能等於沒補回來。
        Route::middleware(['web', 'inertia'])->get('/__query-profile-full-probe', function () {
            DB::table('probe')->count();

            return Inertia::render('Probe', ['other' => 'x']);
        });

        $this->actingAs($this->makeUser(User::ROLE_SUPER_ADMIN));

        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new HandleInertiaRequests())->version(request()),
        ])->get('/__query-profile-full-probe');

        $response->assertOk();
        $profile = $response->json('props.query_profile');
        $this->assertGreaterThan(0, $profile['count']);
        $this->assertNotEmpty($profile['queries'], '整頁載入必須給管理員明細');
    }

    #[Test]
    public function the_detail_list_is_capped_but_reports_the_true_total(): void {
        // 明細只送前 100 筆，但筆數要如實回報，否則管理員會以為頁面只跑了 100 筆查詢。
        $this->actingAs($this->makeUser(User::ROLE_SUPER_ADMIN));

        for ($i = 0; $i < 105; $i++) {
            $this->runQuery();
        }
        $resolved = ((new HandleInertiaRequests())->share(request())['query_profile'])();

        $this->assertGreaterThan(100, $resolved['count'], '總筆數不該被截斷');
        $this->assertCount(100, $resolved['queries'], '明細應只有前 100 筆');
        $this->assertTrue($resolved['truncated']);
    }

    #[Test]
    public function collection_is_memory_bounded_without_distorting_the_totals(): void {
        // 保留明細有上限，但筆數與總耗時必須照實累計——否則管理員在一個跑幾千筆查詢的
        // 頁面上只會看到「200 筆」，反而掩蓋了要抓的效能問題。
        $this->actingAs($this->makeUser(User::ROLE_SUPER_ADMIN));

        $total = QueryProfile::MAX_STORED + 37;
        for ($i = 0; $i < $total; $i++) {
            $this->runQuery();
        }

        $profile = app(QueryProfile::class);
        $this->assertGreaterThanOrEqual($total, $profile->count(), '筆數不該被上限截掉');
        $this->assertCount(QueryProfile::MAX_STORED, $profile->summary()['queries'], '明細應停在上限');
        $this->assertGreaterThan(0.0, $profile->totalTime());
    }

    #[Test]
    public function summary_shape_is_stable(): void {
        $this->actingAs($this->makeUser(User::ROLE_SUPER_ADMIN));
        $this->runQuery();

        $summary = app(QueryProfile::class)->summary();
        $this->assertArrayHasKey('count', $summary);
        $this->assertArrayHasKey('time_ms', $summary);
        $this->assertArrayHasKey('queries', $summary);
        $this->assertArrayHasKey('bindings_json', $summary['queries'][0]);
    }
}
