<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 實體聚合列表頁的通用瀏覽引擎（docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5）。
 *
 * 把「與 codes 裸表頁 feature parity」的瀏覽機制做成描述子驅動的單一實作：
 * 全欄位 thead、計算欄位（selectRaw 運算式，contains／exact 比對）、關鍵字全欄搜尋、
 * 逐欄篩選（含 AND/OR/NOT 布林模式，复用 ColumnFilterExpression 與 codes 同組 i18n）、
 * 任意欄排序＋主鍵 tie-breaker、標準分頁、朝代對照。OfficeEntityController 與
 * SocialInstitutionEntityController 共用；後續實體（code+type-rel 家族、text、place）
 * 只需給描述子。**刻意不合併 CodesController::buildShowPayload**（其帶 cursor 分頁、
 * JOIN config 等裸表專屬包袱），僅收斂實體頁這條線。
 *
 * 訪問模型（與 codes 頁一致）：讀取公開；排序／篩選需登入且已激活
 * （guard()；見 docs/CODES_SORT_FILTER_AUTH_GATE.md 的成本理由）。
 *
 * 描述子（descriptor）鍵：
 *  - table: string             主表名
 *  - columns: string[]         實體欄位（物理欄序）
 *  - computed: array<string, array{expression: string, match_mode: 'contains'|'exact'}>
 *  - key_column: string        識別鍵（PK 徽章、tie-breaker、預設倒序）
 *  - search_expressions?: string[]  關鍵字搜尋額外納入的原始運算式（如 joined 名稱）
 */
class EntityTableBrowser {
    public function __construct(protected ColumnFilterExpression $columnFilterExpression) {
    }

    /**
     * 排序／篩選門檻（鏡像 CodesController::guardSortFilterRequiresAuth）：
     * 未登入導回登入頁記 intended；已登入未激活 flash 後導回 $indexRoute。
     */
    public function guard(Request $request, string $indexRoute): ?RedirectResponse {
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

            return redirect()->route($indexRoute);
        }

        return null;
    }

    /**
     * 組列表 props（thead/rows/meta/filters/sort/boolean…；呼叫端補 can_write、urls、翻譯）。
     *
     * @param array{table: string, columns: array<int, string>, computed: array<string, array{expression: string, match_mode: string}>, key_column: string, search_expressions?: array<int, string>} $d
     * @return array<string, mixed>
     */
    public function payload(Request $request, array $d): array {
        $computed = $d['computed'] ?? [];
        $thead = array_merge($d['columns'], array_keys($computed));

        $query = DB::table($d['table'])->select($d['table'].'.*');
        foreach ($computed as $name => $definition) {
            $query->selectRaw($definition['expression'].' as '.$name);
        }

        // 關鍵字搜尋：所有實體欄位 %term%（與 codes 頁語義一致）＋描述子額外運算式。
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function ($sub) use ($q, $d) {
                foreach ($d['columns'] as $column) {
                    $sub->orWhere($column, 'like', '%'.$q.'%');
                }
                foreach ($d['search_expressions'] ?? [] as $expression) {
                    $sub->orWhere(DB::raw($expression), 'like', '%'.$q.'%');
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
            $filterColumn = $this->resolveColumn($column, $computed);
            $matchMode = $computed[$column]['match_mode'] ?? 'contains';
            if ($matchMode === 'exact' && !$booleanEnabled && !is_numeric($value)) {
                // 非數字輸入略過：避免 MySQL 將字串隱式轉 0，誤命中 0 值列。
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

        // 排序 + 主鍵 tie-breaker（未指定排序時維持識別鍵倒序瀏覽體驗）。
        [$sortBy, $sortDir] = $this->sanitizeSortParameters(
            (string) $request->query('sort_by', ''),
            (string) $request->query('sort_dir', 'asc'),
            $thead
        );
        if ($sortBy !== '') {
            $query->orderBy($this->resolveColumn($sortBy, $computed), $sortDir);
            $query->orderBy($d['key_column'], 'asc');
        } else {
            $query->orderByDesc($d['key_column']);
        }

        // 分頁連結只攜帶實際套用的 filters（排除語法錯誤欄位），其餘 query 參數原樣保留。
        $appendQuery = $request->except(['page', 'filters']);
        if (!empty($appliedFilters)) {
            $appendQuery['filters'] = $appliedFilters;
        }
        $paginator = $query->paginate((int) config('codes.per_page', 20))->appends($appendQuery);

        return [
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
            'dynasty_map' => (object) DB::table('DYNASTIES')->pluck('c_dynasty_chn', 'c_dy')->all(),
            'key_columns' => [$d['key_column']],
            'computed_columns' => array_keys($computed),
            'filters' => (object) $filters,
            'sort_by' => $sortBy,
            'sort_dir' => $sortDir,
            'boolean_enabled' => $booleanEnabled,
            'boolean_filter_available' => $booleanAvailable,
            'filter_errors' => (object) $filterErrors,
            'filter_descriptions' => (object) $filterDescriptions,
        ];
    }

    /** 欄位名 → 查詢用欄位（計算欄位還原為原始運算式，供 WHERE／ORDER BY 使用）。 */
    protected function resolveColumn(string $column, array $computed): \Illuminate\Contracts\Database\Query\Expression|string {
        if (isset($computed[$column])) {
            return DB::raw($computed[$column]['expression']);
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
