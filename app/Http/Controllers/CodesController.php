<?php

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Repositories\CodesRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\ColumnFilterExpression;
use App\Support\ColumnFilterParseException;
use App\Support\PinyinUmlaut;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class CodesController extends Controller {
    protected $codesrepostory;
    protected $operationRepository;

    protected $allowedTables = [];
    protected $allowedTablesMap = [];
    /**
     * Cache of dynasty ID to name mapping for current request.
     *
     * @var array<int|string, string>|null
     */
    protected $dynastyNameMap = null;
    /**
     * Tables treated as read-only within the generic codes UI.
     *
     * @var array<int, string>
     */
    protected $readOnlyTables = [
        'CBDB__NAME_FTS',
        'CBDB__TRAD_SIMP_MAP',
        'DYNASTIES',
        'GANZHI_CODES',
    ];
    /**
     * Copyright notices for specific tables.
     *
     * @var array<string, string>
     */
    protected $tableCopyrightNotes = [
        'CBDB__TRAD_SIMP_MAP' => '此表格數據來自 <a href="https://github.com/BYVoid/OpenCC" target="_blank">OpenCC 項目</a>的字典文件，該文件以 <a href="https://www.apache.org/licenses/LICENSE-2.0" target="_blank">Apache 2.0 License</a> 授權，因此這個表格的授權也是 Apache 2.0，而非 CC BY-NC-SA 4.0 International 授權。',
    ];
    /**
     * JOIN configurations for relationship tables.
     *
     * @var array<string, array<string, mixed>>
     */
    protected $tableJoinConfigurations = [
        'ADMIN_CAT_CODE_TYPE_REL' => [
            'base_table' => 'ADMIN_CAT_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'ADMIN_CAT_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_admin_cat_code', '=', 'code.c_admin_cat_code'],
                    'type' => 'left',
                ],
                [
                    'table' => 'ADMIN_CAT_TYPES',
                    'alias' => 'type',
                    'on' => ['rel.c_admin_cat_type_code', '=', 'type.c_admin_cat_type_code'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_admin_cat_code',
                'code.c_admin_cat_hz as admin_cat_name',
                'rel.c_admin_cat_type_code',
                'type.c_admin_cat_type_hz as admin_cat_type_name',
            ],
        ],
        'APPOINTMENT_CODE_TYPE_REL' => [
            'base_table' => 'APPOINTMENT_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'APPOINTMENT_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_appt_code', '=', 'code.c_appt_code'],
                    'type' => 'left',
                ],
                [
                    'table' => 'APPOINTMENT_TYPES',
                    'alias' => 'type',
                    'on' => ['rel.c_appt_type_code', '=', 'type.c_appt_type_code'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_appt_code',
                'code.c_appt_desc_chn as appt_name',
                'rel.c_appt_type_code',
                'type.c_appt_type_desc_chn as appt_type_name',
            ],
        ],
        'ENTRY_CODE_TYPE_REL' => [
            'base_table' => 'ENTRY_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'ENTRY_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_entry_code', '=', 'code.c_entry_code'],
                    'type' => 'left',
                ],
                [
                    'table' => 'ENTRY_TYPES',
                    'alias' => 'type',
                    'on' => ['rel.c_entry_type', '=', 'type.c_entry_type'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_entry_code',
                'code.c_entry_desc_chn as entry_name',
                'rel.c_entry_type',
                'type.c_entry_type_desc_chn as entry_type_name',
            ],
        ],
        'OFFICE_CODE_TYPE_REL' => [
            'base_table' => 'OFFICE_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'OFFICE_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_office_id', '=', 'code.c_office_id'],
                    'type' => 'left',
                ],
                [
                    'table' => 'OFFICE_TYPE_TREE',
                    'alias' => 'type',
                    'on' => ['rel.c_office_tree_id', '=', 'type.c_office_type_node_id'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_office_id',
                'code.c_office_chn as office_name',
                'rel.c_office_tree_id',
                'type.c_office_type_desc_chn as office_type_name',
            ],
        ],
        'ASSOC_CODE_TYPE_REL' => [
            'base_table' => 'ASSOC_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'ASSOC_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_assoc_code', '=', 'code.c_assoc_code'],
                    'type' => 'left',
                ],
                [
                    'table' => 'ASSOC_TYPES',
                    'alias' => 'type',
                    'on' => ['rel.c_assoc_type_code', '=', 'type.c_assoc_type_code'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_assoc_code',
                'code.c_assoc_desc_chn as assoc_name',
                'rel.c_assoc_type_code',
                'type.c_assoc_type_desc_chn as assoc_type_name',
            ],
        ],
        'STATUS_CODE_TYPE_REL' => [
            'base_table' => 'STATUS_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'STATUS_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_status_code', '=', 'code.c_status_code'],
                    'type' => 'left',
                ],
                [
                    'table' => 'STATUS_TYPES',
                    'alias' => 'type',
                    'on' => ['rel.c_status_type_code', '=', 'type.c_status_type_code'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_status_code',
                'code.c_status_desc_chn as status_name',
                'rel.c_status_type_code',
                'type.c_status_type_chn as status_type_name',
            ],
        ],
        'TEXT_BIBLCAT_CODE_TYPE_REL' => [
            'base_table' => 'TEXT_BIBLCAT_CODE_TYPE_REL',
            'base_alias' => 'rel',
            'joins' => [
                [
                    'table' => 'TEXT_BIBLCAT_CODES',
                    'alias' => 'code',
                    'on' => ['rel.c_text_cat_code', '=', 'code.c_text_cat_code'],
                    'type' => 'left',
                ],
                [
                    'table' => 'TEXT_BIBLCAT_TYPES',
                    'alias' => 'type',
                    'on' => ['rel.c_text_cat_type_id', '=', 'type.c_text_cat_type_id'],
                    'type' => 'left',
                ],
            ],
            'select' => [
                'rel.c_text_cat_code',
                'code.c_text_cat_desc_chn as text_cat_name',
                'rel.c_text_cat_type_id',
                'type.c_text_cat_type_desc_chn as text_cat_type_name',
            ],
        ],
    ];
    /**
     * Explicit primary key definitions for code tables.
     *
     * @var array<string, array<int, string>>
     */
    protected $tablePrimaryKeyOverrides = [
        'CBDB__NAME_FTS' => ['id'],
        'CBDB__TRAD_SIMP_MAP' => ['trad_char'],
        'POSSESSION_DATA' => ['c_possession_record_id'],
        'SOCIAL_INSTITUTION_CODES' => ['c_inst_code'],
        'SOCIAL_INSTITUTION_ADDR' => ['c_inst_code', 'c_inst_addr_id'],
        'TEXT_CODES' => ['c_textid'],
    ];
    /**
     * Table column listings cached per request.
     *
     * @var array<string, array<int, string>>
     */
    protected $tableColumnsCache = [];

    protected ColumnFilterExpression $columnFilterExpression;

    protected AuditLogService $auditLogService;

    public function __construct(
        CodesRepository $codesRepository,
        OperationRepository $operationRepository,
        ?ColumnFilterExpression $columnFilterExpression = null,
        ?AuditLogService $auditLogService = null
    ) {
        $this->codesrepostory = $codesRepository;
        $this->operationRepository = $operationRepository;
        $this->columnFilterExpression = $columnFilterExpression ?? new ColumnFilterExpression();
        $this->auditLogService = $auditLogService ?? app(AuditLogService::class);
        $this->allowedTables = $this->codesrepostory->allowedTables();

        // 直接从配置构建大小写映射，避免 SHOW TABLES 查询
        $this->allowedTablesMap = [];
        foreach ($this->allowedTables as $table) {
            $this->allowedTablesMap[strtoupper($table)] = $table;
        }
    }

    protected function guardTable(string $table): string {
        $key = strtoupper($table);
        if (!isset($this->allowedTablesMap[$key])) {
            abort(404);
        }

        return $this->allowedTablesMap[$key];
    }

    /**
     * 該表是否開放全量匯出。判斷單一來源：config('codes.export_columns') 中配置了**非空**有序欄位清單。
     * export()（gate）與 show()（下載連結顯示）共用此條件，避免兩處漂移。
     */
    protected function isExportable(string $table): bool {
        $columns = config('codes.export_columns.' . strtoupper($table));

        return is_array($columns) && $columns !== [];
    }

    /**
     * Effective state of the per-page boolean filter switch.
     *
     * Precedence (see design §2.2 C3): global kill-switch forces off; otherwise the
     * `filter_bool` query param. Default off (literal behaviour, fully backward compatible).
     */
    protected function resolveBooleanFilterEnabled(Request $request): bool {
        if (!config('codes.boolean_filter_enabled', true)) {
            return false;
        }

        return $request->boolean('filter_bool');
    }

    public function index() {
        $data = $this->codesrepostory->codes();

        return view('codes.index', [
            'page_title' => __('nav.all_tables'),
            'page_title_key' => '全部表格',
            'page_description' => __('nav.all_tables_desc'),
            'page_url' => '/codes',
            'data' => $data,
        ]);
    }

    /**
     * Inertia + React 版：代碼表總覽。
     */
    public function appIndex() {
        $data = $this->codesrepostory->codes();

        // 每個代碼表的「檢視」連結受 codes flag 控制（新版 show 就緒後指向 React）。
        $rows = array_map(function ($item) {
            $name = $item['name'];

            return [
                'name' => $name,
                'description' => $item['description'] ?? '',
                'url' => $this->codesShowUrl($name),
            ];
        }, $data);

        return Inertia::render('Codes/Index', [
            'tables' => $rows,
            'page_translations' => [
                'codes' => is_array($t = trans('codes')) ? $t : [],
            ],
        ]);
    }

    /**
     * 代碼表 show 連結：依 codes flag 指向新 React 或舊 Blade（相對 URL）。
     */
    protected function codesShowUrl(string $tableName): string {
        if (migration_flag_is_new('codes') && Route::has('app.codes.show')) {
            return route('app.codes.show', ['table_name' => $tableName], false);
        }

        return '/codes/' . $tableName;
    }

    public function show(Request $request, $table_name) {
        $table = $this->guardTable($table_name);
        $search = trim((string) $request->query('search', ''));

        try {
            $payload = $this->buildShowPayload($request, $table, $search);
        } catch (\PDOException $e) {
            flash('找不到該資料表', 'warning');

            return redirect()->back();
        }

        return view('codes.show', array_merge([
            'page_title' => $table,
            'page_description' => '',
            'page_url' => '/codes',
            'archer' => "<li class='breadcrumb-item'><a href='/codes'>全部表格</a></li>",
        ], $payload));
    }

    /**
     * Inertia + React 版：代碼表單表資料（與 Blade show() 共用 buildShowPayload）。
     */
    public function appShow(Request $request, $table_name) {
        $table = $this->guardTable($table_name);
        $search = trim((string) $request->query('search', ''));

        try {
            $payload = $this->buildShowPayload($request, $table, $search);
        } catch (\PDOException $e) {
            flash('找不到該資料表', 'warning');

            return redirect()->route('app.codes.index');
        }

        $useCursor = $payload['useCursorPagination'] ?? false;

        // 序列化分頁資料為前端可消費結構（標準 = Laravel paginator；游標 = cursorMeta）。
        if ($useCursor) {
            $cursor = $payload['data'];
            $rows = collect($cursor['data'])->map(fn ($r) => (array) $r)->all();
            $dataProp = [
                'rows' => $rows,
                'first_id' => $cursor['first_id'],
                'last_id' => $cursor['last_id'],
                'has_more_pages' => $cursor['has_more_pages'],
                'has_prev_pages' => $cursor['has_prev_pages'],
                'next_cursor' => $cursor['next_cursor'],
                'prev_cursor' => $cursor['prev_cursor'],
            ];
            $meta = null;
        } else {
            $paginator = $payload['data'];
            $dataProp = ['rows' => array_map(fn ($r) => (array) $r, $paginator->items())];
            $meta = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ];
        }

        return Inertia::render('Codes/Show', [
            'table' => $table,
            'thead' => $payload['thead'],
            'rows' => $dataProp['rows'],
            'cursor' => $useCursor ? $dataProp : null,
            'meta' => $meta,
            'use_cursor' => $useCursor,
            'search' => $payload['search'],
            'dynasty_map' => (object) ($payload['dynastyMap'] ?? []),
            'is_read_only' => $payload['isReadOnly'] ?? false,
            'exportable' => $payload['exportable'] ?? false,
            'key_columns' => array_values($payload['keyColumns'] ?? []),
            'joined_columns' => array_values($payload['joinedColumns'] ?? []),
            'copyright_note' => $payload['copyrightNote'] ?? null,
            'filters' => (object) ($payload['filters'] ?? []),
            'sort_by' => $payload['sortBy'] ?? '',
            'sort_dir' => $payload['sortDir'] ?? 'asc',
            'boolean_enabled' => $payload['booleanEnabled'] ?? false,
            'boolean_filter_available' => $payload['booleanFilterAvailable'] ?? false,
            'filter_errors' => (object) ($payload['filterErrors'] ?? []),
            'filter_descriptions' => (object) ($payload['filterDescriptions'] ?? []),
            // 操作連結（建立/編輯/刪除）依 codes flag 解析（新版就緒前回退 Blade）。
            'can_edit' => \Illuminate\Support\Facades\Auth::check() && !($payload['isReadOnly'] ?? false),
            'urls' => [
                'index' => $this->codesIndexUrl(),
                'create' => $this->codesActionUrl('create', $table),
                'export' => '/codes/' . $table . '/export',
                // edit/destroy 模板（含 __ID__ 佔位）依 codes flag 解析。
                'edit_template' => $this->codesIdTemplate('app.codes.edit', 'edit', $table),
                'destroy_template' => $this->codesIdTemplate('app.codes.destroy', null, $table),
            ],
            'page_translations' => [
                'codes' => is_array($t = trans('codes')) ? $t : [],
            ],
        ]);
    }

    /**
     * 帶 __ID__ 佔位的 edit/destroy 連結模板（flag-aware）。
     * 新版路由存在且 flag=new 時用 app 路由，否則回退舊 Blade 路徑。
     */
    protected function codesIdTemplate(string $appRoute, ?string $suffix, string $table): string {
        if (migration_flag_is_new('codes') && Route::has($appRoute)) {
            return route($appRoute, ['table_name' => $table, 'id' => '__ID__'], false);
        }

        return '/codes/' . $table . '/__ID__' . ($suffix ? '/' . $suffix : '');
    }

    /** 代碼表總覽 URL（flag-aware）。 */
    protected function codesIndexUrl(): string {
        if (migration_flag_is_new('codes') && Route::has('app.codes.index')) {
            return route('app.codes.index', [], false);
        }

        return '/codes';
    }

    /** 代碼表 create 連結 base（flag-aware；edit/destroy 由前端帶 id 組合）。 */
    protected function codesActionUrl(string $action, string $table): string {
        if ($action === 'create' && migration_flag_is_new('codes') && Route::has('app.codes.create')) {
            return route('app.codes.create', ['table_name' => $table], false);
        }

        // 其餘（edit/destroy，P2-4）就緒前一律回退舊 Blade 路徑。
        return '/codes/' . $table . '/' . $action;
    }

    /**
     * 建立 codes show 的 props payload（標準 offset 或游標分頁）。Blade 與 Inertia 共用。
     *
     * @return array<string, mixed>
     */
    protected function buildShowPayload(Request $request, string $table, string $search): array {
        $perPage = config('codes.per_page', 20);
        $upperTable = strtoupper($table);

        // Check if this table needs JOIN
        $joinConfig = $this->tableJoinConfigurations[$upperTable] ?? null;
        if ($joinConfig) {
            $query = $this->buildJoinQuery($joinConfig);
        } else {
            $query = DB::table($table);
        }

        $sampleRow = (clone $query)->first();
        $thead = $this->buildTableHead($table, $sampleRow, $joinConfig);
        $searchableColumns = $this->determineSearchableColumns($table, $thead);

        // ★ 提前取得主鍵（tie-breaker 排序需要用到）
        $keyColumns = $this->getKeyColumns($table);
        $filters = $this->sanitizeColumnFilters($request->query('filters', []), $thead);
        [$sortBy, $sortDir] = $this->sanitizeSortParameters(
            $request->query('sort_by', ''),
            $request->query('sort_dir', 'asc'),
            $thead
        );
        $booleanEnabled = $this->resolveBooleanFilterEnabled($request);

        // 游标分页大表（如 CBDB__NAME_FTS）硬短路：無條件拒絕逐欄/布林 filter 與 sort，
        // 永遠走游標路徑，避免對百萬列大表跑 %term% 全表掃描。見設計 §2.3。
        $cursorPaginationTables = ['CBDB__NAME_FTS'];
        if (in_array(strtoupper($table), $cursorPaginationTables, true)) {
            $filters = [];
            $sortBy = '';
            $sortDir = 'asc';
            $booleanEnabled = false;
        }

        $useCursorPagination = in_array(strtoupper($table), $cursorPaginationTables, true)
            && empty($filters)
            && $sortBy === '';

        if ($search !== '' && !empty($searchableColumns)) {
            $query->where(function ($subQuery) use ($searchableColumns, $search, $useCursorPagination, $joinConfig) {
                foreach ($searchableColumns as $column) {
                    $searchColumn = $this->resolveColumnForQuery($column, $joinConfig);
                    if ($searchColumn === null) {
                        continue;
                    }
                    if ($useCursorPagination) {
                        $subQuery->orWhere($searchColumn, 'like', $search . '%');
                    } else {
                        $subQuery->orWhere($searchColumn, 'like', '%' . $search . '%');
                    }
                }
            });
        }

        if ($useCursorPagination) {
            return $this->buildCursorPayload($request, $table, $query, $search, $perPage, $thead, $filters, $sortBy, $sortDir);
        }

        // 欄位過濾（欄位之間 AND）。
        // - 關閉布林模式（預設）：單一 `%value%` 字面比對（與現狀完全相同）。
        // - 開啟布林模式：對每欄解析 AND/OR/NOT 布林；解析失敗的欄位記錄錯誤並略過（不轉字面）。
        // $appliedFilters 只含實際套用的欄位，供分頁/排序連結使用，避免把語法錯誤的欄位回灌 URL。
        // 見設計 §6 / §9.2（M4/M9）。
        $appliedFilters = [];
        $filterErrors = [];
        $filterDescriptions = [];
        $descLabels = $booleanEnabled ? [
            'contains' => (string) __('codes.filter_desc_contains'),
            'not' => (string) __('codes.filter_desc_not'),
            'and' => (string) __('codes.filter_desc_and'),
            'or' => (string) __('codes.filter_desc_or'),
        ] : [];
        foreach ($filters as $column => $value) {
            if ($value === '') {
                continue;
            }
            $filterColumn = $this->resolveColumnForQuery($column, $joinConfig);
            if ($filterColumn === null) {
                continue;
            }

            if ($booleanEnabled) {
                try {
                    $ast = $this->columnFilterExpression->parse($value);
                } catch (ColumnFilterParseException $e) {
                    $filterErrors[$column] = $e->errorCode;

                    continue;
                }
                $this->columnFilterExpression->applyToBuilder($query, $filterColumn, $ast);
                $filterDescriptions[$column] = $this->columnFilterExpression->describe($ast, $descLabels);
            } else {
                $query->where($filterColumn, 'like', '%' . $value . '%');
            }

            $appliedFilters[$column] = $value;
        }

        // 排序 + 主鍵 tie-breaker
        if ($sortBy !== '') {
            $sortColumn = $this->resolveColumnForQuery($sortBy, $joinConfig);
            if ($sortColumn !== null) {
                $query->orderBy($sortColumn, $sortDir);
            }
        }
        foreach ($keyColumns as $pkCol) {
            $pkSortExpr = $this->resolveColumnForQuery($pkCol, $joinConfig);
            if ($pkSortExpr !== null) {
                $query->orderBy($pkSortExpr, 'asc');
            }
        }

        // 分頁連結只攜帶實際套用的 filters（排除語法錯誤被略過的欄位），其餘 query 參數
        // （search、sort、filter_bool 等）原樣保留。
        $appendQuery = $request->except(['page', 'filters']);
        if (!empty($appliedFilters)) {
            $appendQuery['filters'] = $appliedFilters;
        }
        $data = $query->paginate($perPage)->appends($appendQuery);

        $dynastyMap = [];
        if (in_array('c_dy', $thead, true)) {
            $dynastyMap = $this->getDynastyNameMap();
        }

        $isReadOnly = $this->isReadOnlyTable($table);
        $copyrightNote = $this->tableCopyrightNotes[$table] ?? null;

        $joinedColumns = [];
        if ($joinConfig) {
            $joinedColumns = $this->getJoinedColumnNames($joinConfig);
        }

        return [
            'q' => $table,
            'thead' => $thead,
            'data' => $data,
            'search' => $search,
            'dynastyMap' => $dynastyMap,
            'isReadOnly' => $isReadOnly,
            'exportable' => $this->isExportable($table),
            'keyColumns' => $keyColumns,
            'copyrightNote' => $copyrightNote,
            'joinedColumns' => $joinedColumns,
            'filters' => $filters,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
            'booleanEnabled' => $booleanEnabled,
            'booleanFilterAvailable' => (bool) config('codes.boolean_filter_enabled', true),
            'appliedFilters' => $appliedFilters,
            // rawFilters 為 sanitize 後（已 trim、限 $thead/scalar）的集合，供輸入框回填，
            // 與既有 blade 回填使用的 $filters 一致。Phase 4 若需「使用者完全原樣輸入」再調整。
            'rawFilters' => $filters,
            'filterErrors' => $filterErrors,
            'filterDescriptions' => $filterDescriptions,
        ];
    }

    /**
     * 全量導出代碼表為下游同步用檔：tab 分隔、quote-aware CSV、無表頭、UTF-8、LF、串流。
     *
     * 範圍由 config('codes.export_columns') 白名單收斂：只有在該設定中明確配置有序欄位的表
     * 才可匯出（本輪僅 OFFICE_CODES），其餘表（即使通過 guardTable，如 ADDR_CODES）一律 404。
     * 輸出欄序 = config 指定順序（與 live schema 物理欄序無關）。設計見 docs/OFFICE_CODES_EXPORT_SYNC.md。
     *
     * 公開唯讀（對齊已公開的 show 頁；OFFICE_CODES 為 CC BY-NC-SA 公開參考資料）、route 端 throttle:6,1。
     * 此為 AGENTS.md §5 授權規則的有意識正當例外（維護者拍板）：僅唯讀、公開資料、且 update.py 需無憑證自助拉取。
     */
    public function export(Request $request, $table_name) {
        $table = $this->guardTable($table_name);

        if (!$this->isExportable($table)) {
            abort(404);
        }
        $exportColumns = config('codes.export_columns.' . strtoupper($table));

        // fail-fast：config 欄位必須全部存在於 live schema；缺欄/改名寧可 500 也不輸出錯位資料。
        $missing = array_values(array_diff($exportColumns, Schema::getColumnListing($table)));
        if (!empty($missing)) {
            abort(500, sprintf('export_columns 與 %s 實際欄位不符，缺少：%s', $table, implode(', ', $missing)));
        }

        $keyColumns = $this->getKeyColumns($table);
        $filename = $table . '.txt';

        return response()->streamDownload(function () use ($table, $exportColumns, $keyColumns) {
            $out = fopen('php://output', 'w');

            $query = DB::table($table)->select($exportColumns);
            // chunk() 需穩定排序；以主鍵排序（OFFICE_CODES = 單一主鍵 c_office_id）。
            // c_office_id 升冪同時滿足下游 update.py 的「c_office_id 嚴格遞增」檢查。
            foreach ($keyColumns as $keyColumn) {
                $query->orderBy($keyColumn);
            }

            $query->chunk(2000, function ($rows) use ($out, $exportColumns) {
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($exportColumns as $column) {
                        $value = $row->{$column} ?? null;
                        $values[] = $value === null ? '' : (string) $value;
                    }
                    // escape 設為空字串：停用 PHP 專屬反斜線轉義，產生 RFC4180 標準引號加倍，
                    // 與下游 Python csv.reader(delimiter="\t") 相容。
                    fputcsv($out, $values, "\t", '"', '');
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function edit($table_name, $id) {
        //        dd($table_name);
        $table = $this->guardTable($table_name);
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止編輯。', 'warning');

            return redirect()->route('codes.show', ['table_name' => $table]);
        }
        if ($table) {
            try {
                $keyColumns = $this->getKeyColumns($table);
                $conditions = $this->buildConditionsFromId($keyColumns, $id);

                $query = DB::table($table);
                foreach ($conditions as $column => $value) {
                    $query->where($column, $value);
                }
                $data = $query->first();

                // 舊版操作紀錄可能以 '-' 分隔複合鍵（如 "4005-7531"），
                // 若以標準 '_._' 分隔找不到，嘗試用 '-' 重新解析
                if (!$data && count($keyColumns) > 1
                    && !str_contains($id, '_._') && str_contains($id, '-')) {
                    $fallbackConditions = $this->buildConditionsFromId($keyColumns, str_replace('-', '_._', $id));
                    if (count($fallbackConditions) > count($conditions)) {
                        $fallbackQuery = DB::table($table);
                        foreach ($fallbackConditions as $col => $val) {
                            $fallbackQuery->where($col, $val);
                        }
                        $data = $fallbackQuery->first();
                    }
                }

                if (!$data) {
                    flash('找不到該筆資料', 'warning');

                    return redirect()->back();
                }

                $rowArray = $this->convertRowToArray($data);
                $rowArray = $this->orderAuditFieldsForDisplay($rowArray);
                $compositeId = $this->buildCompositeId($keyColumns, $rowArray);

                return view('codes.edit', [
                    'page_title' => '編輯',
                    'page_description' => '',
                    'page_url' => '/codes',
                    'archer' => "<li class='breadcrumb-item'><a href='/codes'>全部表格</a></li><li class='breadcrumb-item'><a href='/codes/".rawurlencode($table)."'>".e($table)."</a></li>",
                    'id' => $compositeId, 'row' => $rowArray,
                    'table' => $table]);
            } catch (\PDOException $e) {
                flash('找不到該資料表', 'warning');

                return redirect()->back();
            }

        }

        return redirect()->route('codes.index');
    }

    /**
     * Inertia + React 版：編輯表單頁。
     */
    public function appEdit($table_name, $id) {
        $table = $this->guardTable($table_name);
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止編輯。', 'warning');

            return redirect()->route('app.codes.show', ['table_name' => $table]);
        }

        try {
            $keyColumns = $this->getKeyColumns($table);
            $conditions = $this->buildConditionsFromId($keyColumns, $id);
            $query = DB::table($table);
            foreach ($conditions as $column => $value) {
                $query->where($column, $value);
            }
            $data = $query->first();

            // 舊版以 '-' 分隔複合鍵的相容回退（與 Blade edit 一致）。
            if (!$data && count($keyColumns) > 1 && !str_contains($id, '_._') && str_contains($id, '-')) {
                $fallbackConditions = $this->buildConditionsFromId($keyColumns, str_replace('-', '_._', $id));
                if (count($fallbackConditions) > count($conditions)) {
                    $fallbackQuery = DB::table($table);
                    foreach ($fallbackConditions as $col => $val) {
                        $fallbackQuery->where($col, $val);
                    }
                    $data = $fallbackQuery->first();
                }
            }

            if (!$data) {
                flash('找不到該筆資料', 'warning');

                return redirect()->route('app.codes.show', ['table_name' => $table]);
            }

            $rowArray = $this->orderAuditFieldsForDisplay($this->convertRowToArray($data));
            $compositeId = $this->buildCompositeId($keyColumns, $rowArray);
        } catch (\PDOException $e) {
            flash('找不到該資料表', 'warning');

            return redirect()->route('app.codes.index');
        }

        return Inertia::render('Codes/Edit', [
            'table' => $table,
            'id' => $compositeId,
            'columns' => array_keys($rowArray),
            'values' => $rowArray,
            'key_columns' => array_values($keyColumns),
            'can_propose' => Auth::check() && Auth::user()->isActive(),
            'tier2_fields' => $this->codeTableTier2Fields($table),
            'urls' => [
                'update' => route('app.codes.update', ['table_name' => $table, 'id' => $compositeId], false),
                'propose' => route('app.codes.propose.update', ['table_name' => $table, 'id' => $compositeId], false),
                'destroy' => route('app.codes.destroy', ['table_name' => $table, 'id' => $compositeId], false),
                'show' => route('app.codes.show', ['table_name' => $table], false),
            ],
            'page_translations' => [
                'codes' => is_array($t = trans('codes')) ? $t : [],
            ],
        ]);
    }

    public function update(Request $request, $table_name, $id) {
        $table = $this->guardTable($table_name);

        return $this->performUpdate($request, $table, $id, 'codes.show', 'codes.edit');
    }

    /**
     * Inertia + React 版：直接更新（與 Blade update 共用 performUpdate）。
     */
    public function appUpdate(Request $request, $table_name, $id) {
        $table = $this->guardTable($table_name);

        return $this->performUpdate($request, $table, $id, 'app.codes.show', 'app.codes.edit');
    }

    /**
     * 直接更新代碼表的共用實作；$showRoute/$editRoute 控制唯讀/成功重導目標。
     */
    protected function performUpdate(Request $request, string $table, $id, string $showRoute, string $editRoute) {
        if (!Auth::check()) {
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->isActive()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止編輯。', 'warning');

            return redirect()->route($showRoute, ['table_name' => $table]);
        }
        $keyColumns = $this->getKeyColumns($table);
        $conditions = $this->buildConditionsFromId($keyColumns, $id);
        $originalRow = $this->fetchRowByKeys($table, $keyColumns, $conditions);

        $query = DB::table($table);
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }
        $data = Arr::except($request->all(), ['_method', '_token', '__proposal_comment']);
        $data = $this->enforceAuditFieldsForUpdate($data, $originalRow ?: []);
        $data = $this->normalizeCodeTablePinyin($table, $data);

        try {
            $query->update($data);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($this->isDuplicateKeyException($e)) {
                flash('更新失敗：主鍵或唯一值已存在。', 'error');

                return redirect()->back()
                    ->withInput()
                    ->withErrors(['duplicate' => '更新失敗：主鍵或唯一值已存在。']);
            }

            throw $e;
        }

        $updatedRow = $this->fetchRowByKeys($table, $keyColumns, $conditions) ?: ($originalRow ? array_merge($originalRow, $data) : $data);

        $this->recordOperation(2, $table, $keyColumns, $updatedRow, $originalRow ?: []);

        flash('Update success @ '.Carbon::now(), 'success');

        $id = $this->buildCompositeId($keyColumns, $updatedRow);

        return redirect()->route($editRoute, ['table_name' => $table, 'id' => $id]);
    }

    //20210315增加table_name等於SOCIAL_INSTITUTION_CODES的例外判斷式，將預設遮除的第1個欄位呈現。
    public function create($table_name) {
        //        dd($table_name);
        $table = $this->guardTable($table_name);
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止新增。', 'warning');

            return redirect()->route('codes.show', ['table_name' => $table]);
        }
        $columns = $this->getTableColumns($table);
        $keyColumns = $this->getKeyColumns($table);
        $columns = $this->orderColumnsForCreate($columns, $keyColumns);

        $defaults = [];
        $firstKey = $keyColumns[0] ?? null;
        if ($firstKey && in_array($firstKey, $columns, true)) {
            $nextValue = $this->guessNextKeyValue($table, $firstKey);
            if ($nextValue !== null) {
                $defaults[$firstKey] = $nextValue;
            }
        }

        $firstColumn = $columns[0] ?? null;
        $id = $firstColumn && isset($defaults[$firstColumn]) ? $defaults[$firstColumn] : null;

        return view('codes.create', [
            'page_title' => '新增',
            'page_description' => '',
            'page_url' => '/codes',
            'archer' => "<li class='breadcrumb-item'><a href='/codes'>Codes</a></li><li class='breadcrumb-item'><a href='/codes/".rawurlencode($table)."'>".e($table)."</a></li>",
            'row' => $columns,
            'id' => $id,
            'defaults' => $defaults,
            'table' => $table,
        ]);
    }

    /**
     * Inertia + React 版：新增表單頁。
     */
    public function appCreate($table_name) {
        $table = $this->guardTable($table_name);
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止新增。', 'warning');

            return redirect()->route('app.codes.show', ['table_name' => $table]);
        }

        $columns = $this->getTableColumns($table);
        $keyColumns = $this->getKeyColumns($table);
        $columns = $this->orderColumnsForCreate($columns, $keyColumns);

        $defaults = [];
        $firstKey = $keyColumns[0] ?? null;
        if ($firstKey && in_array($firstKey, $columns, true)) {
            $nextValue = $this->guessNextKeyValue($table, $firstKey);
            if ($nextValue !== null) {
                $defaults[$firstKey] = $nextValue;
            }
        }

        return Inertia::render('Codes/Create', [
            'table' => $table,
            'columns' => array_values($columns),
            'defaults' => (object) $defaults,
            'can_propose' => Auth::check() && Auth::user()->isActive(),
            'tier2_fields' => $this->codeTableTier2Fields($table),
            'urls' => [
                'store' => route('app.codes.store', ['table_name' => $table], false),
                'propose' => route('app.codes.propose.store', ['table_name' => $table], false),
                'show' => route('app.codes.show', ['table_name' => $table], false),
            ],
            'page_translations' => [
                'codes' => is_array($t = trans('codes')) ? $t : [],
            ],
        ]);
    }

    public function proposalStore(Request $request, $table_name) {
        $table = $this->guardTable($table_name);

        return $this->performProposalStore($request, $table, 'codes.show');
    }

    /**
     * Inertia + React 版：提交新增提案（與 Blade proposalStore 共用 performProposalStore）。
     */
    public function appProposeStore(Request $request, $table_name) {
        $table = $this->guardTable($table_name);

        return $this->performProposalStore($request, $table, 'app.codes.show');
    }

    /**
     * 新增提案的共用實作。$showRoute 控制成功後重導目標；授權/驗證/提案記錄邏輯共用。
     */
    protected function performProposalStore(Request $request, string $table, string $showRoute) {
        if ($redirect = $this->ensureEditableAccess($table)) {
            return $redirect;
        }

        $payload = $this->extractFormData($request);
        $payload = $this->normalizeCodeTablePinyin($table, $payload);
        $keyColumns = $this->getKeyColumns($table);

        if (!$this->hasPrimaryKeyValues($keyColumns, $payload)) {
            flash('提案失敗：請確認主鍵欄位已填寫完整。', 'error');

            return redirect()->back()->withInput();
        }

        $conditions = $this->buildConditionsFromRow($keyColumns, $payload);
        $existing = $this->fetchRowByKeys($table, $keyColumns, $conditions);
        if ($existing) {
            flash('提案失敗：資料已存在，請改用修改提案。', 'warning');

            return redirect()->back()->withInput();
        }

        if ($this->hasActiveCreateProposalConflict($table, $keyColumns, $payload)) {
            flash('提案失敗：已有其他新增提案使用相同主鍵，請調整後再提交。', 'warning');

            return redirect()->back()->withInput();
        }

        $meta = $this->buildProposalMeta('create', $table, $request);
        $operation = $this->recordProposalOperation(
            Operation::TYPE_PROPOSAL_CREATE,
            $table,
            $keyColumns,
            $payload,
            [],
            $meta
        );

        if ($operation) {
            flash('已提交新增提案，等待管理員審核 @ '.Carbon::now(), 'info');
        }

        return redirect()->route($showRoute, ['table_name' => $table]);
    }

    public function proposalEdit($table_name, $operationId) {
        $table = $this->guardTable($table_name);
        $operation = $this->findOperationOrAbort((int) $operationId);
        $payload = $this->ensureProposalEditable($operation, $table);

        $columns = Schema::getColumnListing($table);
        $values = [];
        foreach ($columns as $column) {
            $values[$column] = $payload[$column] ?? '';
        }

        return view('codes.proposal-edit', [
            'table' => $table,
            'columns' => $columns,
            'values' => $values,
            'operationId' => $operation['id'],
            'keyColumns' => $payload['__key_columns'] ?? $this->getKeyColumns($table),
            'proposalMeta' => $payload['__proposal_meta'] ?? [],
            'reviewStatus' => $payload['__review_status'] ?? 'pending',
            'reviewComment' => $payload['__review_comment'] ?? null,
            'isCreateProposal' => (int) $operation['op_type'] === Operation::TYPE_PROPOSAL_CREATE,
            'page_title' => 'Codes',
            'page_description' => $table . ' ' . __('admin.proposal_adjustment'),
            'page_url' => route('codes.show', ['table_name' => $table]),
            'archer' => "<li class='breadcrumb-item'><a href='/codes'>全部表格</a></li><li class='breadcrumb-item'><a href='/codes/".rawurlencode($table)."'>".e($table)."</a></li><li class='breadcrumb-item active'>提案調整</li>",
        ]);
    }

    /**
     * Inertia + React 版：提案調整表單頁（與 Blade proposalEdit 同資料來源）。
     */
    public function appProposalEdit($table_name, $operationId) {
        $table = $this->guardTable($table_name);
        $operation = $this->findOperationOrAbort((int) $operationId);
        $payload = $this->ensureProposalEditable($operation, $table);

        $columns = Schema::getColumnListing($table);
        $values = [];
        foreach ($columns as $column) {
            $values[$column] = $payload[$column] ?? '';
        }

        return Inertia::render('Codes/ProposalEdit', [
            'table' => $table,
            'columns' => array_values($columns),
            'values' => $values,
            'operation_id' => $operation['id'],
            'key_columns' => array_values($payload['__key_columns'] ?? $this->getKeyColumns($table)),
            'proposal_meta' => (object) ($payload['__proposal_meta'] ?? []),
            'review_status' => $payload['__review_status'] ?? 'pending',
            'review_comment' => $payload['__review_comment'] ?? null,
            'is_create_proposal' => (int) $operation['op_type'] === Operation::TYPE_PROPOSAL_CREATE,
            'urls' => [
                'update' => route('app.codes.proposals.update', ['table_name' => $table, 'operation' => $operation['id']], false),
                'return' => route('operations.index', ['proposals_only' => 1], false),
            ],
            'page_translations' => [
                'codes' => is_array($t = trans('codes')) ? $t : [],
            ],
        ]);
    }

    public function proposalUpdateExisting(Request $request, $table_name, $operationId) {
        $table = $this->guardTable($table_name);
        $operation = $this->findOperationOrAbort((int) $operationId);
        $payload = $this->ensureProposalEditable($operation, $table);
        $keyColumns = $payload['__key_columns'] ?? $this->getKeyColumns($table);

        $data = $this->extractFormData($request);
        $data = $this->normalizeCodeTablePinyin($table, $data); // §D-6：編輯既有提案時亦歸一化 Tier 1，避免核准落庫仍帶 v
        $isCreate = (int) $operation['op_type'] === Operation::TYPE_PROPOSAL_CREATE;

        if ($isCreate) {
            if (!$this->hasPrimaryKeyValues($keyColumns, $data)) {
                flash('提案失敗：請確認主鍵欄位已填寫完整。', 'error');

                return redirect()->back()->withInput();
            }

            $conditions = $this->buildConditionsFromRow($keyColumns, $data);
            if (!empty($conditions) && $this->fetchRowByKeys($table, $keyColumns, $conditions)) {
                flash('提案失敗：資料已存在，請改用修改提案。', 'warning');

                return redirect()->back()->withInput();
            }

            if ($this->hasActiveCreateProposalConflict($table, $keyColumns, $data, $operation['id'])) {
                flash('提案失敗：已有其他新增提案使用相同主鍵，請調整後再提交。', 'warning');

                return redirect()->back()->withInput();
            }
        } else {
            $original = $this->decodeResourceOriginal($operation);
            if (!empty($original)) {
                $data = $this->enforceAuditFieldsForUpdate($data, $original);
            }
        }

        $meta = $payload['__proposal_meta'] ?? [];
        $comment = trim((string) $request->input('__proposal_comment', ''));
        if ($comment !== '') {
            $meta['comment'] = $comment;
        } else {
            unset($meta['comment']);
        }
        unset($meta['cancelled_at'], $meta['cancelled_by'], $meta['cancelled_by_id'], $meta['cancel_reason']);
        $meta['updated_at'] = Carbon::now()->format('Y-m-d H:i:s');

        $newPayload = $data;
        $newPayload['__proposal_meta'] = $meta;
        $newPayload['__key_columns'] = $keyColumns;
        $this->resetProposalReviewState($newPayload);

        $updates = [
            'resource_data' => json_encode($newPayload, JSON_UNESCAPED_UNICODE),
            'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ];

        if ($isCreate) {
            $resourceId = $this->buildCompositeId($keyColumns, $newPayload);
            if ($resourceId === '') {
                $resourceId = 'proposal:' . $operation['id'];
            }
            $updates['resource_id'] = $resourceId;
        }

        DB::table('operations')->where('id', $operation['id'])->update($updates);

        flash('提案內容已更新，等待審核 @ '.Carbon::now(), 'success');

        return redirect()->route('operations.index', ['proposals_only' => 1]);
    }

    public function proposalCancel(Request $request, $table_name, $operationId) {
        $table = $this->guardTable($table_name);
        $operation = $this->findOperationOrAbort((int) $operationId);
        $payload = $this->ensureProposalEditable($operation, $table);

        if (!isset($payload['__proposal_meta']) || !is_array($payload['__proposal_meta'])) {
            $payload['__proposal_meta'] = [];
        }

        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            unset($payload['__proposal_meta']['cancel_reason']);
        } else {
            $payload['__proposal_meta']['cancel_reason'] = $reason;
        }

        $payload['__proposal_meta']['cancelled_at'] = Carbon::now()->format('Y-m-d H:i:s');
        $payload['__proposal_meta']['cancelled_by'] = Auth::user()->name ?? Auth::id();
        $payload['__proposal_meta']['cancelled_by_id'] = Auth::id();
        $this->markProposalCancelled($payload);

        DB::table('operations')->where('id', $operation['id'])->update([
            'resource_data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        flash('提案已撤回 @ '.Carbon::now(), 'info');

        return redirect()->route('operations.index', ['proposals_only' => 1]);
    }

    //20210315增加table_name等於SOCIAL_INSTITUTION_CODES的例外判斷式，將預設自動增加的$id遮除。
    public function store(Request $request, $table_name) {
        $table = $this->guardTable($table_name);

        return $this->performStore($request, $table, 'codes.show', 'codes.edit');
    }

    /**
     * Inertia + React 版：直接儲存（與 Blade store 共用 performStore）。
     */
    public function appStore(Request $request, $table_name) {
        $table = $this->guardTable($table_name);

        // 編輯頁尚未遷移（P2-4），成功後暫導向 app.codes.show。
        return $this->performStore($request, $table, 'app.codes.show', 'app.codes.show');
    }

    /**
     * 直接寫入代碼表的共用實作。$showRoute/$editRoute 控制唯讀/成功的重導目標，
     * 其餘授權/驗證/寫入/稽核邏輯 Blade 與 Inertia 完全共用（write-path 單一來源）。
     */
    protected function performStore(Request $request, string $table, string $showRoute, string $editRoute) {
        if (!Auth::check()) {
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->isActive()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止新增。', 'warning');

            return redirect()->route($showRoute, ['table_name' => $table]);
        }
        $data = Arr::except($request->all(), ['_token', '__proposal_comment']);
        $keyColumns = $this->getKeyColumns($table);
        if (!$this->hasPrimaryKeyValues($keyColumns, $data)) {
            flash('新增失敗：請確認主鍵欄位已填寫完整。', 'error');

            return redirect()->back()
                ->withInput()
                ->withErrors(['missing_keys' => '新增失敗：請確認主鍵欄位已填寫完整。']);
        }
        $data = $this->enforceAuditFieldsForCreate($table, $data);
        $data = $this->normalizeCodeTablePinyin($table, $data);

        //20210323遮除「第一欄預設隱藏」
        //$id_ = $this->getIdName($table_name);
        //if($table_name != 'SOCIAL_INSTITUTION_CODES') {
        //$id = DB::table($table_name)->max($id_) + 1;
        //$data[$id_] = $id;
        //}
        //else {
        //當資料表等於SOCIAL_INSTITUTION_CODES，$id從表單取值。
        //$id = $data[$id_];
        //}
        try {
            DB::table($table)->insert($data);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($this->isDuplicateKeyException($e)) {
                flash('新增失敗：主鍵或唯一值已存在。', 'error');

                return redirect()->back()
                    ->withInput()
                    ->withErrors(['duplicate' => '新增失敗：主鍵或唯一值已存在。']);
            }

            throw $e;
        }

        $storedRow = $this->fetchRowByKeys($table, $keyColumns, $this->buildConditionsFromRow($keyColumns, $data));
        $rowData = $storedRow ?: $data;
        $this->recordOperation(1, $table, $keyColumns, $rowData);

        $id = $this->buildCompositeId($keyColumns, $rowData);

        flash('Store success @ '.Carbon::now(), 'success');

        return redirect()->route($editRoute, ['table_name' => $table, 'id' => $id]);
    }

    public function proposalUpdate(Request $request, $table_name, $id) {
        $table = $this->guardTable($table_name);

        return $this->performProposalUpdate($request, $table, $id, 'codes.edit');
    }

    /**
     * Inertia + React 版：提交修改提案（與 Blade proposalUpdate 共用）。
     */
    public function appProposalUpdate(Request $request, $table_name, $id) {
        $table = $this->guardTable($table_name);

        return $this->performProposalUpdate($request, $table, $id, 'app.codes.edit');
    }

    /**
     * 修改提案的共用實作；$editRoute 控制成功重導目標。
     */
    protected function performProposalUpdate(Request $request, string $table, $id, string $editRoute) {
        if ($redirect = $this->ensureEditableAccess($table)) {
            return $redirect;
        }

        $keyColumns = $this->getKeyColumns($table);
        $conditions = $this->buildConditionsFromId($keyColumns, $id);
        $originalRow = $this->fetchRowByKeys($table, $keyColumns, $conditions);
        if (!$originalRow) {
            flash('提案失敗：找不到對應的資料列。', 'error');

            return redirect()->back()->withInput();
        }

        $payload = $this->enforceAuditFieldsForUpdate(
            $this->extractFormData($request),
            $originalRow
        );
        $payload = $this->normalizeCodeTablePinyin($table, $payload);

        $diff = $this->operationRepository->getArrDiff($payload, $originalRow, $originalRow);
        if ($diff === null) {
            flash('提案失敗：未偵測到任何修改內容。', 'warning');

            return redirect()->back()->withInput();
        }

        $meta = $this->buildProposalMeta('update', $table, $request);
        $operation = $this->recordProposalOperation(
            Operation::TYPE_PROPOSAL_UPDATE,
            $table,
            $keyColumns,
            $payload,
            $originalRow,
            $meta
        );

        if ($operation) {
            flash('已提交修改提案，等待管理員審核 @ '.Carbon::now(), 'info');
        }

        return redirect()->route($editRoute, ['table_name' => $table, 'id' => $id]);
    }

    public function destroy($table_name, $id) {
        $table = $this->guardTable($table_name);

        return $this->performDestroy($table, $id, 'codes.show');
    }

    /**
     * Inertia + React 版：刪除（與 Blade destroy 共用 performDestroy）。
     */
    public function appDestroy($table_name, $id) {
        $table = $this->guardTable($table_name);

        return $this->performDestroy($table, $id, 'app.codes.show');
    }

    /**
     * 刪除代碼表列的共用實作；$showRoute 控制唯讀/成功重導目標。
     */
    protected function performDestroy(string $table, $id, string $showRoute) {
        if (!Auth::check()) {
            flash('請登入後編輯 @ '.Carbon::now(), 'error');

            return redirect()->back();
        } elseif (!Auth::user()->isActive()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止刪除。', 'warning');

            return redirect()->route($showRoute, ['table_name' => $table]);
        }
        $keyColumns = $this->getKeyColumns($table);
        $conditions = $this->buildConditionsFromId($keyColumns, $id);
        $row = $this->fetchRowByKeys($table, $keyColumns, $conditions);

        $this->recordOperation(4, $table, $keyColumns, $row ?: $conditions, $row ?: []);

        $query = DB::table($table);
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }
        $query->delete();

        flash('Delete success @ '.Carbon::now(), 'success');

        return redirect()->route($showRoute, ['table_name' => $table]);
    }

    protected function buildTableHead(string $table, $sampleRow, ?array $joinConfig = null): array {
        $thead = $this->getTableColumns($table);

        if (!empty($joinConfig)) {
            $thead = array_merge($thead, $this->getJoinedColumnNames($joinConfig));
        }

        if ($sampleRow) {
            $thead = array_merge($thead, array_keys((array) $sampleRow));
        }

        return array_values(array_unique(array_filter($thead)));
    }

    protected function determineSearchableColumns(string $table, array $thead): array {
        if (!empty($thead)) {
            return $thead;
        }

        return $this->getTableColumns($table);
    }

    protected function getKeyColumns(string $table): array {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $upperTable = strtoupper($table);

        // 先检查配置，有配置则直接返回，不需要查询数据库
        if (isset($this->tablePrimaryKeyOverrides[$upperTable])) {
            $overrideKeys = array_values(array_unique(array_filter($this->tablePrimaryKeyOverrides[$upperTable])));
            if (!empty($overrideKeys)) {
                return $cache[$table] = $overrideKeys;
            }
        }

        $keys = [];

        try {
            $indexes = Schema::getIndexes($table);
            foreach ($indexes as $index) {
                if (!empty($index['primary']) && !empty($index['columns']) && is_array($index['columns'])) {
                    $keys = $index['columns'];

                    break;
                }
            }
        } catch (\Throwable $e) {
            $keys = [];
        }

        try {
            if (empty($keys)) {
                $connection = DB::connection();
                $details = $connection->getDoctrineSchemaManager()->listTableDetails($table);
                if ($details->hasPrimaryKey()) {
                    $keys = $details->getPrimaryKey()->getColumns();
                }
            }
        } catch (\Throwable $e) {
            if (empty($keys)) {
                $keys = [];
            }
        }

        // 只有在需要时才查询列（作为 fallback）
        if (empty($keys)) {
            $columns = Schema::getColumnListing($table);
            $keys[] = $columns[0] ?? 'id';
            if (isset($columns[1])) {
                $keys[] = $columns[1];
            }
        }

        return $cache[$table] = array_values(array_unique(array_filter($keys)));
    }

    /**
     * @param mixed $rawFilters
     * @param array<int, string> $thead
     * @return array<string, string>
     */
    protected function sanitizeColumnFilters($rawFilters, array $thead): array {
        if (!is_array($rawFilters)) {
            return [];
        }

        $filters = [];
        foreach ($rawFilters as $column => $value) {
            if (!in_array($column, $thead, true) || !is_scalar($value)) {
                continue;
            }

            $filters[$column] = trim((string) $value);
        }

        return $filters;
    }

    /**
     * @param mixed $sortBy
     * @param mixed $sortDir
     * @param array<int, string> $thead
     * @return array{0: string, 1: string}
     */
    protected function sanitizeSortParameters($sortBy, $sortDir, array $thead): array {
        $normalizedSortBy = is_scalar($sortBy) ? trim((string) $sortBy) : '';
        $normalizedSortDir = strtolower(is_scalar($sortDir) ? trim((string) $sortDir) : 'asc');

        if (!in_array($normalizedSortDir, ['asc', 'desc'], true)) {
            $normalizedSortDir = 'asc';
        }

        if (!in_array($normalizedSortBy, $thead, true)) {
            $normalizedSortBy = '';
        }

        return [$normalizedSortBy, $normalizedSortDir];
    }

    /**
     * Determine whether the given table should be treated as read-only.
     *
     * @param string $table
     * @return bool
     */
    protected function isReadOnlyTable(string $table): bool {
        return in_array(strtoupper($table), $this->readOnlyTables, true);
    }

    /**
     * Retrieve mapping of dynasty IDs to Chinese dynasty names.
     *
     * @return array<int|string, string>
     */
    protected function getDynastyNameMap(): array {
        if ($this->dynastyNameMap !== null) {
            return $this->dynastyNameMap;
        }

        try {
            $map = DB::table('DYNASTIES')
                ->select('c_dy', 'c_dynasty_chn')
                ->get()
                ->pluck('c_dynasty_chn', 'c_dy')
                ->toArray();
        } catch (\Throwable $e) {
            $map = [];
        }

        $normalized = [];
        foreach ($map as $id => $name) {
            $normalized[(string) $id] = $name;
        }

        return $this->dynastyNameMap = $normalized;
    }

    protected function buildCompositeId(array $keyColumns, array $row): string {
        $parts = [];
        foreach ($keyColumns as $column) {
            if (array_key_exists($column, $row)) {
                $parts[] = (string) $row[$column];
            }
        }

        return implode('_._', array_filter($parts, function ($part) {
            return $part !== '';
        }));
    }

    /**
     * Apply audit columns for create operations when the table supports them.
     *
     * @param string $table
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    /**
     * §D-6 保存止血：對本表的 **Tier 1** 拼音欄（config/code_table_mutations.php）靜默套 v→ü 歸一化。
     *
     * Tier 2（混西文，如 ADDR_CODES.c_name）**不**在此轉——由前端 altname 式彈窗讓使用者決定。
     * 未登錄於 config 的表（非 Phase B 拼音表）不動任何欄。與 v2 API 的 ConfigCodeTableMutationHandler 一致。
     */
    protected function normalizeCodeTablePinyin(string $table, array $data): array {
        $upper = strtoupper($table);
        foreach ((array) config('code_table_mutations.tables', []) as $def) {
            if (($def['table'] ?? null) === $upper) {
                return PinyinUmlaut::normalizeFields($data, $def['tier1_fields'] ?? []);
            }
        }

        return $data;
    }

    /**
     * 本表的 Tier 2 拼音欄（可能含西文）——傳給前端 /codes 編輯器，供保存時偵測 v→ü 並彈窗由使用者決定。
     * 未登錄於 config 的表回傳空陣列（不彈窗）。
     *
     * @return list<string>
     */
    protected function codeTableTier2Fields(string $table): array {
        $upper = strtoupper($table);
        foreach ((array) config('code_table_mutations.tables', []) as $def) {
            if (($def['table'] ?? null) === $upper) {
                return array_values($def['tier2_fields'] ?? []);
            }
        }

        return [];
    }

    protected function enforceAuditFieldsForCreate(string $table, array $data): array {
        $columns = $this->getTableColumns($table);
        $now = Carbon::now();

        if (in_array('c_created_by', $columns, true) && Auth::check()) {
            $data['c_created_by'] = Auth::user()->name;
        }

        if (in_array('c_created_date', $columns, true)) {
            // Store as Carbon object (Laravel will convert to TIMESTAMP in DB)
            $data['c_created_date'] = $now;
        }

        return $data;
    }

    /**
     * Retrieve (and cache) table columns.
     *
     * @param string $table
     * @return array<int, string>
     */
    protected function getTableColumns(string $table): array {
        if (!array_key_exists($table, $this->tableColumnsCache)) {
            $this->tableColumnsCache[$table] = $this->resolveTableColumns($table);
        }

        return $this->tableColumnsCache[$table];
    }

    /**
     * Resolve table columns from schema builder and fallback to information_schema/PRAGMA.
     *
     * @param string $table
     * @return array<int, string>
     */
    protected function resolveTableColumns(string $table): array {
        try {
            $columns = Schema::getColumnListing($table);
            if (!empty($columns)) {
                return array_values(array_unique($columns));
            }
        } catch (\Throwable $e) {
            // Fall through to DB metadata query.
        }

        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            if ($driver === 'sqlite') {
                $rows = $connection->select('PRAGMA table_info("'.$table.'")');
                $columns = [];
                foreach ($rows as $row) {
                    $name = (array) $row;
                    if (!empty($name['name'])) {
                        $columns[] = $name['name'];
                    }
                }

                return array_values(array_unique($columns));
            }

            $databaseName = $connection->getDatabaseName();
            if ($databaseName !== null && $databaseName !== '') {
                $rows = $connection->select(
                    'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                    [$databaseName, $table]
                );
                $columns = [];
                foreach ($rows as $row) {
                    $name = (array) $row;
                    if (!empty($name['COLUMN_NAME'])) {
                        $columns[] = $name['COLUMN_NAME'];
                    }
                }

                return array_values(array_unique($columns));
            }
        } catch (\Throwable $e) {
            // Metadata fallback failed.
        }

        return [];
    }

    /**
     * Ensure primary key columns appear first when rendering create form.
     *
     * @param array<int, string> $columns
     * @param array<int, string> $keyColumns
     * @return array<int, string>
     */
    protected function orderColumnsForCreate(array $columns, array $keyColumns): array {
        $keyColumns = array_values(array_intersect($keyColumns, $columns));
        $nonKey = array_values(array_diff($columns, $keyColumns));

        return array_merge($keyColumns, $nonKey);
    }

    /**
     * Guess the next key value for auto-increment-like columns.
     *
     * @param string $table
     * @param string $column
     * @return string|null
     */
    protected function guessNextKeyValue(string $table, string $column): ?string {

        try {
            $max = DB::table($table)->max($column);
        } catch (\Throwable $e) {
            return null;
        }

        if ($max === null) {
            return '1';
        }

        if (is_numeric($max)) {
            return (string) ((int) $max + 1);
        }

        return null;
    }

    /**
     * Ensure audit columns cannot be tampered with via requests.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $original
     * @return array<string, mixed>
     */
    protected function enforceAuditFieldsForUpdate(array $data, array $original): array {
        $now = Carbon::now();

        // 保护 created_* 字段不被修改
        foreach (['c_created_by', 'c_created_date'] as $field) {
            if (array_key_exists($field, $data) && array_key_exists($field, $original)) {
                $data[$field] = $original[$field];
            }
        }

        // 更新 c_modified_by
        if (array_key_exists('c_modified_by', $data)) {
            if (Auth::check()) {
                $data['c_modified_by'] = Auth::user()->name;
            } elseif (array_key_exists('c_modified_by', $original)) {
                $data['c_modified_by'] = $original['c_modified_by'];
            }
        }

        // 更新 c_modified_date
        if (array_key_exists('c_modified_date', $data)) {
            // Store as Carbon object (Laravel will convert to TIMESTAMP in DB)
            $data['c_modified_date'] = $now;
        } elseif (array_key_exists('c_modified_date', $original)) {
            $data['c_modified_date'] = $original['c_modified_date'];
        }

        return $data;
    }

    /**
     * Reorder fields to display audit columns in a logical sequence.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function orderAuditFieldsForDisplay(array $row): array {
        // 定义审计字段的理想顺序
        $auditFieldOrder = [
            'c_created_by',
            'c_created_date',
            'c_modified_by',
            'c_modified_date',
        ];

        // 时间戳字段需要转换时区
        $timestampFields = [
            'c_created_date',
            'c_modified_date',
        ];

        // 分离审计字段和其他字段
        $auditFields = [];
        $otherFields = [];

        foreach ($row as $key => $value) {
            if (in_array($key, $auditFieldOrder, true)) {
                // 时间戳字段转换为应用配置的时区
                if (in_array($key, $timestampFields, true) && $value !== null && $value !== '') {
                    try {
                        $carbon = Carbon::parse($value);
                        // 转换为应用配置的时区（与写入时保持一致）
                        $carbon->setTimezone(config('app.timezone'));
                        $auditFields[$key] = $carbon->format('Y-m-d H:i:s');
                    } catch (\Exception $e) {
                        // 如果解析失败，保持原值
                        $auditFields[$key] = $value;
                    }
                } else {
                    $auditFields[$key] = $value;
                }
            } else {
                $otherFields[$key] = $value;
            }
        }

        // 按照定义的顺序重新排列审计字段
        $orderedAuditFields = [];
        foreach ($auditFieldOrder as $field) {
            if (array_key_exists($field, $auditFields)) {
                $orderedAuditFields[$field] = $auditFields[$field];
            }
        }

        // 合并：其他字段在前，审计字段在后（按顺序）
        return array_merge($otherFields, $orderedAuditFields);
    }

    protected function buildConditionsFromRow(array $keyColumns, array $row): array {
        $conditions = [];
        foreach ($keyColumns as $column) {
            if (array_key_exists($column, $row)) {
                $conditions[$column] = $row[$column];
            }
        }

        return $conditions;
    }

    protected function buildConditionsFromId(array $keyColumns, string $id): array {
        $conditions = [];
        $parts = explode('_._', $id);
        foreach ($keyColumns as $index => $column) {
            if (isset($parts[$index]) && $parts[$index] !== '') {
                $conditions[$column] = $parts[$index];
            }
        }

        return $conditions;
    }

    protected function fetchRowByKeys(string $table, array $keyColumns, array $conditions) {
        if (empty($conditions)) {
            return null;
        }

        $query = DB::table($table);
        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }

        $row = $query->first();

        return $row ? $this->convertRowToArray($row) : null;
    }

    protected function hasActiveCreateProposalConflict(string $table, array $keyColumns, array $row, ?int $excludeOperationId = null): bool {
        if (!Schema::hasTable('operations')) {
            return false;
        }

        if (count($keyColumns) === 1) {
            return false;
        }

        $resourceId = $this->buildCompositeId($keyColumns, $row);
        if ($resourceId === '') {
            return false;
        }

        return $this->operationRepository->hasPendingCreateProposal($table, $resourceId, $excludeOperationId);
    }

    protected function findOperationOrAbort(int $operationId): array {
        if (!Schema::hasTable('operations')) {
            abort(404);
        }

        $row = DB::table('operations')->where('id', $operationId)->first();
        if (!$row) {
            abort(404);
        }

        return (array) $row;
    }

    protected function ensureProposalEditable(array $operation, string $table): array {
        if (!Auth::check()) {
            abort(403, '請登入後再試。');
        }

        if (($operation['resource'] ?? null) !== $table) {
            abort(404);
        }

        $payload = json_decode($operation['resource_data'] ?? '', true);
        $payload = is_array($payload) ? $payload : [];

        $meta = $payload['__proposal_meta'] ?? [];
        $submittedById = $meta['submitted_by_id'] ?? ($operation['user_id'] ?? null);

        if ($submittedById === null || (int) $submittedById !== (int) Auth::id()) {
            abort(403, '僅提案者本人可編輯或撤回該提案。');
        }

        $status = $payload['__review_status'] ?? 'pending';
        if (!in_array($status, ['pending', 'rejected'], true)) {
            abort(403, '該提案目前不可修改或撤回。');
        }

        return $payload;
    }

    protected function resetProposalReviewState(array &$payload): void {
        $payload['__review_status'] = 'pending';
        unset(
            $payload['__review_comment'],
            $payload['__reviewed_by'],
            $payload['__reviewed_by_id'],
            $payload['__reviewed_at'],
            $payload['__cancelled_at'],
            $payload['__cancelled_by'],
            $payload['__cancelled_by_id']
        );
    }

    protected function markProposalCancelled(array &$payload): void {
        $payload['__review_status'] = 'cancelled';
        unset(
            $payload['__review_comment'],
            $payload['__reviewed_by'],
            $payload['__reviewed_by_id'],
            $payload['__reviewed_at']
        );
    }

    protected function decodeResourceOriginal(array $operation): array {
        $original = json_decode($operation['resource_original'] ?? '', true);

        return is_array($original) ? $original : [];
    }

    protected function ensureEditableAccess(string $table): ?RedirectResponse {
        if (!Auth::check()) {
            flash('請登入後再進行操作 @ '.Carbon::now(), 'error');

            return redirect()->back()->withInput();
        }
        if (!Auth::user()->isActive()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

            return redirect()->back()->withInput();
        }
        if ($this->isReadOnlyTable($table)) {
            flash('該代碼表為只讀，禁止編輯或提案。', 'warning');

            return redirect()->route('codes.show', ['table_name' => $table]);
        }

        return null;
    }

    protected function extractFormData(Request $request): array {
        return Arr::except($request->all(), ['_token', '_method', '__proposal_comment']);
    }

    protected function hasPrimaryKeyValues(array $keyColumns, array $data): bool {
        foreach ($keyColumns as $column) {
            if (!array_key_exists($column, $data) || $data[$column] === '' || $data[$column] === null) {
                return false;
            }
        }

        return true;
    }

    protected function buildProposalMeta(string $action, string $table, Request $request): array {
        $user = Auth::user();
        $meta = [
            'action' => $action,
            'table' => $table,
            'submitted_by' => $user ? ($user->name ?: $user->email ?: $user->id) : null,
            'submitted_by_id' => $user ? $user->id : null,
            'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ];

        $comment = trim((string) $request->input('__proposal_comment', ''));
        if ($comment !== '') {
            $meta['comment'] = $comment;
        }

        return array_filter($meta, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    protected function recordProposalOperation(int $type, string $table, array $keyColumns, array $data, array $original = [], array $meta = []) {
        if (!Auth::check()) {
            return null;
        }

        $resourceRow = $type === Operation::TYPE_PROPOSAL_CREATE ? $data : ($original ?: $data);
        $resourceId = $this->buildCompositeId($keyColumns, $resourceRow);
        if ($resourceId === '') {
            $resourceId = 'proposal:' . uniqid();
        }

        $payload = $data;
        if (!empty($meta)) {
            $payload['__proposal_meta'] = $meta;
        }
        $payload['__key_columns'] = $keyColumns;
        $payload['__review_status'] = 'pending';

        return $this->operationRepository->store(
            Auth::id(),
            0,
            $type,
            $table,
            $resourceId,
            $payload,
            $original
        );
    }

    protected function recordOperation(int $type, string $table, array $keyColumns, array $data, array $original = []) {
        if (!Auth::check()) {
            return;
        }

        $resourceId = $this->buildCompositeId($keyColumns, $data);
        if ($resourceId === '' && !empty($original)) {
            $resourceId = $this->buildCompositeId($keyColumns, $original);
        }

        $operation = $this->operationRepository->store(
            Auth::id(),
            0,
            $type,
            $table,
            $resourceId,
            $data,
            $original
        );

        // §D-2：/codes 直寫路徑補 audit_log，使 UI 與 v2 API 審計一致（先前只寫 operations）。
        // 僅 direct 寫入（INSERT/UPDATE/DELETE）；提案（recordProposalOperation）不落表、於核准時才審計。
        // 注意：performUpdate 傳的是 TYPE_UPDATE_FULL(2)（Put），非 TYPE_UPDATE(3)；兩者皆映射為 UPDATE。
        $auditOp = match ($type) {
            Operation::TYPE_CREATE => 'INSERT',
            Operation::TYPE_UPDATE_FULL, Operation::TYPE_UPDATE => 'UPDATE',
            Operation::TYPE_DELETE => 'DELETE',
            default => null,
        };
        if ($auditOp !== null && Schema::hasTable('audit_log')) {
            $pkSource = !empty($data) ? $data : $original;
            $rowPk = [];
            foreach ($keyColumns as $col) {
                if (array_key_exists($col, $pkSource)) {
                    $rowPk[$col] = $pkSource[$col];
                }
            }
            $this->auditLogService->write(
                $table,
                $auditOp,
                $rowPk,
                $auditOp === 'INSERT' ? null : ($original ?: null),
                $auditOp === 'DELETE' ? null : ($data ?: null),
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );
        }
    }

    protected function convertRowToArray($row): array {
        if (is_null($row)) {
            return [];
        }

        if (is_array($row)) {
            return $row;
        }

        if ($row instanceof \ArrayAccess) {
            return (array) $row;
        }

        return json_decode(json_encode($row), true) ?: [];
    }

    protected function isDuplicateKeyException(\Illuminate\Database\QueryException $exception): bool {
        if ($exception->getCode() === '23000') {
            return true;
        }

        $message = $exception->getMessage();

        return strpos($message, 'Duplicate entry') !== false;
    }

    /**
     * Extract joined column names (aliases) from JOIN configuration.
     *
     * @param array $config
     * @return array
     */
    protected function getJoinedColumnNames(array $config): array {
        $joinedColumns = [];
        $select = $config['select'] ?? [];

        foreach ($select as $selectExpr) {
            // 提取 "column as alias" 中的 alias
            if (strpos($selectExpr, ' as ') !== false) {
                $parts = explode(' as ', $selectExpr);
                if (count($parts) === 2) {
                    $joinedColumns[] = trim($parts[1]);
                }
            }
        }

        return $joinedColumns;
    }

    /**
     * Build a JOIN query based on configuration.
     *
     * @param array $config
     * @return \Illuminate\Database\Query\Builder
     */
    protected function buildJoinQuery(array $config) {
        $baseTable = $config['base_table'];
        $baseAlias = $config['base_alias'];
        $joins = $config['joins'] ?? [];
        $select = $config['select'] ?? [];

        // Start query with base table alias
        $query = DB::table($baseTable . ' as ' . $baseAlias);

        // Apply JOINs
        foreach ($joins as $join) {
            $joinTable = $join['table'] . ' as ' . $join['alias'];
            $joinType = $join['type'] ?? 'left';

            if ($joinType === 'left') {
                $query->leftJoin($joinTable, $join['on'][0], $join['on'][1], $join['on'][2]);
            } elseif ($joinType === 'inner') {
                $query->join($joinTable, $join['on'][0], $join['on'][1], $join['on'][2]);
            }
        }

        // Always include all base table columns, then append configured joined aliases.
        $query->select(array_merge([$baseAlias . '.*'], $select));

        return $query;
    }

    /**
     * Show table with cursor-based pagination (for large tables).
     *
     * @param Request $request
     * @param string $table
     * @param \Illuminate\Database\Query\Builder $query
     * @param string $search
     * @param int $perPage
     * @param array $thead
     * @return \Illuminate\View\View
     */
    protected function buildCursorPayload(
        Request $request,
        string $table,
        $query,
        string $search,
        int $perPage,
        array $thead,
        array $filters = [],
        string $sortBy = '',
        string $sortDir = 'asc'
    ): array {
        $after = $request->query('after');   // 下一页游标 (id)
        $before = $request->query('before'); // 上一页游标 (id)

        // 游标查询逻辑
        if ($before) {
            // 上一页：取 id < before 的最后 N+1 条（倒序），然后反转
            $results = (clone $query)
                ->where('id', '<', $before)
                ->orderBy('id', 'desc')
                ->limit($perPage + 1)
                ->get();

            $hasMore = $results->count() > $perPage;
            if ($hasMore) {
                $results = $results->slice(0, $perPage);
            }
            $results = $results->reverse()->values();
            $hasPrev = $hasMore;
            $hasNext = true; // 既然有 before，说明肯定有下一页
        } else {
            // 下一页或首页：取 id > after 的前 N+1 条
            if ($after) {
                $query->where('id', '>', $after);
            }
            $results = $query
                ->orderBy('id', 'asc')
                ->limit($perPage + 1)
                ->get();

            $hasMore = $results->count() > $perPage;
            if ($hasMore) {
                $results = $results->slice(0, $perPage)->values();
            }
            $hasNext = $hasMore;
            $hasPrev = (bool)$after; // 有 after 说明不是首页，肯定有上一页
        }

        // 构建游标元数据
        $firstId = $results->first()->id ?? null;
        $lastId = $results->last()->id ?? null;

        $cursorMeta = [
            'type' => 'cursor',
            'data' => $results,
            'per_page' => $perPage,
            'first_id' => $firstId,
            'last_id' => $lastId,
            'has_more_pages' => $hasNext,
            'has_prev_pages' => $hasPrev,
            'next_cursor' => $hasNext ? $lastId : null,
            'prev_cursor' => $hasPrev ? $firstId : null,
            'search' => $search,
        ];

        // 其他元数据
        $dynastyMap = [];
        if (in_array('c_dy', $thead, true)) {
            $dynastyMap = $this->getDynastyNameMap();
        }

        $isReadOnly = $this->isReadOnlyTable($table);
        $keyColumns = $this->getKeyColumns($table);
        $copyrightNote = $this->tableCopyrightNotes[$table] ?? null;

        // 标记哪些列是通过 JOIN 获得的別名列        $upperTable = strtoupper($table);
        $upperTable = strtoupper($table);
        $joinConfig = $this->tableJoinConfigurations[$upperTable] ?? null;
        $joinedColumns = [];
        if ($joinConfig) {
            $joinedColumns = $this->getJoinedColumnNames($joinConfig);
        }

        return [
            'q' => $table,
            'thead' => $thead,
            'data' => $cursorMeta,  // 传递游标元数据而非标准分页对象
            'search' => $search,
            'dynastyMap' => $dynastyMap,
            'isReadOnly' => $isReadOnly,
            'exportable' => $this->isExportable($table),
            'keyColumns' => $keyColumns,
            'copyrightNote' => $copyrightNote,
            'joinedColumns' => $joinedColumns,
            'useCursorPagination' => true,  // 标记使用游标分页
            'filters' => $filters,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
            // 游标分頁路徑硬短路 filter/sort/布林，傳齊空值與兩分支對齊（避免 Blade undefined）。
            'booleanEnabled' => false,
            'booleanFilterAvailable' => false,
            'appliedFilters' => [],
            'rawFilters' => $filters,
            'filterErrors' => [],
            'filterDescriptions' => [],
        ];
    }

    protected function resolveColumnForQuery(string $column, ?array $joinConfig): ?string {
        if ($joinConfig === null) {
            return $column;
        }

        $baseAlias = $joinConfig['base_alias'];
        $baseTable = $joinConfig['base_table'];
        $selectList = $joinConfig['select'] ?? [];

        // 情境 B：欄位名是 JOIN alias（錨定結尾的 regex，防止 appt_name 匹配到 appt_name_chn）
        foreach ($selectList as $selectExpr) {
            if (preg_match('/\s+as\s+' . preg_quote($column, '/') . '\s*$/i', $selectExpr)) {
                $parts = preg_split('/\s+as\s+/i', $selectExpr, 2);
                if (count($parts) === 2) {
                    return trim($parts[0]);
                }
            }
        }

        // 情境 C：欄位名是 base table 的真實欄位，確認存在後補前綴
        if (!str_contains($column, '.')) {
            $baseTableColumns = $this->getTableColumns($baseTable);
            if (in_array($column, $baseTableColumns, true)) {
                return $baseAlias . '.' . $column;
            }

            return null;
        }

        // 情境 D：已帶 prefix，來源不明，拒絕
        return null;
    }
}
