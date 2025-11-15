<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class CbdbTableMaintenanceController extends Controller
{
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

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            if (!Auth::user() || Auth::user()->is_admin != 1 || Auth::user()->is_active != 1) {
                abort(403, '此功能僅限活躍管理員使用');
            }
            return $next($request);
        });
    }

    public function index(Request $request)
    {
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

    public function rebuild(Request $request)
    {
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

        try {
            // 構建 Artisan::call() 參數（關聯數組格式）
            $params = [];

            if ($truncate) {
                $params['--truncate'] = true;
            }

            if ($idFrom && is_numeric($idFrom)) {
                $params['--id-from'] = (int)$idFrom;
            }

            if ($idTo && is_numeric($idTo)) {
                $params['--id-to'] = (int)$idTo;
            }

            $paramsLog = json_encode($params);
            Log::info("開始執行命令：{$command}，參數：{$paramsLog}");

            // 使用 Artisan::call 執行命令
            $exitCode = Artisan::call($command, $params);
            $outputStr = Artisan::output();

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
                    'message' => "重建完成！資料表現有 " . number_format($count) . " 筆記錄"
                ]);
            } else {
                throw new \Exception('Artisan 命令執行失敗，退出代碼：' . $exitCode);
            }

        } catch (\Exception $e) {
            Log::error('CBDB table maintenance rebuild error', [
                'table' => $tableName,
                'command' => $command,
                'params' => isset($params) ? $params : [],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '重建失敗：' . $e->getMessage()
            ], 500);
        }
    }

    protected function logOperation($operation, $tableName, $count = null, $additionalData = [])
    {
        $logData = [
            'operation' => $operation,
            'table_name' => $tableName,
            'user_id' => Auth::id(),
            'user_name' => Auth::user()->name,
            'timestamp' => Carbon::now()->toDateTimeString(),
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
}
