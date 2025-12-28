<?php

namespace App\Http\Controllers;

use App\Services\NaturalLanguageQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
     * Heuristic to extract table names from SQL queries.
     * Supports FROM/JOIN clauses and comma-separated tables.
     */
    protected function extractTableNames($sql) {
        // 1. Remove strings and comments to avoid false positives
        $sqlClean = preg_replace("/'[^']*'/", '', $sql);
        $sqlClean = preg_replace('/"[^"]*"/', '', $sqlClean);
        $sqlClean = preg_replace('/`[^`]*`/', '', $sqlClean); // We shouldn't remove backticks blindly as they contain validation targets, but we can treat them as delimiters.
        // Actually, let's keep backticks but just strip the ticks later.

        // Better approach: Normalize whitespace
        $sql = preg_replace('/\s+/', ' ', $sql);

        // 2. Find "FROM ... [WHERE|GROUP|ORDER|LIMIT|;]" blocks
        // This is complex regex. Let's iterate through keywords.
        // We look for patterns starting with FROM or JOIN, ending at the next reserved keyword.

        // Keywords that end a FROM/JOIN clause
        $stoppers = 'WHERE|GROUP|HAVING|ORDER|LIMIT|OFFSET|UNION|INTERSECT|EXCEPT|;|LEFT|RIGHT|INNER|OUTER|CROSS|NATURAL|JOIN';

        preg_match_all("/(?:FROM|JOIN)\s+(.*?)(?=(?:\s+(?:$stoppers))|$)/i", $sql, $matches);

        $candidates = [];
        foreach ($matches[1] as $block) {
            // Block contains "table1, table2 alias, table3"
            $parts = explode(',', $block);
            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part)) {
                    continue;
                }

                // Get the first word (table name) ignoring optional parenthesis for subqueries?
                // Subqueries "(SELECT...)" break this.
                // We should BLOCK subqueries in FROM check if they are not just aliased valid tables?
                // No, "FROM (SELECT * FROM whitelisted)" is safe.
                // "FROM (SELECT * FROM forbidden)" is unsafe.
                // Our regex logic above will see "SELECT" as a table name if we are not careful?
                // Wait, logic: "FROM (SELECT..." -> match block "(SELECT..."
                // first word is "(".

                // If it starts with (, it's a subquery. Recursion needed?
                // For Playground MVP: simple table names only?
                // Users might want complex queries.

                // Let's rely on checking ALL words found in the FROM/JOIN block.
                // If any word looks like a table name (alphanumeric), check it against whitelist?
                // No, aliases will flag false positives. "FROM allowed AS forbidden_name" -> Error.

                // Keep it simple: Extract first token of each comma-segment.
                // If token starts with (, ignore (it effectively recurses via the global regex search for FROM inside).

                // Remove opening parenthesis
                $part = ltrim($part, '(');

                // Get first token
                $token = preg_split('/\s+/', $part)[0] ?? '';

                // Strip quotes/backticks
                $token = trim($token, "`'\"");

                if (!empty($token)) {
                    $candidates[] = $token;
                }
            }
        }

        // Also run a global simple match for standard "FROM table" just in case the block logic missed something
        // due to nested parens structure.
        preg_match_all('/(?:FROM|JOIN)\s+[`\'"]?([a-zA-Z0-9_]+)[`\'"]?/i', $sql, $fallbackMatches);
        $candidates = array_merge($candidates, $fallbackMatches[1] ?? []);

        return array_unique($candidates);
    }

    /**
     * 使用自然语言生成 SQL 查询
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
        ]);

        $question = $request->input('question');
        $tables = $request->input('tables');

        $result = $nlqService->generateSQL($question, $tables);

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
