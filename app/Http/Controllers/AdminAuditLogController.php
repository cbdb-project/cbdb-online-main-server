<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminAuditLogController extends Controller {
    /**
     * Audit Log 列表（僅 Super Admin）
     */
    public function index(Request $request) {
        if (!Auth::user()->isSuperAdmin()) {
            abort(403, '此功能僅限超級管理員使用');
        }

        if (!Schema::hasTable('audit_log')) {
            abort(404, 'audit_log 資料表尚未建立');
        }

        $query = DB::table('audit_log');

        if ($request->filled('table_name')) {
            $query->where('table_name', $request->input('table_name'));
        }

        if ($request->filled('operation')) {
            $query->where('operation', strtoupper($request->input('operation')));
        }

        if ($request->filled('actor_type')) {
            $query->where('actor_type', $request->input('actor_type'));
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->input('actor_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('operation_id', 'like', "%{$search}%")
                    ->orWhere('row_pk_text', 'like', "%{$search}%")
                    ->orWhere('table_name', 'like', "%{$search}%");
            });
        }

        $tableNames = DB::table('audit_log')
            ->select('table_name')
            ->distinct()
            ->orderBy('table_name')
            ->pluck('table_name');

        $actorTypes = DB::table('audit_log')
            ->select('actor_type')
            ->distinct()
            ->orderBy('actor_type')
            ->pluck('actor_type');

        $query->orderByDesc('occurred_at')->orderByDesc('id');

        $logs = $query->paginate(20)->withQueryString();

        return view('admin.audit_logs.index', [
            'page_title' => '審計日誌',
            'page_title_key' => '審計日誌',
            'page_url' => route('admin.audit-logs'),
            'logs' => $logs,
            'table_names' => $tableNames,
            'actor_types' => $actorTypes,
            'filters' => [
                'search' => $request->input('search'),
                'table_name' => $request->input('table_name'),
                'operation' => $request->input('operation'),
                'actor_type' => $request->input('actor_type'),
                'actor_id' => $request->input('actor_id'),
            ],
        ]);
    }
}
