<?php

namespace App\Services\Import;

use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\CharVariantMapService;
use App\Services\Import\Concerns\SharesImportHelpers;
use App\Services\PinyinDictionary;
use App\Services\VariantCharNormalizer;
use App\Support\AuditActor;
use App\Support\PinyinUmlaut;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 「文獻實體（Text，含版本／instance 層級）」聚合 CRUD 的單一真源。
 *
 * 一個文獻實體 = TEXT_CODES 一列（識別＝c_textid 單鍵）＋ 其 TEXT_INSTANCE_DATA 版本列集合
 * （版本以 (c_text_edition_id, c_text_instance_id) 在文獻內定位）。這是繼 office／
 * social-institution 之後第三個聚合根（docs/ENTITY_AGGREGATE_ARCHITECTURE.md §2.2、§6.5），
 * 由 admin 批量表單（AdminBatchLoadBookTitlesController）與 mutation API
 * （通用 EntityAggregate*Handler → TextAggregateDefinition）共用，確保書名標準化
 * （char_variant_map 寬鬆替換）、拼音派生（去卷冊註記＋異體字歸一化）與審計只有一份實作。
 *
 * 【層級】文獻有兩重層級，聚合的處理各不同：
 *  1. collection→instance：TEXT_INSTANCE_DATA 屬於聚合內部，update() 以
 *     (edition_id, instance_id) 為列鍵做集合對賬，delete() 先刪版本列再刪主列。
 *  2. c_source 自引用（TEXT_CODES.c_source → TEXT_CODES，著錄來源樹）：**跨實體**引用，
 *     不屬聚合內部。update 改 c_source 時呼叫端須以 sourceCreatesCycle() 擋自環／成環；
 *     delete 時 referenceCount() 把「被其他文獻／版本當作來源」也計入，樹中間節點不可刪。
 *
 * 稽核欄：TEXT_CODES 主列有 c_created_*／c_modified_*，由本服務蓋章（AuditActor，見 AGENTS §1.2）；
 * TEXT_INSTANCE_DATA 版本列經集合對賬寫入、稽核由 operations + audit_log 承載（同 office／social
 * 的配套列），不另蓋章。
 *
 * operations 的 resource_id 慣例：TEXT_CODES 沿用既有的「純數字 c_textid」格式（批量匯入撤回
 * undo() 與 /operations 還原鏈路都以此比對，見 recordTextOp*），TEXT_INSTANCE_DATA 為 3 鍵
 * query-string 格式（CompositePrimaryKey）。
 *
 * 寫入方法皆不自開交易，於呼叫端交易內執行；輸入須已驗證（ResolvesTextAggregateInput）。
 */
class TextImportService implements EntityAggregateService {
    use SharesImportHelpers;

    /**
     * referenceCount() 計數的「入邊引用」：所有以 FK 指向 TEXT_CODES.c_textid 的表／欄
     * （出處 c_source 與著述 c_textid；來源＝2025_01_01 schema 的 RESTRICT 外鍵清單）。
     * MERGED_PERSON_DATA 的 FK 雖為 SET NULL（DB 允許刪除），仍計入——靜默清空合併紀錄的
     * 出處也是資料損失。TEXT_CODES／TEXT_INSTANCE_DATA 的 c_source 自引用另行處理（見方法註）。
     *
     * @var array<int, array{0: string, 1: string}>
     */
    public const REFERENCE_COLUMNS = [
        ['BIOG_SOURCE_DATA', 'c_textid'],
        ['BIOG_TEXT_DATA', 'c_textid'],
        ['BIOG_TEXT_DATA', 'c_source'],
        ['ADDR_BELONGS_DATA', 'c_source'],
        ['ALTNAME_DATA', 'c_source'],
        ['ASSOC_DATA', 'c_source'],
        ['BIOG_ADDR_DATA', 'c_source'],
        ['BIOG_INST_DATA', 'c_source'],
        ['ENTRY_DATA', 'c_source'],
        ['EVENTS_DATA', 'c_source'],
        ['EVENT_CODES', 'c_source'],
        ['KIN_DATA', 'c_source'],
        ['MERGED_PERSON_DATA', 'c_source'],
        ['OFFICE_CODES', 'c_source'],
        ['POSSESSION_DATA', 'c_source'],
        ['POSTED_TO_OFFICE_DATA', 'c_source'],
        ['SOCIAL_INSTITUTION_ADDR', 'c_source'],
        ['SOCIAL_INSTITUTION_CODES', 'c_source'],
        ['STATUS_DATA', 'c_source'],
    ];

