<?php

namespace App\Http\Controllers;

use App\Services\NaturalLanguageQueryService;
use App\Services\QueryPlaygroundService;
use App\Services\SqlTableNameExtractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class QueryPlaygroundController extends Controller {
    protected const SSE_HEARTBEAT_INTERVAL_SECONDS = 10;

    protected QueryPlaygroundService $playgroundService;

    public function __construct(QueryPlaygroundService $playgroundService) {
        $this->middleware('auth');
        $this->playgroundService = $playgroundService;
    }

    /**
     * Forbidden keywords (case-insensitive checking in logic).
     */
    protected $forbiddenKeywords = [
        'UPDATE', 'DELETE', 'INSERT', 'ALTER', 'DROP', 'TRUNCATE',
        'CREATE', 'GRANT', 'REVOKE', 'REPLACE', 'LOCK', 'UNLOCK',
        'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'SET', 'EXECUTE', 'CALL',
        'SHOW', 'DESCRIBE', 'USE', 'EXPLAIN',
    ];

    public function index(Request $request) {
        if (!Auth::user()->isActive()) {
            abort(403, '您的帳號尚未啟用，無法使用此功能。');
        }

        return redirect()->route('app.query-playground.index', $request->query());
    }

    /**
     * Inertia + React 版本的 Query Playground 主頁。
     */
    public function appIndex(Request $request): InertiaResponse {
        if (!Auth::user()->isActive()) {
            abort(403, '您的帳號尚未啟用，無法使用此功能。');
        }

        $props = $this->playgroundService->buildPageProps(
            $request->input('sql')
        );

        return Inertia::render('QueryPlayground/Index', $props);
    }

    public function qbeSchema(Request $request) {
        if (!Auth::user()->isActive()) {
            return response()->json(['error' => '您的帳號尚未啟用，無法使用此功能。'], 403);
        }

        $request->validate([
            'tables' => 'nullable|array|max:20',
            'tables.*' => 'string|max:128',
        ]);

        $requestedTables = $request->input('tables', []);
        if (empty($requestedTables)) {
            $requestedTables = array_keys(config('codes.tables', []));
        }

        $tableSchemas = $this->playgroundService->getTableSchemas($requestedTables);

        return response()->json([
            'tables' => $tableSchemas,
        ]);
    }

    public function run(Request $request) {
        if (!Auth::user()->isActive()) {
            return response()->json(['error' => '您的帳號尚未啟用，無法使用此功能。'], 403);
        }

        $request->validate([
            'sql' => 'required|string|max:5000',
            'page' => 'nullable|integer|min:1',
        ]);

        $sql = trim($request->input('sql'));

        // Remove trailing semicolons to allow "SELECT ...;"
        $sql = rtrim($sql, "; \t\n\r\0\x0B");

        // Check for multiple statements (semicolon inside query)
        if (strpos($sql, ';') !== false) {
            return response()->json([
               'error' => "Forbidden character detected: ';'. Multiple statements are not allowed.",
            ], 403);
        }

        $page = (int) $request->input('page', 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        // 1. Basic security check: forbidden keywords
        foreach ($this->forbiddenKeywords as $keyword) {
            // Use word boundary to avoid false positives (e.g., "USE" inside "USERS")
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $sql)) {
                return response()->json([
                    'error' => "Forbidden keyword detected: $keyword",
                ], 403);
            }
        }

        // 2. Table whitelist check
        $allowedTables = array_keys(config('codes.tables', []));
        // Add internal allowed tables if not in codes.tables but deemed safe?
        // For now strict adherence to codes.tables as requested.

        $tablesInQuery = app(SqlTableNameExtractor::class)->extractTableNames($sql);

        if (empty($tablesInQuery)) {
            return response()->json([
               'error' => "Could not detect any table names. Please ensure your query is standard SQL.",
            ], 400);
        }

        foreach ($tablesInQuery as $table) {
            // Case-insensitive check against allowed list
            $found = false;
            foreach ($allowedTables as $allowed) {
                if (strcasecmp($allowed, $table) === 0) {
                    $found = true;

                    break;
                }
            }
            if (!$found) {
                return response()->json([
                    'error' => "Table '$table' is not allowed. (Not in codes whitelist)",
                ], 403);
            }
        }

        // 3. Execution with pagination limit
        // Wrap user query: SELECT * FROM (UserSQL) as T LIMIT 21 OFFSET X
        // Fetch 21 rows to know if there is a next page.

        // Remove trailing semicolon if strictly at end (though regex might catch it)
        $sql = rtrim($sql, ';');

        $wrappedSql = "SELECT * FROM ($sql) AS subquery_wrapper LIMIT " . ($perPage + 1) . " OFFSET $offset";

        try {
            $results = DB::select($wrappedSql);

            $hasMore = count($results) > $perPage;
            if ($hasMore) {
                array_pop($results); // Remove the 21st item
            }

            $columns = [];
            if (!empty($results)) {
                $columns = array_keys((array) $results[0]);
            }

            return response()->json([
                'data' => $results,
                'columns' => $columns,
                'page' => $page,
                'has_more' => $hasMore,
                'usage_note' => 'Results are capped at 20 per page for performance.',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Database Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 建立標準 SSE 回應，提供事件發送、heartbeat 與斷線檢查。
     *
     * @param callable(callable(string, mixed): void, callable(): bool, callable(): bool): void $producer
     */
    protected function streamSseResponse(callable $producer) {
        return response()->stream(function () use ($producer) {
            set_time_limit(300);  // SSE 串流需要較長時間（多輪工具呼叫 + LLM 回應）
            ignore_user_abort(true);

            $lastSentAt = microtime(true);
            $heartbeatInterval = max(0, (int) config('query_playground.sse_heartbeat_seconds', self::SSE_HEARTBEAT_INTERVAL_SECONDS));
            $clientDisconnected = false;

            $isClientDisconnected = function () use (&$clientDisconnected) {
                if ($clientDisconnected) {
                    return true;
                }

                $clientDisconnected = connection_aborted() !== 0;

                return $clientDisconnected;
            };

            $flush = function () use (&$clientDisconnected) {
                if (ob_get_level() > 0) {
                    @ob_flush();
                }

                flush();
                $clientDisconnected = connection_aborted() !== 0;
            };

            $sendComment = function (string $comment) use (&$lastSentAt, $flush, $isClientDisconnected) {
                if ($isClientDisconnected()) {
                    return false;
                }

                echo ': ' . $comment . "\n\n";
                $lastSentAt = microtime(true);
                $flush();

                return !$isClientDisconnected();
            };

            $sendEvent = function (string $event, $data) use (&$lastSentAt, $flush, $isClientDisconnected) {
                if ($isClientDisconnected()) {
                    return false;
                }

                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                $lastSentAt = microtime(true);
                $flush();

                return !$isClientDisconnected();
            };

            $sendHeartbeatIfNeeded = function () use (&$lastSentAt, $heartbeatInterval, $sendComment, $isClientDisconnected) {
                if ($isClientDisconnected()) {
                    return false;
                }

                if ((microtime(true) - $lastSentAt) >= $heartbeatInterval) {
                    return $sendComment('keep-alive');
                }

                return true;
            };

            // 先送出一個 padding comment，降低代理 / 瀏覽器對小回應的緩衝影響。
            if (!$sendComment(str_repeat(' ', (int) config('query_playground.sse_padding_bytes', 2048)))) {
                return;
            }

            $producer(
                function (string $event, $data) use ($sendEvent, $sendHeartbeatIfNeeded, $isClientDisconnected) {
                    if (!$sendHeartbeatIfNeeded() || $isClientDisconnected()) {
                        return;
                    }

                    $sendEvent($event, $data);
                },
                $sendHeartbeatIfNeeded,
                $isClientDisconnected
            );
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * 使用自然语言生成 SQL 查询（SSE 流式响应）
     *
     * @param Request $request
     * @param NaturalLanguageQueryService $nlqService
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function generateFromNLStream(Request $request, NaturalLanguageQueryService $nlqService) {
        if (!Auth::user()->isActive()) {
            return response()->json(['error' => '您的帳號尚未啟用，無法使用此功能。'], 403);
        }

        $request->validate([
            'question' => 'required|string|max:1000',
            'tables' => 'nullable|array',
            'tables.*' => 'string',
            'use_tools' => 'nullable|boolean',
        ]);

        $question = $request->input('question');
        $tables = $request->input('tables');

        $useTools = $request->boolean('use_tools', true);

        return $this->streamSseResponse(function (callable $sendEvent, callable $sendHeartbeatIfNeeded, callable $isClientDisconnected) use ($question, $tables, $nlqService, $useTools) {
            try {
                // 调用服务并传入进度回调
                $result = $nlqService->generateSQL($question, $tables, $sendEvent, $useTools, $sendHeartbeatIfNeeded, $isClientDisconnected);

                // 发送最终结果
                if ($result['success']) {
                    $sendEvent('complete', [
                        'success' => true,
                        'sql' => $result['sql'],
                        'explanation' => $result['explanation'],
                        'model' => $result['model'] ?? null,
                        'tool_calls' => $result['tool_calls'] ?? null,
                    ]);
                } else {
                    $sendEvent('error', [
                        'success' => false,
                        'error' => $result['error'],
                    ]);
                }
            } catch (\Exception $e) {
                $sendEvent('error', [
                    'success' => false,
                    'error' => '生成 SQL 时发生错误: ' . $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * 使用自然语言生成 SQL 查询（兼容旧版，非流式）
     *
     * @param Request $request
     * @param NaturalLanguageQueryService $nlqService
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateFromNL(Request $request, NaturalLanguageQueryService $nlqService) {
        if (!Auth::user()->isActive()) {
            return response()->json(['error' => '您的帳號尚未啟用，無法使用此功能。'], 403);
        }

        $request->validate([
            'question' => 'required|string|max:1000',
            'tables' => 'nullable|array',
            'tables.*' => 'string',
            'use_tools' => 'nullable|boolean',
        ]);

        $question = $request->input('question');
        $tables = $request->input('tables');

        $useTools = $request->boolean('use_tools', true);
        $result = $nlqService->generateSQL($question, $tables, null, $useTools);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'sql' => $result['sql'],
            'explanation' => $result['explanation'],
            'model' => $result['model'] ?? null,
            'tool_calls' => $result['tool_calls'] ?? null,
        ]);
    }

    /**
     * 使用自然語言回答歷史人物問題（非流式）
     */
    public function answerFromNL(Request $request, NaturalLanguageQueryService $nlqService) {
        if (!Auth::user()->isActive()) {
            return response()->json(['error' => '您的帳號尚未啟用，無法使用此功能。'], 403);
        }

        $request->validate([
            'question' => 'required|string|max:1000',
            'tables' => 'nullable|array',
            'tables.*' => 'string',
            'use_tools' => 'nullable|boolean',
        ]);

        $question = $request->input('question');
        $tables = $request->input('tables');
        $useTools = $request->boolean('use_tools', true);

        $result = $nlqService->answerQuestion($question, $tables, null, $useTools);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 400);
        }

        return response()->json($result);
    }

    /**
     * 使用自然語言回答歷史人物問題（SSE 流式）
     */
    public function answerFromNLStream(Request $request, NaturalLanguageQueryService $nlqService) {
        if (!Auth::user()->isActive()) {
            return response()->json(['error' => '您的帳號尚未啟用，無法使用此功能。'], 403);
        }

        $request->validate([
            'question' => 'required|string|max:1000',
            'tables' => 'nullable|array',
            'tables.*' => 'string',
            'use_tools' => 'nullable|boolean',
        ]);

        $question = $request->input('question');
        $tables = $request->input('tables');
        $useTools = $request->boolean('use_tools', true);

        return $this->streamSseResponse(function (callable $sendEvent, callable $sendHeartbeatIfNeeded, callable $isClientDisconnected) use ($question, $tables, $nlqService, $useTools) {
            try {
                $result = $nlqService->answerQuestion($question, $tables, $sendEvent, $useTools, $sendHeartbeatIfNeeded, $isClientDisconnected);

                if ($result['success']) {
                    $sendEvent('complete', [
                        'success' => true,
                        'answer_markdown' => $result['answer_markdown'],
                        'summary' => $result['summary'] ?? '',
                        'sql_used' => $result['sql_used'] ?? [],
                        'tool_calls' => $result['tool_calls'] ?? [],
                        'evidence' => $result['evidence'] ?? [],
                        'caveat' => $result['caveat'] ?? '',
                        'model' => $result['model'] ?? null,
                    ]);
                } else {
                    $sendEvent('error', [
                        'success' => false,
                        'error' => $result['error'],
                    ]);
                }
            } catch (\Exception $e) {
                $sendEvent('error', [
                    'success' => false,
                    'error' => '問答生成時發生錯誤: ' . $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * 顯示自然語言查詢日誌（僅管理員）
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function nlQueryLogs(Request $request) {
        if (!Auth::user()->isSuperAdmin()) {
            abort(403, 'Unauthorized. Super admin access required.');
        }

        $query = DB::table('nl_query_logs')
            ->leftJoin('users', 'nl_query_logs.user_id', '=', 'users.id')
            ->select(
                'nl_query_logs.*',
                'users.name as user_name',
                'users.email as user_email'
            );

        // 搜尋過濾
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('nl_query_logs.question', 'like', "%{$search}%")
                    ->orWhere('nl_query_logs.generated_sql', 'like', "%{$search}%")
                    ->orWhere('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        // 成功/失敗過濾
        if ($request->filled('success')) {
            $query->where('nl_query_logs.success', $request->input('success'));
        }

        // 用戶過濾
        if ($request->filled('user_id')) {
            $query->where('nl_query_logs.user_id', $request->input('user_id'));
        }

        // 排序
        $query->orderBy('nl_query_logs.created_at', 'desc');

        // 分頁
        $logs = $query->paginate(20)->withQueryString();

        // 獲取所有用戶列表用於篩選
        $users = DB::table('users')
            ->whereIn('id', function ($query) {
                $query->select('user_id')
                    ->from('nl_query_logs')
                    ->whereNotNull('user_id')
                    ->distinct();
            })
            ->orderBy('name')
            ->get();

        return view('query_playground.nl_query_logs', [
            'page_title' => '自然語言查詢日誌',
            'page_title_key' => 'NL Query Logs',
            'page_url' => route('query-playground.nl-query-logs'),
            'logs' => $logs,
            'users' => $users,
            'filters' => [
                'search' => $request->input('search'),
                'success' => $request->input('success'),
                'user_id' => $request->input('user_id'),
            ],
        ]);
    }
}
