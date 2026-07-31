<?php

namespace App\Services\Mutations;

use App\Http\Requests\BasicInformationRequest;
use App\Models\BiogMain;
use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Services\BracketNormalizer;
use App\Services\CharVariantMapService;
use App\Services\NameSearchIndexService;
use App\Services\ProposalRevisionService;
use App\Support\CompositePrimaryKey;
use App\Support\PinyinUmlaut;
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
    protected ProposalRevisionService $proposalRevisionService;

    public function __construct(
        BiogMainRepository $biogMainRepository,
        NameSearchIndexService $nameSearchIndexService,
        OperationRepository $operationRepository,
        ProposalRevisionService $proposalRevisionService
    ) {
        $this->biogMainRepository = $biogMainRepository;
        $this->nameSearchIndexService = $nameSearchIndexService;
        $this->operationRepository = $operationRepository;
        $this->proposalRevisionService = $proposalRevisionService;
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

        $validator = Validator::make($merged, $this->validationRules($original), $this->validationMessages());
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

        $response = [
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
        ];

        $notices = CharVariantMapService::buildNotices($result['variant_replaced'] ?? []);
        if ($notices !== []) {
            $response['notices'] = $notices;
        }

        return response()->json($response);
    }

    /**
     * 見 docs/PROPOSAL_REVISION_HASH_DESIGN.md：BIOG_MAIN proposal update 第一階段
     * 強制要求 base_revision，於提交當下重算 current_revision 比對，避免使用者基於
     * 舊版本提交的提案在核准時盲目覆寫掉期間已發生的變更。
     */
    protected function handleProposalUpdate(int $personId, array $updatedFields, array $merged, BiogMain $original, array $meta): JsonResponse {
        $baseRevision = is_string($meta['base_revision'] ?? null) ? trim($meta['base_revision']) : '';
        if ($baseRevision === '') {
            return $this->errorResponse('缺少 base_revision，請重新整理後再提交提案', 422, [
                'base_revision' => ['required'],
            ]);
        }

        $currentRevision = $this->proposalRevisionService->hash('BIOG_MAIN', $original->toArray());
        if (!hash_equals($currentRevision, $baseRevision)) {
            return $this->errorResponse('資料已被更新，請重新載入後再提交提案', 409, [
                'base_revision' => ['stale'],
            ]);
        }

        ['payload' => $proposalData, 'replaced' => $variantReplaced] = $this->prepareProposalPayload($merged);
        $validator = Validator::make($proposalData, $this->validationRules($original), $this->validationMessages());
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
                'base_revision' => $currentRevision,
                'revision_algo' => ProposalRevisionService::ALGO,
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

        $response = [
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
        ];

        $notices = CharVariantMapService::buildNotices($variantReplaced);
        if ($notices !== []) {
            $response['notices'] = $notices;
        }

        return response()->json($response);
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

    /**
     * @return array{payload: array, replaced: array<string,string>}
     */
    protected function prepareProposalPayload(array $payload): array {
        // 異體字落地替換（嚴格模式）：先替換姓／名分欄，再組出 c_name_chn，維持
        // c_name_chn === c_surname_chn.c_mingzi_chn 的既有 invariant（見
        // docs/CHAR_VARIANT_MAP_CALL_SITE_WIRING_PLAN.md 待決事項 1）。
        $surnameReplaced = CharVariantMapService::replaceStrict((string) ($payload['c_surname_chn'] ?? ''));
        $mingziReplaced = CharVariantMapService::replaceStrict((string) ($payload['c_mingzi_chn'] ?? ''));
        $variantReplaced = array_merge($surnameReplaced['replaced'], $mingziReplaced['replaced']);
        $payload['c_surname_chn'] = $surnameReplaced['text'];
        $payload['c_mingzi_chn'] = $mingziReplaced['text'];

        $payload['c_name_chn'] = $surnameReplaced['text'].$mingziReplaced['text'];
        $payload['c_name'] = trim(($payload['c_surname'] ?? '').' '.($payload['c_mingzi'] ?? ''));
        $payload['c_name_proper'] = trim(($payload['c_mingzi_proper'] ?? '').' '.($payload['c_surname_proper'] ?? ''));
        $payload['c_name_rm'] = trim(($payload['c_mingzi_rm'] ?? '').' '.($payload['c_surname_rm'] ?? ''));

        $payload = BracketNormalizer::normalizeBiogMain($payload);

        // 保存時拼音 v→ü 歸一化（Tier 1；提案於提交時歸一化，核准逐字套用故此處為必要且充分）
        $payload = PinyinUmlaut::normalizeFields($payload, PinyinUmlaut::BIOG_MAIN_PINYIN_V_FIELDS);

        $female = $payload['c_female'] ?? null;
        $payload['c_female'] = ($female === null || $female === '' || $female === 'NULL')
            ? null
            : (int) $female;
        $payload['c_by_intercalary'] = (int) ($payload['c_by_intercalary'] ?? 0);
        $payload['c_dy_intercalary'] = (int) ($payload['c_dy_intercalary'] ?? 0);
        $payload = BiogMainRepository::nullifyEmptyForeignKeys($payload);

        return ['payload' => (new ToolsRepository())->timestamp($payload), 'replaced' => $variantReplaced];
    }

    protected function validationRules(BiogMain $original): array {
        $requestTemplate = new BasicInformationRequest();
        $rules = $requestTemplate->rules();

        // 「不可清空」語義（direct 與 proposal 一致）：名（中）／拼音名原值非空才掛 required，
        // 擋下顯式清空；原本即為空的人物可維持空、照常編輯其他欄位。兩欄獨立判斷。
        foreach (['c_mingzi_chn', 'c_mingzi'] as $field) {
            if (trim((string) ($original->{$field} ?? '')) === '') {
                unset($rules[$field]);
            }
        }

        return $rules;
    }

    protected function validationMessages(): array {
        return (new BasicInformationRequest())->messages();
    }
}