    /** TEXT_INSTANCE_DATA 集合對賬時允許同鍵改寫的非鍵欄。 */
    protected const INSTANCE_UPDATABLE_COLUMNS = [
        'c_instance_title_chn', 'c_instance_title', 'c_publisher', 'c_pub_loc',
        'c_pub_year', 'c_pub_dy', 'c_pub_nh_code', 'c_pub_nh_year',
        'c_source', 'c_pages', 'c_extant', 'c_notes',
    ];

    /**
     * 本次 create()／update() 實際套用的異體字替換（變體字 → 參考字），涵蓋主列與版本列。
     * 供呼叫端組回應 notices 與批次匯入結果頁（比照 OfficeImportService::$lastVariantReplaced）。
     *
     * **每次 create()／update() 進入時重置**：批次匯入用同一個 service 實例逐列呼叫，
     * 不重置會把上一本書的替換帶到下一本。
     *
     * @var array<string, string|array<int, string>>
     */
    protected array $lastVariantReplaced = [];

    public function __construct(
        protected OperationRepository $operationRepository,
        protected AuditLogService $auditLogService
    ) {
    }

    /**
     * 文獻被其他資料引用的筆數；>0 表示不可刪除。
     *
     * 含兩類：(1) REFERENCE_COLUMNS——人物資料的出處／著述與各實體表的 c_source；
     * (2) c_source 自引用層級——把本文獻當作來源的**其他**文獻（TEXT_CODES 子節點）與
     * **其他文獻的**版本列（TEXT_INSTANCE_DATA，排除本聚合自己的版本列——它們隨聚合一併刪除）。
     * DB 端所有入邊 FK 已為 RESTRICT，漏網引用由 1451 fail-closed 兜底（EntityAggregateDeleteHandler）。
     */
    public function referenceCount(int $textId): int {
        $total = $this->countReferences(self::REFERENCE_COLUMNS, $textId);

        $total += (int) DB::table('TEXT_CODES')->where('c_source', $textId)->count();
        $total += (int) DB::table('TEXT_INSTANCE_DATA')
            ->where('c_source', $textId)
            ->where('c_textid', '!=', $textId)
            ->count();

        return $total;
    }

    /**
     * c_source 是否會構成自環／成環：沿既有 c_source 祖先鏈從 $sourceId 上溯，途中撞到
     * $textId 即成環（含 $sourceId === $textId 的自引用）。樹目前無環（TEXT_CODES 著錄
     * 來源樹），仍設 200 層上限防髒資料造成的既有環讓上溯死循環。
     */
    public function sourceCreatesCycle(int $textId, int $sourceId): bool {
        $current = $sourceId;
        for ($depth = 0; $depth < 200; $depth++) {
            if ($current === $textId) {
                return true;
            }
            $parent = DB::table('TEXT_CODES')->where('c_textid', $current)->value('c_source');
            if ($parent === null) {
                return false;
            }
            $current = (int) $parent;
        }

        return true; // 上溯超限：視同成環，fail-closed。
    }

