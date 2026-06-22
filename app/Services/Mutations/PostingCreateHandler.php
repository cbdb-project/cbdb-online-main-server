<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * POSTED_TO_OFFICE_DATA（任官）create handler（API v2）
 *
 * 任官新增與 possession 類似，PK c_posting_id 為伺服器配發（POSTING_DATA 序列 max+1），
 * 且連動寫入 POSTED_TO_ADDR_DATA 地址副表。client 提供 c_office_id（指定官職）等欄位，
 * 伺服器配發 c_posting_id。
 *
 * 故委派既有具回歸保護的 BiogMainRepository::officeStoreById()（重用序列配發 + 副表 +
 * operation + audit_log），包上 API v2 授權與回應契約。
 *
 * proposal 模式：與 legacy BasicInformationProposalController::proposalStore('offices') 等價，
 * 寫 TYPE_PROPOSAL_CREATE operation（白名單化 changes、地址陣列存入 __proposal_aux 的 c_addr），
 * 不配發 c_posting_id、不寫主表；核准時由 OperationsProposalController::applyOfficeProposal
 * 委派 officeStoreById 配發 c_posting_id 並寫主表 + POSTED_TO_ADDR_DATA。
 *
 * 輸入契約：target.pk 可為空 {}；欄位走 changes（須含 c_office_id；c_addr 為地址陣列副表）。
 */
class PostingCreateHandler extends AbstractMutationHandler {
    protected BiogMainRepository $biogMainRepository;
    protected AuditLogService $auditLogService;
    protected OperationRepository $operationRepository;

    /** POSTED_TO_OFFICE_DATA 可寫欄位白名單（與 PostingMutationHandler::allowedFields 對齊） */
    private const ALLOWED_FIELDS = [
        'c_office_id',
        'c_sequence',
        'c_source',
        'c_pages',
        'c_notes',
        'c_firstyear',
        'c_fy_nh_code',
        'c_fy_nh_year',
        'c_fy_range',
        'c_fy_intercalary',
        'c_lastyear',
        'c_ly_nh_code',
        'c_ly_nh_year',
        'c_ly_range',
        'c_ly_intercalary',
        'c_appt_code',
        'c_assume_office_code',
        // Task 27 補回（皆 POSTED_TO_OFFICE_DATA 真實欄）
        'c_dy',
        'c_inst_code',
        'c_inst_name_code',
        'c_office_category_id',
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
        return in_array($resource, ['postings', 'posting', 'offices', 'posted_to_office_data'], true)
            && in_array($mode, ['direct', 'proposal'], true)
            && $operation === 'create';
    }

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $authorizationError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        if ((int) $personId === 0) {
            return $this->errorResponse('「未詳」人物不能新增任官記錄。', 422, ['person_id' => ['invalid']]);
        }

        // c_office_id 為任官 PK 的必要欄位（指定官職），缺少則無法新增。
        if (!array_key_exists('c_office_id', $changes) || $changes['c_office_id'] === null || $changes['c_office_id'] === '') {
            return $this->errorResponse('缺少 c_office_id', 422, ['changes' => ['c_office_id required']]);
        }

        // 白名單化：只保留允許欄位 + c_addr（地址副表）。
        $writable = array_intersect_key($changes, array_flip(self::ALLOWED_FIELDS));
        // officeStoreById 會對這兩欄做 (int) 轉型，預設補 0 避免未定義鍵警告。
        $writable += ['c_fy_intercalary' => 0, 'c_ly_intercalary' => 0];

        $addr = $changes['c_addr'] ?? [];
        if (!is_array($addr)) {
            $addr = [$addr];
        }

        if ($mode === 'proposal') {
            $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

            return $this->handleProposal($personId, $writable, $addr, $comment);
        }

        $request = new Request();
        $request->merge($writable);
        $request->merge(['c_addr' => $addr]);

        $resourceId = $this->biogMainRepository->officeStoreById($request, $personId);

        $pk = CompositePrimaryKey::parseStoredResourceId($resourceId, 'POSTED_TO_OFFICE_DATA');
        if ($pk === null) {
            return $this->errorResponse('任官新增後主鍵解析失敗', 500, ['resource_id' => [$resourceId]]);
        }

        $row = DB::table('POSTED_TO_OFFICE_DATA')
            ->where('c_office_id', $pk['c_office_id'])
            ->where('c_posting_id', $pk['c_posting_id'])
            ->first();

        return response()->json([
            'ok' => true,
            'resource' => 'postings',
            'mode' => 'direct',
            'operation' => 'create',
            'result' => [
                'pk' => $pk,
                'row' => $row ? $this->auditLogService->normalizeRow($row) : null,
            ],
        ]);
    }

    /**
     * 任官新增提案：寫 TYPE_PROPOSAL_CREATE operation，不配發 c_posting_id、不寫主表。
     * 地址陣列存入 __proposal_aux['c_addr']，核准時由 applyOfficeProposal 委派 officeStoreById
     * 配發 c_posting_id 並寫主表 + POSTED_TO_ADDR_DATA。
     *
     * resource_id 因 c_posting_id 尚未配發，僅以 c_office_id（c_posting_id 留空）建立，
     * 用於同一官職的待審重複提案防呆；核准後 resource_id 不改寫（沿用 office 慣例）。
     */
    protected function handleProposal(int $personId, array $writable, array $addr, string $comment): JsonResponse {
        $payload = $writable;
        $payload['c_personid'] = $personId;

        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_office_id' => $payload['c_office_id'] ?? null,
            'c_posting_id' => null,
        ]);

        if ($this->operationRepository->hasPendingCreateProposal('POSTED_TO_OFFICE_DATA', $resourceId)) {
            return $this->errorResponse('相同官職已有待審核的新增提案', 409, [
                'changes' => ['pending_proposal_exists'],
            ]);
        }

        $proposalData = array_merge($payload, [
            '__proposal_aux' => ['c_addr' => array_values($addr)],
            '__proposal_meta' => [
                'action' => 'create',
                'resource_type' => 'offices',
                'table' => 'POSTED_TO_OFFICE_DATA',
                'display_name' => '官名',
                'submitted_by' => Auth::user()->name ?? Auth::id(),
                'submitted_by_id' => Auth::id(),
                'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
                'comment' => $comment,
            ],
            '__review_status' => 'pending',
            '__key_columns' => ['c_office_id', 'c_posting_id'],
        ]);

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_PROPOSAL_CREATE,
            'POSTED_TO_OFFICE_DATA',
            $resourceId,
            $proposalData,
            []
        );

        return response()->json([
            'ok' => true,
            'resource' => 'postings',
            'mode' => 'proposal',
            'operation' => 'create',
            'result' => [
                'status' => 'proposal_created',
                'operation_id' => $operation?->id,
            ],
        ]);
    }
}
