<?php

namespace App\Http\Controllers;

use App\Services\NaturalLanguageQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

class QueryPlaygroundController extends Controller {
    public function __construct() {
        $this->middleware('auth');
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
        if (!Auth::user()->isAdmin()) {
            abort(403, 'Unauthorized. Expert access required.');
        }

        $initialSql = $request->input('sql', 'SELECT * FROM DYNASTIES');

        return view('query_playground.index', [
            'page_title' => 'SQL 查詢練習場',
            'page_title_key' => 'Query Playground',
            'page_description' => '本功能目前處於測試階段，請適度使用以維護系統穩定',
            'page_url' => route('query-playground.index'),
            'initial_sql' => $initialSql,
            'nl_model' => config('services.gemini.model', 'gemini-3-flash-preview'),
        ]);
    }

    public function run(Request $request) {
        if (!Auth::user()->isAdmin()) {
            return response()->json(['error' => 'Unauthorized. Expert access required.'], 403);
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

        $tablesInQuery = $this->extractTableNames($sql);

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
     * Extract table names from SQL queries using AST parser.
     * This correctly handles subqueries, quoted identifiers, and string literals.
     */
    protected function extractTableNames($sql) {
        try {
            // Parse the SQL using PhpMyAdmin SQL Parser
            $parser = new Parser($sql);

            $tables = [];

            // Process all statements (usually just one SELECT)
            foreach ($parser->statements as $statement) {
                if ($statement instanceof SelectStatement) {
                    $tables = array_merge($tables, $this->extractTablesFromSelectStatement($statement));
                }
            }

            // Check for parser errors
            if (!empty($parser->errors)) {
                // If parser fails, return empty array to trigger the "could not detect" error
                return [];
            }

            return array_unique($tables);
        } catch (\Exception $e) {
            // If parsing fails entirely, return empty array
            return [];
        }
    }

    /**
     * Recursively extract table names from a SELECT statement and its subqueries.
     */
    protected function extractTablesFromSelectStatement(SelectStatement $statement) {
        $tables = [];

        // Extract from FROM clause
        if ($statement->from) {
            foreach ($statement->from as $fromClause) {
                // Check if this is a subquery (indicated by subquery property or expr starting with '(')
                if ($fromClause->subquery || (is_string($fromClause->expr) && strpos($fromClause->expr, '(') === 0)) {
                    // Extract subquery content from parentheses
                    $subqueryContent = $fromClause->expr;
                    if (is_string($subqueryContent) && preg_match('/^\((.*)\)$/s', $subqueryContent, $matches)) {
                        // Parse the subquery
                        $subqueryParser = new Parser($matches[1]);
                        foreach ($subqueryParser->statements as $subStatement) {
                            if ($subStatement instanceof SelectStatement) {
                                $tables = array_merge($tables, $this->extractTablesFromSelectStatement($subStatement));
                            }
                        }
                    }
                } elseif ($fromClause->table) {
                    // Regular table reference
                    $tableName = $fromClause->table;
                    if (is_string($tableName)) {
                        $tableName = trim($tableName, '`\'"');
                        if (!empty($tableName)) {
                            $tables[] = $tableName;
                        }
                    }
                }
            }
        }

        // Extract from JOIN clauses
        if ($statement->join) {
            foreach ($statement->join as $joinClause) {
                // JOIN clause expr is an Expression object
                if ($joinClause->expr instanceof Expression) {
                    $expression = $joinClause->expr;

                    // Check if this is a subquery
                    if ($expression->subquery || (is_string($expression->expr) && strpos($expression->expr, '(') === 0)) {
                        // Extract subquery content from parentheses
                        $subqueryContent = $expression->expr;
                        if (is_string($subqueryContent) && preg_match('/^\((.*)\)$/s', $subqueryContent, $matches)) {
                            // Parse the subquery
                            $subqueryParser = new Parser($matches[1]);
                            foreach ($subqueryParser->statements as $subStatement) {
                                if ($subStatement instanceof SelectStatement) {
                                    $tables = array_merge($tables, $this->extractTablesFromSelectStatement($subStatement));
                                }
                            }
                        }
                    } elseif ($expression->table) {
                        // Regular table reference
                        $tableName = $expression->table;
                        if (is_string($tableName)) {
                            $tableName = trim($tableName, '`\'"');
                            if (!empty($tableName)) {
                                $tables[] = $tableName;
                            }
                        }
                    }
                }
            }
        }

        return $tables;
    }

    /**
     * 使用自然语言生成 SQL 查询（SSE 流式响应）
     *
     * @param Request $request
     * @param NaturalLanguageQueryService $nlqService
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function generateFromNLStream(Request $request, NaturalLanguageQueryService $nlqService) {
        if (!Auth::user()->isAdmin()) {
            return response()->json(['error' => 'Unauthorized. Expert access required.'], 403);
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

        return response()->stream(function () use ($question, $tables, $nlqService, $useTools) {
            // 设置 SSE 头部
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no'); // 禁用 nginx 缓冲

            // 发送事件的辅助函数
            $sendEvent = function ($event, $data) {
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                ob_flush();
                flush();
            };

            try {
                // 调用服务并传入进度回调
                $result = $nlqService->generateSQL($question, $tables, $sendEvent, $useTools);

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
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * 使用自然语言生成 SQL 查询（兼容旧版，非流式）
     *
     * @param Request $request
     * @param NaturalLanguageQueryService $nlqService
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateFromNL(Request $request, NaturalLanguageQueryService $nlqService) {
        if (!Auth::user()->isAdmin()) {
            return response()->json(['error' => 'Unauthorized. Expert access required.'], 403);
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
