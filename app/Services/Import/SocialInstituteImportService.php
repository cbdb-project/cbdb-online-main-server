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
 * （SocialInstituteImportHandler）共用，確保名稱去重、派生（拼音）、自動 id、
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
 */
class SocialInstituteImportService {
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

        // 名稱去重：同名已存在則複用碼、不新增 NAME_CODES。
        // lockForUpdate 防兩個同新名的並發請求同時判定「不存在」而各建一列同碼 NAME_CODES；
        // MariaDB 生效，SQLite（測試）grammar 編譯為 no-op。
        $existing = DB::table('SOCIAL_INSTITUTION_NAME_CODES')
            ->where('c_inst_name_hz', $name)
            ->orderBy('c_inst_name_code')
            ->lockForUpdate()
            ->first();

        $nameCreated = false;
        $namePinyin = '';
        $nameOp = null;

        if ($existing) {
            $nameCode = (int) $existing->c_inst_name_code;
            $namePinyin = (string) ($existing->c_inst_name_py ?? '');
        } else {
            // lockForUpdate 序列化並發的 max()+1 配號，避免撞主鍵（同上，SQLite no-op）。
            $nameCode = max(0, (int) DB::table('SOCIAL_INSTITUTION_NAME_CODES')->lockForUpdate()->max('c_inst_name_code')) + 1;
            $namePinyin = $this->buildPinyin($name);
            $namePayload = [
                'c_inst_name_code' => $nameCode,
                'c_inst_name_hz' => $name,
                'c_inst_name_py' => $namePinyin,
            ];
            DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert($namePayload);
            $nameOp = $this->recordOp('SOCIAL_INSTITUTION_NAME_CODES', ['c_inst_name_code' => $nameCode], $namePayload, $actorPersonId);
            $nameCreated = true;
        }

        $instCode = max(0, (int) DB::table('SOCIAL_INSTITUTION_CODES')->lockForUpdate()->max('c_inst_code')) + 1;

        $codePayload = [
            'c_inst_name_code' => $nameCode,
            'c_inst_code' => $instCode,
            'c_inst_type_code' => (int) $input['type_code'],
            'c_inst_begin_dy' => (int) $input['dynasty_code'],
            'c_inst_floruit_dy' => (int) $input['dynasty_code'],
            'c_source' => (int) $input['source_id'],
        ];
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
            'operation_id_name' => $nameOp?->id,
            'operation_id_code' => $codeOp?->id,
            'operation_id_addr' => $addrOp?->id,
        ];
    }
}
