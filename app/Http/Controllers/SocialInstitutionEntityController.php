<?php

namespace App\Http\Controllers;

use App\Services\Import\SocialInstituteImportService;
use App\Support\BrowsesEntityTable;
use App\Support\EntityTableBrowser;
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
class SocialInstitutionEntityController extends Controller implements BrowsesEntityTable {
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
        protected EntityTableBrowser $browser,
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

    /** 瀏覽描述子（見 BrowsesEntityTable：提出來供漂移守衛逐欄比對 migration schema）。 */
    public function browseDescriptor(): array {
        return [
            'table' => 'SOCIAL_INSTITUTION_CODES',
            'columns' => self::INST_COLUMNS,
            'computed' => self::COMPUTED_COLUMNS,
            'key_column' => 'c_inst_code',
            // 關鍵字搜尋額外命中機構名（joined 運算式）。
            'search_expressions' => [self::COMPUTED_COLUMNS['c_inst_name_hz']['expression']],
        ];
    }

    /**
     * 機構列表：與 app/codes/SOCIAL_INSTITUTION_CODES 裸表頁 feature parity（全欄位、任意欄
     * 排序＋主鍵 tie-breaker、逐欄篩選含布林模式、關鍵字搜尋、朝代標籤、公開可讀），另加
     * 聚合特有的機構名（joined）與地址數計算欄。瀏覽機制由 EntityTableBrowser 描述子驅動
     * （§6.5）。此頁是側欄「社會機構編碼表」的新入口。
     */
    public function appIndex(Request $request) {
        $guardRedirect = $this->browser->guard($request, 'app.social-institution.index');
        if ($guardRedirect !== null) {
            return $guardRedirect;
        }

        $payload = $this->browser->payload($request, $this);

        return Inertia::render('SocialInstitution/Index', array_merge($payload, [
            'can_write' => Auth::check() && Auth::user()->canWriteDirectly(),
            'urls' => $this->urls(),
            'page_translations' => $this->translations(),
        ]));
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
            // 改名護欄（見 SocialInstitutionAggregateDefinition::guardWrite）：被人物資料引用時後端會擋改名，
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
}
