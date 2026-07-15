<?php

namespace App\Services\Mutations;

use App\Services\Import\OfficeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * mutation API 的「刪除官職實體」handler（resource=office、operation=delete）。
 * 委派 OfficeImportService::delete()：先刪 OFFICE_CODE_TYPE_REL 各行、再刪 OFFICE_CODES。
 *
 * 安全護欄：仍被人物任官（POSTED_TO_OFFICE_DATA）引用者不可刪，回 409，避免製造孤兒外鍵。
 * target.pk 須帶 c_office_id；不存在回 404。person_id 對本資源無意義（僅記入 operations）。
 */
class OfficeDeleteHandler extends AbstractMutationHandler {
    public function __construct(protected OfficeImportService $service) {
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return $operation === 'delete'
            && $mode === 'direct'
            && in_array($resource, ['office', 'offices'], true);
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authError = $this->authorizeDirect();
        if ($authError) {
            return $authError;
        }

        $officeId = $this->scalarOrNull($targetPk['c_office_id'] ?? $targetPk['office_id'] ?? null);
        if ($officeId === null || $officeId === '' || !ctype_digit((string) $officeId)) {
            return $this->errorResponse('target.pk 缺少有效的 c_office_id', 422, ['c_office_id' => ['required_integer']]);
        }
        $officeId = (int) $officeId;

        if ($this->service->load($officeId) === null) {
            return $this->errorResponse('找不到官職', 404, ['c_office_id' => ['not_found']]);
        }

        $refCount = $this->service->referenceCount($officeId);
        if ($refCount > 0) {
            return $this->errorResponse(
                "此官職仍被 {$refCount} 筆人物任官引用，無法刪除",
                409,
                ['c_office_id' => ['referenced_by_postings'], 'reference_count' => [$refCount]]
            );
        }

        $result = DB::transaction(fn () => $this->service->delete($officeId, $personId));

        return response()->json([
            'ok' => true,
            'resource' => 'office',
            'mode' => 'direct',
            'operation' => 'delete',
            'result' => [
                'pk' => ['c_office_id' => $officeId],
                'status' => 'deleted',
                'operation_id' => $result['operation_id_office'],
                'rel_deleted' => $result['rel_deleted'],
            ],
        ]);
    }
}
