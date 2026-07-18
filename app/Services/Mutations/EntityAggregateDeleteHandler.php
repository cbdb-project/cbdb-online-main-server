<?php

namespace App\Services\Mutations;

use App\Services\Mutations\EntityAggregate\AbstractEntityAggregateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * 通用「刪除複合實體」handler（operation=delete、mode=direct）。依 resource 分派到對應
 * EntityAggregateDefinition，取代原 OfficeDeleteHandler／SocialInstituteDeleteHandler。
 * 引用護欄（被人物資料引用回 409）由 definition::guardWrite 提供。
 * 見 docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5。
 */
class EntityAggregateDeleteHandler extends AbstractEntityAggregateHandler {
    protected function operation(): string {
        return 'delete';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authError = $this->authorizeDirect();
        if ($authError) {
            return $authError;
        }

        $definition = $this->registry->forResource($resource);
        $pkField = $definition->pkField();

        $id = $this->parsePk($targetPk, $pkField);
        if ($id === null) {
            return $this->errorResponse("target.pk 缺少有效的 {$pkField}", 422, [$pkField => ['required_integer']]);
        }

        $existing = $definition->service()->load($id);
        if ($existing === null) {
            return $this->errorResponse($definition->notFoundMessage(), 404, [$pkField => ['not_found']]);
        }

        $guard = $definition->guardWrite('delete', $id, [], $existing);
        if ($guard !== null) {
            return $this->errorResponse($guard[0], $guard[1], $guard[2]);
        }

        $result = DB::transaction(fn () => $definition->result(
            'delete',
            $id,
            [],
            $definition->service()->delete($id, $personId)
        ));

        return $this->envelope($definition->resourceName(), 'delete', $result);
    }
}
