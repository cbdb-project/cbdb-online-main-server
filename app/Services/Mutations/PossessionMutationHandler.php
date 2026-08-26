<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PossessionMutationHandler extends AbstractPersonSubresourceMutationHandler {
    /** 本次 update 的地址意圖（c_addr_id 多值 / c_addr_cleared）；null=未改、[]=清空、[ids]=列表。 */
    private ?array $pendingIncomingAddr = null;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    /**
     * 覆寫：抽出 c_addr_id（POSSESSION_ADDR 副表，非 POSSESSION_DATA 純量白名單），委派父類處理財產欄位；
     * direct 更新成功後於同交易由 afterDirectUpdate 同步 POSSESSION_ADDR（record_id 為固定 surrogate，無遷移）。
     * 僅改地址時走獨立路徑（父類會因 changes 空而 422）。
     */
    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $hasAddrKey = array_key_exists('c_addr_id', $changes);
        $rawAddr = $hasAddrKey ? $changes['c_addr_id'] : null;
        $addrCleared = (string) ($changes['c_addr_cleared'] ?? '');
        unset($changes['c_addr_id'], $changes['c_addr_cleared']);
        $this->pendingIncomingAddr = $hasAddrKey ? array_values((array) $rawAddr) : ($addrCleared === '1' ? [] : null);

        try {
            if ($this->pendingIncomingAddr !== null && $changes === []) {
                return $mode === 'proposal'
                    ? $this->handleAddressOnlyProposal($personId, $targetPk, $meta)
                    : $this->handleAddressOnlyDirect($personId, $targetPk);
            }

            return parent::handle($resource, $mode, $operation, $personId, $targetPk, $changes, $meta);
        } finally {
            $this->pendingIncomingAddr = null;
        }
    }

    /** direct 財產更新成功後同交易同步 POSSESSION_ADDR（record_id 固定，刪重插整組）。 */
    protected function afterDirectUpdate(int $personId, array $targetPk, array $updateData, array $newArray, ?Operation $operation): void {
        if ($this->pendingIncomingAddr === null) {
            return;
        }
        $recordId = (int) ($targetPk['c_possession_record_id'] ?? $newArray['c_possession_record_id'] ?? 0);
        app(BiogMainRepository::class)->syncPossessionAddresses($this->pendingIncomingAddr, $recordId, $personId);
    }

    /** proposal 更新把地址寫入 __proposal_aux（核准時由 applyPossessionUpdateProposal 套用）。 */
    protected function proposalAuxiliaryPayload(): array {
        if ($this->pendingIncomingAddr === null) {
            return [];
        }

        return ['c_addr_id' => $this->pendingIncomingAddr];
    }

    /** 僅改地址（無財產欄）的 direct 路徑。 */
    private function handleAddressOnlyDirect(int $personId, array $targetPk): JsonResponse {
        if ($authError = $this->authorizeDirect()) {
            return $authError;
        }
        $original = $this->findPossessionRow($targetPk);
        if (!$original) {
            return $this->errorResponse('POSSESSION_DATA 記錄不存在', 404);
        }
        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        $recordId = (int) $original->c_possession_record_id;
        $existing = $this->existingAddrIds($recordId);
        if (!$this->addressesChanged($this->pendingIncomingAddr ?? [], $existing)) {
            return $this->errorResponse('未偵測到任何修改內容', 422, ['changes' => ['no_effective_changes']]);
        }

        DB::transaction(function () use ($personId, $recordId) {
            app(BiogMainRepository::class)->syncPossessionAddresses($this->pendingIncomingAddr ?? [], $recordId, $personId);
        });

        return response()->json([
            'ok' => true,
            'resource' => 'possessions',
            'mode' => 'direct',
            'operation' => 'update',
            'result' => ['pk' => $targetPk, 'updated_fields' => ['c_addr_id']],
        ]);
    }

    /** 僅改地址的 proposal 路徑：寫 TYPE_PROPOSAL_UPDATE，地址存 __proposal_aux。 */
    private function handleAddressOnlyProposal(int $personId, array $targetPk, array $meta): JsonResponse {
        if ($authError = $this->authorizeProposal()) {
            return $authError;
        }
        $original = $this->findPossessionRow($targetPk);
        if (!$original) {
            return $this->errorResponse('POSSESSION_DATA 記錄不存在', 404);
        }
        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        $recordId = (int) $original->c_possession_record_id;
        $resourceId = CompositePrimaryKey::buildStoredResourceId(['c_possession_record_id' => $recordId]);
        if ($this->operationRepository->hasPendingUpdateProposal($this->tableName(), $resourceId)) {
            return $this->errorResponse('相同主鍵已有待審核提案', 409, ['target.pk' => ['pending_proposal_exists']]);
        }

        $originalArray = $this->auditLogService->normalizeRow($original);
        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';
        $proposalData = array_merge($originalArray, [
            '__proposal_aux' => $this->proposalAuxiliaryPayload(),
            '__proposal_meta' => [
                'action' => 'update',
                'resource_type' => $this->resourceName(),
                'table' => $this->tableName(),
                'display_name' => $this->displayName(),
                'submitted_by' => Auth::user()->name ?? Auth::id(),
                'submitted_by_id' => Auth::id(),
                'submitted_at' => now()->format('Y-m-d H:i:s'),
                'comment' => $comment,
            ],
            '__review_status' => 'pending',
            '__key_columns' => $this->keyColumns(),
        ]);

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_PROPOSAL_UPDATE,
            $this->tableName(),
            $resourceId,
            $proposalData,
            $originalArray
        );

        return response()->json([
            'ok' => true,
            'resource' => 'possessions',
            'mode' => 'proposal',
            'operation' => 'update',
            'result' => ['pk' => $targetPk, 'updated_fields' => ['c_addr_id'], 'status' => 'proposal_updated', 'operation_id' => $operation?->id],
        ]);
    }

    private function findPossessionRow(array $targetPk): ?object {
        return DB::table('POSSESSION_DATA')->where('c_possession_record_id', $targetPk['c_possession_record_id'] ?? null)->first();
    }

    /** @return list<int> */
    private function existingAddrIds(int $recordId): array {
        return DB::table('POSSESSION_ADDR')->where('c_possession_record_id', $recordId)->pluck('c_addr_id')->map(fn ($v) => (int) $v)->all();
    }

    private function addressesChanged(array $incoming, array $existing): bool {
        $norm = function (array $a): array {
            $a = array_map(fn ($v) => ((int) $v === -999 ? 0 : (int) $v), $a);
            sort($a);

            return array_values(array_unique($a));
        };

        return $norm($incoming) !== $norm($existing);
    }

    protected function resourceName(): string {
        return 'possessions';
    }

    protected function tableName(): string {
        return 'POSSESSION_DATA';
    }

    protected function displayName(): string {
        return '財產';
    }

    protected function resourceAliases(): array {
        return ['possessions', 'possession', 'possession_data'];
    }

    protected function keyColumns(): array {
        return ['c_possession_record_id'];
    }

    /** PK 不含 c_personid，跳過 PK 中的 person_id 檢查 */
    protected function validatePersonIdInPk(int $personId, array $targetPk): ?JsonResponse {
        return null;
    }

    /** 透過 row 的 c_personid 欄位驗證 person_id 一致性 */
    protected function validatePersonIdInRow(int $personId, object $original): ?JsonResponse {
        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        return null;
    }

    protected function allowedFields(): array {
        return [
            'c_sequence',
            'c_possession_act_code',
            'c_possession_desc',
            'c_possession_desc_chn',
            'c_quantity',
            'c_measure_code',
            'c_possession_yr',
            'c_possession_nh_code',
            'c_possession_nh_yr',
            'c_possession_yr_range',
            'c_source',
            'c_pages',
            'c_notes',
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, [
            'c_source', 'c_measure_code', 'c_possession_act_code',
        ]);
        // sentinel 完全幂等：三個碼欄（legacy 哨兵 0=Unknown）的 null/'' 也→0（normalizeSentinelValues 只做 -999）。
        $data = $this->normalizeEmptyCodeFields($data, ['c_source', 'c_measure_code', 'c_possession_act_code']);

        return $data;
    }
}
