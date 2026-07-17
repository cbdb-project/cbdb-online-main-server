<?php

namespace App\Http\Controllers;

use App\Services\Import\SocialInstituteImportService;
use App\Support\ColumnFilterExpression;
use App\Support\ColumnFilterParseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * 「社會機構實體」聚合 CRUD 的 Inertia 頁面（/app/social-institution/*）。
 *
 * 讀取（列表／載入）在此 controller 完成；寫入（create／update／delete）由前端走 mutation API
 * （/api/v2/*，resource=social-institution），與 SocialInstituteImportService 共用同一聚合
 * 存儲過程——故此 controller 不自帶寫入路徑。
 *
 * 這是「上層聚合入口」：把 SOCIAL_INSTITUTION_NAME_CODES + SOCIAL_INSTITUTION_CODES +
 * SOCIAL_INSTITUTION_ADDR 當作單一機構實體管理，有別於 /app/codes/SOCIAL_INSTITUTION_* 的
 * 裸單表 CRUD（已封寫）。實體識別＝c_inst_code 單鍵（見 service 類註）。
 *
 * 列表與 OfficeEntityController::appIndex 同構：與裸表頁 feature parity（全欄位、排序、
 * 逐欄／布林篩選、公開讀、排序篩選登入門檻），另加聚合特有的名稱（joined）與地址數計算欄。
 */
class SocialInstitutionEntityController extends Controller {
    /**
     * SOCIAL_INSTITUTION_CODES 實體欄位（物理欄序，與 codes 裸表頁一致）。
     * 列表 thead ＝ 此清單 ＋ 計算欄位（見 COMPUTED_COLUMNS）。
     *
     * @var array<int, string>
     */
    protected const INST_COLUMNS = [
        'c_inst_name_code', 'c_inst_code', 'c_inst_type_code',
        'c_inst_begin_year', 'c_by_nianhao_code', 'c_by_nianhao_year', 'c_by_year_range',
        'c_inst_begin_dy', 'c_inst_floruit_dy', 'c_inst_first_known_year',
        'c_inst_end_year', 'c_ey_nianhao_code', 'c_ey_nianhao_year', 'c_ey_year_range',
        'c_inst_end_dy', 'c_inst_last_known_year',
        'c_source', 'c_pages', 'c_notes',
    ];

    /**
     * 聚合計算欄位（對齊 OfficeEntityController::COMPUTED_COLUMNS 結構）：
     * - c_inst_name_hz：機構名（NAME_CODES join），裸單表頁沒有、聚合視角必備；contains 比對。
     * - addr_count：SOCIAL_INSTITUTION_ADDR 地址列數；exact 比對避免數值 LIKE 誤命中。
     *
     * @var array<string, array{expression: string, match_mode: string}>
     */
    protected const COMPUTED_COLUMNS = [
        'c_inst_name_hz' => [
            'expression' => '(SELECT c_inst_name_hz FROM SOCIAL_INSTITUTION_NAME_CODES WHERE SOCIAL_INSTITUTION_NAME_CODES.c_inst_name_code = SOCIAL_INSTITUTION_CODES.c_inst_name_code)',
            'match_mode' => 'contains',
        ],
        'addr_count' => [
            'expression' => '(SELECT COUNT(*) FROM SOCIAL_INSTITUTION_ADDR WHERE SOCIAL_INSTITUTION_ADDR.c_inst_code = SOCIAL_INSTITUTION_CODES.c_inst_code)',
            'match_mode' => 'exact',
        ],
    ];

