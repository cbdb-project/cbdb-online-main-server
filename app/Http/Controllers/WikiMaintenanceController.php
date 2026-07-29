<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 外部資料庫引用瀏覽器（唯讀）。
 *
 * 瀏覽 BIOG_SOURCE_DATA 裡指向維基百科／維基數據／明清婦女著作數據庫／PersDB／
 * 唐五代人物傳記與社會網絡資料庫等外部資料庫（見 $targetSourceIds 對應的 c_textid）
 * 的人物條目引用。
 *
 * 這裡刻意不提供任何寫入功能（全量刪除、URL 匯入等）——正式的增修管道是
 * `/api/v2/mutate|create|delete|batch_mutate`（resource=sources），每筆異動走
 * operations + audit_log、可回滾；詳見 docs/ZHWIKI_SOURCE_SYNC.md。舊版「全量刪除重灌」
 * 功能已於 2026-07 移除：那套流程原本就只設計給「首次導入」使用（見同一份文件開頭警語），
 * 對已有大量正式資料的資料庫而言，後台按鈕觸發整表刪除＋重新從任意 URL 匯入的風險遠高於
 * 收益，也與其他管道（mutation API）的審計/回滾能力不對等。
 */
class WikiMaintenanceController extends Controller {
    protected $targetSourceIds = [60795, 68942, 68943, 9601, 9602, 24309, 32033, 71853];
    protected $sourceNames = [
        60795 => '中文維基百科 (Wikipedia)',
        68942 => '維基數據 (Wikidata)',
        68943 => '英文維基百科 (Wikipedia)',
        9601 => '明清婦女著作數據庫 (MQWW)',
        9602 => '人名權威資料（中研院史語所）',
        24309 => 'PersDB 唐代人物知識ベース',
        32033 => '唐五代人物傳記與社會網絡資料庫 (1.0版)',
        71853 => '唐五代人物傳記與社會網絡資料庫 (1.5版)',
    ];

    // 與 $sourceNames 一一對應，用於卡片圖示／顏色；顏色名稱同時是合法的 AdminLTE
    // （Blade）與 Tailwind（React）色彩前綴，兩邊各自組出 bg-{color} / bg-{color}-500。
    protected $sourceIcons = [
        60795 => 'fab fa-wikipedia-w',
        68942 => 'fas fa-globe',
        68943 => 'fab fa-wikipedia-w',
        9601 => 'fas fa-book',
        9602 => 'fas fa-id-card',
        24309 => 'fas fa-user-circle',
        32033 => 'fas fa-project-diagram',
        71853 => 'fas fa-project-diagram',
    ];
    protected $sourceColors = [
        60795 => 'blue',
        68942 => 'green',
        68943 => 'orange',
        9601 => 'purple',
        9602 => 'cyan',
        24309 => 'pink',
        32033 => 'teal',
        71853 => 'indigo',
    ];

    public function __construct() {
        $this->middleware('auth');
        // 唯讀瀏覽頁，開放給所有活躍帳號（含一般用戶）；帶排序／搜尋的伺服器端查詢
        // 仍需登入且活躍，與 codes 的 sort/filter 門檻精神一致（docs/CODES_SORT_FILTER_AUTH_GATE.md）。
        $this->middleware(function ($request, $next) {
            if (!Auth::user() || !Auth::user()->isActive()) {
                abort(403, '此功能僅限已啟用帳號使用');
            }

            return $next($request);
        });
    }

    /** 可排序欄位 → 完整限定欄位名的白名單；不在名單內的 sort 參數一律忽略。 */
    protected const SORTABLE_COLUMNS = [
        'c_personid' => 'bsd.c_personid',
        'c_name_chn' => 'bm.c_name_chn',
        'c_index_year' => 'bm.c_index_year',
        'c_pages' => 'bsd.c_pages',
    ];

