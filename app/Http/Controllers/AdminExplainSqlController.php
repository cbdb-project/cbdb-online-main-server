<?php

namespace App\Http\Controllers;

use App\Services\Mcp\ReadOnlyTableQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use InvalidArgumentException;

class AdminExplainSqlController extends Controller {
    protected function ensureAdmin() {
        if (!Auth::check() || !Auth::user()->canManageUsers()) {
            abort(403);
        }
    }

    /**
     * 對唯讀 SQL 跑 EXPLAIN。回傳 [sql, results, columns, error]。Blade 與 Inertia 共用。
     *
     * @return array{sql: string, results: array<int, mixed>|null, columns: array<int, string>, error: ?string}
     */
    protected function runExplain(string $sql): array {
        $sql = trim($sql);
        $error = null;
        $results = null;
        $columns = [];
        $service = app(ReadOnlyTableQueryService::class);

        try {
            $inspection = $service->inspectReadOnlySql($sql);
            $sql = $inspection['sql'];
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        if (!$error) {
            try {
                // DB::select 需要字串；傳 DB::raw() Expression 會觸發
                // 「PDO::prepare(): Argument #1 must be of type string, Expression given」，
                // 使 EXPLAIN 永遠丟例外（新舊頁共用此方法，故兩邊都壞）。
                $results = DB::select('EXPLAIN ' . $sql);
                $columns = !empty($results) ? array_keys((array) $results[0]) : [];
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return ['sql' => $sql, 'results' => $results, 'columns' => $columns, 'error' => $error];
    }

    public function show() {
        $this->ensureAdmin();

        return view('admin.explain_sql', [
            'page_title' => 'SQL 執行計畫',
            'page_description' => 'EXPLAIN 查詢計畫',
            'page_url' => route('admin.explainsql'),
            'sql' => '',
            'results' => null,
            'columns' => [],
            'error' => null,
        ]);
    }

    public function explain(Request $request) {
        $this->ensureAdmin();

        $data = $request->validate(['sql' => 'required|string']);
        $outcome = $this->runExplain($data['sql']);

        return view('admin.explain_sql', array_merge([
            'page_title' => 'SQL 執行計畫',
            'page_description' => 'EXPLAIN 查詢計畫',
            'page_url' => route('admin.explainsql'),
        ], $outcome));
    }

    /**
     * Inertia + React 版（表單頁）。
     */
    public function appShow() {
        $this->ensureAdmin();

        return Inertia::render('Admin/ExplainSql/Index', [
            'sql' => '',
            'results' => null,
            'columns' => [],
            'error' => null,
            'explain_url' => route('app.admin.explainsql.explain', [], false),
            'page_translations' => [
                'admin' => is_array($t = trans('admin')) ? $t : [],
            ],
        ]);
    }

    /**
     * Inertia POST：跑 EXPLAIN 後重新 render 同元件（結果/錯誤以 props 回傳）。
     */
    public function appExplain(Request $request) {
        $this->ensureAdmin();

        $data = $request->validate(['sql' => 'required|string']);
        $outcome = $this->runExplain($data['sql']);

        // EXPLAIN 結果（含可能的 DB 錯誤）以 results/error props 表示，
        // 將每列 stdClass 轉為陣列供前端渲染。
        $results = $outcome['results'] === null
            ? null
            : array_map(fn ($row) => (array) $row, $outcome['results']);

        return Inertia::render('Admin/ExplainSql/Index', [
            'sql' => $outcome['sql'],
            'results' => $results,
            'columns' => $outcome['columns'],
            'error' => $outcome['error'],
            'explain_url' => route('app.admin.explainsql.explain', [], false),
            'page_translations' => [
                'admin' => is_array($t = trans('admin')) ? $t : [],
            ],
        ]);
    }
}