    /** 載入單一文獻聚合（主列＋版本列）；不存在回 null。 */
    public function load(int $textId): ?array {
        $row = DB::table('TEXT_CODES')->where('c_textid', $textId)->first();
        if (!$row) {
            return null;
        }

        $int = fn ($v) => $v !== null ? (int) $v : null;
        $str = fn ($v) => $v !== null ? (string) $v : null;

        $instances = DB::table('TEXT_INSTANCE_DATA')
            ->where('c_textid', $textId)
            ->orderBy('c_text_edition_id')
            ->orderBy('c_text_instance_id')
            ->get()
            ->map(fn ($i) => [
                'edition_id' => (int) $i->c_text_edition_id,
                'instance_id' => (int) $i->c_text_instance_id,
                'title_chn' => $str($i->c_instance_title_chn),
                'title_pinyin' => $str($i->c_instance_title),
                'publisher' => $str($i->c_publisher),
                'pub_loc' => $str($i->c_pub_loc),
                'pub_year' => $int($i->c_pub_year),
                'pub_dy' => $int($i->c_pub_dy),
                'pub_nh_code' => $int($i->c_pub_nh_code),
                'pub_nh_year' => $int($i->c_pub_nh_year),
                'source_id' => $int($i->c_source),
                'pages' => $str($i->c_pages),
                'extant' => $int($i->c_extant),
                'notes' => $str($i->c_notes),
            ])
            ->values()
            ->all();

        return [
            'textid' => (int) $row->c_textid,
            'title' => (string) ($row->c_title_chn ?? ''),
            'title_pinyin' => (string) ($row->c_title ?? ''),
            'title_trans' => $str($row->c_title_trans),
            'title_alt_chn' => $str($row->c_title_alt_chn),
            'type_id' => $str($row->c_text_type_id),
            'year' => $int($row->c_text_year),
            'nh_code' => $int($row->c_text_nh_code),
            'nh_year' => $int($row->c_text_nh_year),
            'range_code' => $int($row->c_text_range_code),
            'bibl_cat_code' => $int($row->c_bibl_cat_code),
            'extant' => $int($row->c_extant),
            'country' => $int($row->c_text_country),
            'dynasty_code' => $int($row->c_text_dy),
            'source_id' => $int($row->c_source),
            'pages' => $str($row->c_pages),
            'url_api' => $str($row->c_url_api),
            'url_api_coda' => $str($row->c_url_api_coda),
            'url_homepage' => $str($row->c_url_homepage),
            'notes' => $str($row->c_notes),
            'instances' => $instances,
        ];
    }

    // ── 書名標準化與拼音派生（自 AdminBatchLoadBookTitlesController 抽出的存儲過程語義） ──

    /** 去除書名多餘空白／全形括號，冒號後統一為「: 」。 */
    public function normalizeTitle(string $title): string {
        $title = preg_replace('/\s+/u', '', $title);
        $title = str_replace(['（', '）'], ['(', ')'], $title);
        $title = preg_replace('/[:：]\s*/u', ': ', $title);

        return trim($title);
    }

    /** 去掉冒號後的卷冊註記（該段不進拼音）。 */
    public function stripVolumeInfo(string $title): string {
        return trim(preg_replace('/[:：].*$/u', '', $title));
    }

    /**
     * 書名字形標準化：改寫**落庫書名本身**，讓 c_title_chn／拼音／無拼音檢查看到同一字形
     * （與批量匯入一致）。
     *
     * 走 replaceFor() 而不是直接指定 replaceLenient()：模式與範圍一律由
     * VariantReplaceScope::modeFor('TEXT_CODES', 'c_title_chn') 決定，呼叫端不自己選模式
     * （AGENTS.md §1.3）。
     *
     * @return array{
     *   title: string,
     *   variant_replacements: array<int, array{from: string, to: string}>,
     *   replaced: array<string, string|array<int, string>>
     * }
     */
    public function standardizeTitleVariants(string $title): array {
        $result = CharVariantMapService::replaceFor('TEXT_CODES', 'c_title_chn', $title);

        // 用 flattenReplaced() 而不是自己 foreach：replaced 的值在衝突時會是 list
        // （見 CharVariantMapService::mergeReplaced()），直接 foreach 會讓 'to' 變成陣列、
        // JSON 化後打壞批次結果頁的契約（Pages/Admin/BatchLoadBookTitles/Index.tsx）。
        return [
            'title' => $result['text'],
            'variant_replacements' => CharVariantMapService::flattenReplaced($result['replaced']),
            // 原始 map（未扁平化）供 textColumns() 併進本次寫入的累積器。
            'replaced' => $result['replaced'],
        ];
    }

