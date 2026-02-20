<?php

namespace App\Http\Controllers;

use App\Services\Mcp\ReadOnlyTableQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AdminExplainSqlController extends Controller {
    protected function ensureAdmin() {
        if (!Auth::check() || !Auth::user()->canManageUsers()) {
            abort(403);
        }
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

        $data = $request->validate([
            'sql' => 'required|string',
        ]);

        $sql = trim($data['sql']);
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
                $results = DB::select(DB::raw('EXPLAIN ' . $sql));
                $columns = !empty($results) ? array_keys((array) $results[0]) : [];
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return view('admin.explain_sql', [
            'page_title' => 'SQL 執行計畫',
            'page_description' => 'EXPLAIN 查詢計畫',
            'page_url' => route('admin.explainsql'),
            'sql' => $sql,
            'results' => $results,
            'columns' => $columns,
            'error' => $error,
        ]);
    }
}
