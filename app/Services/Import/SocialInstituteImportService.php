<?php

namespace App\Services\Import;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\Import\Concerns\SharesImportHelpers;
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
 */
class SocialInstituteImportService implements EntityAggregateService {
    use SharesImportHelpers;

    public function __construct(
        protected OperationRepository $operationRepository,
        protected AuditLogService $auditLogService
    ) {
    }

    /** 機構類型（中文名或拼音）→代碼對照。 */
    public function typeMap(): array {
        $records = DB::table('SOCIAL_INSTITUTION_TYPES')
            ->select('c_inst_type_code', 'c_inst_type_hz', 'c_inst_type_py')
            ->orderBy('c_inst_type_code')
            ->get();

        $map = [];
        foreach ($records as $record) {
            $code = (int) $record->c_inst_type_code;
            $hz = trim((string) ($record->c_inst_type_hz ?? ''));
            $py = trim((string) ($record->c_inst_type_py ?? ''));
            if ($hz !== '') {
                $map[$hz] = $code;
            }
            if ($py !== '') {
                $map[$py] = $code;
            }
        }

        return $map;
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
        ];
    }

    /**
     * 名稱→名碼解析（create／update 共用的去重語義）：同名已存在則複用其 c_inst_name_code，
     * 否則配號（max+1）、派生拼音並新增 NAME_CODES 一列（記 op）。
     *
     * @return array{name_code:int,name_created:bool,name_pinyin:string,operation_id:?int}
     */
    protected function resolveNameCode(string $name, int $actorPersonId): array {
        // lockForUpdate 防兩個同新名的並發請求同時判定「不存在」而各建一列同碼 NAME_CODES；
        // MariaDB 生效，SQLite（測試）grammar 編譯為 no-op。
        $existing = DB::table('SOCIAL_INSTITUTION_NAME_CODES')
            ->where('c_inst_name_hz', $name)
            ->orderBy('c_inst_name_code')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return [
                'name_code' => (int) $existing->c_inst_name_code,
                'name_created' => false,
                'name_pinyin' => (string) ($existing->c_inst_name_py ?? ''),
                'operation_id' => null,
            ];
        }

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
        ];
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
        $str = fn ($v) => (isset($v) && $v !== '' && $v !== null) ? (string) $v : null;

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
            'c_pages' => $str($input['pages'] ?? null),
            'c_notes' => $str($input['notes'] ?? null),
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

        return [
            'inst_code' => $instCode,
            'name_code' => $nameCode,
            'name_created' => $nameCreated,
            'name_pinyin' => $namePinyin,
            'operation_id_name' => $resolved['operation_id'],
            'operation_id_code' => $codeOp?->id,
            'operation_id_addr' => $addrOp?->id,
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
            'c_pages' => $r['pages'],
            'c_notes' => $r['notes'],
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

        return [
            'inst_code' => $instCode,
            'name_code' => $nameCode,
            'name_changed' => $nameChanged,
            'addr_added' => $result['added'],
            'addr_removed' => $result['removed'],
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
        $before = (array) DB::table('SOCIAL_INSTITUTION_CODES')->where('c_inst_code', $instCode)->first();
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
            'operation_id_code' => $codeOp?->id,
        ];
    }
}
