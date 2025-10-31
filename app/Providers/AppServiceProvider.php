<?php

namespace App\Providers;

use App\Services\QueryProfile;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(QueryProfile::class, function () {
            return new QueryProfile();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $profiler = $this->app->make(QueryProfile::class);

        DB::listen(function (QueryExecuted $query) use ($profiler) {
            $profiler->add($query);
        });

        View::composer('*', function ($view) use ($profiler) {
            $view->with('queryProfileSummary', $profiler->summary());
        });
    }
}
