<?php

namespace App\Services\Mutations;

use App\Services\Import\SocialInstituteImportService;
use App\Services\Mutations\Concerns\ResolvesSocialInstituteAggregateInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * mutation API 的「更新社會機構實體」handler（resource=social-institution、operation=update）。
 * 委派 SocialInstituteImportService::update()：SOCIAL_INSTITUTION_CODES 欄位整體覆寫、
 * 名稱走去重解析、SOCIAL_INSTITUTION_ADDR 集合對賬。
 *
 * 實體識別＝c_inst_code 單鍵（見 service 類註）；target.pk 須帶 c_inst_code，不存在回 404。
 * 改名護欄：解析後 name_code 改變且 referenceCount()>0 時回 409——人物表存
 * (inst_code, name_code) 對，被引用時改名會使既存引用失配。
 * person_id 對本資源無意義（僅記入 operations）。
 */
class SocialInstituteUpdateHandler extends AbstractMutationHandler {
    use ResolvesSocialInstituteAggregateInput;

    public function __construct(protected SocialInstituteImportService $service) {
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return $operation === 'update'
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

        $aggregate = $this->service->load($instCode);
        if ($aggregate === null) {
            return $this->errorResponse('找不到社會機構', 404, ['c_inst_code' => ['not_found']]);
        }

        [$errors, $input] = $this->validateSocialInstituteAggregate($changes, $this->service);
        if ($errors !== []) {
            return $this->errorResponse('參數校驗失敗', 422, $errors);
        }

        // 改名護欄：名稱改變（去重解析後 name_code 與現值不同）且仍被人物資料引用時擋下。
        // 「同名同碼」的編輯（僅改其他欄位）不受影響。
        if ($input['name'] !== $aggregate['name']) {
            $refCount = $this->service->referenceCount($instCode);
            if ($refCount > 0) {
                return $this->errorResponse(
                    "此機構仍被 {$refCount} 筆人物資料引用，暫不支援改名（會使既存引用的名稱碼失配）；其餘欄位可正常修改",
                    409,
                    ['name' => ['rename_blocked_while_referenced'], 'reference_count' => [$refCount]]
                );
            }
        }

        $result = DB::transaction(fn () => $this->service->update($instCode, $input, $personId));

        return response()->json([
            'ok' => true,
            'resource' => 'social-institution',
            'mode' => 'direct',
            'operation' => 'update',
            'result' => [
                'pk' => ['c_inst_code' => $instCode],
                'status' => 'updated',
                'operation_id' => $result['operation_id_code'],
                'name_changed' => $result['name_changed'],
                'addr_added' => $result['addr_added'],
                'addr_removed' => $result['addr_removed'],
                'row' => [
                    'c_inst_code' => $instCode,
                    'c_inst_name_code' => $result['name_code'],
                    'c_inst_name_hz' => $input['name'],
                    'c_inst_type_code' => $input['type_code'],
                ],
            ],
        ]);
    }
}
