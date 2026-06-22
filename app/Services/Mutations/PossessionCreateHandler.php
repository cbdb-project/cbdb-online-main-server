<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * POSSESSION_DATA create handler（API v2）
 *
 * POSSESSION_DATA 與多數子資源不同：
 * - 主鍵 c_possession_record_id 為伺服器配發的 surrogate id（max+1），client 無從於 target.pk 提供。
 * - 新增時連動寫入 POSSESSION_ADDR 副表（地址多筆）。
 *
 * 故本 handler 不沿用 AbstractPersonSubresourceCreateHandler（單表、client 提供完整 PK），
 * 改為委派既有且具回歸保護的 BiogMainRepository::possessionStoreById()，重用 id 配發 +
 * 副表寫入 + operation + audit_log，再包上 API v2 的授權與回應契約。
 *
 * 輸入契約：target.pk 可為空 {}；欄位走 changes（含 c_addr_id 陣列，對應地址副表）。
 *
 * proposal 模式：與 legacy BasicInformationProposalController::proposalStore('possessions') 等價，
 * 寫 TYPE_PROPOSAL_CREATE operation（白名單化 changes、地址陣列存入 __proposal_aux 的 c_addr_id），
 * 不寫主表；核准時由 OperationsProposalController::applyPossessionProposal 委派 possessionStoreById
 * 配發 c_possession_record_id 並寫主表 + POSSESSION_ADDR。
 */
class PossessionCreateHandler extends AbstractMutationHandler {
    protected BiogMainRepository $biogMainRepository;
    protected AuditLogService $auditLogService;
    protected OperationRepository $operationRepository;

    /** POSSESSION_DATA 可寫欄位白名單（與 PossessionMutationHandler::allowedFields 對齊；c_addr_id 為副表用） */
    private const ALLOWED_FIELDS = [
        'c_sequence',
        'c_possession_act_code',
        'c_possession_desc',
        'c_possession_desc_chn',
        'c_quantity',
        'c_measure_code',
        'c_possession_yr',
        'c_possession_nh_code',
        'c_possession_nh_yr',
        'c_possession_yr_range',
        'c_source',
        'c_pages',
        'c_notes',
    ];

    public function __construct(
        BiogMainRepository $biogMainRepository,
        AuditLogService $auditLogService,
        OperationRepository $operationRepository
    ) {
        $this->biogMainRepository = $biogMainRepository;
        $this->auditLogService = $auditLogService;
        $this->operationRepository = $operationRepository;
    }

    public function supports(string $resource, string $mode, string $operation): bool {
        return in_array($resource, ['possessions', 'possession', 'possession_data'], true)
            && in_array($mode, ['direct', 'proposal'], true)
            && $operation === 'create';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authorizationError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        if ((int) $personId === 0) {
            return $this->errorResponse('「未詳」人物不能新增財產記錄。', 422, ['person_id' => ['invalid']]);
        }

        // 白名單化：只保留允許欄位 + c_addr_id（地址副表），其餘一律丟棄，避免任意欄位寫入。
        $writable = array_intersect_key($changes, array_flip(self::ALLOWED_FIELDS));
        $addr = $changes['c_addr_id'] ?? [];
        if (!is_array($addr)) {
            $addr = [$addr];
        }

        if ($mode === 'proposal') {
            $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

            return $this->handleProposal($personId, $writable, $addr, $comment);
        }

        $request = new Request();
        $request->merge($writable);
        $request->merge(['c_addr_id' => $addr]);

        $newId = $this->biogMainRepository->possessionStoreById($request, $personId);

        $row = DB::table('POSSESSION_DATA')->where('c_possession_record_id', $newId)->first();

        return response()->json([
            'ok' => true,
            'resource' => 'possessions',
            'mode' => 'direct',
            'operation' => 'create',
            'result' => [
                'pk' => ['c_possession_record_id' => $newId],
                'row' => $row ? $this->auditLogService->normalizeRow($row) : null,
            ],
        ]);
    }

    /**
     * 財產新增提案：寫 TYPE_PROPOSAL_CREATE operation，不寫主表。
     * 地址陣列存入 __proposal_aux['c_addr_id']，核准時由 applyPossessionProposal 委派
     * possessionStoreById 配發 c_possession_record_id（交易內 max+1）並寫主表 + POSSESSION_ADDR。
     *
     * c_possession_record_id 為單一數值 surrogate PK，每筆新增皆為獨立記錄、無主鍵重複語意，
     * 故沿用 legacy hasActiveProposalConflict 對單鍵表回 false 的慣例，不做待審重複防呆。
     * resource_id 於提交時留空（核准時由 updateProposalStatus 回填實際配發的 id）。
     */
    protected function handleProposal(int $personId, array $writable, array $addr, string $comment): JsonResponse {
        $payload = $writable;
        $payload['c_personid'] = $personId;

        $proposalData = array_merge($payload, [
            '__proposal_aux' => ['c_addr_id' => array_values($addr)],
            '__proposal_meta' => [
                'action' => 'create',
                'resource_type' => 'possessions',
                'table' => 'POSSESSION_DATA',
                'display_name' => '所有物',
                'submitted_by' => Auth::user()->name ?? Auth::id(),
                'submitted_by_id' => Auth::id(),
                'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
                'comment' => $comment,
            ],
            '__review_status' => 'pending',
            '__key_columns' => ['c_possession_record_id'],
        ]);

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_PROPOSAL_CREATE,
            'POSSESSION_DATA',
            '',
            $proposalData,
            []
        );

        return response()->json([
            'ok' => true,
            'resource' => 'possessions',
            'mode' => 'proposal',
            'operation' => 'create',
            'result' => [
                'status' => 'proposal_created',
                'operation_id' => $operation?->id,
            ],
        ]);
    }
}
