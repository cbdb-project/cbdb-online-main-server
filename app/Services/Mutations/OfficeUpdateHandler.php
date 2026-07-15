<?php

namespace App\Services\Mutations;

use App\Services\Import\OfficeImportService;
use App\Services\Mutations\Concerns\ResolvesOfficeAggregateInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * mutation API 的「更新官職實體」handler（resource=office、operation=update）。
 * 委派 OfficeImportService::update()：OFFICE_CODES 欄位整體覆寫、OFFICE_CODE_TYPE_REL 做集合對賬。
 *
 * 校驗與 OfficeImportHandler 共用 ResolvesOfficeAggregateInput（create／update 一致，含類型至少一個）。
 * target.pk 須帶 c_office_id；不存在回 404。person_id 對本資源無意義（僅記入 operations）。
 */
class OfficeUpdateHandler extends AbstractMutationHandler {
    use ResolvesOfficeAggregateInput;

    public function __construct(protected OfficeImportService $service) {
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return $operation === 'update'
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

        [$errors, $input] = $this->validateOfficeAggregate($changes, $this->service);
        if ($errors !== []) {
            return $this->errorResponse('參數校驗失敗', 422, $errors);
        }

        $result = DB::transaction(fn () => $this->service->update($officeId, $input, $personId));

        return response()->json([
            'ok' => true,
            'resource' => 'office',
            'mode' => 'direct',
            'operation' => 'update',
            'result' => [
                'pk' => ['c_office_id' => $officeId],
                'status' => 'updated',
                'operation_id' => $result['operation_id_office'],
                'types_added' => $result['types_added'],
                'types_removed' => $result['types_removed'],
                'row' => [
                    'c_office_id' => $officeId,
                    'c_office_chn' => $input['name'],
                    'c_office_pinyin' => $result['pinyin'],
                    'type_ids' => $result['type_ids'],
                ],
            ],
        ]);
    }
}
