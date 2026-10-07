<?php

namespace App\Services\Import;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\CharVariantMapService;
use App\Services\Import\Concerns\SharesImportHelpers;
use App\Services\Mutations\EntityAggregate\AggregateWriteConflictException;
use App\Support\VariantLabelMap;
use Illuminate\Support\Facades\DB;

/**
 * 「新增一個社會機構」的存儲過程之單一真源。
 *
 * 由 admin 批量表單（AdminBatchLoadSocialInstitutesController）與 mutation API
 * （通用 EntityAggregate*Handler → SocialInstitutionAggregateDefinition）共用，確保名稱去重、派生（拼音）、自動 id、
 * 配套地址行與審計（operations + AuditLog）只有一份實作、不漂移。
 * 共用的派生／校驗／審計基元見 SharesImportHelpers（與 OfficeImportService 共用）。
 *
 * 一次「加機構」= 原子寫入至多三表：
 *  - SOCIAL_INSTITUTION_NAME_CODES：名稱去重——同名（c_inst_name_hz）已存在則複用其
 *    c_inst_name_code、不新增；否則 c_inst_name_code(max+1) + 派生 c_inst_name_py。
 *  - SOCIAL_INSTITUTION_CODES：c_inst_code(max+1)、類型、起始/在世朝代（begin/floruit 同碼）、來源。
 *  - SOCIAL_INSTITUTION_ADDR：複合鍵、c_inst_addr_type_code 固定 1、座標固定 0、來源。
 *
 * 這三張表無 c_created_by/date 審計欄，故直接 plain insert（與原批量工具一致），
 * 審計改由 operations + audit_log 承載。
 *
 * create() 不自開交易，於呼叫端交易內執行，以保留「全有或全無」語意。
 * 名稱去重以 DB 查詢為準：批量非原子逐筆提交、原子批同一交易內 savepoint，
 * 後一筆皆能看見前一筆剛插入的 NAME_CODES，故無需跨筆記憶體狀態。
 *
 * 【實體識別】機構實體的識別是 c_inst_code 單鍵：生產庫 4011 列 c_inst_code 全數唯一
 * （零「一碼多名」），底層複合主鍵 (c_inst_code, c_inst_name_code) 是把「當前名稱」冗餘
 * 進主鍵的儲存層遺留，c_inst_name_code 為屬性、由本聚合根內部解析與維護
 * （見 docs/ENTITY_AGGREGATE_ARCHITECTURE.md §2.5）。推論：改名＝換 name_code＝底層 PK
 * 變更，且人物表（BIOG_INST_DATA 等）存的是 (inst_code, name_code) 對，改名會使既存引用
 * 失配——故 update() 僅在 referenceCount()==0 時允許改名，呼叫端須先擋。
 *
 * 除 create() 外亦提供 load()／update()／delete()，作為「機構實體」聚合 CRUD 的單一真源，
 * 供 mutation API 與前端聚合編輯頁共用。update() 對 SOCIAL_INSTITUTION_ADDR 做集合對賬
 * （同鍵改值、僅增刪差異）。delete() 前須先 referenceCount() 檢查四張人物表引用。
 *
 * 【別名】SOCIAL_INSTITUTION_ALTNAME_DATA 也屬本聚合（選填的 alt_names 清單）：create 時
 * 一併寫入、update 時**僅在請求帶了 alt_names 才**集合對賬（沒帶＝不動，見
 * ResolvesSocialInstituteAggregateInput::parseSocialInstituteAltNames()）、改名時整批改寫名碼、
 * delete 時先子後父逐列刪除。該表在資料庫**沒有主鍵**，邏輯列鍵是
 * (c_inst_code, c_inst_altname_type, c_inst_altname_hz)，見 reconcileAltNames()。
 */
class SocialInstituteImportService implements EntityAggregateService {
    use SharesImportHelpers;

    /**
     * 上一次寫入實際套用的異體字替換（變體字 → 參考字）。供批次匯入結果頁組
     * variant_replacements（比照書名匯入）。
     *
     * @var array<string,string|array<int,string>>
     */
    protected array $lastVariantReplaced = [];

    /**
     * 上一次寫入的別名通知（非字元替換：送來的字形被併入既有的另一個字形）。
     *
     * @var array<int, string>
     */
    protected array $lastAltNameNotices = [];

    public function __construct(
        protected OperationRepository $operationRepository,
        protected AuditLogService $auditLogService
    ) {
    }

    /**
     * typeMapAndCodes() 的 per-instance memo（見該方法註解）。
     *
     * @var array{0: array<string,int>, 1: array<int,int>}|null
     */
    protected ?array $typeMapMemo = null;

    /**
     * 機構類型（中文名或拼音）→代碼對照。**鍵已做異體字歸一**（見 VariantLabelMap）。
     * 拼音鍵的歸一是恆等映射（對照表的鍵都是漢字），列入只是讓兩種鍵走同一條路。
     *
     * 白名單檢查請改用 typeCodes()：歸一後鍵碰撞時 map 只留最小碼，
     * 拿 map 的值當白名單會讓另一個**完全合法**的代碼開始被判 invalid。
     */
    public function typeMap(): array {
        return $this->typeMapAndCodes()[0];
    }

    /** 全部合法機構類型代碼（含標籤歸一後被碰撞吃掉的那些）。 */
    public function typeCodes(): array {
        return $this->typeMapAndCodes()[1];
    }

    /**
     * @return array{0: array<string,int>, 1: array<int,int>}
     *
     * per-instance memo：同一請求會同時用到 typeMap() 與 typeCodes()，不 memo 就會重複
     * 查詢、重複 build、重複記碰撞 warning（見 SharesImportHelpers::dynastyMapAndCodes()）。
     */
    protected function typeMapAndCodes(): array {
        if ($this->typeMapMemo !== null) {
            return $this->typeMapMemo;
        }

        $records = DB::table('SOCIAL_INSTITUTION_TYPES')
            ->select('c_inst_type_code', 'c_inst_type_hz', 'c_inst_type_py')
            ->orderBy('c_inst_type_code')
            ->get();

        $hzPairs = [];
        $pyPairs = [];
        foreach ($records as $record) {
            $code = (int) $record->c_inst_type_code;
            $hzPairs[] = [(string) ($record->c_inst_type_hz ?? ''), $code];
            $pyPairs[] = [(string) ($record->c_inst_type_py ?? ''), $code];
        }

        [$hzMap, $hzCodes] = VariantLabelMap::build($hzPairs, 'SOCIAL_INSTITUTION_TYPES', 'c_inst_type_hz');
        [$pyMap, $pyCodes] = VariantLabelMap::build($pyPairs, 'SOCIAL_INSTITUTION_TYPES', 'c_inst_type_py');

        // 白名單取**兩份的聯集**：schema 允許 c_inst_type_hz／c_inst_type_py 任一為 null，
        // 舊 typeMap() 也是任一有值就收。只取 hz 那份會讓「只有拼音名」的合法類型碼
        // 在 v2 的 type_code 驗證被錯判成 invalid（422）。
        $codes = array_values(array_unique(array_merge($hzCodes, $pyCodes)));

        // 漢字鍵優先（與舊碼「hz 先寫、py 後寫」的 last-wins 相反，但只有在同一字串
        // 同時是某型的漢字名與另一型的拼音時才有差別，實務上不存在；明確取漢字優先）。
        return $this->typeMapMemo = [$hzMap + $pyMap, $codes];
    }

