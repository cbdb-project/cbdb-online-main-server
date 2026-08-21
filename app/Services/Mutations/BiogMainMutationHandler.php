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

        $validator = Validator::make($merged, $this->validationRules($original), $this->validationMessages());
        if ($validator->fails()) {
            return $this->errorResponse('參數校驗失敗', 422, $validator->errors()->toArray());
        }

        $proxy = Request::create('/api/v2/mutate', 'PATCH', $merged);
        if (isset($meta['comment']) && is_string($meta['comment']) && trim($meta['comment']) !== '') {
            $proxy->request->set('__proposal_comment', trim($meta['comment']));
        }

        try {
            $result = $this->biogMainRepository->updateById($proxy, $personId, $updatedFields);
        } catch (\Throwable $e) {
            return $this->errorResponse('更新失敗：'.$e->getMessage(), 500);
        }

        if (($result['no_changes'] ?? false) === true) {
            // 使用者輸入被落地替換歸一成與現值相同時，422 也要帶 notices，
            // 否則「未偵測到任何修改內容」看起來毫無道理（對齊子資源 handler）。
            return CharVariantMapService::withNotices(
                $this->errorResponse('未偵測到任何修改內容', 422, [
                    'changes' => ['no_effective_changes'],
                ]),
                $result['variant_replaced'] ?? []
            );
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

    protected function handleProposalUpdate(int $personId, array $updatedFields, array $merged, BiogMain $original, array $meta): JsonResponse {
        ['payload' => $proposalData, 'replaced' => $variantReplaced] = $this->prepareProposalPayload($merged, $updatedFields);
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
     * @param array<int,string>|null $variantFields 只對這些欄位做落地替換；null＝整列。
     * @return array{payload: array, replaced: array<string,string>}
     */
    protected function prepareProposalPayload(array $payload, ?array $variantFields = null): array {
        // 異體字落地替換（姓名欄 strict、其餘文本欄 lenient，由 VariantReplaceScope
        // 按「表.欄位」決定）。
        //
        // 這裡**不能**只改 BiogMainRepository 就算了：提案 payload 不經 repository，
        // 若漏掉此處，非姓名文本欄在審核畫面看到的字形會與核准後落庫的不一致。
        //
        // $payload 是「原列 ∪ changes」的整列，所以替換範圍要收在「本次實際變更的欄」——
        // 否則審核者會在 diff 裡看到提案人根本沒改過的欄位被歸一（D6 不做回溯校正）。
        $variantScope = $variantFields === null
            ? $payload
            : array_intersect_key($payload, array_flip($variantFields));
        $variantResult = CharVariantMapService::replaceRow($variantScope, 'BIOG_MAIN');
        $payload = array_replace($payload, $variantResult['data']);
        $variantReplaced = $variantResult['replaced'];

        // 組 c_name_chn 一律讀**替換後**的 $payload（維持 c_name_chn === 姓.名 的 invariant）；
        // 兩個分欄都空時不重組，否則沒有明確姓氏的歷史人物（分欄 NULL、c_name_chn 存完整
        // 姓名）會在任何一次更新提案裡被清成空字串（與 BiogMainRepository::updateById()
        // 及 store() 的保護一致）。
        $payload['c_surname_chn'] = (string) ($payload['c_surname_chn'] ?? '');
        $payload['c_mingzi_chn'] = (string) ($payload['c_mingzi_chn'] ?? '');
        if ($payload['c_surname_chn'] !== '' || $payload['c_mingzi_chn'] !== '') {
            $payload['c_name_chn'] = $payload['c_surname_chn'].$payload['c_mingzi_chn'];
        }
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