    /**
     * 書名→空格分隔拼音（v→ü 正規化）。與 SharesImportHelpers::buildPinyin 的差異：
     * 先套 VariantCharNormalizer（異體字歸一化只影響拼音查表、不改書名）——批量匯入既有語義。
     */
    public function buildTitlePinyin(string $title): string {
        $normalizedTitle = VariantCharNormalizer::normalize($title);

        $chars = preg_split('//u', $normalizedTitle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $syllables = [];
        foreach ($chars as $char) {
            if (preg_match('/\p{Han}/u', $char)) {
                $syllables[] = strtolower(PinyinDictionary::getPinyin($char));
            } elseif (preg_match('/[A-Za-z0-9]/u', $char)) {
                $syllables[] = strtolower($char);
            }
        }
        $syllables = array_filter($syllables, fn ($s) => $s !== '');
        if (empty($syllables)) {
            return strtolower(trim(preg_replace('/\s+/u', ' ', $normalizedTitle)));
        }

        return PinyinUmlaut::normalize(implode(' ', $syllables));
    }

    /**
     * 由業務輸入組出 TEXT_CODES 非鍵、非稽核欄位（create／update 共用）。
     * title 先標準化（normalizeTitle＋字形標準化）；拼音留空則自動派生
     * （去卷冊註記＋異體字歸一化），給值則僅做空白／大小寫與 v→ü 正規化（管理員為準，不重跑字典）。
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function textColumns(array $input): array {
        $str = fn ($v) => (isset($v) && $v !== '') ? (string) $v : null;
        $int = fn ($v) => (isset($v) && $v !== '' && $v !== null) ? (int) $v : null;

        // 異體字落地替換（AGENTS.md §1.3）。**必須早於 buildTitlePinyin()**：拼音是從中文
        // 逐字派生的，而 `pinyin.c_chn` 被排除在替換範圍外、異體字保有自己的讀音，先替換
        // 才會拿到參考字的讀音——這正是想要的結果。也早於落庫與 operations／audit_log 的組裝。
        //
        // 用 replaceFor() 而不是 replaceRow()：$input 的鍵是輸入欄名（title／notes／pages…），
        // 不是 TEXT_CODES 的欄位名，replaceRow() 會全部判成非文本欄而整列跳過。
        // 範圍與模式一律由 VariantReplaceScope 決定，故 URL 欄與 c_text_type_id（代碼鍵）
        // 即使傳進去也是 no-op，呼叫端不需自己維護欄位清單。
        $variantReplaced = [];
        $replace = function (?string $value, string $column) use (&$variantReplaced): ?string {
            if ($value === null || $value === '') {
                return $value;
            }
            $result = CharVariantMapService::replaceFor('TEXT_CODES', $column, $value);
            $variantReplaced = CharVariantMapService::mergeReplaced($variantReplaced, $result['replaced']);

            return $result['text'];
        };

        $standardized = $this->standardizeTitleVariants($this->normalizeTitle((string) $input['title']));
        $title = $standardized['title'];
        $variantReplaced = CharVariantMapService::mergeReplaced($variantReplaced, $standardized['replaced']);

        $titleTrans = $replace($str($input['title_trans'] ?? null), 'c_title_trans');
        $titleAltChn = $replace($str($input['title_alt_chn'] ?? null), 'c_title_alt_chn');
        $pages = $replace($str($input['pages'] ?? null), 'c_pages');
        $notes = $replace($str($input['notes'] ?? null), 'c_notes');
        $this->lastVariantReplaced = $variantReplaced;

        $pinyinGiven = isset($input['title_pinyin']) && trim((string) $input['title_pinyin']) !== '';
        $pinyin = $pinyinGiven
            ? PinyinUmlaut::normalize(strtolower(trim(preg_replace('/\s+/u', ' ', (string) $input['title_pinyin']))))
            : $this->buildTitlePinyin($this->stripVolumeInfo($title));

        return [
            'c_title_chn' => $title,
            'c_title' => $pinyin,
            'c_title_trans' => $titleTrans,
            'c_title_alt_chn' => $titleAltChn,
            'c_text_type_id' => $str($input['type_id'] ?? null),
            'c_text_year' => $int($input['year'] ?? null),
            'c_text_nh_code' => $int($input['nh_code'] ?? null),
            'c_text_nh_year' => $int($input['nh_year'] ?? null),
            'c_text_range_code' => $int($input['range_code'] ?? null),
            'c_bibl_cat_code' => $int($input['bibl_cat_code'] ?? null),
            'c_extant' => $int($input['extant'] ?? null),
            'c_text_country' => $int($input['country'] ?? null),
            'c_text_dy' => $int($input['dynasty_code'] ?? null),
            'c_source' => $int($input['source_id'] ?? null),
            'c_pages' => $pages,
            'c_url_api' => $str($input['url_api'] ?? null),
            'c_url_api_coda' => $str($input['url_api_coda'] ?? null),
            'c_url_homepage' => $str($input['url_homepage'] ?? null),
            'c_notes' => $notes,
        ];
    }

    /**
     * 執行「新增一個文獻」（於呼叫端交易內）。輸入須已驗證。
     * 版本列（instances）可一併建立；批量匯入僅建主列。
     *
     * @param array<string, mixed> $input
     * @return array{
     *   textid: int, title: string, title_pinyin: string,
     *   variant_replacements: array<int, array{from: string, to: string}>,
     *   instances_added: int, operation_id_text: ?int, row: array<string, mixed>
     * }
     */
    public function create(array $input, int $actorPersonId = 0): array {
        // 批次匯入用同一個 service 實例逐列呼叫 ⇒ 每次進入都要重置，否則上一本書的替換
        // 會被算進這一本的通知（比照 OfficeImportService）。
        $this->lastVariantReplaced = [];

        $textId = $this->allocateNextId('TEXT_CODES', 'c_textid');
        $columns = $this->textColumns($input);

        // 只插入非 null 欄（欄位預設值語義），另蓋建檔稽核欄（AGENTS §1.2：經 AuditActor）。
        $payload = array_merge(
            ['c_textid' => $textId],
            array_filter($columns, fn ($v) => $v !== null),
            ['c_created_by' => AuditActor::currentName(), 'c_created_date' => Carbon::now()]
        );
        DB::table('TEXT_CODES')->insert($payload);
        $textOp = $this->recordTextOp(Operation::TYPE_CREATE, $textId, $payload, []);
        $this->auditLogService->write(
            'TEXT_CODES',
            'INSERT',
            ['c_textid' => $textId],
            null,
            $this->auditLogService->normalizeRow($payload),
            'user',
            (string) Auth::id(),
            $textOp ? (string) $textOp->id : null
        );

        $instancesAdded = 0;
        foreach ($this->normalizeInstanceRows($textId, $input['instances'] ?? []) as $row) {
            DB::table('TEXT_INSTANCE_DATA')->insert($row['payload']);
            $this->recordOp('TEXT_INSTANCE_DATA', $row['pk'], $row['payload'], $actorPersonId);
            $instancesAdded++;
        }

        return [
            'textid' => $textId,
            'title' => $columns['c_title_chn'],
            'title_pinyin' => $columns['c_title'],
            'variant_replacements' => CharVariantMapService::flattenReplaced($this->lastVariantReplaced),
            'variant_replaced' => $this->lastVariantReplaced,
            'instances_added' => $instancesAdded,
            'operation_id_text' => $textOp?->id,
            'row' => $payload,
        ];
    }

    /**
     * 更新文獻聚合（於呼叫端交易內）。輸入須已驗證且文獻存在；改 c_source 的成環檢查
     * 由呼叫端（TextAggregateDefinition::guardWrite）先擋。
     *
     * - TEXT_CODES 非鍵欄整體覆寫，另蓋 c_modified_*（沿用 c_created_*，永不覆寫）。
     * - TEXT_INSTANCE_DATA 集合對賬：以 (edition_id, instance_id) 為列鍵，
     *   同鍵改非鍵值、僅增刪差異，逐筆記 op。
     *
     * @param array<string, mixed> $input
     * @return array{
     *   textid: int, title: string, title_pinyin: string,
     *   instances_added: int, instances_removed: int, instances_updated: int, operation_id_text: ?int
     * }
     */
    public function update(int $textId, array $input, int $actorPersonId = 0): array {
        $this->lastVariantReplaced = [];

        $before = (array) DB::table('TEXT_CODES')->where('c_textid', $textId)->lockForUpdate()->first();

        $after = array_merge($this->textColumns($input), [
            'c_modified_by' => AuditActor::currentName(),
            'c_modified_date' => Carbon::now(),
        ]);
        DB::table('TEXT_CODES')->where('c_textid', $textId)->update($after);
        $textOp = $this->recordTextOp(
            Operation::TYPE_UPDATE,
            $textId,
            array_merge(['c_textid' => $textId], $after),
            $before
        );
        $this->auditLogService->write(
            'TEXT_CODES',
            'UPDATE',
            ['c_textid' => $textId],
            $this->auditLogService->normalizeRow($before),
            $this->auditLogService->normalizeRow(array_merge(['c_textid' => $textId], $after)),
            'user',
            (string) Auth::id(),
            $textOp ? (string) $textOp->id : null
        );

        // 版本列集合對賬。
        $rowKey = fn (array $r) => ((int) $r['edition_id']).'|'.((int) $r['instance_id']);
        $desired = [];
        foreach ($this->normalizeInstanceInput($input['instances'] ?? []) as $r) {
            $desired[$rowKey($r)] = $r;
        }
        $current = [];
        foreach (DB::table('TEXT_INSTANCE_DATA')->where('c_textid', $textId)->get() as $i) {
            $r = [
                'edition_id' => (int) $i->c_text_edition_id,
                'instance_id' => (int) $i->c_text_instance_id,
                'title_chn' => $i->c_instance_title_chn !== null ? (string) $i->c_instance_title_chn : null,
                'title_pinyin' => $i->c_instance_title !== null ? (string) $i->c_instance_title : null,
                'publisher' => $i->c_publisher !== null ? (string) $i->c_publisher : null,
                'pub_loc' => $i->c_pub_loc !== null ? (string) $i->c_pub_loc : null,
                'pub_year' => $i->c_pub_year !== null ? (int) $i->c_pub_year : null,
                'pub_dy' => $i->c_pub_dy !== null ? (int) $i->c_pub_dy : null,
                'pub_nh_code' => $i->c_pub_nh_code !== null ? (int) $i->c_pub_nh_code : null,
                'pub_nh_year' => $i->c_pub_nh_year !== null ? (int) $i->c_pub_nh_year : null,
                'source_id' => $i->c_source !== null ? (int) $i->c_source : null,
                'pages' => $i->c_pages !== null ? (string) $i->c_pages : null,
                'extant' => $i->c_extant !== null ? (int) $i->c_extant : null,
                'notes' => $i->c_notes !== null ? (string) $i->c_notes : null,
            ];
            $current[$rowKey($r)] = $r;
        }

        $result = $this->reconcileRowSet(
            'TEXT_INSTANCE_DATA',
            $current,
            $desired,
            fn (array $r) => [
                'c_textid' => $textId,
                'c_text_edition_id' => (int) $r['edition_id'],
                'c_text_instance_id' => (int) $r['instance_id'],
            ],
            fn (array $r) => $this->instancePayload($textId, $r),
            self::INSTANCE_UPDATABLE_COLUMNS,
            $actorPersonId
        );

        return [
            'textid' => $textId,
            'title' => $after['c_title_chn'],
            'title_pinyin' => $after['c_title'],
            'instances_added' => $result['added'],
            'instances_removed' => $result['removed'],
            'instances_updated' => $result['updated'],
            'operation_id_text' => $textOp?->id,
            // 與 create() 對稱：本次實際套用的替換（供呼叫端組 notices）。
            'variant_replacements' => CharVariantMapService::flattenReplaced($this->lastVariantReplaced),
            'variant_replaced' => $this->lastVariantReplaced,
        ];
    }

    /**
     * 刪除文獻聚合（於呼叫端交易內）：先刪 TEXT_INSTANCE_DATA 版本列、再刪 TEXT_CODES，
     * 逐筆記 op（先子後父，見 AGENTS §1.1）。呼叫端須先過 referenceCount() 護欄。
     *
     * @return array{textid: int, instances_deleted: int, operation_id_text: ?int}
     */
    public function delete(int $textId, int $actorPersonId = 0): array {
        $before = (array) DB::table('TEXT_CODES')->where('c_textid', $textId)->first();

        $instanceRows = DB::table('TEXT_INSTANCE_DATA')->where('c_textid', $textId)->get();
        foreach ($instanceRows as $i) {
            DB::table('TEXT_INSTANCE_DATA')
                ->where('c_textid', $textId)
                ->where('c_text_edition_id', $i->c_text_edition_id)
                ->where('c_text_instance_id', $i->c_text_instance_id)
                ->delete();
            $this->recordDelete('TEXT_INSTANCE_DATA', [
                'c_textid' => $textId,
                'c_text_edition_id' => (int) $i->c_text_edition_id,
                'c_text_instance_id' => (int) $i->c_text_instance_id,
            ], (array) $i, $actorPersonId);
        }

        DB::table('TEXT_CODES')->where('c_textid', $textId)->delete();
        $textOp = $this->recordTextOp(Operation::TYPE_DELETE, $textId, $before, $before);
        $this->auditLogService->write(
            'TEXT_CODES',
            'DELETE',
            ['c_textid' => $textId],
            $this->auditLogService->normalizeRow($before),
            null,
            'user',
            (string) Auth::id(),
            $textOp ? (string) $textOp->id : null
        );

        return [
            'textid' => $textId,
            'instances_deleted' => count($instanceRows),
            'operation_id_text' => $textOp?->id,
        ];
    }

    /**
     * TEXT_CODES 的 operations 紀錄：resource_id 沿用既有「純數字 c_textid」慣例
     * （非 CompositePrimaryKey query-string），與批量匯入撤回（undo() 以 resource_id 比對）、
     * /operations 還原鏈路及既存資料一致。audit_log 由呼叫處另寫（op id 已知後）。
     */
    protected function recordTextOp(int $opType, int $textId, array $resourceData, array $resourceOriginal): ?Operation {
        return $this->operationRepository->store(
            Auth::id(),
            0,
            $opType,
            'TEXT_CODES',
            (string) $textId,
            $resourceData,
            $resourceOriginal
        );
    }

    /**
     * 版本列輸入正規化（update 對賬用的業務鍵形狀）。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeInstanceInput(array $rows): array {
        $int = fn ($v) => (isset($v) && $v !== '' && $v !== null) ? (int) $v : null;
        $str = fn ($v) => (isset($v) && $v !== '' && $v !== null) ? (string) $v : null;

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'edition_id' => (int) $r['edition_id'],
                'instance_id' => (int) $r['instance_id'],
                'title_chn' => $str($r['title_chn'] ?? null),
                'title_pinyin' => $str($r['title_pinyin'] ?? null),
                'publisher' => $str($r['publisher'] ?? null),
                'pub_loc' => $str($r['pub_loc'] ?? null),
                'pub_year' => $int($r['pub_year'] ?? null),
                'pub_dy' => $int($r['pub_dy'] ?? null),
                'pub_nh_code' => $int($r['pub_nh_code'] ?? null),
                'pub_nh_year' => $int($r['pub_nh_year'] ?? null),
                'source_id' => $int($r['source_id'] ?? null),
                'pages' => $str($r['pages'] ?? null),
                'extant' => $int($r['extant'] ?? null),
                'notes' => $str($r['notes'] ?? null),
            ];
        }

        return $out;
    }

    /** 版本列業務鍵 → TEXT_INSTANCE_DATA 落庫欄位。 */
    protected function instancePayload(int $textId, array $r): array {
        $row = [
            'c_textid' => $textId,
            'c_text_edition_id' => (int) $r['edition_id'],
            'c_text_instance_id' => (int) $r['instance_id'],
            'c_instance_title_chn' => $r['title_chn'],
            'c_publisher' => $r['publisher'],
            'c_pub_loc' => $r['pub_loc'],
            'c_pub_year' => $r['pub_year'],
            'c_pub_dy' => $r['pub_dy'],
            'c_pub_nh_code' => $r['pub_nh_code'],
            'c_pub_nh_year' => $r['pub_nh_year'],
            'c_source' => $r['source_id'],
            'c_pages' => $r['pages'],
            'c_extant' => $r['extant'],
            'c_notes' => $r['notes'],
        ];

        // 版本列的異體字落地替換（AGENTS.md §1.3）。這裡的鍵**就是** TEXT_INSTANCE_DATA 的
        // 欄位名，所以用整列的 replaceRow()——範圍由欄位型別決定，呼叫端不需維護欄位清單。
        // 不只是理論上的掛鉤：c_publisher 是不帶 _chn 後綴的中文欄（異體字計畫 D3 列的 8 個
        // 同類欄之一），送「淸華書局」會落庫「清華書局」；c_pub_loc／c_notes 同理。
        //
        // **必須早於下面的拼音派生**：c_instance_title 逐字查 pinyin.c_chn（該表排除在替換
        // 範圍外、異體字保有自己讀音），先替換才會拿到參考字的讀音。
        $result = CharVariantMapService::replaceRow($row, 'TEXT_INSTANCE_DATA');
        $row = $result['data'];
        $this->lastVariantReplaced = CharVariantMapService::mergeReplaced(
            $this->lastVariantReplaced,
            $result['replaced']
        );

        $row['c_instance_title'] = $r['title_pinyin'] !== null
            ? $r['title_pinyin']
            : ($row['c_instance_title_chn'] !== null ? $this->buildTitlePinyin((string) $row['c_instance_title_chn']) : null);

        return $row;
    }

    /**
     * create 用：版本列輸入 → [pk, payload] 清單。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{pk: array<string, int>, payload: array<string, mixed>}>
     */
    protected function normalizeInstanceRows(int $textId, array $rows): array {
        $out = [];
        foreach ($this->normalizeInstanceInput($rows) as $r) {
            $out[] = [
                'pk' => [
                    'c_textid' => $textId,
                    'c_text_edition_id' => (int) $r['edition_id'],
                    'c_text_instance_id' => (int) $r['instance_id'],
                ],
                'payload' => $this->instancePayload($textId, $r),
            ];
        }

        return $out;
    }
}
