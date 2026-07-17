<?php

namespace App\Http\Controllers;

use App\Services\Import\OfficeImportService;
use App\Support\ColumnFilterExpression;
use App\Support\ColumnFilterParseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * 「官職實體」聚合 CRUD 的 Inertia 頁面（/app/office/*）。
 *
 * 讀取（列表／載入）在此 controller 完成；寫入（create／update／delete）由前端走 mutation API
 * （/api/v2/*，resource=office），與 OfficeImportService 共用同一聚合存儲過程——故此 controller
 * 不自帶寫入路徑，避免與 mutation stack 產生第二份審計/寫入邏輯。
 *
 * 這是「上層聚合入口」：把 OFFICE_CODES + OFFICE_CODE_TYPE_REL 當作單一官職實體編輯，
 * 有別於 /app/codes/OFFICE_CODES 的裸單表 CRUD（後者為待收斂的下層洩漏路徑）。
 */
class OfficeEntityController extends Controller {
    /**
     * OFFICE_CODES 實體欄位（物理欄序，與 codes 裸表頁一致）。
     * 列表 thead ＝ 此清單 ＋ 計算欄位 type_count（見 COMPUTED_COLUMNS）。
     *
     * @var array<int, string>
     */
    protected const OFFICE_COLUMNS = [
        'c_office_id', 'c_dy', 'c_office_pinyin', 'c_office_chn',
        'c_office_pinyin_alt', 'c_office_chn_alt', 'c_office_trans', 'c_office_trans_alt',
        'c_source', 'c_pages', 'c_notes',
        'c_category_1', 'c_category_2', 'c_category_3', 'c_category_4', 'c_office_id_old',
    ];

    /**
     * 聚合計算欄位（對齊 CodesController::$tableComputedColumns 的結構）：
     * type_count ＝ 該官職在 OFFICE_CODE_TYPE_REL 的類型關聯數，是聚合視角特有、
     * 裸單表頁沒有的欄位；exact 比對避免數值欄位 LIKE 子字串誤命中。
     *
     * @var array<string, array{expression: string, match_mode: string}>
     */
    protected const COMPUTED_COLUMNS = [
        'type_count' => [
            'expression' => '(SELECT COUNT(*) FROM OFFICE_CODE_TYPE_REL WHERE OFFICE_CODE_TYPE_REL.c_office_id = OFFICE_CODES.c_office_id)',
            'match_mode' => 'exact',
        ],
    ];

    public function __construct(
        protected OfficeImportService $service,
        protected ColumnFilterExpression $columnFilterExpression,
    ) {
    }

    /** 瀏覽需登入且帳號有效。 */
    protected function ensureActive(): void {
        if (!Auth::check() || !Auth::user()->isActive()) {
            abort(403);
        }
    }

    /** 新增／編輯需直接寫入權限（與 mutation API authorizeDirect 對齊）。 */
    protected function ensureWrite(): void {
        if (!Auth::check() || !Auth::user()->canWriteDirectly()) {
            abort(403);
        }
    }

    /** 前端共用的 API 端點與路由。 */
    protected function urls(): array {
        return [
            'index' => route('app.office.index', [], false),
            'create' => route('app.office.create', [], false),
            // 字面模板（前端以 office_id 取代 __ID__）；不走 route() 以免撞 whereNumber('id') 約束。
            'edit_template' => '/app/office/__ID__/edit',
            'api_create' => '/api/v2/create',
            'api_mutate' => '/api/v2/mutate',
            'api_delete' => '/api/v2/delete',
            'search_type' => '/api/select/search/officetype',
            'search_source' => '/api/select/search/text',
            // 全表匯出沿用既有公開 codes 匯出路由（throttle:6,1、CC BY-NC-SA），不另開端點。
            'export' => '/codes/OFFICE_CODES/export',
        ];
    }

    protected function translations(): array {
        return [
            'office' => is_array($t = trans('office')) ? $t : [],
            // 列表的排序／篩選 UI（布林語法、錯誤訊息、套用說明）與 codes 頁共用同一組字串。
            'codes' => is_array($t = trans('codes')) ? $t : [],
        ];
    }

