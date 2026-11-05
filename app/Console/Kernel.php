<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel {
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\SyncOpenccTradSimpSource::class,
        \App\Console\Commands\RebuildNameSearchIndex::class,
        \App\Console\Commands\ExportMysqlToSqlite::class,
        \App\Console\Commands\AssertSqliteReleaseScope::class,
        \App\Console\Commands\RegenerateAddresses::class,
        \App\Console\Commands\ManageUser::class,
        \App\Console\Commands\GenerateSchemaDocs::class,
        \App\Console\Commands\RebuildIndexYear::class,
        \App\Console\Commands\RebuildIndexAddress::class,
        \App\Console\Commands\FetchChgisMap::class,
        \App\Console\Commands\RebuildPersonChangeIndex::class,
        \App\Console\Commands\MigratePinyinV::class,
        \App\Console\Commands\MigrateCodeTablePinyinV::class,
        \App\Console\Commands\NormalizeAddrCoords::class,
        \App\Console\Commands\OidcCheck::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule) {
        // OIDC Provider 的 token 清理（docs/OIDC_PROVIDER_PLAN.md §8.1）。
        // 總開關關閉時不排程（表可能根本沒資料）；--expired --hours=24：只刪「過期滿一天」的列；不加 --expired 的話 purge 會無條件刪掉所有已撤銷的列（--hours 只管過期那一支）。撤銷的列因此保留到過期後一天。
        // 需要 `* * * * * php artisan schedule:run` 的 cron 才會生效；prod 目前沒有，沒有也無妨——週六的
        // scripts/weekly-sqlite-sync-and-oidc-purge.sh 已經順便跑同一條清理。這裡是備援。
        $schedule->command('passport:purge --expired --hours=24')
            ->daily()
            ->at('04:15')
            ->onOneServer()
            ->withoutOverlapping()
            ->when(fn () => (bool) config('oidc.enabled'));
    }

    /**
     * Register the Closure based commands for the application.
     *
     * @return void
     */
    protected function commands() {
        require base_path('routes/console.php');
    }
}
