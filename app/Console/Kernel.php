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
        Commands\WikiTaskManager::class,
        \App\Console\Commands\ImportTradSimpMap::class,
        \App\Console\Commands\RebuildNameSearchIndex::class,
        \App\Console\Commands\ExportMysqlToSqlite::class,
        \App\Console\Commands\RegenerateAddresses::class,
        \App\Console\Commands\ManageUser::class,
        \App\Console\Commands\GenerateSchemaDocs::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule) {
        // $schedule->command('inspire')
        //          ->hourly();
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
