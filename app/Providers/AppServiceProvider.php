<?php

namespace App\Providers;

use App\Models\BiogMain;
use App\Observers\BiogMainObserver;
use App\Services\PersonChangeIndexService;
use App\Services\QueryProfile;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider {
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register() {
        $this->app->singleton(QueryProfile::class, function () {
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

        $profiler = $this->app->make(QueryProfile::class);

        DB::listen(function (QueryExecuted $query) use ($profiler) {
            $profiler->add($query);
        });

        View::composer('*', function ($view) use ($profiler) {
            $view->with('queryProfileSummary', $profiler->summary());
        });
    }
}
