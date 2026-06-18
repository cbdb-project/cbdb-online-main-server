<?php

namespace App\Http\Controllers;

use App\Support\BasicInformationHistory;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class AdminAuditLogController extends Controller {
    /** 可排序欄位白名單（Inertia 變體用）。 */
    protected const SORTABLE = ['id', 'occurred_at', 'table_name', 'operation', 'actor_type'];

    /**
     * 共用授權 + 資料表存在性檢查；通過回傳 null，否則 abort。
     */
    protected function guard(): void {
        if (!Auth::check() || !Auth::user()->canViewAuditLogs()) {
            abort(403, '此功能僅限活躍管理員使用');
        }

        if (!Schema::hasTable('audit_log')) {
            abort(404, 'audit_log 資料表尚未建立');
        }
    }

    /**
     * 套用篩選條件（搜尋/表名/操作/actor/歷史脈絡）到查詢。Blade 與 Inertia 共用。
     */
    protected function applyRequestFilters($query, Request $request, ?array $historyContext): void {
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
    }

    /** distinct 表名/actor 篩選選項。 */
    protected function filterOptions(): array {
        return [
            'table_names' => DB::table('audit_log')->select('table_name')->distinct()->orderBy('table_name')->pluck('table_name'),
            'actor_types' => DB::table('audit_log')->select('actor_type')->distinct()->orderBy('actor_type')->pluck('actor_type'),
        ];
    }

    /**
     * Audit Log 列表（舊 Blade 版，僅活躍管理員）
     */
    public function index(Request $request) {
        $this->guard();

        $historyContext = $this->resolveHistoryContext($request);
        $query = DB::table('audit_log');
        $this->applyRequestFilters($query, $request, $historyContext);

        $options = $this->filterOptions();
        $tableNames = $options['table_names'];
        $actorTypes = $options['actor_types'];

        $query->orderByDesc('occurred_at')->orderByDesc('id');

        $logs = $query->paginate(20)->withQueryString();

        return view('admin.audit_logs.index', [
            'page_title' => __('admin.audit_logs'),
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

    /**
     * Audit Log 列表（Inertia + React 版）。授權/篩選與 Blade 版一致，另支援
     * sort/direction（白名單），並把每列的 diff/PK/時間預備好交給前端。
     */
    public function appIndex(Request $request) {
        $this->guard();

        $historyContext = $this->resolveHistoryContext($request);
        $query = DB::table('audit_log');
        $this->applyRequestFilters($query, $request, $historyContext);

        $options = $this->filterOptions();

        // 排序：白名單欄位 + 方向；預設 occurred_at desc（與 Blade 版一致）。
        $sort = in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'occurred_at';
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction)->orderByDesc('id');

        $paginator = $query->paginate(20)->withQueryString();

        $rows = array_map(fn ($log) => $this->prepareRow($log), $paginator->items());

        return Inertia::render('Admin/AuditLogs/Index', [
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
            'table_names' => $options['table_names'],
            'actor_types' => $options['actor_types'],
            'history_context' => $historyContext,
            'filters' => [
                'search' => $request->input('search'),
                'table_name' => $request->input('table_name'),
                'operation' => $request->input('operation'),
                'actor_type' => $request->input('actor_type'),
                'actor_id' => $request->input('actor_id'),
            ],
            'sort' => $sort,
            'direction' => $direction,
            // 頁面翻譯（admin 群組）；common 已常駐於 shared translations。
            'page_translations' => [
                'admin' => is_array($t = trans('admin')) ? $t : [],
            ],
        ]);
    }

    /**
     * 把一筆 audit_log 預備成前端可直接渲染的結構（diff/PK 描述/時間）。
     * 重用 Blade 版的 diff 與 CompositePrimaryKey 解析邏輯，避免在 TS 重寫。
     *
     * @param object $log
     * @return array<string, mixed>
     */
    protected function prepareRow($log): array {
        $rowPk = $log->row_pk ? json_decode($log->row_pk, true) : null;
        $oldData = $log->old_data ? json_decode($log->old_data, true) : null;
        $newData = $log->new_data ? json_decode($log->new_data, true) : null;

        $formatValue = function ($value) {
            if (is_array($value) || is_object($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            if ($value === null) {
                return '(null)';
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            return (string) $value;
        };
        $normalizeValue = function ($value) {
            if (is_array($value) || is_object($value)) {
                return json_encode($value);
            }
            if ($value === null) {
                return '';
            }
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            return trim((string) $value);
        };

        $oldArr = is_array($oldData) ? $oldData : [];
        $newArr = is_array($newData) ? $newData : [];
        $allKeys = array_unique(array_merge(array_keys($oldArr), array_keys($newArr)));
        $diffRows = [];
        foreach ($allKeys as $key) {
            if (in_array($key, ['_method', '_token'], true)) {
                continue;
            }
            $beforeRaw = array_key_exists($key, $oldArr) ? $oldArr[$key] : null;
            $afterRaw = array_key_exists($key, $newArr) ? $newArr[$key] : null;
            if ($normalizeValue($beforeRaw) === $normalizeValue($afterRaw)) {
                continue;
            }
            $diffRows[] = [
                'field' => $key,
                'before' => $formatValue($beforeRaw),
                'after' => $formatValue($afterRaw),
            ];
        }

        $pkDescription = $this->formatPkDescription($log->table_name, $log->row_pk_text ?? '', $rowPk);

        $appTimezone = config('app.timezone', 'Asia/Shanghai');
        [$occurredDisplay, $occurredIso] = $this->formatTime($log->occurred_at, $appTimezone);
        [$createdDisplay, $createdIso] = $this->formatTime($log->created_at, $appTimezone);

        return [
            'id' => $log->id,
            'table_name' => $log->table_name,
            'operation' => $log->operation,
            'actor_type' => $log->actor_type,
            'actor_id' => $log->actor_id,
            'operation_id' => $log->operation_id,
            'pk_description' => $pkDescription,
            'occurred_at_display' => $occurredDisplay,
            'occurred_at_iso' => $occurredIso,
            'created_at_display' => $createdDisplay,
            'created_at_iso' => $createdIso,
            'show_created' => $log->created_at !== $log->occurred_at,
            'old_data' => is_array($oldData) ? $oldData : null,
            'new_data' => is_array($newData) ? $newData : null,
            'diff_rows' => !empty($diffRows) ? $diffRows : null,
        ];
    }

    /**
     * 解析時間為 [顯示字串（app 時區）, UTC ISO8601]。
     *
     * @param mixed $raw
     * @return array{0: string, 1: string}
     */
    protected function formatTime($raw, string $appTimezone): array {
        if ($raw instanceof \Carbon\Carbon) {
            return [(string) $raw, $raw->copy()->setTimezone('UTC')->toIso8601String()];
        }
        if (is_string($raw) && trim($raw) !== '') {
            $display = trim($raw);

            try {
                $iso = \Carbon\Carbon::parse($raw, $appTimezone)->setTimezone('UTC')->toIso8601String();
            } catch (\Exception $e) {
                $iso = $display;
            }

            return [$display, $iso];
        }

        return ['', ''];
    }

    /**
     * 產生 PK 描述（沿用 Blade 版邏輯：優先 row_pk_text，再 row_pk）。
     *
     * @param mixed $rowPkData
     */
    protected function formatPkDescription($tableName, $rowPkText, $rowPkData): string {
        $rowPkText = trim((string) $rowPkText);
        if ($rowPkText !== '') {
            $parsed = CompositePrimaryKey::parseStoredResourceId($rowPkText, (string) $tableName);
            if (is_array($parsed) && !empty($parsed)) {
                $parts = [];
                foreach ($parsed as $column => $value) {
                    $parts[] = $column . '：' . (($value === 'NULL' || $value === null) ? '(null)' : (string) $value);
                }

                return implode("\n", $parts);
            }
        }

        if (is_array($rowPkData) && !empty($rowPkData)) {
            $parts = [];
            foreach ($rowPkData as $column => $value) {
                $parts[] = $column . '：' . (($value === 'NULL' || $value === null) ? '(null)' : (string) $value);
            }

            return implode("\n", $parts);
        }

        return $rowPkText;
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
