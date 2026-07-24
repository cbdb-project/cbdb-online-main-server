<?php

namespace App\Services\Mutations;

use App\Services\Mutations\EntityAggregate\AbstractEntityAggregateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * 通用「更新複合實體」handler（operation=update、mode=direct）。依 resource 分派到對應
 * EntityAggregateDefinition，取代原 OfficeUpdateHandler／SocialInstituteUpdateHandler。
 * target.pk 須帶識別鍵；不存在回 404。見 docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5。
 */
class EntityAggregateUpdateHandler extends AbstractEntityAggregateHandler {
    protected function operation(): string {
        return 'update';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
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

        [$errors, $input] = $definition->validate('update', $changes);
        if ($errors !== []) {
            return $this->errorResponse('參數校驗失敗', 422, $errors);
        }

        $guard = $definition->guardWrite('update', $id, $input, $existing);
        if ($guard !== null) {
            return $this->errorResponse($guard[0], $guard[1], $guard[2]);
        }

        if ($mode === 'proposal') {
            return $this->storeProposal($definition, 'update', $id, $personId, $changes, $meta);
        }

        $result = DB::transaction(fn () => $definition->result(
            'update',
            $id,
            $input,
            $definition->service()->update($id, $input, $personId)
        ));

        return $this->envelope($definition->resourceName(), 'update', $result);
    }
}