    /** 校驗地址 ID 存在於 ADDR_CODES；回傳缺失的 id。 */
    public function missingAddrIds(array $addrIds): array {
        $unique = array_values(array_unique(array_map('intval', array_filter($addrIds, fn ($v) => $v !== '' && $v !== null))));
        if (empty($unique)) {
            return [];
        }
        $found = DB::table('ADDR_CODES')->whereIn('c_addr_id', $unique)->pluck('c_addr_id')->map(fn ($v) => (int) $v)->all();

        return array_values(array_diff($unique, $found));
    }

    /**
     * 機構被人物資料引用的筆數；>0 表示不可刪除、不可改名。
     *
     * ⚠ 安全關鍵：BIOG_INST_DATA／ENTRY_DATA／ASSOC_DATA／POSTED_TO_OFFICE_DATA 四張人物表
     * 都以 ON DELETE CASCADE 外鍵引用 c_inst_code（與 c_inst_name_code），有引用時刪除機構
     * 會連帶刪掉人物資料（靜默損毀）。呼叫端（handler）務必先以本方法擋下，勿移除此護欄。
     * 改名同樣受此護欄約束：人物表存 (inst_code, name_code) 對，改名會使既存引用失配。
     */
    public function referenceCount(int $instCode): int {
        return $this->countReferences([
            ['BIOG_INST_DATA', 'c_inst_code'],
            ['ENTRY_DATA', 'c_inst_code'],
            ['ASSOC_DATA', 'c_inst_code'],
            ['POSTED_TO_OFFICE_DATA', 'c_inst_code'],
        ], $instCode);
    }

    /** 載入單一機構聚合（識別＝c_inst_code；供編輯頁與 API 讀取）；不存在回 null。 */
    public function load(int $instCode): ?array {
        $row = DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', $instCode)->first();
        if (!$row) {
            return null;
        }
        $name = DB::table('SOCIAL_INSTITUTION_NAME_CODES')
            ->where('c_inst_name_code', $row->c_inst_name_code)
            ->first();

        $addresses = DB::table('SOCIAL_INSTITUTION_ADDR')
            ->where('c_inst_code', $instCode)
            ->orderBy('c_inst_addr_type_code')
            ->orderBy('c_inst_addr_id')
            ->get()
            ->map(fn ($a) => [
                'addr_id' => (int) $a->c_inst_addr_id,
                'addr_type_code' => (int) $a->c_inst_addr_type_code,
                'begin_year' => $a->c_inst_addr_begin_year !== null ? (int) $a->c_inst_addr_begin_year : null,
                'end_year' => $a->c_inst_addr_end_year !== null ? (int) $a->c_inst_addr_end_year : null,
                'xcoord' => (float) $a->inst_xcoord,
                'ycoord' => (float) $a->inst_ycoord,
                'source_id' => $a->c_source !== null ? (int) $a->c_source : null,
                'pages' => $a->c_pages !== null ? (string) $a->c_pages : null,
                'notes' => $a->c_notes !== null ? (string) $a->c_notes : null,
            ])
            ->values()
            ->all();

        $int = fn ($v) => $v !== null ? (int) $v : null;
        $str = fn ($v) => $v !== null ? (string) $v : null;

        return [
            'inst_code' => (int) $row->c_inst_code,
            'name_code' => (int) $row->c_inst_name_code,
            'name' => (string) ($name->c_inst_name_hz ?? ''),
            'name_pinyin' => (string) ($name->c_inst_name_py ?? ''),
            'type_code' => $int($row->c_inst_type_code),
            'begin_year' => $int($row->c_inst_begin_year),
            'by_nianhao_code' => $int($row->c_by_nianhao_code),
            'by_nianhao_year' => $int($row->c_by_nianhao_year),
            'by_year_range' => $int($row->c_by_year_range),
            'begin_dy' => $int($row->c_inst_begin_dy),
            'floruit_dy' => $int($row->c_inst_floruit_dy),
            'first_known_year' => $int($row->c_inst_first_known_year),
            'end_year' => $int($row->c_inst_end_year),
            'ey_nianhao_code' => $int($row->c_ey_nianhao_code),
            'ey_nianhao_year' => $int($row->c_ey_nianhao_year),
            'ey_year_range' => $int($row->c_ey_year_range),
            'end_dy' => $int($row->c_inst_end_dy),
            'last_known_year' => $int($row->c_inst_last_known_year),
            'source_id' => $int($row->c_source),
            'pages' => $str($row->c_pages),
            'notes' => $str($row->c_notes),
            'addresses' => $addresses,
            'alt_names' => $this->loadAltNames($instCode),
        ];
    }

    /**
     * 別名資料表。**資料庫沒有主鍵、全欄可空**；本聚合以 (c_inst_code, 類型, 別名) 作邏輯列鍵
     * （CompositePrimaryKey::SCHEMAS 同名項），定位一律走 whereAltName()。
     *
     * 異體字替換模式：c_inst_altname_hz **刻意走預設的寬鬆模式**，不列入
     * VariantReplaceScope::STRICT_COLUMNS。strict 是人名／人物別名欄（BIOG_MAIN 姓名、
     * ALTNAME_DATA.c_alt_name_chn）的逐欄例外；機構名 SOCIAL_INSTITUTION_NAME_CODES.c_inst_name_hz
     * 是寬鬆的，機構別名與機構名同一套規則，兩者的字形才不會分歧。
     */
    protected const ALTNAME_TABLE = 'SOCIAL_INSTITUTION_ALTNAME_DATA';

