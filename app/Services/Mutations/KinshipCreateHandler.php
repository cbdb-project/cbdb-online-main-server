<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KinshipCreateHandler extends AbstractPersonSubresourceCreateHandler {
    /** 暫存本次互逆配對碼，一律以 KINSHIP_CODES.c_kin_pair1 權威推導（不採信前端 c_kinship_pair）。 */
    private $pendingKinPair = null;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    /**
     * 覆寫：剝除 c_kinship_pair（非 KIN_DATA 欄，否則父類白名單 422）。
     * 互逆配對碼一律以 KINSHIP_CODES.c_kin_pair1 權威推導，「不信任」前端送來的值——否則
     * 呼叫端可對 (A,B,code=X) 強塞任意反向碼污染鏡像列；proposal 核准會重播同一污染路徑。
     * direct 主列寫入成功後於同交易內由 afterDirectInsert 寫互逆鏡像列。
     */
    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        unset($changes['c_kinship_pair']);
        $kinCode = $changes['c_kin_code'] ?? ($targetPk['c_kin_code'] ?? null);
        $this->pendingKinPair = $this->lookupKinPair($kinCode);

        try {
            return parent::handle($resource, $mode, $operation, $personId, $targetPk, $changes, $meta);
        } finally {
            $this->pendingKinPair = null;
        }
    }

    /**
     * 寫互逆鏡像列（對齊 legacy kinshipStoreById）：反向親屬碼、對方為主體、原人為客體。
     * 無條件寫入＝永遠雙向同步（create 無 legacy 選擇性跳過問題）。
     */
    protected function afterDirectInsert(int $personId, array $actualPk, array $rowData, array $insertedArray, ?Operation $operation): void {
        $mirror = $insertedArray;
        unset($mirror['__operation_id'], $mirror['__note']);
        $mirror['c_kin_code'] = CompositePrimaryKey::emptyToSentinel($this->pendingKinPair);
        $mirror['c_personid'] = $insertedArray['c_kin_id'];
        $mirror['c_kin_id'] = $personId;

        DB::table('KIN_DATA')->insert($mirror);
        $this->auditLogService->write(
            'KIN_DATA',
            'INSERT',
            ['c_personid' => $mirror['c_personid'], 'c_kin_id' => $mirror['c_kin_id'], 'c_kin_code' => $mirror['c_kin_code']],
            null,
            $mirror,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );
    }

    /** proposal 模式把配對碼（送的或權威值）存入 __proposal_aux（核准時 applyKinshipProposal 套用）。 */
    protected function proposalAuxiliaryPayload(): array {
        return ['c_kinship_pair' => CompositePrimaryKey::emptyToSentinel($this->pendingKinPair)];
    }

    /** 親屬碼的權威反向碼（KINSHIP_CODES.c_kin_pair1）；0／查無 → null。 */
    private function lookupKinPair($code) {
        if ($code === null || $code === '' || (int) $code === 0) {
            return null;
        }
        $v = DB::table('KINSHIP_CODES')->where('c_kincode', $code)->value('c_kin_pair1');

        return $v !== null ? (int) $v : null;
    }

    protected function resourceName(): string {
        return 'kinship';
    }

    protected function tableName(): string {
        return 'KIN_DATA';
    }

    protected function displayName(): string {
        return '親屬關係';
    }

    protected function resourceAliases(): array {
        return ['kinship', 'kin', 'kin_data'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_kin_id', 'c_kin_code'];
    }

    protected function allowedFields(): array {
        return [
            'c_personid',
            'c_kin_id',
            'c_kin_code',
            'c_source',
            'c_pages',
            'c_notes',
            // Task 27 補回：c_autogen_notes 為 KIN_DATA 真實欄（舊表單以 textarea 暴露為可錄入）；
            // 移除幻影 c_supplement（KIN_DATA 無此欄，SHOW COLUMNS 確認）。
            'c_autogen_notes',
        ];
    }

    protected function preprocessCreateData(array $data): array {
        return $this->normalizeSentinelValues($data, ['c_kin_code', 'c_kin_id', 'c_source']);
    }
}
