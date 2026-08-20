<?php

namespace App\Http\Controllers;

use App\Services\Import\TextImportService;
use App\Support\EntityTableBrowser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * 「文獻實體」聚合 CRUD 的 Inertia 頁面（/app/text/*）。
 *
 * 讀取（列表／載入）在此 controller 完成；寫入（create／update／delete）由前端走 mutation API
 * （/api/v2/*，resource=text-entity），與 TextImportService 共用同一聚合存儲過程——
 * 故此 controller 不自帶寫入路徑。
 *
 * 這是「上層聚合入口」：把 TEXT_CODES ＋ TEXT_INSTANCE_DATA（版本層級）當作單一文獻實體
 * 管理，有別於 /app/codes/TEXT_CODES 的裸單表 CRUD（已封寫）。實體識別＝c_textid 單鍵。
 *
 * 列表與 Office／SocialInstitution EntityController 同構（EntityTableBrowser 描述子驅動），
 * 另加聚合特有的版本數與子文獻數（c_source 自引用樹）計算欄。
 */
class TextEntityController extends Controller {
    /**
     * TEXT_CODES 實體欄位（物理欄序，與 codes 裸表頁一致）。
     *
     * @var array<int, string>
     */
    protected const TEXT_COLUMNS = [
        'c_textid', 'c_title_chn', 'c_title', 'c_title_trans', 'c_text_type_id',
        'c_text_year', 'c_text_nh_code', 'c_text_nh_year', 'c_text_range_code',
        'c_bibl_cat_code', 'c_extant', 'c_text_country', 'c_text_dy',
        'c_source', 'c_pages', 'c_url_api', 'c_url_api_coda', 'c_url_homepage',
        'c_notes', 'c_title_alt_chn',
        'c_created_by', 'c_modified_by', 'c_created_date', 'c_modified_date',
    ];

    /**
     * 聚合計算欄位：
     * - instance_count：TEXT_INSTANCE_DATA 版本列數（collection→instance 層級）；exact 比對。
     * - child_count：以本文獻為 c_source 的子文獻數（著錄來源樹層級）；exact 比對。
     *
     * @var array<string, array{expression: string, match_mode: string}>
     */
    protected const COMPUTED_COLUMNS = [
        'instance_count' => [
            'expression' => '(SELECT COUNT(*) FROM TEXT_INSTANCE_DATA WHERE TEXT_INSTANCE_DATA.c_textid = TEXT_CODES.c_textid)',
            'match_mode' => 'exact',
        ],
        'child_count' => [
            'expression' => '(SELECT COUNT(*) FROM TEXT_CODES AS child WHERE child.c_source = TEXT_CODES.c_textid)',
            'match_mode' => 'exact',
        ],
    ];

    public function __construct(
        protected TextImportService $service,
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
            'index' => route('app.text.index', [], false),
            'create' => route('app.text.create', [], false),
            // 字面模板（前端以 textid 取代 __ID__）；不走 route() 以免撞 whereNumber('id') 約束。
            'edit_template' => '/app/text/__ID__/edit',
            'api_create' => '/api/v2/create',
            'api_mutate' => '/api/v2/mutate',
            'api_delete' => '/api/v2/delete',
            'search_source' => '/api/select/search/text',
        ];
    }

    protected function translations(): array {
        return [
            'text_entity' => is_array($t = trans('text_entity')) ? $t : [],
            // 列表的排序／篩選 UI（布林語法、錯誤訊息、套用說明）與 codes 頁共用同一組字串。
            'codes' => is_array($t = trans('codes')) ? $t : [],
        ];
    }

    /** 存世狀態下拉選項（EXTANT_CODES 全量，量小直接內嵌）。 */
    protected function extantOptions(): array {
        return DB::table('EXTANT_CODES')
            ->orderBy('c_extant_code')
            ->get(['c_extant_code', 'c_extant_desc', 'c_extant_desc_chn'])
            ->map(fn ($r) => [
                'code' => (int) $r->c_extant_code,
                'label' => trim(((string) ($r->c_extant_desc_chn ?? '')).' '.((string) ($r->c_extant_desc ?? ''))),
            ])
            ->values()
            ->all();
    }

    /**
     * 文獻列表：與 app/codes/TEXT_CODES 裸表頁 feature parity（全欄位、任意欄排序＋主鍵
     * tie-breaker、逐欄篩選含布林模式、關鍵字搜尋、朝代標籤、公開可讀），另加版本數與
     * 子文獻數計算欄。此頁是側欄「文獻代碼表」的新入口。
     */
    public function appIndex(Request $request) {
        $guardRedirect = $this->browser->guard($request, 'app.text.index');
        if ($guardRedirect !== null) {
            return $guardRedirect;
        }

        $payload = $this->browser->payload($request, [
            'table' => 'TEXT_CODES',
            'columns' => self::TEXT_COLUMNS,
            'computed' => self::COMPUTED_COLUMNS,
            'key_column' => 'c_textid',
        ]);

        return Inertia::render('Text/Index', array_merge($payload, [
            'can_write' => Auth::check() && Auth::user()->canWriteDirectly(),
            'urls' => $this->urls(),
            'page_translations' => $this->translations(),
        ]));
    }

    /** 新增文獻表單頁。 */
    public function appCreate() {
        $this->ensureWrite();

        return Inertia::render('Text/Create', [
            'extant_options' => $this->extantOptions(),
            'urls' => $this->urls(),
            'page_translations' => $this->translations(),
        ]);
    }

    /** 編輯文獻表單頁：載入聚合 + 預備 picker 初始標籤 + 刪除護欄狀態。 */
    public function appEdit(Request $request, int $id) {
        $this->ensureWrite();

        $aggregate = $this->service->load($id);
        if ($aggregate === null) {
            abort(404);
        }

        $dynastyLabels = [];
        $dyCodes = array_values(array_unique(array_filter(array_merge(
            [$aggregate['dynasty_code']],
            array_map(fn ($i) => $i['pub_dy'], $aggregate['instances'])
        ), fn ($v) => $v !== null)));
        if (!empty($dyCodes)) {
            $dynastyLabels = DB::table('DYNASTIES')->whereIn('c_dy', $dyCodes)
                ->pluck('c_dynasty_chn', 'c_dy')->all();
        }

        $sourceLabel = null;
        if ($aggregate['source_id'] !== null) {
            $src = DB::table('TEXT_CODES')->where('c_textid', $aggregate['source_id'])->first();
            if ($src) {
                $label = trim((string) ($src->c_title_chn ?? '')) ?: trim((string) ($src->c_title ?? ''));
                $sourceLabel = trim($aggregate['source_id'].' '.$label);
            }
        }

        return Inertia::render('Text/Edit', [
            'text' => $aggregate,
            // 刪除護欄（見 TextAggregateDefinition::guardWrite）：被出處／著述／子文獻等引用時
            // 後端會擋刪除，前端據此預先停用刪除鈕並提示。
            'reference_count' => $this->service->referenceCount($id),
            'initial_labels' => [
                'dynasties' => $dynastyLabels,
                'source' => $sourceLabel,
            ],
            'extant_options' => $this->extantOptions(),
            'urls' => $this->urls(),
            'page_translations' => $this->translations(),
        ]);
    }
}
