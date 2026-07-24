<?php

namespace App\Services\Mutations\EntityAggregate;

use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Services\Mutations\AbstractMutationHandler;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * 通用實體聚合 handler 的共用骨架（docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5）。
 *
 * 承擔所有實體共通、每輪都被複製的部分：direct／proposal 授權、resource→definition 分派、
 * target.pk 解析、回應信封、提案存檔。子類（Create／Update／Delete）只實作各自的操作流程，
 * 實體差異全在 definition。
 *
 * 提案（§4.5 實體級提案）：mode=proposal 時同樣 validate＋guardWrite（早擋壞提案），但**不呼叫
 * service()**，改為把「聚合意圖」（使用者原始 changes ＋ 操作／pk 標記）存入 operations；核准時由
 * OperationsProposalController 以 mode=direct 重放同一 handler（validate→guardWrite→service），
 * direct 與 proposal 天然對等。存的是意圖而非單表行——複合實體跨多表，單表行快照表達不了（§3.3）。
 */
abstract class AbstractEntityAggregateHandler extends AbstractMutationHandler {
    public function __construct(protected EntityAggregateDefinitionRegistry $registry) {
    }

    /** 本 handler 負責的操作（'create'／'update'／'delete'）。 */
    abstract protected function operation(): string;

    public function supports(string $resource, string $mode, string $operation): bool {
        if (!in_array($mode, ['direct', 'proposal'], true) || $operation !== $this->operation()) {
            return false;
        }
        $definition = $this->registry->forResource($resource);

        return $definition !== null && in_array($this->operation(), $definition->operations(), true);
    }

    /**
     * 存一筆實體聚合提案（不落庫）。resource＝聚合 API 名（如 office），resource_data 帶
     * __entity_aggregate 標記供核准端辨識並重放。回應信封與 direct 對稱、標明 mode=proposal。
     *
     * @param array<string, mixed> $changes 使用者原始輸入（核准時原樣重放，不預先 normalize）
     */
    protected function storeProposal(
        EntityAggregateDefinition $definition,
        string $operation,
        ?int $id,
        int $personId,
        array $changes,
        array $meta
    ): JsonResponse {
        $resource = $definition->resourceName();
        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

        $opType = match ($operation) {
            'create' => Operation::TYPE_PROPOSAL_CREATE,
            'delete' => Operation::TYPE_PROPOSAL_DELETE,
            default => Operation::TYPE_PROPOSAL_UPDATE,
        };

        $resourceData = [
            '__entity_aggregate' => true,
            '__entity_resource' => $resource,
            '__entity_operation' => $operation,
            '__entity_pk' => $id,
            'changes' => $changes,
            '__proposal_meta' => [
                'action' => $operation,
                'resource_type' => $resource,
                'display_name' => $definition->resourceName(),
                'submitted_by' => Auth::user()->name ?? Auth::id(),
                'submitted_by_id' => Auth::id(),
                'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
                'comment' => $comment,
            ],
            '__review_status' => 'pending',
        ];

        $operationRecord = app(OperationRepository::class)->store(
            Auth::id(),
            $personId,
            $opType,
            $resource,
            $id !== null ? (string) $id : '',
            $resourceData
        );

        return response()->json([
            'ok' => true,
            'resource' => $resource,
            'mode' => 'proposal',
            'operation' => $operation,
            'result' => [
                'pk' => $id !== null ? [$definition->pkField() => $id] : null,
                'status' => 'proposal_'.$operation.'d',
                'operation_id' => $operationRecord?->id,
            ],
        ]);
    }

    /** 解析 target.pk 的識別鍵（接受 c_xxx 或去前綴的 xxx）；非正整數回 null。 */
    protected function parsePk(array $targetPk, string $pkField): ?int {
        $alias = preg_replace('/^c_/', '', $pkField);
        $raw = $this->scalarOrNull($targetPk[$pkField] ?? $targetPk[$alias] ?? null);
        if ($raw === null || $raw === '' || !ctype_digit((string) $raw)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * 統一回應信封。
     *
     * @param array<string, mixed> $result
     */
    protected function envelope(string $resource, string $operation, array $result): JsonResponse {
        return response()->json([
            'ok' => true,
            'resource' => $resource,
            'mode' => 'direct',
            'operation' => $operation,
            'result' => $result,
        ]);
    }
}
