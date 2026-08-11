<?php

namespace App\Http\Middleware;

use App\Support\Navigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware {
    /**
     * Inertia 使用的根模板
     */
    protected $rootView = 'inertia';

    /**
     * 每個 Inertia 回應共用的 props
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'app' => [
                'name' => config('app.name'),
                'version' => get_app_version(),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                    'institution' => $user->institution,
                    // 角色旗標（對齊 User::is* 方法）；前端側邊欄/頁面閘門用。
                    // ⚠️ 僅供 UX，後端每條 mutation 路由仍須獨立授權（AGENTS.md §5）。
                    'roles' => [
                        'is_active' => $user->isActive(),
                        'is_admin' => $user->isAdmin(),
                        'is_expert' => $user->isExpert(),
                        'is_super_admin' => $user->isSuperAdmin(),
                        'is_crowdsourcing' => $user->isCrowdsourcingUser(),
                        'is_regular' => $user->isRegularUser(),
                    ],
                    // 能力旗標（對齊 User::can* 方法）；側邊欄連結顯示與否。
                    'can' => [
                        'manage_users' => $user->canManageUsers(),
                        'restore_operations' => $user->canRestoreOperations(),
                        'review_proposals' => $user->canReviewProposals(),
                        'view_audit_logs' => $user->canViewAuditLogs(),
                        'write_directly' => $user->canWriteDirectly(),
                        'run_batch_import' => $user->canRunBatchImport(),
                    ],
                ] : null,
            ],
            'locale' => app()->getLocale(),
            'locale_url' => route('locale.switch', [], false),
            // 導覽單一來源（與 Blade sidebar 共用 App\Support\Navigation）：
            // 已套用角色閘門、已依 feature flag 解析連結。React AppShell 側邊欄
            // 依目前路由（active.patterns）自行判定 active，不靠中文字串比對。
            'nav' => Navigation::tree($user),
            // 殼所需的固定連結（導覽列首頁/個人資料/登入登出等），由後端解析路由
            // 為相對 URL，React DashboardLayout 直接使用，避免前端硬編碼路徑。
            'shell' => [
                // #59：首頁連結 flag-aware（basicinformation.index 翻 new 後指 React 版，否則 legacy；無自動轉址）。
                'home_url' => person_index_url(),
                // profile 連結受 migration flag 控制：flag=new 且新路由存在時指向 React 版。
                'profile_url' => $this->profileUrl(),
                'logout_url' => route('logout', [], false),
                'login_url' => route('login', [], false),
                // 註冊路由可被關閉（Auth::routes(['register' => false])）。這是**每一頁**都會
                // 求值的共享 prop，無條件呼叫 route('register') 會在關閉註冊時讓整個 React
                // 站台每頁拋 RouteNotFoundException（比只壞掉 /login 嚴重得多）。
                // null＝前端不渲染註冊入口。
                'register_url' => Route::has('register') ? route('register', [], false) : null,
            ],
            // flash 訊息橋接：把 laracasts/flash 的 session 訊息轉成陣列，
            // 由 React AppShell 統一渲染 toast/alert（取代 Blade flash::message partial）。
            'flash' => $this->flashMessages(),
            // SQL 查詢明細（管理員的效能除錯輔助）。舊版 Blade layout 有這一區、React layout 沒有，
            // 於是 DB::listen 照樣收集卻沒人看得到；連同收集端的閘門一起補回。null＝不顯示。
            //
            // ⚠️ 必須是 closure（且用 Inertia::always 包起來），不可直接呼叫：
            // inertia-laravel 的 Middleware::handle 是在 `$next($request)` **之前**
            // 呼叫 share()，此刻控制器一筆查詢都還沒跑，直接求值只會拿到 session／撈使用者
            // 那兩筆，摘要永遠顯示個位數。closure 由 Response::resolveArrayableProperties
            // 以 App::call() 在 toResponse()（控制器跑完之後）才求值，才是本次請求的真實筆數。
            //
            // always()：局部重載（partial reload，如切換人物分頁）只回傳 `only` 指定的 props，
            // 其餘 shared props 會被丟掉，前端於是沿用舊值——除錯輔助顯示上一次請求的筆數
            // 比不顯示更誤導。包成 AlwaysProp 讓每個回應都帶上當次的實際筆數。
            'query_profile' => Inertia::always(fn () => $this->queryProfile($request)),
            // ⚠️ 頁面特定翻譯群組（views、codes、operations、admin）
            //   請由控制器以 'page_translations' key 傳入，不可複用此 'translations' key，
            //   否則 inertia-laravel 的淺合併會覆蓋此處的 shared 翻譯。
            'translations' => [
                'common' => is_array($t = trans('common')) ? $t : [],
                'nav' => is_array($t = trans('nav')) ? $t : [],
                'person' => is_array($t = trans('person')) ? $t : [],
                'query' => is_array($t = trans('query')) ? $t : [],
                // 殼所需翻譯群組常駐（驗證錯誤訊息、共用按鈕等）
                'auth' => is_array($t = trans('auth')) ? $t : [],
                'validation' => is_array($t = trans('validation')) ? $t : [],
                // 人物編輯器（13 個 *Editor / PersonEditor 中樞）皆用 biogmains group；
                // 常駐共享後，中英切換才會即時重渲染各編輯器的 label/按鈕（否則只回退硬編碼 zh）。
                'biogmains' => is_array($t = trans('biogmains')) ? $t : [],
            ],
        ]);
    }

    /**
     * 個人資料連結（依 migration flag 指向 Blade 或 React 版；皆不存在時 null）。
     */
    protected function profileUrl(): ?string {
        $route = \Illuminate\Support\Facades\Route::class;
        if (migration_flag_is_new('profile') && $route::has('app.profile.edit')) {
            return route('app.profile.edit', [], false);
        }
        if ($route::has('profile.edit')) {
            return route('profile.edit', [], false);
        }

        return null;
    }

    /**
     * SQL 查詢明細，供 React layout 顯示。
     *
     * 筆數與耗時給所有人（對齊舊版 Blade 那一行本來就沒有權限閘）；**每筆 SQL 與 bindings
     * 只給管理員**。這裡自己再檢查一次 isAdmin，不只依賴收集端的閘門
     * （AppServiceProvider::shouldRetainQueryDetails()）：那個閘門長在全域 DB::listen 回呼裡、
     * 還包著 try/catch，一旦有人為了「讓筆數回到舊版的數字」把它放寬，原始 SQL 與 bind 值
     * 就會直接出現在每個訪客的 data-page JSON 裡。授權不該只有一道，且不該只長在除錯收集器內
     * （AGENTS.md §5）。此處不在 DB::listen 內，可以安全呼叫 Auth。
     *
     * 明細只取前 100 筆（與舊版 modal 的 array_slice 一致）並回報是否被截斷，避免把一頁上千筆
     * 查詢全部塞進 Inertia props。沒有任何查詢時回 null，前端就不渲染這一區。
     *
     * **局部重載不帶明細**：Inertia 會把整份 page props 存進 window.history.state，而
     * 局部重載（切換人物分頁等）在管理員操作中非常頻繁；每次都夾帶上百句 SQL 與 bind 值，
     * 等於為了一個偶爾打開的 modal 讓每個 XHR 都變胖、並把 bind 值留在瀏覽器歷史裡。
     * 摘要（筆數／耗時）仍每次更新，明細則以整頁載入那次為準。
     *
     * @return array<string, mixed>|null
     */
    protected function queryProfile(Request $request): ?array {
        $profiler = app(\App\Services\QueryProfile::class);
        if ($profiler->count() === 0) {
            return null;
        }

        // 與收集端同一個 guard（AppServiceProvider::shouldRetainQueryDetails() 用 web）：
        // OptionalAuthentication 會在執行期改寫預設 guard，兩端若各看各的預設值，就會出現
        // 「有收集卻不給看」這種不一致。
        $user = \Illuminate\Support\Facades\Auth::guard('web')->user();
        $isAdmin = $user instanceof \App\Models\User && $user->isAdmin();
        // 兩個 partial header 都要在：inertia-laravel 的 Response::isPartial() 除了 partial-data
        // 還要求 partial-component 與本次元件相符，只看單一 header 會把畸形請求也當成局部重載
        // （後果只是不給明細，不會外洩，但沒必要）。
        $isPartial = $request->hasHeader('X-Inertia-Partial-Data')
            && $request->hasHeader('X-Inertia-Partial-Component');
        $canSeeDetails = $isAdmin && !$isPartial;

        $limit = 100;
        $summary = $profiler->summary($canSeeDetails ? $limit : 0);

        return [
            'count' => $summary['count'],
            'time_ms' => round((float) $summary['time_ms'], 2),
            // 「這次刻意不送明細，但你本來看得到」——前端據此保留上一次整頁載入的明細。
            // 非管理員永遠是 false，因此降權／登出後的回應會讓前端**清掉**先前的明細，
            // 而不是繼續顯示（同一個 React 殼會活過這種身分變化）。
            'details_omitted' => $isAdmin && $isPartial,
            // 非管理員沒有明細，但那不叫「被截斷」——前端據此決定要不要顯示「查看詳細」。
            'truncated' => $canSeeDetails && $summary['count'] > count($summary['queries']),
            'queries' => array_map(static fn (array $q): array => [
                'time' => round((float) $q['time'], 2),
                'sql' => $q['sql'],
                'bindings' => $q['bindings_json'],
            ], $summary['queries']),
        ];
    }

    /**
     * 將 laracasts/flash 的 session 訊息（session key `flash_notification`）
     * 正規化成前端可消費的陣列。flash 訊息屬一次性 session flash data，
     * 在本次請求被讀取後即隨 Laravel flash 生命週期清除，不需手動 forget。
     *
     * @return array<int, array<string, mixed>>
     */
    protected function flashMessages(): array {
        $messages = session('flash_notification', collect());

        if (!$messages instanceof \Illuminate\Support\Collection) {
            $messages = collect($messages);
        }

        $result = $messages->map(function ($message) {
            // Message 物件或已是陣列皆可能出現，統一取欄位。
            $get = fn ($key, $default = null) => is_array($message)
                ? ($message[$key] ?? $default)
                : ($message->{$key} ?? $default);

            return [
                'level' => $get('level', 'info'),
                'message' => $get('message', ''),
                'title' => $get('title'),
                'important' => (bool) $get('important', false),
                'overlay' => (bool) $get('overlay', false),
            ];
        })->values()->all();

        // 一併橋接 Laravel 慣用的一次性 session 訊息（控制器常用 ->with('success', ...)）。
        $generic = [
            'success' => 'success',
            'error' => 'danger',
            'warning' => 'warning',
            'info' => 'info',
            'status' => 'info',
        ];
        foreach ($generic as $key => $level) {
            $value = session($key);
            if (is_string($value) && $value !== '') {
                $result[] = [
                    'level' => $level,
                    'message' => $value,
                    'title' => null,
                    'important' => false,
                    'overlay' => false,
                ];
            }
        }

        return $result;
    }
}
