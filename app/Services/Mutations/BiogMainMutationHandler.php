<?php

namespace App\Services\Mutations;

use App\Http\Requests\BasicInformationRequest;
use App\Models\BiogMain;
use App\Repositories\BiogMainRepository;
use App\Services\NameSearchIndexService;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class BiogMainMutationHandler extends AbstractMutationHandler {
    protected const BLOCKED_FIELDS = [
        'c_personid',
        'c_name_chn',
        'c_name',
        'c_name_proper',
        'c_name_rm',
        'c_created_by',
        'c_created_date',
        'c_modified_by',
        'c_modified_date',
    ];

    protected BiogMainRepository $biogMainRepository;
    protected NameSearchIndexService $nameSearchIndexService;

    public function __construct(BiogMainRepository $biogMainRepository, NameSearchIndexService $nameSearchIndexService) {
        $this->biogMainRepository = $biogMainRepository;
        $this->nameSearchIndexService = $nameSearchIndexService;
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return in_array($resource, ['basicinformation', 'biogmain', 'biog_main'], true)
            && $mode === 'direct'
            && $operation === 'update';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authorizationError = $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        try {
            CompositePrimaryKey::validateOrFail($targetPk, 'BIOG_MAIN');
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        if (empty($changes)) {
            return $this->errorResponse('changes 不可為空', 422, ['changes' => ['empty']]);
        }

        if ((string) ($targetPk['c_personid'] ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與 target.pk.c_personid 不一致', 422, [
                'person_id' => ['mismatch'],
            ]);
        }

        $original = BiogMain::find($personId);
        if (!$original) {
            return $this->errorResponse('BIOG_MAIN 記錄不存在', 404);
        }

        [$merged, $updatedFields] = $this->buildMergedPayload($original, $changes);
        if ($updatedFields === []) {
            return $this->errorResponse('目前此接口未包含可更新欄位', 422, [
                'changes' => ['no_supported_fields'],
            ]);
        }

        $requestTemplate = new BasicInformationRequest();
        $validator = Validator::make($merged, $requestTemplate->rules(), $requestTemplate->messages());
        if ($validator->fails()) {
            return $this->errorResponse('參數校驗失敗', 422, $validator->errors()->toArray());
        }

        $proxy = Request::create('/api/v2/mutate', 'PATCH', $merged);
        if (isset($meta['comment']) && is_string($meta['comment']) && trim($meta['comment']) !== '') {
            $proxy->request->set('__proposal_comment', trim($meta['comment']));
        }

        try {
            $result = $this->biogMainRepository->updateById($proxy, $personId);
        } catch (\Throwable $e) {
            return $this->errorResponse('更新失敗：'.$e->getMessage(), 500);
        }

        if (($result['no_changes'] ?? false) === true) {
            return $this->errorResponse('未偵測到任何修改內容', 422, [
                'changes' => ['no_effective_changes'],
            ]);
        }

        $updated = BiogMain::find($personId);
        if ($updated && Schema::hasTable('CBDB__NAME_FTS')) {
            $this->nameSearchIndexService->reindexPerson($updated);
        }

        return response()->json([
            'ok' => true,
            'resource' => 'basicinformation',
            'mode' => 'direct',
            'operation' => 'update',
            'result' => [
                'pk' => ['c_personid' => $personId],
                'updated_fields' => $updatedFields,
                'operation_id' => $result['operation_id'] ?? null,
                'row' => $updated ? $updated->toArray() : null,
            ],
        ]);
    }

    protected function buildMergedPayload(BiogMain $original, array $changes): array {
        $base = $original->toArray();
        $allowedChanges = array_intersect_key(
            $changes,
            array_flip(array_diff(array_keys($base), self::BLOCKED_FIELDS))
        );

        $payload = array_replace($base, $allowedChanges);
        $payload['c_by_intercalary'] = $payload['c_by_intercalary'] ?? 0;
        $payload['c_dy_intercalary'] = $payload['c_dy_intercalary'] ?? 0;

        return [$payload, array_keys($allowedChanges)];
    }
}
