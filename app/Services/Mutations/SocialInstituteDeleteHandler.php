<?php

namespace App\Services\Mutations;

use App\Services\Import\SocialInstituteImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * mutation API 的「刪除社會機構實體」handler（resource=social-institution、operation=delete）。
 * 委派 SocialInstituteImportService::delete()：先刪 SOCIAL_INSTITUTION_ADDR 各行、
 * 再刪 SOCIAL_INSTITUTION_CODES；名稱碼不回收。
 *
 * 安全護欄：仍被人物資料（BIOG_INST_DATA／ENTRY_DATA／ASSOC_DATA／POSTED_TO_OFFICE_DATA）
 * 引用者不可刪，回 409——這些表以 ON DELETE CASCADE 引用 c_inst_code，有引用時刪除會
 * 連帶刪掉人物資料。target.pk 須帶 c_inst_code；不存在回 404。
 */
class SocialInstituteDeleteHandler extends AbstractMutationHandler {
    public function __construct(protected SocialInstituteImportService $service) {
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return $operation === 'delete'
            && $mode === 'direct'
            && in_array($resource, ['social-institution', 'social-institutions'], true);
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authError = $this->authorizeDirect();
        if ($authError) {
            return $authError;
        }

        $instCode = $this->scalarOrNull($targetPk['c_inst_code'] ?? $targetPk['inst_code'] ?? null);
        if ($instCode === null || $instCode === '' || !ctype_digit((string) $instCode)) {
            return $this->errorResponse('target.pk 缺少有效的 c_inst_code', 422, ['c_inst_code' => ['required_integer']]);
        }
        $instCode = (int) $instCode;

        if ($this->service->load($instCode) === null) {
            return $this->errorResponse('找不到社會機構', 404, ['c_inst_code' => ['not_found']]);
        }

        $refCount = $this->service->referenceCount($instCode);
        if ($refCount > 0) {
            return $this->errorResponse(
                "此機構仍被 {$refCount} 筆人物資料引用，無法刪除",
                409,
                ['c_inst_code' => ['referenced_by_person_data'], 'reference_count' => [$refCount]]
            );
        }

        $result = DB::transaction(fn () => $this->service->delete($instCode, $personId));

        return response()->json([
            'ok' => true,
            'resource' => 'social-institution',
            'mode' => 'direct',
            'operation' => 'delete',
            'result' => [
                'pk' => ['c_inst_code' => $instCode],
                'status' => 'deleted',
                'operation_id' => $result['operation_id_code'],
                'addr_deleted' => $result['addr_deleted'],
            ],
        ]);
    }
}
