<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 外部資料庫引用瀏覽器（唯讀）。
 *
 * 瀏覽 BIOG_SOURCE_DATA 裡指向中文維基百科／維基數據／英文維基百科（c_textid
 * 60795/68942/68943）的人物條目引用。
 *
 * 這裡刻意不提供任何寫入功能（全量刪除、URL 匯入等）——正式的增修管道是
 * `/api/v2/mutate|create|delete|batch_mutate`（resource=sources），每筆異動走
 * operations + audit_log、可回滾；詳見 docs/ZHWIKI_SOURCE_SYNC.md。舊版「全量刪除重灌」
 * 功能已於 2026-07 移除：那套流程原本就只設計給「首次導入」使用（見同一份文件開頭警語），
 * 對已有大量正式資料的資料庫而言，後台按鈕觸發整表刪除＋重新從任意 URL 匯入的風險遠高於
 * 收益，也與其他管道（mutation API）的審計/回滾能力不對等。
 */
class WikiMaintenanceController extends Controller {
    protected $targetSourceIds = [60795, 68942, 68943];
    protected $sourceNames = [
        60795 => '中文維基百科 (Wikipedia)',
        68942 => '維基數據 (Wikidata)',
        68943 => '英文維基百科 (Wikipedia)',
    ];

    public function __construct() {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            if (!Auth::user() || !Auth::user()->canRunBatchImport()) {
                abort(403, '此功能僅限活躍管理員使用');
            }

            return $next($request);
        });
    }

    protected function buildWikiListing(Request $request): array {
        $sourceId = $request->input('source_id', $this->targetSourceIds[0]);

        // 验证 source_id 是否在允许的范围内
        if (!in_array((int) $sourceId, $this->targetSourceIds)) {
            $sourceId = $this->targetSourceIds[0];
        }

        $page = (int) $request->input('page', 1);
        $perPage = 20;

        // 查询指定 source_id 的记录，关联人名信息和文本链接信息
        $query = DB::table('BIOG_SOURCE_DATA as bsd')
            ->leftJoin('BIOG_MAIN as bm', 'bsd.c_personid', '=', 'bm.c_personid')
            ->leftJoin('TEXT_CODES as tc', 'bsd.c_textid', '=', 'tc.c_textid')
            ->select('bsd.*', 'bm.c_name_chn', 'tc.c_url_api', 'tc.c_url_api_coda')
            ->where('bsd.c_textid', $sourceId)
            ->orderBy('bsd.c_personid');

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
            'stats' => $stats,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'hasNext' => $total > $page * $perPage,
            'hasPrev' => $page > 1,
        ];
    }

    public function index(Request $request) {
        $data = $this->buildWikiListing($request);

        return view('admin.wiki-maintenance', array_merge($data, [
            'page_title' => __('admin.wiki_maintenance'),
            'page_title_key' => '外部資料庫引用瀏覽器',
            'page_description' => __('admin.wiki_maintenance_desc'),
            'page_url' => route('admin.wiki-maintenance'),
        ]));
    }

    public function appIndex(Request $request) {
        $data = $this->buildWikiListing($request);

        $records = collect($data['records'])->map(function ($r) {
            // 與 Blade 相同的條目連結組裝：c_url_api + (含中日韓字元則 rawurlencode) c_pages + c_url_api_coda。
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
                'c_textid' => $r->c_textid,
                'c_pages' => $r->c_pages,
                'link' => $link,
            ];
        })->all();

        $sources = array_map(fn ($id) => [
            'id' => $id,
            'name' => $this->sourceNames[$id],
            'count' => $data['stats'][$id],
        ], $data['targetSourceIds']);

        return \Inertia\Inertia::render('Admin/WikiMaintenance/Index', [
            'records' => $records,
            'current_source_id' => (int) $data['currentSourceId'],
            'sources' => $sources,
            'pagination' => [
                'page' => $data['page'],
                'per_page' => $data['perPage'],
                'total' => $data['total'],
                'has_next' => $data['hasNext'],
                'has_prev' => $data['hasPrev'],
            ],
            'urls' => [
                'index' => route('app.admin.wiki-maintenance', [], false),
            ],
            'page_translations' => [
                'admin' => __('admin'),
                'common' => __('common'),
            ],
        ]);
    }
}