    /** 合法的別名類型碼（SOCIAL_INSTITUTION_ALTNAME_CODES；目前只有 0「未詳」）。 */
    public function altNameTypeCodes(): array {
        return DB::table('SOCIAL_INSTITUTION_ALTNAME_CODES')
            ->whereNotNull('c_inst_altname_type')
            ->pluck('c_inst_altname_type')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 別名的「歸一鍵」：類型＋**異體字歸一後**的名稱。用於認出兩形並存（§1.3）：
     * 既有列存「淸涼寺」、請求送「清涼寺」，比原字面會判成兩個不同別名——刪舊增新，
     * 既有列的原字面就被靜默改寫了。
     */
    public function altNameKey(?int $type, string $name): string {
        $reference = CharVariantMapService::replaceFor(self::ALTNAME_TABLE, 'c_inst_altname_hz', $name)['text'];

        return self::altNameTypeKey($type).'|'.$reference;
    }

    /**
     * 別名的「字面鍵」：類型＋原字面（不歸一）。
     *
     * 尾端空白不算：MariaDB 的 utf8mb4_bin 是 PAD SPACE collation，whereAltName() 下
     * 'X' 與 'X ' 相等。字面鍵若把兩者當成不同，重複列護欄就看不到這一對，而 where 卻會
     * 一次命中兩列。（API 寫入的名稱本來就 trim 過，這只關乎 API 以外寫進來的資料。）
     */
    public static function altNameLiteralKey(?int $type, ?string $name): string {
        return self::altNameTypeKey($type).'|'.($name === null ? "\0null" : rtrim($name, ' '));
    }

    protected static function altNameTypeKey(?int $type): string {
        return $type === null ? 'null' : (string) $type;
    }

    /**
     * 機構的別名列（依類型、名稱排序）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function loadAltNames(int $instCode): array {
        return DB::table(self::ALTNAME_TABLE)
            ->where('c_inst_code', $instCode)
            ->orderBy('c_inst_altname_type')
            ->orderBy('c_inst_altname_hz')
            ->get()
            ->map(fn ($a) => $this->altNameRowFromDb($a))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> 正規化列（與 reconcileAltNames() 的期望列同形；name 可為 null） */
    protected function altNameRowFromDb(object $a): array {
        return [
            'type_code' => $a->c_inst_altname_type !== null ? (int) $a->c_inst_altname_type : null,
            'name' => $a->c_inst_altname_hz !== null ? (string) $a->c_inst_altname_hz : null,
            'pinyin' => $a->c_inst_altname_py !== null ? (string) $a->c_inst_altname_py : null,
            'source_id' => $a->c_source !== null ? (int) $a->c_source : null,
            'pages' => $a->c_pages !== null ? (string) $a->c_pages : null,
            'notes' => $a->c_notes !== null ? (string) $a->c_notes : null,
        ];
    }

    /**
     * 以邏輯鍵定位別名列。
     *
     * **名稱用二進位比對**：欄位是 utf8mb4_general_ci，在它之下大小寫不分、**所有增補平面
     * 字元（擴展 B 以後的漢字）彼此相等**。這張表沒有主鍵擋重複，「𡒄山」「𡕒山」兩列可以並存，
     * 用預設 collation 的 `=` 刪其中一列會把兩列一起刪掉、卻只記一筆稽核。MariaDB 上改用
     * utf8mb4_bin；SQLite（測試）的 `=` 本來就是二進位比對。
     * NULL 的類型／名稱走 IS NULL（`= NULL` 永遠不成立）。
     */
    protected function whereAltName(int $instCode, ?int $type, ?string $name): \Illuminate\Database\Query\Builder {
        $query = DB::table(self::ALTNAME_TABLE)->where('c_inst_code', $instCode);
        $type === null ? $query->whereNull('c_inst_altname_type') : $query->where('c_inst_altname_type', $type);
        if ($name === null) {
            $query->whereNull('c_inst_altname_hz');
        } elseif (is_mysql()) {
            $query->whereRaw('c_inst_altname_hz = ? COLLATE utf8mb4_bin', [$name]);
        } else {
            $query->where('c_inst_altname_hz', $name);
        }

        return $query;
    }

    /**
     * 別名集合對賬（於呼叫端交易內；呼叫端須已過 SocialInstitutionAggregateDefinition::guardWrite()
     * 的別名護欄）。逐列記 operations + audit_log。
     *
     * 配對順序：同字面的既有列優先；沒有才以歸一鍵配對既有列（依字面排序，結果確定）；
     * 都沒有就是新增。沒被配到的既有列刪除。
     * - **配對到既有列時沿用其原字面**（D6：不回溯改寫既有的變體形）；只有新增的列落參考形，
     *   替換紀錄也只在真的落了參考形時才進 notices。
     * - 拼音：請求有給就用；沒給時，既有列保留原拼音，新列由參考形派生。
     * - 名稱為 NULL 的既有列 API 無從指稱，**不參與對賬、原樣保留**。
     * - 每一筆 update／delete 都斷言恰好影響 1 列、每一筆 insert 都先確認同鍵不存在；
     *   不符就拋例外讓整筆交易回滾——寧可失敗，不可在沒有主鍵的表上誤改他列。
     *
     * @param array<int, array<string, mixed>> $rows 已驗證的別名列（見 parseSocialInstituteAltNames()）
     * @return array{added: int, removed: int, updated: int}
     */
    protected function reconcileAltNames(int $instCode, int $nameCode, array $rows, int $actorPersonId): array {
        $this->assertAltNameCodesFit($instCode, $nameCode);
        $current = DB::table(self::ALTNAME_TABLE)
            ->where('c_inst_code', $instCode)
            ->whereNotNull('c_inst_altname_hz')
            // 鎖定讀＝當前讀：核准重放時外層交易的一致性快照可能早於並發寫入，用快照讀會
            // 漏看剛落庫的同名別名而再插一列。
            ->lockForUpdate()
            ->get()
            ->map(fn ($a) => $this->altNameRowFromDb($a))
            ->sortBy(fn ($r) => self::altNameLiteralKey($r['type_code'], $r['name']))
            ->values()
            ->all();

        $byLiteral = [];
        foreach ($current as $i => $row) {
            $literal = self::altNameLiteralKey($row['type_code'], $row['name']);
            if (isset($byLiteral[$literal])) {
                // guardWrite() 已以 409 擋下；走到這裡代表護欄被繞過，不可在分不開的列上動手。
                throw new AggregateWriteConflictException(
                    '此機構的既有別名有完全相同的重複列，無法安全對賬；請先清理重複列',
                    ['alt_names' => ['existing_duplicate_rows']]
                );
            }
            $byLiteral[$literal] = $i;
        }

        $text = function (?string $value, string $column): ?string {
            if ($value === null || $value === '') {
                return null;
            }
            $result = CharVariantMapService::replaceFor(self::ALTNAME_TABLE, $column, $value);
            $this->lastVariantReplaced = CharVariantMapService::mergeReplaced($this->lastVariantReplaced, $result['replaced']);

            return $result['text'];
        };

        $claimed = [];
        $plan = [];
        // 第一輪：字面相同者先配對（兩形並存時，送哪一形就留哪一形）。
        foreach ($rows as $j => $r) {
            $i = $byLiteral[self::altNameLiteralKey($r['type_code'], (string) $r['name'])] ?? null;
            if ($i !== null && !isset($claimed[$i])) {
                $claimed[$i] = true;
                $plan[$j] = $i;
            }
        }
        // 第二輪：歸一鍵配對剩下的既有列。
        foreach ($rows as $j => $r) {
            if (array_key_exists($j, $plan)) {
                continue;
            }
            $plan[$j] = null;
            $key = $this->altNameKey($r['type_code'], (string) $r['name']);
            foreach ($current as $i => $row) {
                if (!isset($claimed[$i]) && $this->altNameKey($row['type_code'], $row['name']) === $key) {
                    $claimed[$i] = true;
                    $plan[$j] = $i;

                    break;
                }
            }
        }

        $added = 0;
        $removed = 0;
        $updated = 0;

        foreach ($current as $i => $row) {
            if (isset($claimed[$i])) {
                continue;
            }
            $affected = $this->whereAltName($instCode, $row['type_code'], $row['name'])->delete();
            $this->assertAltNameAffected($affected, 1, $instCode, 'delete');
            $this->recordDelete(self::ALTNAME_TABLE, $this->altNamePk($instCode, $row), $this->altNamePayload($instCode, $nameCode, $row), $actorPersonId);
            $removed++;
        }

        foreach ($rows as $j => $r) {
            $existing = $plan[$j] !== null ? $current[$plan[$j]] : null;
            if ($existing !== null) {
                $name = $existing['name'];
                if ($name !== (string) $r['name']) {
                    // 不是「正規化」（方向可能相反），記成 replaced 會謊稱已歸一；比照機構名的
                    // 「併入既有字形」改發一般通知。
                    $this->lastAltNameNotices[] = "別名「{$r['name']}」與既有別名「{$name}」為同一別名的不同字形，沿用既有字形";
                }
            } else {
                $replacement = CharVariantMapService::replaceFor(self::ALTNAME_TABLE, 'c_inst_altname_hz', (string) $r['name']);
                $this->lastVariantReplaced = CharVariantMapService::mergeReplaced($this->lastVariantReplaced, $replacement['replaced']);
                $name = $replacement['text'];
            }
            $pinyin = $r['pinyin'] ?? null;
            if ($pinyin === null || $pinyin === '') {
                $pinyin = $existing !== null ? $existing['pinyin'] : $this->buildPinyin($name);
            }
            $desired = [
                'type_code' => $existing !== null ? $existing['type_code'] : $r['type_code'],
                'name' => $name,
                'pinyin' => $pinyin,
                'source_id' => $r['source_id'] !== null ? (int) $r['source_id'] : null,
                'pages' => $text($r['pages'] ?? null, 'c_pages'),
                'notes' => $text($r['notes'] ?? null, 'c_notes'),
            ];

            if ($existing === null) {
                if ($this->whereAltName($instCode, $desired['type_code'], $name)->lockForUpdate()->exists()) {
                    throw new AggregateWriteConflictException(
                        '此機構已有同名別名（可能剛被另一筆寫入建立），本次未寫入',
                        ['alt_names' => ['already_exists']]
                    );
                }
                $payload = $this->altNamePayload($instCode, $nameCode, $desired);
                DB::table(self::ALTNAME_TABLE)->insert($payload);
                $this->recordOp(self::ALTNAME_TABLE, $this->altNamePk($instCode, $desired), $payload, $actorPersonId);
                $added++;
            } elseif ($existing !== $desired) {
                $changes = array_intersect_key(
                    $this->altNamePayload($instCode, $nameCode, $desired),
                    array_flip(['c_inst_altname_py', 'c_source', 'c_pages', 'c_notes'])
                );
                $affected = $this->whereAltName($instCode, $existing['type_code'], $existing['name'])->update($changes);
                $this->assertAltNameAffected($affected, 1, $instCode, 'update');
                $this->recordUpdate(
                    self::ALTNAME_TABLE,
                    $this->altNamePk($instCode, $desired),
                    $this->altNamePayload($instCode, $nameCode, $existing),
                    $this->altNamePayload($instCode, $nameCode, $desired),
                    $actorPersonId
                );
                $updated++;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'updated' => $updated];
    }

    /**
     * 刪除機構的全部別名（delete() 用，先子後父）。逐列記錄：字面完全相同的重複列一條 delete
     * 敘述會一起刪掉，所以**每一列實體列各記一筆**（各帶自己的快照），筆數必須與刪除列數相符。
     */
    protected function deleteAllAltNames(int $instCode, int $actorPersonId): int {
        $groups = [];
        foreach (DB::table(self::ALTNAME_TABLE)->where('c_inst_code', $instCode)->lockForUpdate()->get() as $a) {
            $row = $this->altNameRowFromDb($a);
            $groups[self::altNameLiteralKey($row['type_code'], $row['name'])][] = [$row, (array) $a];
        }

        $deleted = 0;
        foreach ($groups as $members) {
            [$first] = $members[0];
            $affected = $this->whereAltName($instCode, $first['type_code'], $first['name'])->delete();
            $this->assertAltNameAffected($affected, count($members), $instCode, 'delete');
            foreach ($members as [$row, $snapshot]) {
                $this->recordDelete(self::ALTNAME_TABLE, $this->altNamePk($instCode, $row), $snapshot, $actorPersonId);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 改名時把別名列的 c_inst_name_code 改成新碼，**逐列**記 UPDATE（每一列實體列各一筆，
     * 帶自己的前後快照）。分組與筆數斷言同 deleteAllAltNames()。名稱為 NULL 的列一併改寫
     * （名碼屬於機構、不屬於別名本身）。
     */
    protected function renameAltNames(int $instCode, int $oldNameCode, int $nameCode, int $actorPersonId): void {
        $this->assertAltNameCodesFit($instCode, $nameCode);
        $groups = [];
        foreach (DB::table(self::ALTNAME_TABLE)->where('c_inst_code', $instCode)->lockForUpdate()->get() as $a) {
            $row = $this->altNameRowFromDb($a);
            $groups[self::altNameLiteralKey($row['type_code'], $row['name'])][] = [$row, (array) $a];
        }

        foreach ($groups as $members) {
            [$first] = $members[0];
            $affected = $this->whereAltName($instCode, $first['type_code'], $first['name'])
                ->update(['c_inst_name_code' => $nameCode]);
            // 名碼本來就等於新碼的列不會被計入（MariaDB 回報的是「實際改變」的列數）。
            $expected = count(array_filter($members, fn ($m) => (int) ($m[1]['c_inst_name_code'] ?? 0) !== $nameCode));
            if ($affected !== $expected && $affected !== count($members)) {
                $this->assertAltNameAffected($affected, $expected, $instCode, 'rename');
            }
            foreach ($members as [$row, $before]) {
                if ((int) ($before['c_inst_name_code'] ?? 0) === $nameCode) {
                    continue;
                }
                $this->recordUpdate(
                    self::ALTNAME_TABLE,
                    $this->altNamePk($instCode, $row),
                    $before,
                    array_merge($before, ['c_inst_name_code' => $nameCode]),
                    $actorPersonId
                );
            }
        }
    }

    /**
     * 別名表的 c_inst_code／c_inst_name_code 是有號 SMALLINT（上限 32767）。在完整遷移後的 schema
     * 裡機構表與名稱表的對應欄同樣是 SMALLINT，正常情況下不會出現超限的碼；這是**防禦性**檢查，
     * 針對手動改過或舊版的 schema：超過上限時 MariaDB 非 strict 模式會**靜默截斷**，別名會掛到
     * 另一個機構上，寧可擋下整筆寫入。（2026-10 生產最大 c_inst_code 為 4010。）
     */
    protected function assertAltNameCodesFit(int $instCode, int $nameCode): void {
        if ($instCode > 32767 || $nameCode > 32767) {
            throw new AggregateWriteConflictException(
                "機構碼或名稱碼超出別名表欄位上限（SMALLINT 32767；c_inst_code={$instCode}、c_inst_name_code={$nameCode}），未寫入",
                ['alt_names' => ['code_out_of_range']]
            );
        }
    }

    protected function assertAltNameAffected(int $affected, int $expected, int $instCode, string $what): void {
        if ($affected !== $expected) {
            throw new AggregateWriteConflictException(
                "別名{$what}影響 {$affected} 列、預期 {$expected} 列（c_inst_code={$instCode}），已整筆回滾、未寫入",
                ['alt_names' => ['unexpected_row_count']]
            );
        }
    }

    /** 別名列的邏輯鍵（審計 row_pk、operations.resource_id）。 */
    protected function altNamePk(int $instCode, array $r): array {
        return [
            'c_inst_code' => $instCode,
            'c_inst_altname_type' => $r['type_code'],
            'c_inst_altname_hz' => $r['name'],
        ];
    }

    protected function altNamePayload(int $instCode, int $nameCode, array $r): array {
        return [
            'c_inst_name_code' => $nameCode,
            'c_inst_code' => $instCode,
            'c_inst_altname_type' => $r['type_code'],
            'c_inst_altname_hz' => $r['name'],
            'c_inst_altname_py' => $r['pinyin'],
            'c_source' => $r['source_id'],
            'c_pages' => $r['pages'],
            'c_notes' => $r['notes'],
        ];
    }

    /**
     * 名稱→名碼解析（create／update 共用的去重語義）：同名已存在則複用其 c_inst_name_code，
     * 否則配號（max+1）、派生拼音並新增 NAME_CODES 一列（記 op）。
     *
     * @return array{name_code:int,name_created:bool,name_pinyin:string,operation_id:?int}
     */
    /**
     * 這個名稱會解析到哪一個既有 name code（**唯讀**：不配號、不建列、不上鎖）。
     *
     * 供改名護欄判斷「這次儲存會不會真的換掉 c_inst_name_code」。
     * 護欄不能只比字串：異體字歸一後字串相等不代表 code 不變（反方向——輸入參考形而
     * 既有列是另一個變體形——resolveNameCode() 仍會新建一個 code，那正是護欄要擋的
     * 「既存引用失配」）。
     */
    public function findExistingNameCode(string $name): ?int {
        $row = $this->locateNameRow($name, false);

        return $row === null ? null : (int) $row->c_inst_name_code;
    }

    /**
     * 以「原輸入優先、落空才擴成兩形」定位既有 NAME_CODES 列。
     *
     * @param bool $lock 是否 lockForUpdate（寫入路徑要、唯讀探測不要）
     */
    protected function locateNameRow(string $name, bool $lock = true): ?object {
        $referenceName = CharVariantMapService::replaceFor('SOCIAL_INSTITUTION_NAME_CODES', 'c_inst_name_hz', $name)['text'];
        $candidates = array_values(array_unique([$name, $referenceName]));

        $locate = function (array $names) use ($lock) {
            $query = DB::table('SOCIAL_INSTITUTION_NAME_CODES')
                ->whereIn('c_inst_name_hz', $names)
                ->orderBy('c_inst_name_code');
            if ($lock) {
                $query->lockForUpdate();
            }

            return $query->first();
        };

        return $locate([$name]) ?: ($candidates === [$name] ? null : $locate($candidates));
    }

    protected function resolveNameCode(string $name, int $actorPersonId): array {
        // 異體字：**兩形都要探**（替換前的原輸入 + 替換後的參考形）。
        //
        // 只拿替換後的值查會製造新的分裂：既有列字面是「淸…」在 D6 之下永不改寫，
        // 把匯入值正規化成「清…」會讓精確比對**錯過它本來會命中的那一列**，於是鑄出
        // 第二個 name code——比不替換更糟。
        //
        // 由此產生一個**刻意的不對稱**：兩形都在時複用既有的變體形列，該列的
        // c_inst_name_hz 仍是「淸…」、永不歸一，與 D7 第二類「觸碰即歸一」的語義相反。
        // 這是為了不製造重複碼而接受的取捨（見 plan S4）。
        $nameReplacement = CharVariantMapService::replaceFor('SOCIAL_INSTITUTION_NAME_CODES', 'c_inst_name_hz', $name);
        $referenceName = $nameReplacement['text'];
        $candidates = array_values(array_unique([$name, $referenceName]));

        // lockForUpdate 防兩個同新名的並發請求同時判定「不存在」而各建一列同碼 NAME_CODES；
        // MariaDB 生效，SQLite（測試）grammar 編譯為 no-op。
        //
        // ⚠️ 這道去重是**單向**的，明文記錄其邊界：只涵蓋「輸入是變體形、既有列是參考形」。
        // 反方向（輸入參考形、既有列是**另一個**變體形）無法用精確比對命中——那需要列舉
        // 輸入的所有「前像」（會組合爆炸，plan 明確否決）或在表上加一個歸一後的影子欄。
        // 該情境會像 S4 之前一樣新建一個名稱碼；不是本步造成的回歸，但也沒被本步修好。
        //
        // **先精確探原輸入、落空才擴成兩形**：庫裡可能同時有兩形（D6 不自動合併），
        // 若直接 whereIn + 最小碼優先，輸入「清溪書院」時會被字面不同的「淸溪書院」
        // （較小的碼）搶走，機構被掛到另一個既有名稱碼上。精確字面優先才符合直覺，
        // 且兩形都在時的選擇仍是確定的（orderBy c_inst_name_code ＝最小碼優先）。
        $existing = $this->locateNameRow($name);

        if ($existing) {
            $existingName = (string) ($existing->c_inst_name_hz ?? $name);

            // 命中的既有列必然是「輸入本身」或「輸入的參考形」之一（候選集就這兩個值）。
            // 後者代表真的發生了字元替換（輸入變體形 → 用既有的參考形列），記進 replaced
            // 讓使用者看到「淸→清」；否則字面完全相同、什麼都沒變。
            if ($existingName !== $name) {
                $this->lastVariantReplaced = CharVariantMapService::mergeReplaced(
                    $this->lastVariantReplaced,
                    $nameReplacement['replaced']
                );
            }

            return [
                'name_code' => (int) $existing->c_inst_name_code,
                'name_created' => false,
                'name_pinyin' => (string) ($existing->c_inst_name_py ?? ''),
                'operation_id' => null,
                // 複用既有列：回傳它的**原字面**（可能是變體形，刻意不歸一）。
                'name_hz' => $existingName,
            ];
        }

        // 新建：這一筆是真的把使用者輸入歸一後落庫，所以替換紀錄要進 notices／結果頁。
        $this->lastVariantReplaced = CharVariantMapService::mergeReplaced($this->lastVariantReplaced, $nameReplacement['replaced']);

        // 新建時用**參考形**（拼音也從參考形派生：pinyin.c_chn 被排除在替換範圍外、
        // 異體字保有自己讀音，所以要先替換才拿到參考字的讀音）。
        $name = $referenceName;
        $nameCode = $this->allocateNextId('SOCIAL_INSTITUTION_NAME_CODES', 'c_inst_name_code');
        $namePinyin = $this->buildPinyin($name);
        $payload = [
            'c_inst_name_code' => $nameCode,
            'c_inst_name_hz' => $name,
            'c_inst_name_py' => $namePinyin,
        ];
        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert($payload);
        $op = $this->recordOp('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_code' => $nameCode], $payload, $actorPersonId);

        return [
            'name_code' => $nameCode,
            'name_created' => true,
            'name_pinyin' => $namePinyin,
            'operation_id' => $op?->id,
            'name_hz' => $name, // 新建：參考形
        ];
    }

    /**
     * SOCIAL_INSTITUTION_ADDR 的文本欄落地替換（lenient），並把紀錄併進本次累積器。
     *
     * @param mixed $value
     */
    protected function replaceAddrText($value, string $column): ?string {
        if ($value === null || $value === '') {
            return $value === '' ? '' : null;
        }

        $result = CharVariantMapService::replaceFor('SOCIAL_INSTITUTION_ADDR', $column, (string) $value);
        $this->lastVariantReplaced = CharVariantMapService::mergeReplaced($this->lastVariantReplaced, $result['replaced']);

        return $result['text'];
    }

    /**
     * 由業務輸入組出 SOCIAL_INSTITUTION_CODES 非鍵欄位（create／update 共用，確保語意一致）。
     * 必填核心：type_code、dynasty_code（→ c_inst_begin_dy；floruit 未給時同碼）、source_id。
     * 其餘（起訖年、年號、year range、末代朝、頁碼、備註）選填、空值折 null。
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function codeColumns(array $input): array {
        $int = fn ($v) => (isset($v) && $v !== '' && $v !== null) ? (int) $v : null;
        // 文本欄同樣過落地替換（lenient）。用 replaceFor() 而非 replaceRow()：$input 的鍵是
        // 輸入欄名（pages／notes），不是 SOCIAL_INSTITUTION_CODES 的欄位名。
        $str = function ($v, string $column) {
            if (!isset($v) || $v === '' || $v === null) {
                return null;
            }
            $result = CharVariantMapService::replaceFor('SOCIAL_INSTITUTION_CODES', $column, (string) $v);
            $this->lastVariantReplaced = CharVariantMapService::mergeReplaced($this->lastVariantReplaced, $result['replaced']);

            return $result['text'];
        };

        return [
            'c_inst_type_code' => (int) $input['type_code'],
            'c_inst_begin_year' => $int($input['begin_year'] ?? null),
            'c_by_nianhao_code' => $int($input['by_nianhao_code'] ?? null),
            'c_by_nianhao_year' => $int($input['by_nianhao_year'] ?? null),
            'c_by_year_range' => $int($input['by_year_range'] ?? null),
            'c_inst_begin_dy' => (int) $input['dynasty_code'],
            'c_inst_floruit_dy' => $int($input['floruit_dy'] ?? null) ?? (int) $input['dynasty_code'],
            'c_inst_first_known_year' => $int($input['first_known_year'] ?? null),
            'c_inst_end_year' => $int($input['end_year'] ?? null),
            'c_ey_nianhao_code' => $int($input['ey_nianhao_code'] ?? null),
            'c_ey_nianhao_year' => $int($input['ey_nianhao_year'] ?? null),
            'c_ey_year_range' => $int($input['ey_year_range'] ?? null),
            'c_inst_end_dy' => $int($input['end_dy'] ?? null),
            'c_inst_last_known_year' => $int($input['last_known_year'] ?? null),
            'c_source' => (int) $input['source_id'],
            'c_pages' => $str($input['pages'] ?? null, 'c_pages'),
            'c_notes' => $str($input['notes'] ?? null, 'c_notes'),
        ];
    }

    /**
     * 執行「新增一個社會機構」過程（於呼叫端交易內）。輸入須已驗證。
     *
     * @param array{name:string,type_code:int,dynasty_code:int,addr_id:int,source_id:int} $input
     * @return array{
     *   inst_code:int, name_code:int, name_created:bool, name_pinyin:string,
     *   operation_id_name:?int, operation_id_code:?int, operation_id_addr:?int
     * }
     */
    public function create(array $input, int $actorPersonId = 0): array {
        // 批次匯入是同一個 service 實例逐列呼叫；不重置會把上一列的替換紀錄
        // 帶到下一列的結果頁（本累積器由 codeColumns()／resolveNameCode() 以 merge 寫入）。
        $this->lastVariantReplaced = [];
        $this->lastAltNameNotices = [];
        $name = $input['name'];

        // 名稱去重：同名已存在則複用碼、不新增 NAME_CODES（見 resolveNameCode）。
        $resolved = $this->resolveNameCode($name, $actorPersonId);
        $nameCode = $resolved['name_code'];
        $nameCreated = $resolved['name_created'];
        $namePinyin = $resolved['name_pinyin'];

        $instCode = $this->allocateNextId('SOCIAL_INSTITUTION_CODES', 'c_inst_code');

        // 只插入非 null 欄：null 即欄位預設值，維持與原批量工具位元級一致的 insert 語義。
        $codePayload = array_merge(
            ['c_inst_name_code' => $nameCode, 'c_inst_code' => $instCode],
            array_filter($this->codeColumns($input), fn ($v) => $v !== null)
        );
        DB::table('SOCIAL_INSTITUTION_CODES')->insert($codePayload);
        $codeOp = $this->recordOp(
            'SOCIAL_INSTITUTION_CODES',
            ['c_inst_code' => $instCode, 'c_inst_name_code' => $nameCode],
            $codePayload,
            $actorPersonId
        );

        $addrPayload = [
            'c_inst_name_code' => $nameCode,
            'c_inst_code' => $instCode,
            'c_inst_addr_type_code' => 1,
            'c_inst_addr_id' => (int) $input['addr_id'],
            'inst_xcoord' => 0,
            'inst_ycoord' => 0,
            'c_source' => (int) $input['source_id'],
        ];
        DB::table('SOCIAL_INSTITUTION_ADDR')->insert($addrPayload);
        $addrPk = [
            'c_inst_addr_id' => (int) $input['addr_id'],
            'c_inst_addr_type_code' => 1,
            'c_inst_code' => $instCode,
            'c_inst_name_code' => $nameCode,
            'inst_xcoord' => 0,
            'inst_ycoord' => 0,
        ];
        $addrOp = $this->recordOp('SOCIAL_INSTITUTION_ADDR', $addrPk, $addrPayload, $actorPersonId);

        // 選填的別名（批量匯入不帶這個鍵，行為不變）。
        $altResult = ['added' => 0];
        if (($input['alt_names'] ?? null) !== null) {
            $altResult = $this->reconcileAltNames($instCode, $nameCode, $input['alt_names'], $actorPersonId);
        }

        return [
            'inst_code' => $instCode,
            'name_code' => $nameCode,
            'name_created' => $nameCreated,
            'name_pinyin' => $namePinyin,
            'operation_id_name' => $resolved['operation_id'],
            'operation_id_code' => $codeOp?->id,
            'operation_id_addr' => $addrOp?->id,
            'alt_names_added' => $altResult['added'],
            'alt_names' => ($input['alt_names'] ?? null) !== null ? $this->loadAltNames($instCode) : [],
            // 實際生效的機構名（複用既有碼時是既有列的字面——**刻意不歸一**，見 resolveNameCode()）
            // 與本次替換紀錄。
            'name' => $resolved['name_hz'],
            'variant_replaced' => $this->lastVariantReplaced,
            'alt_name_notices' => $this->lastAltNameNotices,
        ];
    }

    /**
     * 更新機構聚合（於呼叫端交易內）。輸入須已驗證，且機構須存在。
     *
     * - SOCIAL_INSTITUTION_CODES 非鍵欄位整體覆寫（codeColumns）。
     * - 名稱走去重解析；若 name_code 因此改變（＝底層 PK 變更），呼叫端須先以
     *   referenceCount()==0 擋下被引用者（見類註「實體識別」），此處會同步改寫
     *   ADDR 各行的 c_inst_name_code 維持聚合一致。孤兒化的舊名碼不回收（NAME_CODES
     *   被多機構共享、且被人物表 CASCADE 引用，誤刪代價不對稱）。
     * - SOCIAL_INSTITUTION_ADDR 做集合對賬：以 (addr_id, addr_type_code, xcoord, ycoord)
     *   為列鍵，同鍵改非鍵值、僅增刪差異，逐筆記 op。
     *
     * @param array<string, mixed> $input 含 name 與 codeColumns 所需鍵；addresses 為列陣列
     * @return array{inst_code:int,name_code:int,name_changed:bool,addr_added:int,addr_removed:int,operation_id_code:?int}
     */
    public function update(int $instCode, array $input, int $actorPersonId = 0): array {
        // 批次匯入是同一個 service 實例逐列呼叫；不重置會把上一列的替換紀錄
        // 帶到下一列的結果頁（本累積器由 codeColumns()／resolveNameCode() 以 merge 寫入）。
        $this->lastVariantReplaced = [];
        $this->lastAltNameNotices = [];
        $before = (array) DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', $instCode)->lockForUpdate()->first();
        $oldNameCode = (int) $before['c_inst_name_code'];

        $resolved = $this->resolveNameCode((string) $input['name'], $actorPersonId);
        $nameCode = $resolved['name_code'];
        $nameChanged = $nameCode !== $oldNameCode;

        $after = array_merge(['c_inst_name_code' => $nameCode], $this->codeColumns($input));
        DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', $instCode)->update($after);
        $codeOp = $this->recordUpdate(
            'SOCIAL_INSTITUTION_CODES',
            ['c_inst_code' => $instCode, 'c_inst_name_code' => $nameCode],
            $before,
            array_merge(['c_inst_code' => $instCode], $after),
            $actorPersonId
        );

        // 改名時 ADDR 各行的 name_code 一併改寫（維持聚合內部一致；對賬在其後、以新碼進行）。
        if ($nameChanged) {
            DB::table('SOCIAL_INSTITUTION_ADDR')
                ->where('c_inst_code', $instCode)
                ->update(['c_inst_name_code' => $nameCode]);
            // 別名列同樣冗餘存名碼。與 ADDR 不同，這裡**逐列記**：這張表沒有主鍵、也不開放泛用
            // 還原，operations／audit_log 是改名前狀態唯一的紀錄。
            $this->renameAltNames($instCode, $oldNameCode, $nameCode, $actorPersonId);
        }

        // ADDR 集合對賬。
        $rowKey = fn (array $r) => implode('|', [
            (int) $r['addr_id'], (int) $r['addr_type_code'],
            (string) (float) $r['xcoord'], (string) (float) $r['ycoord'],
        ]);
        $desired = [];
        foreach (($input['addresses'] ?? []) as $r) {
            $r = [
                'addr_id' => (int) $r['addr_id'],
                'addr_type_code' => (int) ($r['addr_type_code'] ?? 1),
                'begin_year' => (isset($r['begin_year']) && $r['begin_year'] !== '') ? (int) $r['begin_year'] : null,
                'end_year' => (isset($r['end_year']) && $r['end_year'] !== '') ? (int) $r['end_year'] : null,
                'xcoord' => (float) ($r['xcoord'] ?? 0),
                'ycoord' => (float) ($r['ycoord'] ?? 0),
                'source_id' => (isset($r['source_id']) && $r['source_id'] !== '') ? (int) $r['source_id'] : null,
                'pages' => (isset($r['pages']) && $r['pages'] !== '') ? (string) $r['pages'] : null,
                'notes' => (isset($r['notes']) && $r['notes'] !== '') ? (string) $r['notes'] : null,
            ];
            $desired[$rowKey($r)] = $r;
        }

        $currentRows = DB::table('SOCIAL_INSTITUTION_ADDR')->where('c_inst_code', $instCode)->get();
        $current = [];
        foreach ($currentRows as $a) {
            $r = [
                'addr_id' => (int) $a->c_inst_addr_id,
                'addr_type_code' => (int) $a->c_inst_addr_type_code,
                'begin_year' => $a->c_inst_addr_begin_year !== null ? (int) $a->c_inst_addr_begin_year : null,
                'end_year' => $a->c_inst_addr_end_year !== null ? (int) $a->c_inst_addr_end_year : null,
                'xcoord' => (float) $a->inst_xcoord,
                'ycoord' => (float) $a->inst_ycoord,
                'source_id' => $a->c_source !== null ? (int) $a->c_source : null,
                'pages' => $a->c_pages !== null ? (string) $a->c_pages : null,
                'notes' => $a->c_notes !== null ? (string) $a->c_notes : null,
            ];
            $current[$rowKey($r)] = $r;
        }

        $addrPk = fn (array $r) => [
            'c_inst_addr_id' => $r['addr_id'],
            'c_inst_addr_type_code' => $r['addr_type_code'],
            'c_inst_code' => $instCode,
            'c_inst_name_code' => $nameCode,
            'inst_xcoord' => $r['xcoord'],
            'inst_ycoord' => $r['ycoord'],
        ];
        $addrPayload = fn (array $r) => [
            'c_inst_name_code' => $nameCode,
            'c_inst_code' => $instCode,
            'c_inst_addr_type_code' => $r['addr_type_code'],
            'c_inst_addr_begin_year' => $r['begin_year'],
            'c_inst_addr_end_year' => $r['end_year'],
            'c_inst_addr_id' => $r['addr_id'],
            'inst_xcoord' => $r['xcoord'],
            'inst_ycoord' => $r['ycoord'],
            'c_source' => $r['source_id'],
            // 這兩欄同樣在落地替換範圍內（SOCIAL_INSTITUTION_ADDR 是已知表、兩欄都是文本型）。
            // 漏掉會讓同一次 update 裡機構層的 c_notes 被歸一、地址列的 c_notes 原樣入庫。
            'c_pages' => $this->replaceAddrText($r['pages'], 'c_pages'),
            'c_notes' => $this->replaceAddrText($r['notes'], 'c_notes'),
        ];
        // reconcileRowSet：同鍵改非鍵值（起訖年、來源、頁碼、備註）、僅增刪差異。
        $result = $this->reconcileRowSet(
            'SOCIAL_INSTITUTION_ADDR',
            $current,
            $desired,
            $addrPk,
            $addrPayload,
            ['c_inst_addr_begin_year', 'c_inst_addr_end_year', 'c_source', 'c_pages', 'c_notes'],
            $actorPersonId
        );

        // 別名：請求帶了 alt_names 才對賬；沒帶＝不動（見 parseSocialInstituteAltNames()）。
        $altResult = ['added' => 0, 'removed' => 0, 'updated' => 0];
        if (($input['alt_names'] ?? null) !== null) {
            $altResult = $this->reconcileAltNames($instCode, $nameCode, $input['alt_names'], $actorPersonId);
        }

        return [
            'inst_code' => $instCode,
            'name_code' => $nameCode,
            'name_changed' => $nameChanged,
            // 與 create() 對稱：實際生效的名稱字面（複用既有碼時是既有列的原字面）
            // 與本次替換紀錄（供呼叫端組 notices／結果頁）。
            'name' => $resolved['name_hz'],
            'variant_replaced' => $this->lastVariantReplaced,
            'alt_name_notices' => $this->lastAltNameNotices,
            'addr_added' => $result['added'],
            'addr_removed' => $result['removed'],
            'alt_names_added' => $altResult['added'],
            'alt_names_removed' => $altResult['removed'],
            'alt_names_updated' => $altResult['updated'],
            'alt_names' => $this->loadAltNames($instCode),
            'operation_id_code' => $codeOp?->id,
        ];
    }

    /**
     * 刪除機構聚合（於呼叫端交易內）：先刪 SOCIAL_INSTITUTION_ADDR 各行、再刪
     * SOCIAL_INSTITUTION_CODES，逐筆記 op。名稱碼不回收（見 update 註）。
     * 呼叫端須先以 referenceCount() 確認未被人物資料引用。
     *
     * @return array{inst_code:int,addr_deleted:int,operation_id_code:?int}
     */
    public function delete(int $instCode, int $actorPersonId = 0): array {
        // 先鎖機構列、再碰下層列：與 update() 的上鎖順序一致（機構列 → 別名列），
        // 並發的 update 與 delete 才不會互等成死結。
        $before = (array) DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', $instCode)->lockForUpdate()->first();
        $nameCode = (int) ($before['c_inst_name_code'] ?? 0);

        $addrRows = DB::table('SOCIAL_INSTITUTION_ADDR')->where('c_inst_code', $instCode)->get();
        foreach ($addrRows as $a) {
            DB::table('SOCIAL_INSTITUTION_ADDR')
                ->where('c_inst_code', $instCode)
                ->where('c_inst_name_code', $a->c_inst_name_code)
                ->where('c_inst_addr_id', $a->c_inst_addr_id)
                ->where('c_inst_addr_type_code', $a->c_inst_addr_type_code)
                ->where('inst_xcoord', $a->inst_xcoord)
                ->where('inst_ycoord', $a->inst_ycoord)
                ->delete();
            $this->recordDelete('SOCIAL_INSTITUTION_ADDR', [
                'c_inst_addr_id' => (int) $a->c_inst_addr_id,
                'c_inst_addr_type_code' => (int) $a->c_inst_addr_type_code,
                'c_inst_code' => $instCode,
                'c_inst_name_code' => (int) $a->c_inst_name_code,
                'inst_xcoord' => (float) $a->inst_xcoord,
                'inst_ycoord' => (float) $a->inst_ycoord,
            ], (array) $a, $actorPersonId);
        }

        // 別名列（先子後父，逐列記；泛用還原不支援這張表，操作記錄是唯一紀錄）。
        $altDeleted = $this->deleteAllAltNames($instCode, $actorPersonId);

        DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', $instCode)->delete();
        $codeOp = $this->recordDelete(
            'SOCIAL_INSTITUTION_CODES',
            ['c_inst_code' => $instCode, 'c_inst_name_code' => $nameCode],
            $before,
            $actorPersonId
        );

        return [
            'inst_code' => $instCode,
            'addr_deleted' => count($addrRows),
            'alt_names_deleted' => $altDeleted,
            'operation_id_code' => $codeOp?->id,
        ];
    }
}
