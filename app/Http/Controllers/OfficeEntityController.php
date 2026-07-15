<?php

namespace App\Http\Controllers;

use App\Services\Import\OfficeImportService;
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
    public function __construct(protected OfficeImportService $service) {
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
        ];
    }

    protected function translations(): array {
        return [
            'office' => is_array($t = trans('office')) ? $t : [],
        ];
    }

    /** 官職列表（分頁 + 關鍵字），含朝代標籤與類型數。 */
    public function appIndex(Request $request) {
        $this->ensureActive();

        $q = trim((string) $request->query('q', ''));
        $query = DB::table('OFFICE_CODES');
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('c_office_chn', 'like', '%'.$q.'%')
                    ->orWhere('c_office_pinyin', 'like', '%'.$q.'%');
                if (ctype_digit($q)) {
                    $w->orWhere('c_office_id', (int) $q);
                }
            });
        }
        $paginator = $query->orderByDesc('c_office_id')->paginate(20)->appends(['q' => $q]);

        $rows = collect($paginator->items());
        $officeIds = $rows->pluck('c_office_id')->all();
        $dynastyCodes = $rows->pluck('c_dy')->filter(fn ($v) => $v !== null)->unique()->all();

        $dynastyLabels = empty($dynastyCodes) ? collect() : DB::table('DYNASTIES')
            ->whereIn('c_dy', $dynastyCodes)->pluck('c_dynasty_chn', 'c_dy');
        $typeCounts = empty($officeIds) ? collect() : DB::table('OFFICE_CODE_TYPE_REL')
            ->whereIn('c_office_id', $officeIds)
            ->select('c_office_id', DB::raw('COUNT(*) AS n'))
            ->groupBy('c_office_id')->pluck('n', 'c_office_id');

        $data = $rows->map(fn ($r) => [
            'office_id' => (int) $r->c_office_id,
            'name' => (string) ($r->c_office_chn ?? ''),
            'pinyin' => (string) ($r->c_office_pinyin ?? ''),
            'translation' => $r->c_office_trans,
            'dynasty_code' => $r->c_dy !== null ? (int) $r->c_dy : null,
            'dynasty_label' => $r->c_dy !== null ? ($dynastyLabels[$r->c_dy] ?? null) : null,
            'type_count' => (int) ($typeCounts[$r->c_office_id] ?? 0),
            'source_id' => $r->c_source !== null ? (int) $r->c_source : null,
        ])->values();

        return Inertia::render('Office/Index', [
            'rows' => $data,
            'q' => $q,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
            'can_write' => Auth::check() && Auth::user()->canWriteDirectly(),
            'urls' => $this->urls(),
            'page_translations' => $this->translations(),
        ]);
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
