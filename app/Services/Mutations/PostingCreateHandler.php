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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
    use \App\Services\Mutations\Concerns\AppliesVariantReplacement;
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
        'c_fy_month',
        'c_fy_day',
        'c_fy_day_gz',
        'c_lastyear',
        'c_ly_nh_code',
        'c_ly_nh_year',
        'c_ly_range',
        'c_ly_intercalary',
        'c_ly_month',
        'c_ly_day',
        'c_ly_day_gz',
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
        // 異體字落地替換的通知統一在此掛上（成功與 409／422 皆帶）。
        $this->resetVariantReplaced();

        return $this->withVariantNotices(
            $this->handleAfterVariantReset($resource, $mode, $operation, $personId, $targetPk, $changes, $meta)
        );
    }

    /** handle() 的原始流程；異體字通知由 handle() 統一掛上。 */
    protected function handleAfterVariantReset(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
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

        // #71：非 PK 碼欄 c_source 完全幂等（null/''/-999/**缺鍵**→'0'），對齊已修的 PostingMutationHandler；
        // 缺鍵也補 0（CREATE 語義：未填＝哨兵 0，與 legacy 表單空→0 一致）。direct 與 proposal 皆套用。
        $cs = $writable['c_source'] ?? null;
        if ($cs === null || $cs === '' || (int) $cs === -999) {
            $writable['c_source'] = '0';
        }

        // 異體字落地替換（型別驅動；POSTED_TO_OFFICE_DATA 全文本欄走 lenient）。放在白名單化與
        // 哨兵正規化之後、direct／proposal 分派之前；PK 兩欄皆為數值，無文本型 PK 成員。
        $writable = $this->applyVariantReplacement($writable, 'POSTED_TO_OFFICE_DATA');

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

        // AI 自動填：使用者實際提交後回寫 ai_fill_logs（見 recordAiFillSubmission）。
        $this->recordAiFillSubmission($meta, $personId, $writable, $addr);

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

    /**
     * AI 自動填任官：使用者實際提交（direct create）後，把提交的表單資料回寫 ai_fill_logs
     * （user_submitted + submitted_at），使 /admin/ai-fill-logs 正確顯示「已提交」。
     *
     * 背景：舊 Blade offices/store 以 ai_fill_log_id 回寫；React 遷移（2026-06-26 上線）改走
     * v2 mutation 後遺漏此步，導致上線後所有經 AI 自動填的任官日誌一律誤顯示「Not Submitted」，
     * 與使用者是否人工修改過 AI 建議無關（以 log id 連結、不比對欄位值）。
     *
     * 守衛：
     * - log id 由前端經 meta.ai_fill_log_id 傳入；非正整數則略過。
     * - WHERE 以 id + user_id(Auth) + category='posting' + c_personid 四重限定：user_id 防止覆寫
     *   他人日誌，category 防止誤寫非任官日誌，c_personid 確保只回寫「同一人物」的日誌（此處
     *   $personId 為 handler 已知的權威值，非舊路徑那種脆弱的 request 推導，故可安全保留此守衛，
     *   避免把 A 人物的 AI 日誌誤標為在 B 人物存檔時已提交）。
     * - Schema::hasTable 守衛，使無 ai_fill_logs 的既有測試不受影響。
     * - 任何例外只記 warning、不影響主流程（任官已成功寫入）。
     *
     * 註：proposal 模式於核准時才落庫，不在此回寫（另計）。
     */
    protected function recordAiFillSubmission(array $meta, int $personId, array $writable, array $addr): void {
        $logId = $meta['ai_fill_log_id'] ?? null;
        if (!is_numeric($logId) || (int) $logId <= 0) {
            return;
        }

        if (!Schema::hasTable('ai_fill_logs')) {
            return;
        }

        try {
            $submitted = $writable;
            $submitted['c_addr'] = array_values($addr);

            DB::table('ai_fill_logs')
                ->where('id', (int) $logId)
                ->where('user_id', Auth::id())
                ->where('category', 'posting')
                ->where('c_personid', $personId)
                ->update([
                    'user_submitted' => json_encode($submitted, JSON_UNESCAPED_UNICODE),
                    'submitted_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('[AI Fill Log] v2 任官提交回寫失敗: '.$e->getMessage());
        }
    }
}
