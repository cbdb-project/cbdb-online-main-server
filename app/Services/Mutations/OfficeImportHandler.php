<?php

namespace App\Services\Mutations;

use App\Services\Import\OfficeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * mutation API 的「新增官職」handler（resource=office）。薄封裝：把請求 payload 映射為業務輸入，
 * 校驗後委派 OfficeImportService::create()（與 admin 批量表單共用同一存儲過程）。
 *
 * 語意：一次 create = 原子寫入 OFFICE_CODES + OFFICE_CODE_TYPE_REL，含拼音/朝代碼派生、自動 office_id。
 * 為領域級複合寫入，故不走裸 code 表通道。person_id 對本資源無意義（呼叫端仍須帶，僅記入 operations）。
 *
 * changes 欄位（接受業務名或欄名）：
 *  - name / c_office_chn（必填）
 *  - translation / c_office_trans（選填）
 *  - dynasty_code / c_dy（朝代碼）或 dynasty_label（朝代名，二擇一）
 *  - type_id / c_office_tree_id（OFFICE_TYPE_TREE 節點，必填、須存在）
 *  - source_id / c_source（TEXT_CODES textid，必填、須存在）
 */
class OfficeImportHandler extends AbstractMutationHandler {
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

        $name = trim((string) ($changes['name'] ?? $changes['c_office_chn'] ?? ''));
        $translation = $changes['translation'] ?? $changes['c_office_trans'] ?? null;
        $typeId = $changes['type_id'] ?? $changes['c_office_tree_id'] ?? null;
        $sourceId = $changes['source_id'] ?? $changes['c_source'] ?? null;

        // 朝代：給碼（c_dy/dynasty_code）優先；否則以朝代名（dynasty_label）解析。
        $dynastyMap = $this->service->dynastyMap();
        $dynastyCode = $changes['dynasty_code'] ?? $changes['c_dy'] ?? null;
        if (($dynastyCode === null || $dynastyCode === '') && isset($changes['dynasty_label'])) {
            $label = trim((string) $changes['dynasty_label']);
            $dynastyCode = $dynastyMap[$label] ?? null;
            if ($dynastyCode === null) {
                return $this->errorResponse('找不到朝代名對應的代碼', 422, ['dynasty_label' => ['not_found']]);
            }
        }

        $errors = [];
        if ($name === '') {
            $errors['name'] = ['required'];
        }
        if ($dynastyCode === null || $dynastyCode === '' || !in_array((int) $dynastyCode, $dynastyMap, true)) {
            $errors['dynasty'] = ['invalid'];
        }
        if ($typeId === null || $typeId === '') {
            $errors['type_id'] = ['required'];
        } elseif ($this->service->missingOfficeTypes([$typeId]) !== []) {
            $errors['type_id'] = ['not_found_in_office_type_tree'];
        }
        if ($sourceId === null || $sourceId === '' || !ctype_digit((string) $sourceId)) {
            $errors['source_id'] = ['required_integer'];
        } elseif ($this->service->missingSourceIds([(int) $sourceId]) !== []) {
            $errors['source_id'] = ['not_found_in_text_codes'];
        }
        if ($errors !== []) {
            return $this->errorResponse('參數校驗失敗', 422, $errors);
        }

        $result = DB::transaction(fn () => $this->service->create([
            'name' => $name,
            'translation' => $translation,
            'dynasty_code' => (int) $dynastyCode,
            'type_id' => $typeId,
            'source_id' => (int) $sourceId,
        ], $personId));

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
                    'c_office_chn' => $name,
                    'c_office_pinyin' => $result['pinyin'],
                    'c_office_tree_id' => $typeId,
                ],
            ],
        ]);
    }
}
