<?php

namespace App\Services\Mutations;

use App\Services\Import\OfficeImportService;
use App\Services\Mutations\Concerns\ResolvesOfficeAggregateInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * mutation API 的「新增官職」handler（resource=office）。薄封裝：把請求 payload 映射為業務輸入，
 * 校驗後委派 OfficeImportService::create()（與 admin 批量表單共用同一存儲過程）。
 *
 * 語意：一次 create = 原子寫入 OFFICE_CODES + OFFICE_CODE_TYPE_REL（類型可多值），含拼音/朝代碼派生、
 * 自動 office_id。為領域級複合寫入，故不走裸 code 表通道。person_id 對本資源無意義（僅記入 operations）。
 * 校驗與 OfficeUpdateHandler 共用 ResolvesOfficeAggregateInput，確保 create／update 語意一致。
 *
 * changes 欄位（接受業務名或欄名）：
 *  - name / c_office_chn（必填）
 *  - translation / c_office_trans（選填）
 *  - dynasty_code / c_dy（朝代碼）或 dynasty_label（朝代名，二擇一）
 *  - type_ids[]（多值）或 type_id / c_office_tree_id（單值，向後相容）——OFFICE_TYPE_TREE 節點，至少一個、須存在
 *  - source_id / c_source（TEXT_CODES textid，必填、須存在）
 */
class OfficeImportHandler extends AbstractMutationHandler {
    use ResolvesOfficeAggregateInput;

    public function __construct(protected OfficeImportService $service) {
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return $operation === 'create'
            && $mode === 'direct'
            && in_array($resource, ['office', 'offices', 'office-load'], true);
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authError = $this->authorizeDirect();
        if ($authError) {
            return $authError;
        }

        [$errors, $input] = $this->validateOfficeAggregate($changes, $this->service);
        if ($errors !== []) {
            return $this->errorResponse('參數校驗失敗', 422, $errors);
        }

        $result = DB::transaction(fn () => $this->service->create($input, $personId));

        return response()->json([
            'ok' => true,
            'resource' => 'office',
            'mode' => 'direct',
            'operation' => 'create',
            'result' => [
                'pk' => ['c_office_id' => $result['office_id']],
                'status' => 'created',
                'operation_id' => $result['operation_id_office'],
                'row' => [
                    'c_office_id' => $result['office_id'],
                    'c_office_chn' => $input['name'],
                    'c_office_pinyin' => $result['pinyin'],
                    'type_ids' => $result['type_ids'],
                ],
            ],
        ]);
    }
}