    /**
     * 官職列表：與 app/codes/OFFICE_CODES 裸表頁 feature parity（全欄位、任意欄排序＋主鍵
     * tie-breaker、逐欄篩選含布林模式、關鍵字搜尋、朝代標籤、公開可讀），另加聚合特有的
     * type_count 計算欄位。此頁是側欄「任官編碼表」的新入口，裸表頁封寫後為唯一編輯入口。
     */
    public function appIndex(Request $request) {
        // 對齊 codes 頁的訪問模型：讀取公開；排序／篩選需登入且已激活
        // （見 docs/CODES_SORT_FILTER_AUTH_GATE.md 的成本理由）。
        $guardRedirect = $this->guardSortFilterRequiresAuth($request);
        if ($guardRedirect !== null) {
            return $guardRedirect;
        }

        $thead = array_merge(self::OFFICE_COLUMNS, array_keys(self::COMPUTED_COLUMNS));

        $query = DB::table('OFFICE_CODES')->select('OFFICE_CODES.*');
        foreach (self::COMPUTED_COLUMNS as $name => $definition) {
            $query->selectRaw($definition['expression'].' as '.$name);
        }

        // 關鍵字搜尋：所有實體欄位 %term%（與 codes 頁 determineSearchableColumns 一致）。
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                foreach (self::OFFICE_COLUMNS as $column) {
                    $sub->orWhere($column, 'like', '%'.$q.'%');
                }
            });
        }

        // 逐欄篩選（欄位間 AND；布林模式逐欄解析，失敗記錯誤並略過，不轉字面）。
        $filters = $this->sanitizeColumnFilters($request->query('filters', []), $thead);
        $booleanAvailable = (bool) config('codes.boolean_filter_enabled', true);
        $booleanEnabled = $booleanAvailable && $request->boolean('filter_bool');
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
            $filterColumn = $this->resolveColumnForQuery($column);
            $matchMode = self::COMPUTED_COLUMNS[$column]['match_mode'] ?? 'contains';
            if ($matchMode === 'exact' && !$booleanEnabled && !is_numeric($value)) {
                // 非數字輸入略過：避免 MySQL 將字串隱式轉 0，誤命中 count=0 的列。
                continue;
            }

            if ($booleanEnabled) {
                try {
                    $ast = $this->columnFilterExpression->parse($value);
                } catch (ColumnFilterParseException $e) {
                    $filterErrors[$column] = $e->errorCode;

                    continue;
                }
                $this->columnFilterExpression->applyToBuilder($query, $filterColumn, $ast, $matchMode);
                $termLabels = $descLabels;
                if ($matchMode === 'exact') {
                    $termLabels['contains'] = (string) __('codes.filter_desc_exact');
                }
                $filterDescriptions[$column] = $this->columnFilterExpression->describe($ast, $termLabels);
            } elseif ($matchMode === 'exact') {
                // SQLite 對運算式比對字串繫結值不做數字轉換，先轉數字再綁定（MySQL 亦相容）。
                $query->where($filterColumn, '=', $value + 0);
            } else {
                $query->where($filterColumn, 'like', '%'.$value.'%');
            }

            $appliedFilters[$column] = $value;
        }

        // 排序 + 主鍵 tie-breaker（未指定排序時維持原本的 ID 倒序瀏覽體驗）。
        [$sortBy, $sortDir] = $this->sanitizeSortParameters(
            (string) $request->query('sort_by', ''),
            (string) $request->query('sort_dir', 'asc'),
            $thead
        );
        if ($sortBy !== '') {
            $query->orderBy($this->resolveColumnForQuery($sortBy), $sortDir);
            $query->orderBy('c_office_id', 'asc');
        } else {
            $query->orderByDesc('c_office_id');
        }

        // 分頁連結只攜帶實際套用的 filters（排除語法錯誤欄位），其餘 query 參數原樣保留。
        $appendQuery = $request->except(['page', 'filters']);
        if (!empty($appliedFilters)) {
            $appendQuery['filters'] = $appliedFilters;
        }
        $paginator = $query->paginate((int) config('codes.per_page', 20))->appends($appendQuery);

        $dynastyMap = DB::table('DYNASTIES')->pluck('c_dynasty_chn', 'c_dy')->all();

        return Inertia::render('Office/Index', [
            'thead' => $thead,
            'rows' => array_map(fn ($r) => (array) $r, $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'q' => $q,
            'dynasty_map' => (object) $dynastyMap,
            'key_columns' => ['c_office_id'],
            'computed_columns' => array_keys(self::COMPUTED_COLUMNS),
            'filters' => (object) $filters,
            'sort_by' => $sortBy,
            'sort_dir' => $sortDir,
            'boolean_enabled' => $booleanEnabled,
            'boolean_filter_available' => $booleanAvailable,
            'filter_errors' => (object) $filterErrors,
            'filter_descriptions' => (object) $filterDescriptions,
            'can_write' => Auth::check() && Auth::user()->canWriteDirectly(),
            'urls' => $this->urls(),
            'page_translations' => $this->translations(),
        ]);
    }

    /**
     * 排序／篩選需登入且已激活（鏡像 CodesController::guardSortFilterRequiresAuth；
     * 理由與行為一致：未登入導回登入頁記 intended、未激活 flash 後導回）。
     */
    protected function guardSortFilterRequiresAuth(Request $request): ?RedirectResponse {
        $hasSortBy = trim((string) $request->query('sort_by', '')) !== '';

        $hasFilter = false;
        $filters = $request->query('filters', []);
        if (is_array($filters)) {
            foreach ($filters as $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $hasFilter = true;

                    break;
                }
            }
        }

        if (!$hasSortBy && !$hasFilter) {
            return null;
        }

        if (!Auth::check()) {
            return redirect()->guest(route('login'));
        }

        if (!Auth::user()->isActive()) {
            flash('該用戶沒有權限使用排序／篩選功能，請聯絡管理員', 'error');

            return redirect()->route('app.office.index');
        }

        return null;
    }

    /** 欄位名 → 查詢用欄位（計算欄位還原為原始運算式，供 WHERE／ORDER BY 使用）。 */
    protected function resolveColumnForQuery(string $column): \Illuminate\Contracts\Database\Query\Expression|string {
        if (isset(self::COMPUTED_COLUMNS[$column])) {
            return DB::raw(self::COMPUTED_COLUMNS[$column]['expression']);
        }

        return $column;
    }

    /**
     * 只保留 thead 白名單內、scalar、trim 後的 filters（鏡像 CodesController::sanitizeColumnFilters）。
     *
     * @param mixed $rawFilters
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
            $trimmed = trim((string) $value);
            if ($trimmed !== '') {
                $filters[$column] = $trimmed;
            }
        }

        return $filters;
    }

    /**
     * 排序參數白名單化（鏡像 CodesController::sanitizeSortParameters）。
     *
     * @return array{0: string, 1: string}
     */
    protected function sanitizeSortParameters(string $sortBy, string $sortDir, array $thead): array {
        $sortBy = trim($sortBy);
        if ($sortBy === '' || !in_array($sortBy, $thead, true)) {
            return ['', 'asc'];
        }

        return [$sortBy, strtolower($sortDir) === 'desc' ? 'desc' : 'asc'];
    }

    /** 新增官職表單頁。 */
    public function appCreate() {
        $this->ensureWrite();

        return Inertia::render('Office/Create', [
            'urls' => $this->urls(),
            'page_translations' => $this->translations(),
        ]);
    }

    /** 編輯官職表單頁：載入聚合 + 預備 picker 初始標籤。 */
    public function appEdit(Request $request, int $id) {
        $this->ensureWrite();

        $aggregate = $this->service->load($id);
        if ($aggregate === null) {
            abort(404);
        }

        $dynastyLabel = $aggregate['dynasty_code'] !== null
            ? DB::table('DYNASTIES')->where('c_dy', $aggregate['dynasty_code'])->value('c_dynasty_chn')
            : null;

        $sourceLabel = null;
        if ($aggregate['source_id'] !== null) {
            $src = DB::table('TEXT_CODES')->where('c_textid', $aggregate['source_id'])->first();
            if ($src) {
                $title = trim((string) ($src->c_title ?? ''));
                $sourceLabel = trim($aggregate['source_id'].' '.$title);
            }
        }

        $typeLabels = [];
        if (!empty($aggregate['type_ids'])) {
            $typeRows = DB::table('OFFICE_TYPE_TREE')
                ->whereIn('c_office_type_node_id', $aggregate['type_ids'])
                ->get(['c_office_type_node_id', 'c_office_type_desc_chn']);
            foreach ($typeRows as $tr) {
                $nid = (string) $tr->c_office_type_node_id;
                $chn = trim((string) ($tr->c_office_type_desc_chn ?? ''));
                $typeLabels[$nid] = trim($nid.' '.$chn);
            }
        }

        return Inertia::render('Office/Edit', [
            'office' => $aggregate,
            'initial_labels' => [
                'dynasty' => $dynastyLabel,
                'source' => $sourceLabel,
                'types' => $typeLabels,
            ],
            'urls' => $this->urls(),
            'page_translations' => $this->translations(),
        ]);
    }
}
