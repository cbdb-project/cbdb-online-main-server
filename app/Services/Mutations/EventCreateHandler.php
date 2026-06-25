<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\EventStatusRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;

class EventCreateHandler extends AbstractPersonSubresourceCreateHandler {
    /** 本次 create 的地址意圖（c_addr_id 多值）；null=無地址、[ids]=寫入 EVENTS_ADDR。 */
    private ?array $pendingIncomingAddr = null;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    /** 覆寫：抽出 c_addr_id（非 EVENTS_DATA 純量欄），主列寫入後於同交易由 afterDirectInsert 寫 EVENTS_ADDR。 */
    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $hasAddrKey = array_key_exists('c_addr_id', $changes);
        $rawAddr = $hasAddrKey ? $changes['c_addr_id'] : null;
        unset($changes['c_addr_id'], $changes['c_addr_cleared']);
        $this->pendingIncomingAddr = $hasAddrKey ? array_values((array) $rawAddr) : null;

        try {
            return parent::handle($resource, $mode, $operation, $personId, $targetPk, $changes, $meta);
        } finally {
            $this->pendingIncomingAddr = null;
        }
    }

    /** 新增主列成功後寫 EVENTS_ADDR（old==new tuple）。 */
    protected function afterDirectInsert(int $personId, array $actualPk, array $rowData, array $insertedArray, ?Operation $operation): void {
        if ($this->pendingIncomingAddr === null) {
            return;
        }
        $seq = $insertedArray['c_sequence'] ?? ($actualPk['c_sequence'] ?? null);
        $code = $insertedArray['c_event_code'] ?? ($actualPk['c_event_code'] ?? null);
        app(EventStatusRepository::class)->syncEventAddresses($this->pendingIncomingAddr, $personId, $seq, $code, $seq, $code);
    }

    /** proposal 模式把地址寫入 __proposal_aux（核准時 applyEventProposal 合併入請求）。 */
    protected function proposalAuxiliaryPayload(): array {
        if ($this->pendingIncomingAddr === null) {
            return [];
        }

        return ['c_addr_id' => $this->pendingIncomingAddr];
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
            'c_personid',
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
            // c_addr_id 不列入：legacy 將事件地址寫入 EVENTS_ADDR 副表，從不寫 EVENTS_DATA.c_addr_id 純量欄。
            // v2 為單表寫入，若允許 c_addr_id 會直接覆寫純量欄且不同步副表，與 legacy 分歧，故移除（fail closed）。
        ];
    }

    protected function preprocessCreateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, ['c_event_code', 'c_source']);
        // #71：非 PK 碼欄 c_source 完全幂等（null/''/-999→0），對齊已修的 EventMutationHandler。
        $data = $this->normalizeEmptyCodeFields($data, ['c_source']);

        if (array_key_exists('c_intercalary', $data)) {
            $data['c_intercalary'] = (int) ($data['c_intercalary'] ?? 0);
        }

        return $data;
    }
}
