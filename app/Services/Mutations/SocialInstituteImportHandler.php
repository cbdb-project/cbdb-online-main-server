<?php

namespace App\Services\Mutations;

use App\Services\Import\SocialInstituteImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * mutation API 的「新增社會機構」handler（resource=social-institution）。薄封裝：把請求 payload
 * 映射為業務輸入，校驗後委派 SocialInstituteImportService::create()（與 admin 批量表單共用同一存儲過程）。
 *
 * 語意：一次 create = 原子寫入 SOCIAL_INSTITUTION_NAME_CODES（名稱去重）+ SOCIAL_INSTITUTION_CODES
 * + SOCIAL_INSTITUTION_ADDR，含拼音派生、自動 name_code/inst_code。為領域級複合寫入，故不走裸 code 表通道。
 *
 * ⚠ 資源命名刻意用連字符（social-institution），與既有「人物隸屬機構」子資源
 * （BIOG_INST_DATA，resource=social_institution 底線）區隔：前者新建「機構實體」，後者記錄某人隸屬某機構。
 *
 * person_id 對本資源無意義（呼叫端仍須帶，僅記入 operations）。
 *
 * changes 欄位（接受業務名或欄名）：
 *  - name / c_inst_name_hz（必填）
 *  - type_code / c_inst_type_code（類型碼）或 type_label（類型中文名/拼音，二擇一，須存在）
 *  - dynasty_code / c_inst_begin_dy（朝代碼）或 dynasty_label（朝代名，二擇一，須存在）
 *  - addr_id / c_inst_addr_id（ADDR_CODES 地址 id，必填、須存在）
 *  - source_id / c_source（TEXT_CODES textid，必填、須存在）
 */
class SocialInstituteImportHandler extends AbstractMutationHandler {
    public function __construct(protected SocialInstituteImportService $service) {
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return $operation === 'create'
            && $mode === 'direct'
            && in_array($resource, ['social-institution', 'social-institutions', 'social-institution-load', 'socialinst-load'], true);
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authError = $this->authorizeDirect();
        if ($authError) {
            return $authError;
        }

        $name = trim((string) ($changes['name'] ?? $changes['c_inst_name_hz'] ?? ''));
        $addrId = $changes['addr_id'] ?? $changes['c_inst_addr_id'] ?? null;
        $sourceId = $changes['source_id'] ?? $changes['c_source'] ?? null;

        // 類型：給碼（type_code/c_inst_type_code）優先；否則以類型名（type_label）解析。
        $typeMap = $this->service->typeMap();
        $typeCode = $changes['type_code'] ?? $changes['c_inst_type_code'] ?? null;
        if (($typeCode === null || $typeCode === '') && isset($changes['type_label'])) {
            $label = trim((string) $changes['type_label']);
            $typeCode = $typeMap[$label] ?? null;
            if ($typeCode === null) {
                return $this->errorResponse('找不到類型名對應的代碼', 422, ['type_label' => ['not_found']]);
            }
        }

        // 朝代：給碼（dynasty_code/c_inst_begin_dy）優先；否則以朝代名（dynasty_label）解析。
        $dynastyMap = $this->service->dynastyMap();
        $dynastyCode = $changes['dynasty_code'] ?? $changes['c_inst_begin_dy'] ?? null;
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
        if ($typeCode === null || $typeCode === '' || !in_array((int) $typeCode, $typeMap, true)) {
            $errors['type'] = ['invalid'];
        }
        if ($dynastyCode === null || $dynastyCode === '' || !in_array((int) $dynastyCode, $dynastyMap, true)) {
            $errors['dynasty'] = ['invalid'];
        }
        if ($addrId === null || $addrId === '' || !ctype_digit((string) $addrId)) {
            $errors['addr_id'] = ['required_integer'];
        } elseif ($this->service->missingAddrIds([(int) $addrId]) !== []) {
            $errors['addr_id'] = ['not_found_in_addr_codes'];
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
            'type_code' => (int) $typeCode,
            'dynasty_code' => (int) $dynastyCode,
            'addr_id' => (int) $addrId,
            'source_id' => (int) $sourceId,
        ], $personId));

        return response()->json([
            'ok' => true,
            'resource' => 'social-institution',
            'mode' => 'direct',
            'operation' => 'create',
            'result' => [
                'pk' => ['c_inst_code' => $result['inst_code'], 'c_inst_name_code' => $result['name_code']],
                'status' => 'created',
                'operation_id' => $result['operation_id_code'],
                'name_created' => $result['name_created'],
                'row' => [
                    'c_inst_code' => $result['inst_code'],
                    'c_inst_name_code' => $result['name_code'],
                    'c_inst_name_hz' => $name,
                    'c_inst_name_py' => $result['name_pinyin'],
                    'c_inst_type_code' => (int) $typeCode,
                    'c_inst_addr_id' => (int) $addrId,
                ],
            ],
        ]);
    }
}
