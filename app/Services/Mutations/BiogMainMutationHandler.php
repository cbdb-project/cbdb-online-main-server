<?php

namespace App\Services\Mutations;

use App\Http\Requests\BasicInformationRequest;
use App\Models\BiogMain;
use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Services\BracketNormalizer;
use App\Services\NameSearchIndexService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    protected OperationRepository $operationRepository;

    public function __construct(
        BiogMainRepository $biogMainRepository,
        NameSearchIndexService $nameSearchIndexService,
        OperationRepository $operationRepository
    ) {
        $this->biogMainRepository = $biogMainRepository;
        $this->nameSearchIndexService = $nameSearchIndexService;
        $this->operationRepository = $operationRepository;
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return in_array($resource, ['basicinformation', 'biogmain', 'biog_main'], true)
            && in_array($mode, ['direct', 'proposal'], true)
            && $operation === 'update';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authorizationError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
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

        if ($mode === 'proposal') {
            return $this->handleProposalUpdate($personId, $updatedFields, $merged, $original, $meta);
        }

        $validator = Validator::make($merged, $this->validationRules(false), $this->validationMessages());
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

    protected function handleProposalUpdate(int $personId, array $updatedFields, array $merged, BiogMain $original, array $meta): JsonResponse {
        $proposalData = $this->prepareProposalPayload($merged);
        $validator = Validator::make($proposalData, $this->validationRules(true), $this->validationMessages());
        if ($validator->fails()) {
            return $this->errorResponse('參數校驗失敗', 422, $validator->errors()->toArray());
        }

        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';
        $resourceData = array_merge($proposalData, [
            '__proposal_meta' => [
                'action' => 'update',
                'resource_type' => 'biogmain',
                'table' => 'BIOG_MAIN',
                'display_name' => '基本資料',
                'submitted_by' => Auth::user()->name ?? Auth::id(),
                'submitted_by_id' => Auth::id(),
                'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
                'comment' => $comment,
            ],
            '__review_status' => 'pending',
            '__key_columns' => ['c_personid'],
        ]);

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_PROPOSAL_UPDATE,
            'BIOG_MAIN',
            CompositePrimaryKey::buildStoredResourceId(['c_personid' => $personId]),
            $resourceData,
            $original->toArray()
        );

        return response()->json([
            'ok' => true,
            'resource' => 'basicinformation',
            'mode' => 'proposal',
            'operation' => 'update',
            'result' => [
                'pk' => ['c_personid' => $personId],
                'updated_fields' => $updatedFields,
                'status' => 'proposal_updated',
                'operation_id' => $operation?->id,
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
        $payload = BiogMainRepository::nullifyEmptyForeignKeys($payload);

        return [$payload, array_keys($allowedChanges)];
    }

    protected function prepareProposalPayload(array $payload): array {
        $payload['c_name_chn'] = ($payload['c_surname_chn'] ?? '').($payload['c_mingzi_chn'] ?? '');
        $payload['c_name'] = trim(($payload['c_surname'] ?? '').' '.($payload['c_mingzi'] ?? ''));
        $payload['c_name_proper'] = trim(($payload['c_mingzi_proper'] ?? '').' '.($payload['c_surname_proper'] ?? ''));
        $payload['c_name_rm'] = trim(($payload['c_mingzi_rm'] ?? '').' '.($payload['c_surname_rm'] ?? ''));

        $payload = BracketNormalizer::normalizeBiogMain($payload);

        $female = $payload['c_female'] ?? null;
        $payload['c_female'] = ($female === null || $female === '' || $female === 'NULL')
            ? null
            : (int) $female;
        $payload['c_by_intercalary'] = (int) ($payload['c_by_intercalary'] ?? 0);
        $payload['c_dy_intercalary'] = (int) ($payload['c_dy_intercalary'] ?? 0);
        $payload = BiogMainRepository::nullifyEmptyForeignKeys($payload);

        return (new ToolsRepository())->timestamp($payload);
    }

    protected function validationRules(bool $isProposal): array {
        $requestTemplate = new BasicInformationRequest();
        $rules = $requestTemplate->rules();

        if ($isProposal) {
            unset($rules['c_mingzi_chn'], $rules['c_mingzi']);
        }

        return $rules;
    }

    protected function validationMessages(): array {
        return (new BasicInformationRequest())->messages();
    }
}
