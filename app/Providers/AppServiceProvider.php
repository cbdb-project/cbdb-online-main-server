<?php

namespace App\Providers;

use App\Models\BiogMain;
use App\Observers\BiogMainObserver;
use App\Services\PersonChangeIndexService;
use App\Services\QueryProfile;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider {
    /**
     * 閘門判斷已失敗並記錄過一次（避免每筆查詢都 report）。
     *
     * 這是唯一存在 provider 上的狀態，且**只會讓日誌少寫**、不影響任何授權判斷，
     * 因此即使在 Octane 這類常駐 worker 下跨請求存活也無害。
     */
    private bool $retainGateFailureReported = false;

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register() {
        // scoped 而非 singleton：這份 profile 是「本次請求的查詢紀錄」，語意上就該隨請求結束。
        // 在傳統 php-fpm 下兩者等價（程序結束即消滅），但在 Octane／RoadRunner 這類常駐 worker
        // 下，singleton 會活過請求邊界——筆數會愈跑愈大，管理員請求留下的 SQL 明細還可能被
        // 後續請求（甚至非管理員的）讀到。scoped 綁定會在每個請求開始時重建。
        $this->app->scoped(QueryProfile::class, function () {
            return new QueryProfile();
        });

        // person_change_index 水位線寫入服務：singleton 讓 tableExists() 的 schema 檢查
        // 在單一 request/command 內只做一次（避免每筆 audit 寫入都查 information_schema）。
        $this->app->singleton(PersonChangeIndexService::class, function () {
            return new PersonChangeIndexService();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot() {
        // 使用 Bootstrap 分页样式（Laravel 8 默认为 Tailwind）
        Paginator::useBootstrap();

        // 註冊姓名索引 Observers
        BiogMain::observe(BiogMainObserver::class);
        // 注意：ALTNAME_DATA 使用復合主鍵，不使用 Eloquent，改為在控制器中手動調用索引服務

        // ⚠️ 兩個回呼都在**回呼內**才解析 QueryProfile，不在 boot() 就 make() 起來捕進 closure：
        // 那樣會把「boot 當下那一個」實例永久綁在回呼裡，scoped 綁定就完全失效——在常駐 worker
        // 下第二個請求的查詢仍會寫進第一個請求的實例，而 middleware 拿到的又是新的實例。
        // scoped 綁定本身有快取，回呼內解析只是一次容器查表。

        // 只有「看得到明細的人」才付明細的成本。先前是無條件保留：每個 request（含 artisan
        // 匯入、訪客瀏覽）都把每一筆查詢的 SQL 與 bindings 累進記憶體，而 React layout 根本
        // 沒渲染這份資料——等於照樣收集、卻沒人看得到。
        //
        // 閘的是「保留明細」而不是「整個收集」：舊版 layouts/dashboard-v3.blade.php:346 的
        // 「本次查詢共 N 筆，耗時 X ms」那一行**沒有任何權限閘**，訪客與一般使用者都看得到，
        // 只有「查看詳細」連結與 modal 才限管理員（同檔 :348、:369）。若連筆數都不收，
        // 等於順手改掉舊版對所有人的行為（flag 回退到 Blade 時也一樣少一行），那不在本次範圍。
        DB::listen(function (QueryExecuted $query) {
            app(QueryProfile::class)->add($query, $this->shouldRetainQueryDetails());
        });

        // 只掛在真正使用這個變數的 layout 上（全庫僅 layouts/dashboard-v3.blade.php 引用）。
        // 先前掛 '*'：summary() 會把保留的每一筆 bindings json_encode 一次，而一個 Blade 頁面
        // 可能渲染數十個 partial，等於同一份資料重複編碼數十遍、其中絕大多數 view 從不使用它。
        // 不能改成傳 closure 延後求值——Blade 端是 `$queryProfileSummary['count']` 這樣直接
        // 陣列取值，傳 closure 會直接壞掉。
        View::composer('layouts.dashboard-v3', function ($view) {
            $view->with('queryProfileSummary', app(QueryProfile::class)->summary());
        });
    }

    /**
     * 是否要保留這一筆查詢的 SQL 與 bindings（明細）。
     *
     * 注意：**筆數與耗時一律累計**，這裡只決定要不要留明細——舊版 Blade 的摘要行對所有人
     * 顯示，只有明細 modal 限管理員（見 boot() 的說明）。
     *
     * ⚠️ 這個判斷跑在 DB::listen 的回呼裡，**絕對不能**呼叫 Auth::check()／Auth::user()／
     * Auth::guard()->user()——那些會在使用者尚未解析時去資料庫撈使用者，而我們正身處
     * 「查詢被執行」的監聽器中，等於在解析使用者的查詢裡再次觸發解析（遞迴）。
     * hasResolvedGuards() 只數已建立的 guard 數量，hasUser() 只是 `! is_null($this->user)`
     * （Illuminate\Auth\GuardHelpers），SessionGuard::user() 在已快取時直接回傳屬性，
     * 三者都不碰資料庫，因此安全。
     *
     * 代價（已知且可接受）：使用者被解析「之前」的查詢（session 讀取、撈 users 那幾筆）
     * 沒有明細（筆數仍算），故明細列數會略少於總筆數。
     *
     * ⚠️ 這裡不做任何跨請求記憶（見函式內說明）——provider 在常駐 worker 下會活過請求邊界。
     */
    private function shouldRetainQueryDetails(): bool {
        // 刻意**不記憶**判斷結果：ServiceProvider 是長生命週期物件，在 Octane／RoadRunner
        // 這類常駐 worker 下會跨請求存活，一旦某個管理員請求把它設成 true，後續訪客請求
        // 就會開始保留 SQL 明細——那是跨請求的資料外洩。省下的只是每筆查詢幾個屬性讀取
        // （hasResolvedGuards 是 count、hasUser 是 is_null、user() 走已快取屬性、isAdmin 是
        // in_array），全都不碰資料庫，不值得用一個正確性風險去換。
        //
        // 不另外檢查 runningInConsole()：artisan／queue 情境下沒有已解析的登入使用者，
        // 下面的管理員判斷本身就會回 false。多一道 console 判斷反而讓這條路徑無法被測試涵蓋
        // （PHPUnit 本身就跑在 console）。
        //
        // 整段包 try/catch：這是一個純除錯輔助，跑在**全域** DB::listen 回呼裡，
        // 絕不能因為它而讓任何請求失敗。實際踩過的情況是有測試把 Auth facade 換成 mock
        // （partial mock 對未預期的呼叫會丟 BadMethodCallException），於是每一次查詢都炸。
        try {
            if (!Auth::hasResolvedGuards()) {
                return false;
            }

            // 明確指定 web guard，不用預設 guard：App\Http\Middleware\OptionalAuthentication
            // 會在執行期 Auth::shouldUse('sanctum') 改寫預設值，那時預設 guard 會是 sanctum，
            // 於是「帶 token 打 API 的管理員」也開始留明細——而 JSON 回應永遠不顯示這份資料，
            // 正是本次要消除的浪費。
            $guard = Auth::guard('web');
            if (!method_exists($guard, 'hasUser') || !$guard->hasUser()) {
                return false;
            }

            $user = $guard->user();

            return $user !== null && method_exists($user, 'isAdmin') && $user->isAdmin();
        } catch (\Throwable $e) {
            // 判斷不出來就不留明細。只記錄第一次：這裡每筆查詢都會走一次，無條件 report
            // 會淹沒日誌；完全不記錄則會讓「閘門壞掉、功能無聲消失」查不出原因。
            if (!$this->retainGateFailureReported) {
                $this->retainGateFailureReported = true;

                // report() 自己也可能爆（例如測試把 Log／handler 換成 mock）。它若在這裡丟出去，
                // 就等於繞過了外層 catch、讓一個純除錯輔助弄壞請求——正是要避免的事。
                try {
                    report($e);
                } catch (\Throwable) {
                    // 連記錄都做不到就算了。
                }
            }

            return false;
        }
    }
}
