<?php

namespace App\Services\Mutations;

use App\Services\Mutations\EntityAggregate\AbstractEntityAggregateHandler;
use Illuminate\Database\QueryException;
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

        $guard = $definition->guardWrite('delete', $id, [], $existing);
        if ($guard !== null) {
            return $this->errorResponse($guard[0], $guard[1], $guard[2]);
        }

        if ($mode === 'proposal') {
            return $this->storeProposal($definition, 'delete', $id, $personId, [], $meta);
        }

        try {
            $result = DB::transaction(fn () => $definition->result(
                'delete',
                $id,
                [],
                $definition->service()->delete($id, $personId)
            ));
        } catch (QueryException $e) {
            // 詞表入邊外鍵已陸續翻成 ON DELETE RESTRICT（去級聯 Phase 1，OFFICE_CODES 在批次 3）：
            // definition::guardWrite 的引用護欄若有漏網引用（如 POSTED_TO_ADDR_DATA 殘留列），
            // DELETE 會被 DB 以 1451 擋下、交易回滾（含 operations 記錄）。fail-closed、
            // 零資料損失，這裡轉為友好訊息。
            if (($e->errorInfo[1] ?? null) !== 1451) {
                throw $e;
            }

            return $this->errorResponse(
                '此記錄仍被其他資料引用，無法刪除。請先移除引用後再試。',
                409,
                [$pkField => ['referenced_by_other_records']]
            );
        }

        return $this->envelope($definition->resourceName(), 'delete', $result);
    }
}
