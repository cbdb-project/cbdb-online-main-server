<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class WikiTaskManager extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wiki:task {action} {taskId?} {--force : Force cancel without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage Wiki import tasks (list, show, cancel)';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $action = $this->argument('action');
        $taskId = $this->argument('taskId');

        switch ($action) {
            case 'list':
                $this->listTasks();
                break;
            case 'show':
                if (!$taskId) {
                    $this->error('Task ID is required for show action');
                    return 1;
                }
                $this->showTask($taskId);
                break;
            case 'cancel':
                if (!$taskId) {
                    $this->error('Task ID is required for cancel action');
                    return 1;
                }
                $this->cancelTask($taskId);
                break;
            default:
                $this->error('Invalid action. Available actions: list, show, cancel');
                $this->line('Usage:');
                $this->line('  php artisan wiki:task list');
                $this->line('  php artisan wiki:task show <taskId>');
                $this->line('  php artisan wiki:task cancel <taskId>');
                return 1;
        }

        return 0;
    }

    private function listTasks()
    {
        $this->info('Scanning for Wiki import tasks...');

        // 获取所有导入进度的缓存键
        $cacheKeys = [];
        $prefix = 'import_progress_';

        // 简单的方法：检查一些可能的任务ID格式
        $timeRange = range(time() - 3600, time()); // 最近1小时
        $sourceIds = [60795, 68942, 68943];

        foreach ($timeRange as $timestamp) {
            foreach ($sourceIds as $sourceId) {
                $taskId = "import_{$timestamp}_{$sourceId}";
                $cacheKey = $prefix . $taskId;
                if (Cache::has($cacheKey)) {
                    $cacheKeys[] = $taskId;
                }
            }
        }

        if (empty($cacheKeys)) {
            $this->info('No active or recent Wiki import tasks found.');
            return;
        }

        $this->info('Found ' . count($cacheKeys) . ' task(s):');
        $this->line('');

        $headers = ['Task ID', 'Status', 'Progress', 'Message', 'Started At'];
        $rows = [];

        foreach ($cacheKeys as $taskId) {
            $progress = Cache::get($prefix . $taskId);
            $rows[] = [
                $taskId,
                $progress['status'] ?? 'unknown',
                ($progress['progress'] ?? 0) . '%',
                substr($progress['message'] ?? '', 0, 50) . (strlen($progress['message'] ?? '') > 50 ? '...' : ''),
                $progress['started_at'] ?? 'unknown'
            ];
        }

        $this->table($headers, $rows);
    }

    private function showTask($taskId)
    {
        $cacheKey = "import_progress_{$taskId}";
        $progress = Cache::get($cacheKey);

        if (!$progress) {
            $this->error("Task '{$taskId}' not found.");
            return;
        }

        $this->info("Task Details: {$taskId}");
        $this->line('');

        $this->line("<info>Status:</info> {$progress['status']}");
        $this->line("<info>Progress:</info> {$progress['progress']}%");
        $this->line("<info>Message:</info> {$progress['message']}");
        $this->line("<info>Source:</info> {$progress['source_name']}");
        $this->line("<info>Started At:</info> {$progress['started_at']}");
        $this->line("<info>Updated At:</info> {$progress['updated_at']}");

        if (isset($progress['completed_at'])) {
            $this->line("<info>Completed At:</info> {$progress['completed_at']}");
        }

        if ($progress['status'] === 'running') {
            $this->line('');
            $this->comment('This task is currently running. You can cancel it with:');
            $this->line("php artisan wiki:task cancel {$taskId}");
        }
    }

    private function cancelTask($taskId)
    {
        $cacheKey = "import_progress_{$taskId}";
        $progress = Cache::get($cacheKey);

        if (!$progress) {
            $this->error("Task '{$taskId}' not found.");
            return;
        }

        if ($progress['status'] !== 'running') {
            $this->warn("Task '{$taskId}' is not running (status: {$progress['status']}).");
            return;
        }

        if (!$this->option('force') && !$this->confirm("Are you sure you want to cancel task '{$taskId}'?")) {
            $this->info('Task cancellation aborted.');
            return;
        }

        // 设置取消状态
        $progress['status'] = 'cancelled';
        $progress['message'] = '管理員從命令行取消了導入任務';
        $progress['progress'] = 0;
        $progress['completed_at'] = Carbon::now()->toDateTimeString();
        $progress['updated_at'] = Carbon::now()->toDateTimeString();

        Cache::put($cacheKey, $progress, now()->addHour());

        $this->info("Task '{$taskId}' has been cancelled successfully.");
        $this->line('The import process will stop at the next checkpoint.');
    }
}