    public function __construct(
        protected SocialInstituteImportService $service,
        protected ColumnFilterExpression $columnFilterExpression,
    ) {
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
            'index' => route('app.social-institution.index', [], false),
            'create' => route('app.social-institution.create', [], false),
            // 字面模板（前端以 inst_code 取代 __ID__）；不走 route() 以免撞 whereNumber('id') 約束。
            'edit_template' => '/app/social-institution/__ID__/edit',
            'api_create' => '/api/v2/create',
            'api_mutate' => '/api/v2/mutate',
            'api_delete' => '/api/v2/delete',
            'search_addr' => '/api/select/search/addr',
            'search_source' => '/api/select/search/text',
        ];
    }

    protected function translations(): array {
        return [
            'social_institution' => is_array($t = trans('social_institution')) ? $t : [],
            // 列表的排序／篩選 UI（布林語法、錯誤訊息、套用說明）與 codes 頁共用同一組字串。
            'codes' => is_array($t = trans('codes')) ? $t : [],
        ];
    }

    /** 機構類型下拉選項（SOCIAL_INSTITUTION_TYPES 全量，量小直接內嵌）。 */
    protected function typeOptions(): array {
        return DB::table('SOCIAL_INSTITUTION_TYPES')
            ->orderBy('c_inst_type_code')
            ->get(['c_inst_type_code', 'c_inst_type_hz', 'c_inst_type_py'])
            ->map(fn ($r) => [
                'code' => (int) $r->c_inst_type_code,
                'label' => trim(((string) ($r->c_inst_type_hz ?? '')).' '.((string) ($r->c_inst_type_py ?? ''))),
            ])
            ->values()
            ->all();
    }

    /**
     * 機構列表：與 app/codes/SOCIAL_INSTITUTION_CODES 裸表頁 feature parity（全欄位、任意欄
     * 排序＋主鍵 tie-breaker、逐欄篩選含布林模式、關鍵字搜尋、朝代標籤、公開可讀），另加
     * 聚合特有的機構名與地址數計算欄。此頁是側欄「社會機構編碼表」的新入口。
     */
    public function appIndex(Request $request) {
        $guardRedirect = $this->guardSortFilterRequiresAuth($request);
        if ($guardRedirect !== null) {
            return $guardRedirect;
        }

        $thead = array_merge(self::INST_COLUMNS, array_keys(self::COMPUTED_COLUMNS));

        $query = DB::table('SOCIAL_INSTITUTION_CODES')->select('SOCIAL_INSTITUTION_CODES.*');
        foreach (self::COMPUTED_COLUMNS as $name => $definition) {
            $query->selectRaw($definition['expression'].' as '.$name);
        }

        // 關鍵字搜尋：所有實體欄位 %term%＋機構名（joined 運算式），與 codes 頁語義一致再加名稱。
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                foreach (self::INST_COLUMNS as $column) {
                    $sub->orWhere($column, 'like', '%'.$q.'%');
                }
                $sub->orWhere(DB::raw(self::COMPUTED_COLUMNS['c_inst_name_hz']['expression']), 'like', '%'.$q.'%');
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

        // 排序 + 主鍵 tie-breaker（未指定排序時維持 inst_code 倒序瀏覽體驗）。
        [$sortBy, $sortDir] = $this->sanitizeSortParameters(
            (string) $request->query('sort_by', ''),
            (string) $request->query('sort_dir', 'asc'),
            $thead
        );
        if ($sortBy !== '') {
            $query->orderBy($this->resolveColumnForQuery($sortBy), $sortDir);
            $query->orderBy('c_inst_code', 'asc');
        } else {
            $query->orderByDesc('c_inst_code');
        }

        // 分頁連結只攜帶實際套用的 filters（排除語法錯誤欄位），其餘 query 參數原樣保留。
        $appendQuery = $request->except(['page', 'filters']);
        if (!empty($appliedFilters)) {
            $appendQuery['filters'] = $appliedFilters;
        }
        $paginator = $query->paginate((int) config('codes.per_page', 20))->appends($appendQuery);

        $dynastyMap = DB::table('DYNASTIES')->pluck('c_dynasty_chn', 'c_dy')->all();

        return Inertia::render('SocialInstitution/Index', [
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
            'key_columns' => ['c_inst_code'],
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

    /** 新增機構表單頁。 */
    public function appCreate() {
        $this->ensureWrite();

        return Inertia::render('SocialInstitution/Create', [
            'type_options' => $this->typeOptions(),
            'urls' => $this->urls(),
            'page_translations' => $this->translations(),
        ]);
    }

    /** 編輯機構表單頁：載入聚合 + 預備 picker 初始標籤 + 改名護欄狀態。 */
    public function appEdit(Request $request, int $id) {
        $this->ensureWrite();

        $aggregate = $this->service->load($id);
        if ($aggregate === null) {
            abort(404);
        }

        $dynastyLabels = [];
        $dyCodes = array_values(array_unique(array_filter([
            $aggregate['begin_dy'], $aggregate['floruit_dy'], $aggregate['end_dy'],
        ], fn ($v) => $v !== null)));
        if (!empty($dyCodes)) {
            $dynastyLabels = DB::table('DYNASTIES')->whereIn('c_dy', $dyCodes)
                ->pluck('c_dynasty_chn', 'c_dy')->all();
        }

        $sourceLabel = null;
        if ($aggregate['source_id'] !== null) {
            $src = DB::table('TEXT_CODES')->where('c_textid', $aggregate['source_id'])->first();
            if ($src) {
                $sourceLabel = trim($aggregate['source_id'].' '.trim((string) ($src->c_title ?? '')));
            }
        }

        $addrLabels = [];
        $addrIds = array_values(array_unique(array_map(fn ($a) => $a['addr_id'], $aggregate['addresses'])));
        if (!empty($addrIds)) {
            $addrRows = DB::table('ADDR_CODES')->whereIn('c_addr_id', $addrIds)
                ->get(['c_addr_id', 'c_name_chn', 'c_name']);
            foreach ($addrRows as $a) {
                $label = trim((string) ($a->c_name_chn ?? '')) ?: trim((string) ($a->c_name ?? ''));
                $addrLabels[(string) $a->c_addr_id] = trim($a->c_addr_id.' '.$label);
            }
        }

        return Inertia::render('SocialInstitution/Edit', [
            'institution' => $aggregate,
            // 改名護欄（見 SocialInstituteUpdateHandler）：被人物資料引用時後端會擋改名，
            // 前端據此預先鎖名稱欄並提示，避免使用者改完才被 409。
            'reference_count' => $this->service->referenceCount($id),
            'initial_labels' => [
                'dynasties' => $dynastyLabels,
                'source' => $sourceLabel,
                'addresses' => $addrLabels,
            ],
            'type_options' => $this->typeOptions(),
            'urls' => $this->urls(),
            'page_translations' => $this->translations(),
        ]);
    }

    /**
     * 排序／篩選需登入且已激活（鏡像 CodesController::guardSortFilterRequiresAuth，
     * 與 OfficeEntityController 相同）。
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

            return redirect()->route('app.social-institution.index');
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
}
