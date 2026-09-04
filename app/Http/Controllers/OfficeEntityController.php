<?php

namespace App\Http\Controllers;

use App\Services\Import\OfficeImportService;
use App\Support\BrowsesEntityTable;
use App\Support\EntityTableBrowser;
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
class OfficeEntityController extends Controller implements BrowsesEntityTable {
    /**
     * OFFICE_CODES 實體欄位（物理欄序，與 codes 裸表頁一致）。
     * 列表 thead ＝ 此清單 ＋ 計算欄位 type_count（見 COMPUTED_COLUMNS）。
     *
     * 必須與資料表實際欄位完全一致：關鍵字搜尋會對此清單每一欄下 LIKE，
     * 列了不存在的欄就是 1054 Unknown column ⇒ 使用者按「搜尋」拿到 500
     * （c_category_1..4 與 c_office_id_old 在本清單寫成之前就已被 migration 移除，
     * 清單卻照著移除前的表形抄，一出生就是壞的）。
     * 漂移守衛見 tests/Feature/EntityBrowseColumnsSchemaDriftTest.php。
     *
     * @var array<int, string>
     */
    protected const OFFICE_COLUMNS = [
        'c_office_id', 'c_dy', 'c_office_pinyin', 'c_office_chn',
        'c_office_pinyin_alt', 'c_office_chn_alt', 'c_office_trans', 'c_office_trans_alt',
        'c_source', 'c_pages', 'c_notes',
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
        protected EntityTableBrowser $browser,
    ) {
    }

    /** 新增／編輯需直接寫入權限（與 mutation API authorizeDirect 對齊）。 */
    protected function ensureWrite(): void {
        if (!Auth::check() || !Auth::user()->canWriteDirectly()) {
            abort(403);
        }
    }

    /**
     * 表單頁（新增／編輯）門檻：可直接寫或可提案者皆可進（active 使用者，含眾包）。
     * 實際寫入由 mutation API 各自授權（direct→authorizeDirect、proposal→authorizeProposal），
     * 前端依 can_edit／can_propose 顯示對應按鈕。
     */
    protected function ensureCanReachForm(): void {
        if (!Auth::check() || !Auth::user()->canPropose()) {
            abort(403);
        }
    }

    /** 前端表單能力旗標（與人物子資源頁一致）。 */
    protected function formCapabilities(): array {
        $user = Auth::user();

        return [
            'can_edit' => $user ? $user->canWriteDirectly() : false,
            'can_propose' => $user ? $user->canPropose() : false,
        ];
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

    /** 瀏覽描述子（見 BrowsesEntityTable：提出來供漂移守衛逐欄比對 migration schema）。 */
    public function browseDescriptor(): array {
        return [
            'table' => 'OFFICE_CODES',
            'columns' => self::OFFICE_COLUMNS,
            'computed' => self::COMPUTED_COLUMNS,
            'key_column' => 'c_office_id',
        ];
    }

    /**
     * 官職列表：與 app/codes/OFFICE_CODES 裸表頁 feature parity（全欄位、任意欄排序＋主鍵
     * tie-breaker、逐欄篩選含布林模式、關鍵字搜尋、朝代標籤、公開可讀），另加聚合特有的
     * type_count 計算欄位。瀏覽機制由 EntityTableBrowser 描述子驅動（§6.5）。
     * 此頁是側欄「任官編碼表」的新入口，裸表頁封寫後為唯一編輯入口。
     */
    public function appIndex(Request $request) {
        $guardRedirect = $this->browser->guard($request, 'app.office.index');
        if ($guardRedirect !== null) {
            return $guardRedirect;
        }

        $payload = $this->browser->payload($request, $this);

        return Inertia::render('Office/Index', array_merge($payload, [
            'can_write' => Auth::check() && Auth::user()->canWriteDirectly(),
            'urls' => $this->urls(),
            'page_translations' => $this->translations(),
        ]));
    }

    /** 新增官職表單頁。 */
    public function appCreate() {
        $this->ensureCanReachForm();

        return Inertia::render('Office/Create', array_merge($this->formCapabilities(), [
            'urls' => $this->urls(),
            'page_translations' => $this->translations(),
        ]));
    }

    /** 編輯官職表單頁：載入聚合 + 預備 picker 初始標籤。 */
    public function appEdit(Request $request, int $id) {
        $this->ensureCanReachForm();

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

        return Inertia::render('Office/Edit', array_merge($this->formCapabilities(), [
            'office' => $aggregate,
            'initial_labels' => [
                'dynasty' => $dynastyLabel,
                'source' => $sourceLabel,
                'types' => $typeLabels,
            ],
            'urls' => $this->urls(),
            'page_translations' => $this->translations(),
        ]));
    }
}
