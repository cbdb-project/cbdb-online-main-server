<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class WikiMaintenanceController extends Controller
{
    protected $targetSourceIds = [60795, 68942, 68943];
    protected $sourceNames = [
        60795 => '中文維基百科 (Wikipedia)',
        68942 => '維基數據 (Wikidata)',
        68943 => '英文維基百科 (Wikipedia)'
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
        $sourceId = $request->input('source_id', $this->targetSourceIds[0]);

        // 验证 source_id 是否在允许的范围内
        if (!in_array((int) $sourceId, $this->targetSourceIds)) {
            $sourceId = $this->targetSourceIds[0];
        }

        $page = (int) $request->input('page', 1);
        $perPage = 10;

        // 查询指定 source_id 的记录
        $query = DB::table('BIOG_SOURCE_DATA')
            ->where('c_textid', $sourceId)
            ->orderBy('c_personid');

        $total = $query->count();
        $records = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        // 获取统计信息
        $stats = [];
        foreach ($this->targetSourceIds as $id) {
            $stats[$id] = DB::table('BIOG_SOURCE_DATA')
                ->where('c_textid', $id)
                ->count();
        }

        return view('admin.wiki-maintenance', [
            'page_title' => 'Wiki 對照資料維護',
            'page_description' => '管理 BIOG_SOURCE_DATA 中的 Wiki 對照資料',
            'page_url' => route('admin.wiki-maintenance'),
            'records' => $records,
            'currentSourceId' => $sourceId,
            'targetSourceIds' => $this->targetSourceIds,
            'sourceNames' => $this->sourceNames,
            'stats' => $stats,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'hasNext' => $total > $page * $perPage,
            'hasPrev' => $page > 1
        ]);
    }

    public function deleteAll(Request $request)
    {
        $sourceId = (int) $request->input('source_id');

        if (!in_array($sourceId, $this->targetSourceIds)) {
            return redirect()->back()->with('error', '無效的文本 ID');
        }

        try {
            DB::beginTransaction();

            $deletedCount = DB::table('BIOG_SOURCE_DATA')
                ->where('c_textid', $sourceId)
                ->delete();

            // 記錄操作日誌
            $this->logOperation('delete_all', $sourceId, $deletedCount);

            DB::commit();

            $sourceName = $this->sourceNames[$sourceId] ?? "文本 ID {$sourceId}";
            return redirect()->back()->with('success', "成功刪除「{$sourceName}」的 {$deletedCount} 筆記錄");

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', '刪除失敗：' . $e->getMessage());
        }
    }

    public function reimport(Request $request)
    {
        $sourceId = (int) $request->input('source_id');

        if (!in_array($sourceId, $this->targetSourceIds)) {
            return redirect()->back()->with('error', '無效的文本 ID');
        }

        // Placeholder 功能
        $sourceName = $this->sourceNames[$sourceId] ?? "文本 ID {$sourceId}";
        return redirect()->back()->with('info', "「{$sourceName}」的重新導入功能尚未實現");
    }

    public function importFromUrl(Request $request)
    {
        // 驗證輸入
        $request->validate([
            'import_url' => 'required|string',
            'target_source' => 'required|integer|in:' . implode(',', $this->targetSourceIds)
        ]);

        $url = $request->input('import_url');

        // 自定義URL驗證，避免Laravel 5.5的URL正則表達式問題
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//', $url)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'import_url' => ['請輸入有效的HTTPS網址']
                ]
            ], 422);
        }
        $targetSourceId = (int) $request->input('target_source');
        $sourceName = $this->sourceNames[$targetSourceId];

        // 生成唯一的任務 ID
        $taskId = 'import_' . time() . '_' . $targetSourceId;

        // 初始化進度跟踪
        $this->initializeProgress($taskId, $sourceName);

        // 記錄開始操作
        $this->logOperation('import_url_start', $targetSourceId, 0, ['url' => $url, 'task_id' => $taskId]);

        // 立即返回響應
        $response = response()->json([
            'success' => true,
            'message' => '導入任務已開始',
            'task_id' => $taskId
        ]);

        // 註冊關閉時執行的函數
        register_shutdown_function(function() use ($taskId, $url, $targetSourceId, $sourceName) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $this->executeImportTask($taskId, $url, $targetSourceId, $sourceName);
        });

        return $response;
    }

    public function cancelImport(Request $request, $taskId)
    {
        // 设置取消标志
        $this->updateProgress($taskId, 0, '用戶已取消導入任務', 'cancelled');

        return response()->json([
            'success' => true,
            'message' => '導入任務已取消'
        ]);
    }

    public function executeImportTask($taskId, $url, $targetSourceId, $sourceName)
    {
        // 移除執行時間限制
        set_time_limit(0);
        ini_set('memory_limit', '1024M'); // 增加到1GB以處理大型導入

        try {
            // 检查是否已被取消
            if ($this->isTaskCancelled($taskId)) {
                return;
            }

            // 更新進度：開始下載
            $this->updateProgress($taskId, 5, '正在下載資料檔案...');

            // 下載資料
            $jsonData = $this->downloadAndDecompress($url);

            // 检查是否已被取消
            if ($this->isTaskCancelled($taskId)) {
                return;
            }

            // 更新進度：解析資料
            $this->updateProgress($taskId, 15, '正在解析 JSON 資料...');

            // 解析 JSON
            $data = json_decode($jsonData, true);
            $jsonError = json_last_error();

            if ($data === null || $jsonError !== JSON_ERROR_NONE) {
                $errorMessage = $this->getJsonErrorMessage($jsonError);
                throw new \Exception("無法解析 JSON 資料：{$errorMessage}");
            }

            // 驗證資料格式
            if (!isset($data['records'])) {
                throw new \Exception('JSON 格式無效：缺少必要的 "records" 欄位。請確認這是正確的 Wiki 對照資料檔案');
            }

            if (!is_array($data['records'])) {
                throw new \Exception('JSON 格式無效："records" 欄位應該是一個陣列');
            }

            if (empty($data['records'])) {
                throw new \Exception('資料檔案中沒有找到任何記錄，請確認檔案內容是否正確');
            }

            $records = $data['records'];
            $totalRecords = count($records);
            $importedCount = 0;

            // 更新進度：準備導入
            $this->updateProgress($taskId, 20, "準備導入 {$totalRecords} 筆記錄...");

            // 先在單獨事務中刪除舊資料
            DB::beginTransaction();
            try {
                $deletedCount = DB::table('BIOG_SOURCE_DATA')
                    ->where('c_textid', $targetSourceId)
                    ->delete();
                DB::commit();
                $this->updateProgress($taskId, 30, "已清空舊資料 ({$deletedCount} 筆)，開始導入新資料...");
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            try {
                // 性能優化：分段預加載有效的 person IDs 以節省記憶體
                $this->updateProgress($taskId, 35, "正在分段預加載有效人物ID清單...");

                // 分段提取 person IDs 避免記憶體過載
                $allPersonIds = [];
                $recordCount = count($records);
                $segmentSize = 50000; // 每次處理5萬條記錄

                for ($i = 0; $i < $recordCount; $i += $segmentSize) {
                    $segment = array_slice($records, $i, $segmentSize);
                    foreach ($segment as $record) {
                        $recordData = $this->prepareRecordData($record, $targetSourceId, $taskId);
                        if ($recordData && isset($recordData['c_personid'])) {
                            $allPersonIds[] = $recordData['c_personid'];
                        }
                    }

                    // 每處理一段後釋放記憶體
                    unset($segment);
                    if ($i % 100000 == 0) {
                        gc_collect_cycles();
                        if (function_exists('gc_mem_caches')) {
                            gc_mem_caches();
                        }
                    }
                }

                // 批量查詢存在的 person IDs（分批處理避免SQL語句過長）
                $validPersonIds = [];
                $chunkSize = 3000; // 減小查詢批次大小
                $uniquePersonIds = array_unique($allPersonIds);
                unset($allPersonIds); // 釋放原始陣列記憶體

                $personIdChunks = array_chunk($uniquePersonIds, $chunkSize);
                unset($uniquePersonIds); // 釋放記憶體

                foreach ($personIdChunks as $chunk) {
                    $existingIds = DB::table('BIOG_MAIN')
                        ->whereIn('c_personid', $chunk)
                        ->pluck('c_personid')
                        ->toArray();
                    $validPersonIds = array_merge($validPersonIds, $existingIds);
                }

                // 轉為集合以提高查找效率
                $validPersonIdsSet = array_flip($validPersonIds);
                unset($validPersonIds); // 釋放陣列記憶體

                // 強制垃圾回收以釋放預加載階段的記憶體
                gc_collect_cycles();
                if (function_exists('gc_mem_caches')) {
                    gc_mem_caches(); // PHP 7.0+ 清理內存緩存
                }

                $memoryUsage = round(memory_get_usage(true) / 1024 / 1024, 2);
                $this->updateProgress($taskId, 40, "預加載完成，找到 " . count($validPersonIdsSet) . " 個有效人物ID，記憶體使用: {$memoryUsage}MB，開始處理記錄...");

                // 準備批次導入的資料
                $batchData = [];
                $batchSize = 500; // 減小批次大小避免內存問題
                $transactionSize = 5000; // 每5000條記錄提交一次事務
                $processedCount = 0;
                $skippedCount = 0;

                // 開始第一個事務
                DB::beginTransaction();

                foreach ($records as $record) {
                    // 每处理 100 条记录检查一次是否取消
                    if ($processedCount % 100 == 0 && $this->isTaskCancelled($taskId)) {
                        DB::rollBack();
                        return;
                    }

                    $recordData = $this->prepareRecordData($record, $targetSourceId, $taskId);
                    if ($recordData) {
                        // 使用預加載的集合檢查 c_personid 是否存在（性能優化）
                        if (isset($validPersonIdsSet[$recordData['c_personid']])) {
                            $batchData[] = $recordData;
                            $importedCount++;
                        } else {
                            $skippedCount++;
                        }
                    }

                    $processedCount++;

                    // 當達到批次大小時，執行批次插入
                    if (count($batchData) >= $batchSize) {
                        // 在插入前再次检查是否取消
                        if ($this->isTaskCancelled($taskId)) {
                            DB::rollBack();
                            return;
                        }

                        DB::table('BIOG_SOURCE_DATA')->insert($batchData);
                        $batchData = [];

                        // 分批提交事務以避免長時間鎖定
                        if ($importedCount % $transactionSize == 0) {
                            DB::commit();

                            // 在每次事務提交後強制垃圾回收
                            gc_collect_cycles();
                            if (function_exists('gc_mem_caches')) {
                                gc_mem_caches();
                            }

                            $memoryUsage = round(memory_get_usage(true) / 1024 / 1024, 2);
                            $this->updateProgress($taskId, 40 + (($processedCount / $totalRecords) * 50), "已提交 {$importedCount} 筆記錄的事務，記憶體: {$memoryUsage}MB...");
                            DB::beginTransaction(); // 開始新事務
                        }

                        // 每1000條記錄強制垃圾回收
                        if ($importedCount % 1000 == 0) {
                            gc_collect_cycles();
                        }

                        // 更新進度 (40% - 90% 為導入階段)
                        $progress = 40 + (($processedCount / $totalRecords) * 50);
                        $memoryUsage = round(memory_get_usage(true) / 1024 / 1024, 2);
                        $this->updateProgress($taskId, $progress, "已處理 {$processedCount}/{$totalRecords} 筆記錄（已導入 {$importedCount} 筆，跳過 {$skippedCount} 筆）記憶體使用: {$memoryUsage}MB...");
                    }
                }

                // 插入剩餘的資料並提交最後的事務
                if (!empty($batchData)) {
                    $this->updateProgress($taskId, 92, "正在插入最後 " . count($batchData) . " 筆剩餘資料...");
                    DB::table('BIOG_SOURCE_DATA')->insert($batchData);
                    $batchData = []; // 釋放記憶體
                    gc_collect_cycles(); // 清理記憶體
                }

                $memoryUsage = round(memory_get_usage(true) / 1024 / 1024, 2);
                $this->updateProgress($taskId, 95, "資料插入完成，記憶體使用: {$memoryUsage}MB，正在提交最後的事務...");

                // 提交最後的事務
                DB::commit();

                // 強制清理記憶體
                unset($validPersonIdsSet);
                unset($records); // 釋放原始JSON數據
                gc_collect_cycles();
                if (function_exists('gc_mem_caches')) {
                    gc_mem_caches();
                }

                // 清理所有可能的變量引用
                if (isset($batchData)) unset($batchData);
                if (isset($allPersonIds)) unset($allPersonIds);
                if (isset($personIdChunks)) unset($personIdChunks);

                // 最後一次強制垃圾回收
                gc_collect_cycles();

                // 完成進度
                $this->updateProgress($taskId, 100, "導入完成！成功導入 {$importedCount} 筆記錄，跳過 {$skippedCount} 筆（人物ID在CBDB中不存在）", 'completed');

                // 記錄成功操作
                $this->logOperation('import_url_success', $targetSourceId, $importedCount, [
                    'url' => $url,
                    'task_id' => $taskId,
                    'deleted_count' => $deletedCount,
                    'imported_count' => $importedCount,
                    'skipped_count' => $skippedCount
                ]);

            } catch (\Exception $e) {
                // 如果在分批事務中發生錯誤，回滾當前事務
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                throw $e;
            }

        } catch (\Exception $e) {
            // 更新進度為錯誤狀態
            $this->updateProgress($taskId, 0, '導入失敗：' . $e->getMessage(), 'error');

            // 記錄錯誤
            $this->logOperation('import_url_error', $targetSourceId, 0, [
                'url' => $url,
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);

            Log::error('Wiki maintenance import error', [
                'url' => $url,
                'target_source' => $targetSourceId,
                'task_id' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    public function getImportProgress($taskId)
    {
        $cacheKey = "import_progress_{$taskId}";
        $progress = cache($cacheKey);

        if (!$progress) {
            return response()->json([
                'success' => false,
                'message' => '找不到指定的任務'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'progress' => $progress
        ]);
    }

    private function initializeProgress($taskId, $sourceName)
    {
        $cacheKey = "import_progress_{$taskId}";
        $progressData = [
            'task_id' => $taskId,
            'source_name' => $sourceName,
            'progress' => 0,
            'message' => '準備開始導入...',
            'status' => 'running',
            'started_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString()
        ];

        cache([$cacheKey => $progressData], now()->addHour()); // 缓存1小时
    }

    private function updateProgress($taskId, $progress, $message, $status = 'running')
    {
        $cacheKey = "import_progress_{$taskId}";
        $progressData = cache($cacheKey, []);

        $progressData['progress'] = $progress;
        $progressData['message'] = $message;
        $progressData['status'] = $status;
        $progressData['updated_at'] = Carbon::now()->toDateTimeString();

        if ($status === 'completed' || $status === 'error' || $status === 'cancelled') {
            $progressData['completed_at'] = Carbon::now()->toDateTimeString();
        }

        cache([$cacheKey => $progressData], now()->addHour());
    }

    private function isTaskCancelled($taskId)
    {
        $cacheKey = "import_progress_{$taskId}";
        $progressData = cache($cacheKey, []);
        return isset($progressData['status']) && $progressData['status'] === 'cancelled';
    }

    private function downloadAndDecompress($url)
    {
        $client = new Client([
            'timeout' => 0, // 無超時限制
            'verify' => false, // 在開發環境中可能需要
            'headers' => [
                'User-Agent' => 'CBDB-Wiki-Maintenance/1.0'
            ]
        ]);

        try {
            // 下載檔案
            $response = $client->get($url);
            $statusCode = $response->getStatusCode();

            // 檢查 HTTP 狀態碼並提供友好的錯誤信息
            if ($statusCode !== 200) {
                $errorMessage = $this->getHttpErrorMessage($statusCode);
                throw new \Exception($errorMessage);
            }

            $content = $response->getBody()->getContents();

            // 檢查檔案是否為空
            if (empty($content)) {
                throw new \Exception('下載的檔案沒有內容，請檢查 URL 是否正確或聯絡資料提供者');
            }

            // 檢查內容是否看起來像 HTML 錯誤頁面
            if ($this->looksLikeHtmlErrorPage($content)) {
                throw new \Exception('URL 返回的是網頁內容而不是數據檔案，請檢查 URL 是否正確');
            }

            // 檢查是否為 gzip 壓縮檔案
            if (substr($url, -3) === '.gz' || substr($url, -4) === '.gzip') {
                // 解壓縮 gzip 檔案
                $decompressed = gzdecode($content);
                if ($decompressed === false) {
                    throw new \Exception('無法解壓縮 gzip 檔案。可能原因：檔案損壞、不是有效的 gzip 格式，或檔案過大');
                }
                $content = $decompressed;
            }

            // 檢查解壓縮後的內容
            if (empty($content)) {
                throw new \Exception('解壓縮後的檔案為空，請檢查源檔案是否有效');
            }

            // 檢查內容是否可能是有效的 JSON
            if (!$this->looksLikeValidJson($content)) {
                throw new \Exception('下載的檔案不是有效的 JSON 格式，請確認 URL 指向正確的數據檔案');
            }

            return $content;

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // 4xx 錯誤 (客戶端錯誤)
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            $errorMessage = $this->getHttpErrorMessage($statusCode);
            throw new \Exception($errorMessage);
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            // 5xx 錯誤 (服務器錯誤)
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            throw new \Exception("服務器暫時無法處理請求 (HTTP {$statusCode})，請稍後再試或聯絡資料提供者");
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            throw new \Exception('無法連接到指定的 URL，請檢查：1) URL 是否正確 2) 網路連接是否正常 3) 目標服務器是否可用');
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            throw new \Exception('網路請求失敗，請檢查 URL 格式是否正確或稍後重試');
        }
    }

    private function getHttpErrorMessage($statusCode)
    {
        switch ($statusCode) {
            case 400:
                return 'URL 請求格式錯誤 (HTTP 400)，請檢查 URL 是否完整且正確';
            case 401:
                return '需要身份驗證才能存取此 URL (HTTP 401)，請確認 URL 是否為公開存取';
            case 403:
                return '沒有權限存取此 URL (HTTP 403)，請確認 URL 是否為公開存取或聯絡資料提供者';
            case 404:
                return 'URL 指向的檔案不存在 (HTTP 404)，請檢查：1) URL 拼寫是否正確 2) 檔案是否已移動或刪除 3) 聯絡資料提供者確認正確的 URL';
            case 429:
                return '請求過於頻繁 (HTTP 429)，請稍後再試';
            case 500:
                return '目標服務器內部錯誤 (HTTP 500)，請稍後重試或聯絡資料提供者';
            case 502:
                return '目標服務器網關錯誤 (HTTP 502)，請稍後重試';
            case 503:
                return '目標服務器暫時無法使用 (HTTP 503)，請稍後重試';
            case 504:
                return '目標服務器響應超時 (HTTP 504)，請稍後重試';
            default:
                return "HTTP 錯誤 {$statusCode}，請檢查 URL 或聯絡資料提供者";
        }
    }

    private function looksLikeHtmlErrorPage($content)
    {
        // 檢查內容是否看起來像 HTML 錯誤頁面
        $content = strtolower(trim($content));

        // 如果內容以 HTML 標籤開頭且包含常見錯誤關鍵詞，可能是錯誤頁面
        if (strpos($content, '<!doctype html') === 0 || strpos($content, '<html') === 0) {
            $errorKeywords = ['error', '404', '403', '500', 'not found', 'access denied', 'server error'];
            foreach ($errorKeywords as $keyword) {
                if (strpos($content, $keyword) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function looksLikeValidJson($content)
    {
        // 簡單檢查內容是否可能是 JSON
        $trimmed = trim($content);
        return (strpos($trimmed, '{') === 0 && strrpos($trimmed, '}') === strlen($trimmed) - 1) ||
               (strpos($trimmed, '[') === 0 && strrpos($trimmed, ']') === strlen($trimmed) - 1);
    }

    private function prepareRecordData($record, $targetSourceId, $taskId = null)
    {
        // 驗證記錄格式
        if (!isset($record['cbdb_personid']) || !isset($record['wikidata_qid'])) {
            return null;
        }

        $cbdbPersonId = (int) $record['cbdb_personid'];
        $wikidataQid = $record['wikidata_qid'];

        // 驗證 CBDB Person ID 必須為正整數
        if ($cbdbPersonId <= 0) {
            return null;
        }

        // 驗證 Wikidata QID 格式 (應該以 Q 開頭)
        if (empty($wikidataQid)) {
            return null;
        }

        // 安全的正則表達式驗證
        try {
            if (!preg_match('/^Q\d+$/', $wikidataQid)) {
                return null;
            }
        } catch (\Exception $e) {
            // 记录问题数据以便调试
            \Log::error("Regex error in WikiMaintenance", [
                'wikidataQid' => $wikidataQid,
                'cbdb_personid' => $cbdbPersonId,
                'error' => $e->getMessage()
            ]);
            return null;
        }

        // 根據目標來源決定導入的資料
        if ($targetSourceId == 68942) {
            // Wikidata - 導入 QID
            $sourceData = $wikidataQid;
        } elseif ($targetSourceId == 60795) {
            // 中文維基百科 - 導入中文條目標題
            if (empty($record['wikipedia']['zh'] ?? '')) {
                return null; // 沒有中文維基百科條目
            }
            $sourceData = $record['wikipedia']['zh'];
        } elseif ($targetSourceId == 68943) {
            // 英文維基百科 - 導入英文條目標題
            if (empty($record['wikipedia']['en'] ?? '')) {
                return null; // 沒有英文維基百科條目
            }
            $sourceData = $record['wikipedia']['en'];
        } else {
            return null;
        }

        // 準備插入資料
        $now = Carbon::now();
        $userName = Auth::user()->name;
        $taskInfo = $taskId ? " (任務ID: {$taskId})" : "";
        $importNote = "批次導入於 {$now->format('Y-m-d H:i:s')} 由 {$userName}{$taskInfo}";

        return [
            'c_personid' => $cbdbPersonId,
            'c_textid' => $targetSourceId,
            'c_pages' => $sourceData,
            'c_notes' => $importNote,
            'c_main_source' => null,
            'c_self_bio' => null,
        ];
    }

    private function getJsonErrorMessage($jsonError)
    {
        switch ($jsonError) {
            case JSON_ERROR_NONE:
                return '無錯誤';
            case JSON_ERROR_DEPTH:
                return 'JSON 資料結構太深，超過了最大深度限制';
            case JSON_ERROR_STATE_MISMATCH:
                return 'JSON 格式錯誤或資料結構不正確';
            case JSON_ERROR_CTRL_CHAR:
                return 'JSON 中包含無效的控制字元';
            case JSON_ERROR_SYNTAX:
                return 'JSON 語法錯誤，請檢查格式是否正確';
            case JSON_ERROR_UTF8:
                return 'JSON 包含無效的 UTF-8 字元';
            case JSON_ERROR_RECURSION:
                return 'JSON 資料包含遞歸引用';
            case JSON_ERROR_INF_OR_NAN:
                return 'JSON 包含無效的數值（無限大或非數字）';
            case JSON_ERROR_UNSUPPORTED_TYPE:
                return 'JSON 包含不支援的資料類型';
            case JSON_ERROR_INVALID_PROPERTY_NAME:
                return 'JSON 屬性名稱無效';
            case JSON_ERROR_UTF16:
                return 'JSON 包含無效的 UTF-16 字元';
            default:
                return "未知的 JSON 錯誤 (錯誤代碼: {$jsonError})";
        }
    }

    protected function logOperation($operation, $sourceId, $count = null, $additionalData = [])
    {
        $logData = [
            'operation' => $operation,
            'source_id' => $sourceId,
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

        // 可以根據需要將日誌寫入文件或資料庫
        \Log::info('Wiki Maintenance Operation', $logData);
    }
}
