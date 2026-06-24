<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\EventStatusRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EventMutationHandler extends AbstractPersonSubresourceMutationHandler {
    /**
     * 本次 update 的地址意圖（從 changes 抽出的 c_addr_id 多值 / c_addr_cleared 清空旗標）。
     * null = 未修改地址（保留現有，但 PK 變更時仍須遷移）；[] = 清空；[ids] = 明確列表。
     */
    private ?array $pendingIncomingAddr = null;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    /**
     * 覆寫：先把地址欄位（c_addr_id 多值、c_addr_cleared 清空旗標）從 changes 抽出
     * （非 EVENTS_DATA 純量欄白名單，否則父類 422），再委派父類處理事件欄位；
     * direct 更新成功後於同交易內由 afterDirectUpdate 同步 EVENTS_ADDR 副表（含改 PK 時的地址遷移）。
     * 僅改地址（無事件欄變更）時走獨立地址路徑（父類會因 changes 空而 422）。
     */
    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $hasAddrKey = array_key_exists('c_addr_id', $changes);
        $rawAddr = $hasAddrKey ? $changes['c_addr_id'] : null;
        $addrCleared = (string) ($changes['c_addr_cleared'] ?? '');
        unset($changes['c_addr_id'], $changes['c_addr_cleared']);

        $this->pendingIncomingAddr = $hasAddrKey
            ? array_values((array) $rawAddr)
            : ($addrCleared === '1' ? [] : null);

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

    /**
     * direct 事件更新成功後，於同交易內同步 EVENTS_ADDR。地址以「舊」(sequence,event_code) 刪除、
     * 「新」重插（對齊 legacy updateAddrEvent）；未送地址（null）時沿用既有列，確保 PK 變更時仍遷移、不留孤兒。
     */
    protected function afterDirectUpdate(int $personId, array $targetPk, array $updateData, array $newArray, ?Operation $operation): void {
        $oldSeq = $targetPk['c_sequence'] ?? null;
        $oldCode = $targetPk['c_event_code'] ?? null;
        $newSeq = $updateData['c_sequence'] ?? $oldSeq;
        $newCode = $updateData['c_event_code'] ?? $oldCode;

        $existing = $this->existingAddrIds($personId, $oldSeq, $oldCode);
        // 未送地址且 PK 未變 → 無需動作（避免無謂刪重插）。
        if ($this->pendingIncomingAddr === null && (string) $oldSeq === (string) $newSeq && (string) $oldCode === (string) $newCode) {
            return;
        }
        $addrToWrite = $this->pendingIncomingAddr ?? $existing;

        app(EventStatusRepository::class)->syncEventAddresses($addrToWrite, $personId, $oldSeq, $oldCode, $newSeq, $newCode);
    }

    /** proposal 更新把地址寫入 __proposal_aux（核准時 applyEventProposal 合併入請求、由 legacy eventUpdateById 同步）。 */
    protected function proposalAuxiliaryPayload(): array {
        if ($this->pendingIncomingAddr === null) {
            return [];
        }

        return ['c_addr_id' => $this->pendingIncomingAddr];
    }

    /** 僅改地址（無事件欄）的 direct 路徑。 */
    private function handleAddressOnlyDirect(int $personId, array $targetPk): JsonResponse {
        if ($authError = $this->authorizeDirect()) {
            return $authError;
        }
        $original = $this->findEventRow($targetPk);
        if (!$original) {
            return $this->errorResponse('EVENTS_DATA 記錄不存在', 404);
        }
        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        $seq = $original->c_sequence;
        $code = $original->c_event_code;
        $existing = $this->existingAddrIds($personId, $seq, $code);

        if (!$this->addressesChanged($this->pendingIncomingAddr ?? [], $existing)) {
            return $this->errorResponse('未偵測到任何修改內容', 422, ['changes' => ['no_effective_changes']]);
        }

        DB::transaction(function () use ($personId, $seq, $code) {
            app(EventStatusRepository::class)->syncEventAddresses($this->pendingIncomingAddr ?? [], $personId, $seq, $code, $seq, $code);
        });

        return response()->json([
            'ok' => true,
            'resource' => 'events',
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
        $original = $this->findEventRow($targetPk);
        if (!$original) {
            return $this->errorResponse('EVENTS_DATA 記錄不存在', 404);
        }
        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => (int) $original->c_personid,
            'c_sequence' => (int) $original->c_sequence,
            'c_event_code' => (int) $original->c_event_code,
        ]);
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
            'resource' => 'events',
            'mode' => 'proposal',
            'operation' => 'update',
            'result' => ['pk' => $targetPk, 'updated_fields' => ['c_addr_id'], 'status' => 'proposal_updated', 'operation_id' => $operation?->id],
        ]);
    }

    private function findEventRow(array $targetPk): ?object {
        $query = DB::table('EVENTS_DATA');
        foreach ($this->keyColumns() as $col) {
            $query->where($col, $targetPk[$col] ?? null);
        }

        return $query->first();
    }

    /** @return list<int> */
    private function existingAddrIds(int $personId, $seq, $code): array {
        return DB::table('EVENTS_ADDR')
            ->where('c_personid', $personId)
            ->where('c_sequence', $seq)
            ->where('c_event_code', $code)
            ->pluck('c_addr_id')
            ->map(fn ($v) => (int) $v)
            ->all();
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
        return 'events';
    }

    protected function tableName(): string {
        return 'EVENTS_DATA';
    }

    protected function displayName(): string {
        return '事件';
    }

    protected function resourceAliases(): array {
        return ['events', 'event', 'events_data'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_sequence', 'c_event_code'];
    }

    protected function allowedFields(): array {
        return [
            'c_event_code',
            'c_sequence',
            'c_source',
            'c_pages',
            'c_notes',
            'c_year',
            'c_month',
            'c_day',
            'c_day_ganzhi',
            'c_nh_code',
            'c_nh_year',
            'c_yr_range',
            'c_intercalary',
            'c_role',
            'c_event',
            // c_addr_id 仍不列入純量白名單：事件地址寫入 EVENTS_ADDR 副表（由 handle 抽出 + afterDirectUpdate 同步），
            // 不寫 EVENTS_DATA.c_addr_id 純量欄。
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, ['c_event_code', 'c_source']);

        if (array_key_exists('c_intercalary', $data)) {
            $data['c_intercalary'] = (int) ($data['c_intercalary'] ?? 0);
        }

        return $data;
    }
}
