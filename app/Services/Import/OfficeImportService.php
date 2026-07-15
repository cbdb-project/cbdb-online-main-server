<?php

namespace App\Services\Import;

use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Services\AuditLogService;
use App\Services\Import\Concerns\SharesImportHelpers;
use Illuminate\Support\Facades\DB;

/**
 * 「新增一個官職（OFFICE_CODES + OFFICE_CODE_TYPE_REL）」的存儲過程之單一真源。
 *
 * 由 admin 批量表單（AdminBatchLoadOfficesController）與 mutation API（OfficeImportHandler）共用，
 * 確保派生（拼音/朝代碼）、自動 id、配套關聯行與審計（operations + AuditLog）只有一份實作、不漂移。
 * 共用的派生／校驗／審計基元見 SharesImportHelpers（與 SocialInstituteImportService 共用）。
 *
 * 一次「加官職」= 原子寫入兩表：
 *  - OFFICE_CODES：c_office_id(max+1)、c_office_pinyin(派生)、c_dy、c_office_chn/trans、c_source
 *  - OFFICE_CODE_TYPE_REL：(c_office_id, c_office_tree_id=type_id)
 *
 * create() 不自開交易，於呼叫端交易內執行（批量：controller 一個交易包全部；單筆 API：handler 包一筆），
 * 以保留「全有或全無」語意。呼叫端須先以 validate*() 過濾非法輸入。
 */
class OfficeImportService {
    use SharesImportHelpers;

    public function __construct(
        protected OperationRepository $operationRepository,
        protected AuditLogService $auditLogService,
        protected ToolsRepository $toolsRepository
    ) {
    }

    /** 校驗官職類型節點存在於 OFFICE_TYPE_TREE；回傳缺失的 id。 */
    public function missingOfficeTypes(array $typeIds): array {
        $unique = array_values(array_unique(array_filter($typeIds, fn ($v) => $v !== '' && $v !== null)));
        if (empty($unique)) {
            return [];
        }
        $found = DB::table('OFFICE_TYPE_TREE')->whereIn('c_office_type_node_id', $unique)->pluck('c_office_type_node_id')->all();

        return array_values(array_diff($unique, $found));
    }

    /**
     * 執行「新增一個官職」過程（於呼叫端交易內）。輸入須已驗證。
     *
     * @param array{name:string,translation:?string,dynasty_code:int,type_id:string|int,source_id:int} $input
     * @return array{office_id:int,pinyin:string,operation_id_office:?int,operation_id_rel:?int}
     */
    public function create(array $input, int $actorPersonId = 0): array {
        // lockForUpdate 序列化並發的 max()+1 配號：兩個同時到達的請求若讀到同一 max，
        // 後者 insert 會撞主鍵而 500。MariaDB 生效；SQLite（測試）grammar 編譯為 no-op。
        $officeId = max(0, (int) DB::table('OFFICE_CODES')->lockForUpdate()->max('c_office_id')) + 1;
        $pinyin = $this->buildPinyin($input['name']);

        $officePayload = $this->toolsRepository->timestamp([
            'c_office_id' => $officeId,
            'c_dy' => (int) $input['dynasty_code'],
            'c_office_pinyin' => $pinyin,
            'c_office_trans' => $input['translation'] ?? null,
            'c_office_chn' => $input['name'],
            'c_source' => (int) $input['source_id'],
        ], true);

        DB::table('OFFICE_CODES')->insert($officePayload);
        $officeOp = $this->recordOp('OFFICE_CODES', ['c_office_id' => $officeId], $officePayload, $actorPersonId);

        $relPayload = [
            'c_office_id' => $officeId,
            'c_office_tree_id' => $input['type_id'],
        ];
        DB::table('OFFICE_CODE_TYPE_REL')->insert($relPayload);
        $relPk = ['c_office_id' => $officeId, 'c_office_tree_id' => $input['type_id']];
        $relOp = $this->recordOp('OFFICE_CODE_TYPE_REL', $relPk, $relPayload, $actorPersonId);

        return [
            'office_id' => $officeId,
            'pinyin' => $pinyin,
            'operation_id_office' => $officeOp?->id,
            'operation_id_rel' => $relOp?->id,
        ];
    }
}
