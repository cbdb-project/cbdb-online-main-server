<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\Mutations\Concerns\ResolvesKinshipReversePair;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KinshipCreateHandler extends AbstractPersonSubresourceCreateHandler {
    use ResolvesKinshipReversePair;

    /** 暫存本次互逆配對碼：未送覆寫＝權威 c_kin_pair1；送合法覆寫＝使用者選的反向碼（已驗證）。 */
    private $pendingKinPair = null;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    /**
     * 覆寫：先讀 client 可選的 c_kinship_pair（反向碼覆寫），再剝除（非 KIN_DATA 欄，否則父類白名單 422）。
     * 反向配對碼解析（resolveReversePair）：未送覆寫→以 KINSHIP_CODES.c_kin_pair1 權威推導；送了覆寫→
     * 須 ∈ 該正向碼的合法配對候選（對齊 searchKinPair）才接受，否則 422 回滾（fail-closed）——既恢復
     * 使用者對歧義反向關係（父→子/女、第幾子…）的選擇權，又杜絕對鏡像列強塞任意反向碼的污染。
     * direct 主列寫入成功後於同交易內由 afterDirectInsert 寫互逆鏡像列。
     */
    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        // 先讀 client 可選的反向碼覆寫，再剝除（c_kinship_pair 非 KIN_DATA 欄）。
        $clientReverse = $changes['c_kinship_pair'] ?? null;
        unset($changes['c_kinship_pair']);
        $kinCode = $changes['c_kin_code'] ?? ($targetPk['c_kin_code'] ?? null);
        try {
            // 未送覆寫→權威 c_kin_pair1；送覆寫→驗證為合法配對否則 422（fail-closed、回滾）。
            $this->pendingKinPair = $this->resolveReversePair($kinCode, $clientReverse);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

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
