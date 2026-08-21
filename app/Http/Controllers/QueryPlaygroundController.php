<?php

namespace App\Http\Controllers;

use App\Services\NaturalLanguageQueryService;
use App\Services\QueryPlaygroundService;
use App\Services\SqlTableNameExtractor;
use App\Support\ExecutionTimeLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
            ExecutionTimeLimit::extendTo(300);  // SSE 串流需要較長時間（多輪工具呼叫 + LLM 回應）
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

        $this->validateQaRequest($request);

        $question = $request->input('question');
        $tables = $request->input('tables');
        $useTools = $request->boolean('use_tools', true);
        $conversationHistory = $this->conversationHistoryFromRequest($request);

        $result = $nlqService->answerQuestion($question, $tables, useToolsOverride: $useTools, conversationHistory: $conversationHistory);

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

        $this->validateQaRequest($request);

        $question = $request->input('question');
        $tables = $request->input('tables');
        $useTools = $request->boolean('use_tools', true);
        $conversationHistory = $this->conversationHistoryFromRequest($request);

        return $this->streamSseResponse(function (callable $sendEvent, callable $sendHeartbeatIfNeeded, callable $isClientDisconnected) use ($question, $tables, $nlqService, $useTools, $conversationHistory) {
            try {
                $result = $nlqService->answerQuestion($question, $tables, $sendEvent, $useTools, $sendHeartbeatIfNeeded, $isClientDisconnected, $conversationHistory);

                if ($result['success']) {
                    $sendEvent('complete', [
                        'success' => true,
                        'answer_markdown' => $result['answer_markdown'],
                        'summary' => $result['summary'] ?? '',
                        'sql_used' => $result['sql_used'] ?? [],
                        'tool_calls' => $result['tool_calls'] ?? [],
                        'evidence' => $result['evidence'] ?? [],
                        'caveat' => $result['caveat'] ?? '',
                        'suggested_follow_ups' => $result['suggested_follow_ups'] ?? [],
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
     * QA 模式多輪追問（見 docs/QUERY_PLAYGROUND_QA_MULTITURN_PLAN.md）request 驗證：
     * 沿用既有 question/tables/use_tools 規則，新增選填的 conversation_history。
     * conversation_history 陣列筆數上限對應 qa_max_turns - 1（單一對話總輪數硬上限）。
     */
    protected function validateQaRequest(Request $request): void {
        $request->validate([
            'question' => 'required|string|max:1000',
            'tables' => 'nullable|array',
            'tables.*' => 'string',
            'use_tools' => 'nullable|boolean',
            'conversation_history' => 'nullable|array|max:' . max(0, (int) config('query_playground.qa_max_turns', 5) - 1),
            'conversation_history.*.question' => 'required_with:conversation_history|string|max:1000',
            'conversation_history.*.summary' => 'nullable|string|max:2000',
        ]);

        $this->assertConversationHistoryWithinCharLimit((array) $request->input('conversation_history', []));
    }

    /**
     * 輕量保險：conversation_history 全部 question/summary 加總字元數超過門檻視為異常
     * request（正常情況下 qa_max_turns - 1 筆不可能超過此值，除非繞過前端限制竄改）。
     * 於 controller validation 階段擋下、回 422，不讓 request 進入 service 層才失敗。
     */
    protected function assertConversationHistoryWithinCharLimit(array $conversationHistory): void {
        $totalChars = 0;
        foreach ($conversationHistory as $turn) {
            $totalChars += mb_strlen((string) ($turn['question'] ?? ''));
            $totalChars += mb_strlen((string) ($turn['summary'] ?? ''));
        }

        $limit = (int) config('query_playground.qa_history_char_limit', 6000);
        if ($totalChars > $limit) {
            throw ValidationException::withMessages([
                'conversation_history' => ['對話歷史內容過長，請開新對話。'],
            ]);
        }
    }

    /**
     * 將 request 的 conversation_history 正規化為 [['question'=>string,'summary'=>string], ...]，
     * 供 NaturalLanguageQueryService::answerQuestion() 的 $conversationHistory 參數使用。
     */
    protected function conversationHistoryFromRequest(Request $request): array {
        $conversationHistory = $request->input('conversation_history', []);
        if (!is_array($conversationHistory)) {
            return [];
        }

        return array_map(function ($turn) {
            return [
                'question' => (string) ($turn['question'] ?? ''),
                'summary' => (string) ($turn['summary'] ?? ''),
            ];
        }, $conversationHistory);
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

        $logs = $this->buildNlQueryLogsQuery($request)->paginate(20)->withQueryString();

        return view('query_playground.nl_query_logs', [
            'page_title' => '自然語言查詢日誌',
            'page_title_key' => 'NL Query Logs',
            'page_url' => route('query-playground.nl-query-logs'),
            'logs' => $logs,
            'users' => $this->nlQueryLogUsers(),
            'filters' => [
                'search' => $request->input('search'),
                'success' => $request->input('success'),
                'user_id' => $request->input('user_id'),
            ],
        ]);
    }

    /**
     * NL 查詢日誌列表（Inertia + React 版，僅 Super Admin）。
     */
    public function appNlQueryLogs(Request $request) {
        if (!Auth::user()->isSuperAdmin()) {
            abort(403, 'Unauthorized. Super admin access required.');
        }

        $paginator = $this->buildNlQueryLogsQuery($request)->paginate(20)->withQueryString();
        $rows = array_map(fn ($log) => $this->prepareNlLog($log), $paginator->items());

        return Inertia::render('Admin/NlQueryLogs/Index', [
            'logs' => [
                'data' => $rows,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
            'users' => $this->nlQueryLogUsers()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'filters' => [
                'search' => $request->input('search'),
                'success' => $request->input('success'),
                'user_id' => $request->input('user_id'),
            ],
            'playground_url' => route('app.query-playground.index', [], false),
            'page_translations' => [
                'query' => is_array($t = trans('query')) ? $t : [],
                'operations' => is_array($t = trans('operations')) ? $t : [],
            ],
        ]);
    }

    /** 建立 NL 查詢日誌查詢（join users + 套用 search/success/user_id 篩選 + 排序）。 */
    protected function buildNlQueryLogsQuery(Request $request) {
        $query = DB::table('nl_query_logs')
            ->leftJoin('users', 'nl_query_logs.user_id', '=', 'users.id')
            ->select('nl_query_logs.*', 'users.name as user_name', 'users.email as user_email');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('nl_query_logs.question', 'like', "%{$search}%")
                    ->orWhere('nl_query_logs.generated_sql', 'like', "%{$search}%")
                    ->orWhere('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('success')) {
            $query->where('nl_query_logs.success', $request->input('success'));
        }

        if ($request->filled('user_id')) {
            $query->where('nl_query_logs.user_id', $request->input('user_id'));
        }

        return $query->orderBy('nl_query_logs.created_at', 'desc');
    }

    /**
     * 有日誌記錄的使用者清單（篩選用，只需 id 與名稱）。
     *
     * **必須顯式 select**：query builder 回傳 stdClass 全欄列，`User::$hidden` 不生效，
     * 不收窄就會把 password hash、`confirmation_token`、`remember_token`、`settings`
     * （內含 IP）帶進視圖作用域。見 issue #1248 與 `AiFillLogController::logUsers()`。
     */
    protected function nlQueryLogUsers() {
        return DB::table('users')
            ->select('id', 'name')
            ->whereIn('id', function ($query) {
                $query->select('user_id')->from('nl_query_logs')->whereNotNull('user_id')->distinct();
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * 備妥一筆 NL 日誌供前端渲染。LLM 回應原文（llm_response）完整保留於 collapsible，
     * 另計算 llm_summary（model / token 用量 / 回合數）便於快速檢視。
     *
     * @param object $log
     * @return array<string, mixed>
     */
    protected function prepareNlLog($log): array {
        return [
            'id' => $log->id,
            'user_name' => $log->user_name,
            'user_email' => $log->user_email,
            'created_at' => (string) $log->created_at,
            'execution_time_ms' => $log->execution_time_ms,
            'success' => (bool) $log->success,
            'question' => $log->question,
            // QA 與 NL→SQL 共用 nl_query_logs，唯一的區別是 answerFromNL 寫入時給 question 加了
            // '[QA] ' 前綴。前端要靠它決定「解析不出 JSON 的回應原文」該不該當成 Markdown 渲染
            // （QA 的 fallback 是 answer_markdown；NL→SQL 的則可能是裸 SQL）。判定放後端，
            // 避免前端各處各自比對前綴字串。
            'is_qa' => str_starts_with((string) $log->question, '[QA] '),
            'generated_sql' => $log->generated_sql,
            'explanation' => $log->explanation,
            'error_message' => $log->error_message,
            'llm_prompt' => $log->llm_prompt,
            'llm_response' => $log->llm_response,
            'llm_summary' => $this->parseLlmSummary($log->llm_response),
        ];
    }

    /**
     * 從 llm_response JSON 萃取摘要（相容 rounds / OpenAI / Gemini 格式）。
     * 詳細逐回合/工具結果不在此重建（成本取捨）——原文 JSON 已完整提供。
     *
     * @return array<string, mixed>|null
     */
    protected function parseLlmSummary(?string $llmResponse): ?array {
        if ($llmResponse === null || $llmResponse === '') {
            return null;
        }
        $data = json_decode($llmResponse, true);
        if (!is_array($data)) {
            return null;
        }

        $rounds = $data['rounds'] ?? null;
        $model = $data['model'] ?? $data['modelVersion'] ?? null;
        $prompt = 0;
        $completion = 0;
        $total = 0;

        if (is_array($rounds)) {
            foreach ($rounds as $round) {
                $usage = $round['llm_response']['usage'] ?? [];
                $prompt += (int) ($usage['prompt_tokens'] ?? 0);
                $completion += (int) ($usage['completion_tokens'] ?? 0);
                $total += (int) ($usage['total_tokens'] ?? 0);
                $model = $model ?? ($round['llm_response']['model'] ?? null);
            }
        } else {
            $usage = $data['usage'] ?? [];
            $meta = $data['usageMetadata'] ?? [];
            $prompt = (int) ($usage['prompt_tokens'] ?? $meta['promptTokenCount'] ?? 0);
            $completion = (int) ($usage['completion_tokens'] ?? $meta['candidatesTokenCount'] ?? 0);
            $total = (int) ($usage['total_tokens'] ?? $meta['totalTokenCount'] ?? 0);
        }

        return [
            'model' => $model,
            'rounds_count' => is_array($rounds) ? count($rounds) : null,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
        ];
    }
}
