<?php

namespace App\Http\Controllers;

use App\Services\NameFtsProgressService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CbdbTableMaintenanceController extends Controller {
    protected $tables = [
        'CBDB__TRAD_SIMP_MAP' => [
            'name' => 'CBDB__TRAD_SIMP_MAP',
            'name_chn' => '繁簡映射表',
            'description' => '儲存繁體字與簡體字的對照關係',
            'command' => 'cbdb:import-trad-simp-map',
            'icon' => 'language',
            'color' => 'blue',
        ],
        'CBDB__NAME_FTS' => [
            'name' => 'CBDB__NAME_FTS',
            'name_chn' => '姓名搜尋倒排索引',
            'description' => '儲存姓名後綴的倒排索引，用於快速姓名搜尋',
            'command' => 'cbdb:rebuild-name-search',
            'icon' => 'search',
            'color' => 'green',
        ],
    ];

    public function __construct() {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            if (!Auth::user() || !Auth::user()->canRunBatchImport()) {
                abort(403, '此功能僅限活躍管理員使用');
            }

            return $next($request);
        });
    }

    public function index(Request $request) {
        // 獲取各表的統計信息
        $stats = [];
        foreach ($this->tables as $tableName => $tableInfo) {
            if (Schema::hasTable($tableName)) {
                $stats[$tableName] = [
                    'exists' => true,
                    'count' => DB::table($tableName)->count(),
                ];
            } else {
                $stats[$tableName] = [
                    'exists' => false,
                    'count' => 0,
                ];
            }
        }

        return view('admin.cbdb-table-maintenance', [
            'page_title' => 'CBDB 內部表維護',
            'page_description' => '管理 CBDB 內部資料表（繁簡映射表、姓名搜尋索引等）',
            'page_url' => route('admin.cbdb-table-maintenance'),
            'tables' => $this->tables,
            'stats' => $stats,
        ]);
    }

    public function rebuild(Request $request) {
        $tableName = $request->input('table_name');

        if (!isset($this->tables[$tableName])) {
            return redirect()->back()->with('error', '無效的資料表名稱');
        }

        $tableInfo = $this->tables[$tableName];
        $command = $tableInfo['command'];

        // 處理 truncate 參數
        $truncate = $request->has('truncate') && $request->input('truncate') == '1';

        // 處理 id_from 和 id_to（僅針對 CBDB__NAME_FTS）
        $idFrom = null;
        $idTo = null;
        if ($tableName == 'CBDB__NAME_FTS') {
            $idFrom = $request->input('id_from');
            $idTo = $request->input('id_to');
        }

        // 移除執行時間限制
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        // 構建 Artisan::call() 參數（關聯數組格式）
        $params = [];

        if ($truncate) {
            $params['--truncate'] = true;
        }

        if ($idFrom && is_numeric($idFrom)) {
            $params['--id-from'] = (int) $idFrom;
        }

        if ($idTo && is_numeric($idTo)) {
            $params['--id-to'] = (int) $idTo;
        }

        if ($tableName === 'CBDB__NAME_FTS') {
            return $this->startNameFtsRebuildTask($command, $tableName, $params, (bool) $truncate, $idFrom, $idTo);
        }

        try {
            $paramsLog = json_encode($params);
            Log::info("開始執行命令：{$command}，參數：{$paramsLog}");

            // 使用自定義執行方法呼叫 Artisan 指令，避免 ArrayInput 數字索引觸發錯誤
            [$exitCode, $outputStr] = $this->runConsoleCommand($command, $params);

            Log::info("命令執行完成，退出代碼：{$exitCode}");
            Log::info("命令輸出：{$outputStr}");

            if ($exitCode === 0) {
                // 獲取最新的記錄數
                $count = 0;
                if (Schema::hasTable($tableName)) {
                    $count = DB::table($tableName)->count();
                }

                // 記錄成功操作
                $this->logOperation('rebuild_success', $tableName, $count, [
                    'command' => $command,
                    'params' => $params,
                    'truncate' => $truncate,
                    'id_from' => $idFrom,
                    'id_to' => $idTo,
                    'exit_code' => $exitCode,
                    'output' => $outputStr,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => "重建完成！資料表現有 " . number_format($count) . " 筆記錄",
                ]);
            }

            throw new \Exception('Artisan 命令執行失敗，退出代碼：' . $exitCode);
        } catch (\Exception $e) {
            Log::error('CBDB table maintenance rebuild error', [
                'table' => $tableName,
                'command' => $command,
                'params' => isset($params) ? $params : [],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => '重建失敗：' . $e->getMessage(),
            ], 500);
        }
    }

    public function getNameFtsProgress($taskId) {
        $data = NameFtsProgressService::get($taskId);

        if (!$data) {
            return response()->json([
                'success' => false,
                'message' => '找不到對應的重建任務',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'progress' => $data,
        ]);
    }

    protected function logOperation($operation, $tableName, $count = null, $additionalData = []) {
        $logData = [
            'operation' => $operation,
            'table_name' => $tableName,
            'user_id' => Auth::id(),
            'user_name' => Auth::user()->name,
            'timestamp' => Carbon::now()->format('Y-m-d H:i:s'),
        ];

        if ($count !== null) {
            $logData['affected_count'] = $count;
        }

        // 合併額外的資料
        if (!empty($additionalData)) {
            $logData = array_merge($logData, $additionalData);
        }

        Log::info('CBDB Table Maintenance Operation', $logData);
    }

    /**
     * 直接透過 Symfony Command 執行 Artisan 指令，確保輸入參數包含 command 名稱
     * 以避免 ArrayInput 在 PHP 8 上處理數字索引時的錯誤。
     *
     * @param  string  $command
     * @param  array   $params
     * @return array{int,string}
     */
    protected function runConsoleCommand(string $command, array $params = []): array {
        $artisanApplication = $this->resolveArtisanApplication();
        $symfonyCommand = $artisanApplication->find($command);

        $input = new ArrayInput($this->formatConsoleParameters($command, $params));
        $output = new BufferedOutput();

        $exitCode = $symfonyCommand->run($input, $output);

        return [$exitCode, $output->fetch()];
    }

    /**
     * 將表單輸入參數轉換成 Symfony ArrayInput 所需格式，強制指定 command 索引鍵。
     */
    protected function formatConsoleParameters(string $command, array $params = []): array {
        $formatted = ['command' => $command];

        foreach ($params as $key => $value) {
            $formatted[$key] = $value;
        }

        return $formatted;
    }

    protected function resolveArtisanApplication() {
        $kernel = Artisan::getFacadeRoot();

        if (method_exists($kernel, 'getArtisan')) {
            // Laravel 5.5: getArtisan() is protected; use reflection to call it
            $reflection = new ReflectionClass($kernel);
            $method = $reflection->getMethod('getArtisan');
            $method->setAccessible(true);

            return $method->invoke($kernel);
        }

        throw new \RuntimeException('Unable to resolve Artisan console application instance.');
    }

    protected function startNameFtsRebuildTask(string $command, string $tableName, array $params, bool $truncate, $idFrom, $idTo) {
        $taskId = 'cbdb_name_fts_' . time() . '_' . Auth::id();
        $params['--task-id'] = $taskId;
        $meta = [
            'command' => $command,
            'params' => $params,
            'truncate' => $truncate,
            'id_from' => $idFrom,
            'id_to' => $idTo,
            'table_name' => $tableName,
        ];

        NameFtsProgressService::initialize($taskId, $meta);

        $this->logOperation('rebuild_start', $tableName, 0, array_merge($meta, [
            'task_id' => $taskId,
        ]));

        register_shutdown_function(function () use ($taskId, $command, $tableName, $params, $truncate, $idFrom, $idTo) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $this->executeNameFtsRebuildTask($taskId, $command, $tableName, $params, $truncate, $idFrom, $idTo);
        });

        return response()->json([
            'success' => true,
            'task_id' => $taskId,
            'message' => '已啟動姓名索引重建，請透過進度條查看最新狀態。',
        ]);
    }

    protected function executeNameFtsRebuildTask(string $taskId, string $command, string $tableName, array $params, bool $truncate, $idFrom, $idTo) {
        try {
            NameFtsProgressService::update($taskId, 10, '正在準備執行 Artisan 指令…', 'running');
            [$exitCode, $outputStr] = $this->runConsoleCommand($command, $params);

            if ($exitCode !== 0) {
                throw new \Exception('Artisan 命令執行失敗，退出代碼：' . $exitCode);
            }

            NameFtsProgressService::update($taskId, 95, '指令已完成，正在統計結果…', 'running');

            $count = 0;
            if (Schema::hasTable($tableName)) {
                $count = DB::table($tableName)->count();
            }

            $this->logOperation('rebuild_success', $tableName, $count, [
                'command' => $command,
                'params' => $params,
                'truncate' => $truncate,
                'id_from' => $idFrom,
                'id_to' => $idTo,
                'exit_code' => 0,
                'output' => $outputStr,
                'task_id' => $taskId,
            ]);

            NameFtsProgressService::update($taskId, 100, "完成！目前共有 " . number_format($count) . " 筆索引記錄。", 'completed');
        } catch (\Exception $e) {
            NameFtsProgressService::update($taskId, 0, '重建失敗：' . $e->getMessage(), 'error');

            Log::error('CBDB name index rebuild error', [
                'table' => $tableName,
                'command' => $command,
                'params' => $params,
                'task_id' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
