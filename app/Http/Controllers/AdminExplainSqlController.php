<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminExplainSqlController extends Controller
{
    protected function ensureAdmin()
    {
        if (!Auth::check() || !Auth::user()->canManageUsers()) {
            abort(403);
        }
    }

    public function show()
    {
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

    public function explain(Request $request)
    {
        $this->ensureAdmin();

        $data = $request->validate([
            'sql' => 'required|string',
        ]);

        $sql = trim($data['sql']);
        $error = null;
        $results = null;
        $columns = [];

        if (strpos($sql, ';') !== false) {
            $error = '請勿在語句中包含分號。';
        } else {
            $firstToken = strtoupper(strtok(ltrim($sql), " \t\n\r"));
            $allowed = ['SELECT', 'WITH'];

            if (!in_array($firstToken, $allowed, true)) {
                $error = '僅允許只讀查詢（SELECT / WITH）。';
            }
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
