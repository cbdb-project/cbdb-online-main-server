<?php

namespace App\Http\Controllers;

use App\Support\BasicInformationHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminAuditLogController extends Controller {
    /**
     * Audit Log 列表（僅活躍管理員）
     */
    public function index(Request $request) {
        if (!Auth::check() || !Auth::user()->canViewAuditLogs()) {
            abort(403, '此功能僅限活躍管理員使用');
        }

        if (!Schema::hasTable('audit_log')) {
            abort(404, 'audit_log 資料表尚未建立');
        }

        $historyContext = $this->resolveHistoryContext($request);
        $query = DB::table('audit_log');

        if ($historyContext !== null) {
            $this->applyHistoryFilter($query, $historyContext);
        }

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
            'history_context' => $historyContext,
            'filters' => [
                'search' => $request->input('search'),
                'table_name' => $request->input('table_name'),
                'operation' => $request->input('operation'),
                'actor_type' => $request->input('actor_type'),
                'actor_id' => $request->input('actor_id'),
            ],
        ]);
    }

    protected function resolveHistoryContext(Request $request): ?array {
        $personId = trim((string) $request->input('c_personid', ''));
        if ($personId === '' || !ctype_digit($personId) || (int) $personId <= 0) {
            return null;
        }

        $historyConfig = BasicInformationHistory::resolveFromPage($request->input('history_page'));
        if ($historyConfig === null || empty($historyConfig['tables'])) {
            return null;
        }

        return [
            'person_id' => (int) $personId,
            'page' => $historyConfig['page'],
            'tables' => $historyConfig['tables'],
            'label' => $historyConfig['label'],
        ];
    }

    protected function applyHistoryFilter($query, array $historyContext): void {
        $personId = (int) ($historyContext['person_id'] ?? 0);
        $tables = BasicInformationHistory::normalizeTables((array) ($historyContext['tables'] ?? []));

        if ($personId <= 0 || empty($tables)) {
            return;
        }

        $query->whereIn('table_name', $tables)
            ->where(function ($personQuery) use ($personId) {
                foreach (['row_pk_text', 'row_pk', 'old_data', 'new_data'] as $column) {
                    $this->appendAuditPersonIdLike($personQuery, $column, $personId);
                }
            });
    }

    protected function appendAuditPersonIdLike($query, string $column, int $personId): void {
        if ($column === 'row_pk_text') {
            $query->orWhere(function ($columnQuery) use ($column, $personId) {
                $columnQuery->where($column, 'c_personid=' . $personId)
                    ->orWhere($column, 'like', 'c_personid=' . $personId . '&%')
                    ->orWhere($column, 'like', '%&c_personid=' . $personId)
                    ->orWhere($column, 'like', '%&c_personid=' . $personId . '&%');
            });

            return;
        }

        $patterns = [
            '%"c_personid":' . $personId . ',%',
            '%"c_personid":' . $personId . '}%',
            '%"c_personid": ' . $personId . ',%',
            '%"c_personid": ' . $personId . '}%',
            '%"c_personid":"' . $personId . '",%',
            '%"c_personid":"' . $personId . '"}%',
            '%"c_personid": "' . $personId . '",%',
            '%"c_personid": "' . $personId . '"}%',
        ];

        $query->orWhere(function ($columnQuery) use ($column, $patterns) {
            foreach ($patterns as $pattern) {
                $columnQuery->orWhere($column, 'like', $pattern);
            }
        });
    }
}