    protected function buildWikiListing(Request $request): array {
        $sourceId = $request->input('source_id', $this->targetSourceIds[0]);

        // 验证 source_id 是否在允许的范围内
        if (!in_array((int) $sourceId, $this->targetSourceIds)) {
            $sourceId = $this->targetSourceIds[0];
        }

        $page = (int) $request->input('page', 1);
        $perPage = 20;
        $search = trim((string) $request->input('search', ''));
        $sort = (string) $request->input('sort', '');
        if (!isset(self::SORTABLE_COLUMNS[$sort])) {
            $sort = '';
        }
        $direction = strtolower((string) $request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        // 查询指定 source_id 的记录，关联人名信息和文本链接信息
        $query = DB::table('BIOG_SOURCE_DATA as bsd')
            ->leftJoin('BIOG_MAIN as bm', 'bsd.c_personid', '=', 'bm.c_personid')
            ->leftJoin('TEXT_CODES as tc', 'bsd.c_textid', '=', 'tc.c_textid')
            ->leftJoin('DYNASTIES as dy', 'bm.c_dy', '=', 'dy.c_dy')
            ->leftJoin('ADDR_CODES as addr', 'bm.c_index_addr_id', '=', 'addr.c_addr_id')
            ->select(
                'bsd.*',
                'bm.c_name_chn',
                'bm.c_index_year',
                'dy.c_dynasty_chn',
                'addr.c_name_chn as c_index_addr_chn',
                'tc.c_url_api',
                'tc.c_url_api_coda'
            )
            ->where('bsd.c_textid', $sourceId);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('bm.c_name_chn', 'like', "%{$search}%")
                    ->orWhere('bsd.c_pages', 'like', "%{$search}%");
                if (ctype_digit($search)) {
                    $q->orWhere('bsd.c_personid', (int) $search);
                }
            });
        }

        if ($sort !== '') {
            $query->orderBy(self::SORTABLE_COLUMNS[$sort], $direction);
        }
        // 次要排序鍵，確保分頁順序穩定。
        $query->orderBy('bsd.c_personid');

        $total = $query->count();
        $records = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        // 获取统计信息
        $stats = [];
        foreach ($this->targetSourceIds as $id) {
            $stats[$id] = DB::table('BIOG_SOURCE_DATA')
                ->where('c_textid', $id)
                ->count();
        }

        return [
            'records' => $records,
            'currentSourceId' => $sourceId,
            'targetSourceIds' => $this->targetSourceIds,
            'sourceNames' => $this->sourceNames,
            'sourceIcons' => $this->sourceIcons,
            'sourceColors' => $this->sourceColors,
            'stats' => $stats,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'hasNext' => $total > $page * $perPage,
            'hasPrev' => $page > 1,
            'search' => $search,
            'sort' => $sort,
            'direction' => $direction,
        ];
    }

    /** 舊 Blade 版已下架：/external-db-link 硬導向 React 版（同 Query Playground 模式，保留 query 參數）。 */
    public function index(Request $request) {
        return redirect()->route('app.external-db-link', $request->query());
    }

    public function appIndex(Request $request) {
        $data = $this->buildWikiListing($request);

        $records = collect($data['records'])->map(function ($r) {
            // 條目連結組裝：c_url_api + (含中日韓字元則 rawurlencode) c_pages + c_url_api_coda。
            $link = null;
            if ($r->c_url_api && $r->c_textid && $r->c_pages) {
                $urlPart = $r->c_pages;
                if (preg_match('/[\x{4e00}-\x{9fff}]/u', $urlPart)) {
                    $urlPart = rawurlencode($urlPart);
                }
                $link = $r->c_url_api . $urlPart . ($r->c_url_api_coda ?? '');
            }

            return [
                'c_personid' => $r->c_personid,
                'c_name_chn' => $r->c_name_chn,
                'c_dynasty_chn' => $r->c_dynasty_chn,
                'c_index_year' => $r->c_index_year,
                'c_index_addr_chn' => $r->c_index_addr_chn,
                'c_textid' => $r->c_textid,
                'c_pages' => $r->c_pages,
                'link' => $link,
            ];
        })->all();

        $sources = array_map(fn ($id) => [
            'id' => $id,
            'name' => $this->sourceNames[$id],
            'count' => $data['stats'][$id],
            'icon' => $this->sourceIcons[$id],
            'color' => $this->sourceColors[$id],
        ], $data['targetSourceIds']);

        return \Inertia\Inertia::render('Admin/WikiMaintenance/Index', [
            'records' => $records,
            'current_source_id' => (int) $data['currentSourceId'],
            'sources' => $sources,
            // 已套用的搜尋／排序狀態；前端據此還原 UI，並在換頁時原樣帶回（URL 可分享）。
            'filters' => ['search' => $data['search']],
            'sort' => $data['sort'],
            'direction' => $data['direction'],
            // 標準 PaginationMeta 形狀，供共用 DataTable / Pagination 元件使用。
            'pagination' => [
                'current_page' => $data['page'],
                'last_page' => max(1, (int) ceil($data['total'] / $data['perPage'])),
                'per_page' => $data['perPage'],
                'total' => $data['total'],
                'from' => $data['total'] > 0 ? ($data['page'] - 1) * $data['perPage'] + 1 : null,
                'to' => $data['total'] > 0 ? min($data['page'] * $data['perPage'], $data['total']) : null,
            ],
            'urls' => [
                'index' => route('app.external-db-link', [], false),
            ],
            'page_translations' => [
                'admin' => __('admin'),
                'common' => __('common'),
            ],
        ]);
    }
}
