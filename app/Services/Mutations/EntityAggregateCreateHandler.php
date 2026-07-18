<?php

namespace App\Services\Mutations;

use App\Services\Mutations\EntityAggregate\AbstractEntityAggregateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * 通用「新增複合實體」handler（operation=create、mode=direct）。依 resource 分派到對應
 * EntityAggregateDefinition（config/entity_aggregates.php），取代原 office／social institution
 * 各自的 *ImportHandler。新實體上線＝加一個 definition ＋ config 一項，不必新增 handler。
 * 見 docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5。
 */
class EntityAggregateCreateHandler extends AbstractEntityAggregateHandler {
    protected function operation(): string {
        return 'create';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authError = $this->authorizeDirect();
        if ($authError) {
            return $authError;
        }

        $definition = $this->registry->forResource($resource);

        [$errors, $input] = $definition->validate('create', $changes);
        if ($errors !== []) {
            return $this->errorResponse('參數校驗失敗', 422, $errors);
        }

        $guard = $definition->guardWrite('create', null, $input, null);
        if ($guard !== null) {
            return $this->errorResponse($guard[0], $guard[1], $guard[2]);
        }

        $result = DB::transaction(fn () => $definition->result(
            'create',
            null,
            $input,
            $definition->service()->create($input, $personId)
        ));

        return $this->envelope($definition->resourceName(), 'create', $result);
    }
}
