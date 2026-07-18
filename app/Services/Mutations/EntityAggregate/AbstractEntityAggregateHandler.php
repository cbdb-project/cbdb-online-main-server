<?php

namespace App\Services\Mutations\EntityAggregate;

use App\Services\Mutations\AbstractMutationHandler;
use Illuminate\Http\JsonResponse;

/**
 * 通用實體聚合 handler 的共用骨架（docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5）。
 *
 * 承擔所有實體共通、每輪都被複製的部分：direct 授權、resource→definition 分派、
 * target.pk 解析、回應信封。子類（Create／Update／Delete）只實作各自的操作流程，
 * 實體差異全在 definition。分派只認 mode=direct（proposal 為 §4.5 未支援）。
 */
abstract class AbstractEntityAggregateHandler extends AbstractMutationHandler {
    public function __construct(protected EntityAggregateDefinitionRegistry $registry) {
    }

    /** 本 handler 負責的操作（'create'／'update'／'delete'）。 */
    abstract protected function operation(): string;

    public function supports(string $resource, string $mode, string $operation): bool {
        if ($mode !== 'direct' || $operation !== $this->operation()) {
            return false;
        }
        $definition = $this->registry->forResource($resource);

        return $definition !== null && in_array($this->operation(), $definition->operations(), true);
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
