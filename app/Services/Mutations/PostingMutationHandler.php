<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\OfficePostingRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostingMutationHandler extends AbstractPersonSubresourceMutationHandler {
    /**
     * 暫存本次 update 的地址意圖（從 changes 抽出的 c_addr/c_addr_cleared）。
     * null = 未修改地址（保留現有）；[] = 明確清空；[ids] = 明確列表。
     * 於 handle() 設定、afterDirectUpdate()/proposalAuxiliaryPayload() 取用、finally 清除。
     */
    private ?array $pendingIncomingAddr = null;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    /**
     * 覆寫：先把地址欄位（c_addr 多值、c_addr_cleared 清空旗標）從 changes 抽出
     * （它們不屬 POSTED_TO_OFFICE_DATA 白名單，否則父類會以「不允許欄位」422），
     * 再委派父類處理官名欄位；官名 direct 更新成功後於同一交易內由 afterDirectUpdate 同步地址。
     * 僅改地址（無官名欄變更）時走獨立地址路徑（父類會因 changes 空而 422）。
     */
    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $hasAddrKey = array_key_exists('c_addr', $changes);
        $rawAddr = $hasAddrKey ? $changes['c_addr'] : null;
        $addrCleared = (string) ($changes['c_addr_cleared'] ?? '');
        unset($changes['c_addr'], $changes['c_addr_cleared']);

        // incomingAddr 語義對齊 legacy officeUpdateById / proposalStore。
        $this->pendingIncomingAddr = $hasAddrKey
            ? array_values((array) $rawAddr)
            : ($addrCleared === '1' ? [] : null);

        try {
            // 僅改地址（無任何官名欄位變更）：父類會因 changes 空而 422，故獨立處理。
            // 這條路徑不經 parent::handle()，所以自己重置替換紀錄（它只寫數值 addr id，
            // 沒有文本欄要替換，但不重置會把上一筆 mutate 的通知帶進來）。
            if ($this->pendingIncomingAddr !== null && $changes === []) {
                $this->resetVariantReplaced();

                return $mode === 'proposal'
                    ? $this->handleAddressOnlyProposal($personId, $targetPk, $meta)
                    : $this->handleAddressOnlyDirect($personId, $targetPk);
            }

            return parent::handle($resource, $mode, $operation, $personId, $targetPk, $changes, $meta);
        } catch (ValidationException $e) {
            // syncPostingAddresses 在改 c_office_id 撞主鍵時拋 ValidationException（地址衝突）。
            $errors = $e->errors();
            $msg = collect($errors)->flatten()->first() ?? '地址資料衝突';

            // 例外會穿過 parent::handle() 裡的 withVariantNotices()，所以這條 409 要自己補
            // 通知——使用者的字被正規化後才撞到衝突，看不到通知會覺得 409 毫無道理。
            return $this->withVariantNotices(
                $this->errorResponse($msg, 409, $errors !== [] ? $errors : ['c_office_id' => ['conflict']])
            );
        } finally {
            $this->pendingIncomingAddr = null;
        }
    }

    /** direct 官名更新成功後，於同一交易內同步地址副表（含改 c_office_id 時的地址遷移）。 */
    protected function afterDirectUpdate(int $personId, array $targetPk, array $updateData, array $newArray, ?Operation $operation): void {
        if ($this->pendingIncomingAddr === null) {
            return;
        }

        $postingId = (int) ($targetPk['c_posting_id'] ?? $newArray['c_posting_id'] ?? 0);
        $previousOfficeId = (int) ($targetPk['c_office_id'] ?? 0);
        $currentOfficeId = (int) ($updateData['c_office_id'] ?? $previousOfficeId);
        $existing = $this->existingAddrIds($personId, $postingId);

        app(OfficePostingRepository::class)->syncPostingAddresses(
            $personId,
            $postingId,
            $previousOfficeId,
            $currentOfficeId,
            $this->pendingIncomingAddr,
            $existing,
            (string) (Auth::user()->name ?? Auth::id()),
            Carbon::now(),
            $operation,
            $personId
        );
    }

    /** proposal 更新時把地址寫入 __proposal_aux（對齊 legacy，核准時由 applyOfficeProposal 套用）。 */
    protected function proposalAuxiliaryPayload(): array {
        if ($this->pendingIncomingAddr === null) {
            return [];
        }

        return $this->pendingIncomingAddr === []
            ? ['c_addr_cleared' => '1']
            : ['c_addr' => $this->pendingIncomingAddr];
    }

    /** 僅改地址（無官名欄）的 direct 路徑：自行授權/驗證後於交易內同步地址。 */
    private function handleAddressOnlyDirect(int $personId, array $targetPk): JsonResponse {
        if ($authError = $this->authorizeDirect()) {
            return $authError;
        }
        $original = $this->findPostingRow($targetPk);
        if (!$original) {
            return $this->errorResponse('POSTED_TO_OFFICE_DATA 記錄不存在', 404);
        }
        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        $officeId = (int) $original->c_office_id;
        $postingId = (int) $original->c_posting_id;
        $existing = $this->existingAddrIds($personId, $postingId);

        if (!$this->addressesChanged($this->pendingIncomingAddr ?? [], $existing)) {
            return $this->errorResponse('未偵測到任何修改內容', 422, ['changes' => ['no_effective_changes']]);
        }

        DB::transaction(function () use ($personId, $postingId, $officeId, $existing) {
            app(OfficePostingRepository::class)->syncPostingAddresses(
                $personId,
                $postingId,
                $officeId,
                $officeId,
                $this->pendingIncomingAddr,
                $existing,
                (string) (Auth::user()->name ?? Auth::id()),
                Carbon::now(),
                null,
                $personId
            );
        });

        return response()->json([
            'ok' => true,
            'resource' => 'postings',
            'mode' => 'direct',
            'operation' => 'update',
            'result' => [
                'pk' => ['c_office_id' => $officeId, 'c_posting_id' => $postingId],
                'updated_fields' => ['c_addr'],
            ],
        ]);
    }

    /** 僅改地址（無官名欄）的 proposal 路徑：寫 TYPE_PROPOSAL_UPDATE，地址存 __proposal_aux。 */
    private function handleAddressOnlyProposal(int $personId, array $targetPk, array $meta): JsonResponse {
        if ($authError = $this->authorizeProposal()) {
            return $authError;
        }
        $original = $this->findPostingRow($targetPk);
        if (!$original) {
            return $this->errorResponse('POSTED_TO_OFFICE_DATA 記錄不存在', 404);
        }
        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        $officeId = (int) $original->c_office_id;
        $postingId = (int) $original->c_posting_id;
        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_office_id' => $officeId,
            'c_posting_id' => $postingId,
        ]);

        if ($this->operationRepository->hasPendingUpdateProposal($this->tableName(), $resourceId)) {
            return $this->errorResponse('相同主鍵已有待審核提案', 409, ['target.pk' => ['pending_proposal_exists']]);
        }

        $originalArray = $this->auditLogService->normalizeRow($original);
        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

        $proposalData = array_merge($originalArray, [
            '__proposal_aux' => $this->proposalAuxiliaryPayload(),
            '__proposal_meta' => [
                'action' => 'update',
                'resource_type' => $this->resourceName(),
                'table' => $this->tableName(),
                'display_name' => $this->displayName(),
                'submitted_by' => Auth::user()->name ?? Auth::id(),
                'submitted_by_id' => Auth::id(),
                'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
                'comment' => $comment,
            ],
            '__review_status' => 'pending',
            '__key_columns' => $this->keyColumns(),
        ]);

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_PROPOSAL_UPDATE,
            $this->tableName(),
            $resourceId,
            $proposalData,
            $originalArray
        );

        return response()->json([
            'ok' => true,
            'resource' => 'postings',
            'mode' => 'proposal',
            'operation' => 'update',
            'result' => [
                'pk' => ['c_office_id' => $officeId, 'c_posting_id' => $postingId],
                'updated_fields' => ['c_addr'],
                'status' => 'proposal_updated',
                'operation_id' => $operation?->id,
            ],
        ]);
    }

    private function findPostingRow(array $targetPk): ?object {
        return DB::table('POSTED_TO_OFFICE_DATA')
            ->where('c_office_id', $targetPk['c_office_id'] ?? null)
            ->where('c_posting_id', $targetPk['c_posting_id'] ?? null)
            ->first();
    }

    /** @return list<int> */
    private function existingAddrIds(int $personId, int $postingId): array {
        return DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $personId)
            ->where('c_posting_id', $postingId)
            ->pluck('c_addr_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** 地址列表是否有實質變更（-999→0 正規化、去重排序後比對集合）。 */
    private function addressesChanged(array $incoming, array $existing): bool {
        $norm = function (array $a): array {
            $a = array_map(fn ($v) => ((int) $v === -999 ? 0 : (int) $v), $a);
            sort($a);

            return array_values(array_unique($a));
        };

        return $norm($incoming) !== $norm($existing);
    }

    protected function resourceName(): string {
        return 'postings';
    }

    protected function tableName(): string {
        return 'POSTED_TO_OFFICE_DATA';
    }

    protected function displayName(): string {
        return '任官';
    }

    protected function resourceAliases(): array {
        return ['postings', 'posting', 'offices', 'posted_to_office_data'];
    }

    protected function keyColumns(): array {
        return ['c_office_id', 'c_posting_id'];
    }

    /** PK 不含 c_personid，跳過 PK 中的 person_id 檢查 */
    protected function validatePersonIdInPk(int $personId, array $targetPk): ?JsonResponse {
        return null;
    }

    /** 透過 row 的 c_personid 欄位驗證 person_id 一致性 */
    protected function validatePersonIdInRow(int $personId, object $original): ?JsonResponse {
        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        return null;
    }

    protected function allowedFields(): array {
        return [
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
    }

    protected function preprocessUpdateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, ['c_office_id', 'c_source']);
        // sentinel 完全幂等：c_source（legacy 哨兵 0=Unknown）的 null/'' 也→0（同下方 c_appt_code 範式；normalizeSentinelValues 只做 -999）。
        $data = $this->normalizeEmptyCodeFields($data, ['c_source']);

        if (array_key_exists('c_fy_intercalary', $data)) {
            $data['c_fy_intercalary'] = (int) ($data['c_fy_intercalary'] ?? 0);
        }
        if (array_key_exists('c_ly_intercalary', $data)) {
            $data['c_ly_intercalary'] = (int) ($data['c_ly_intercalary'] ?? 0);
        }

        // c_appt_code 欄位為 NOT NULL；null／空字串／-999 統一回填 0（APPOINTMENT_CODES 的「未詳」哨兵）
        if (array_key_exists('c_appt_code', $data)) {
            $value = $data['c_appt_code'];
            if ($value === null || $value === '' || $value == -999) {
                $data['c_appt_code'] = 0;
            }
        }

        return $data;
    }
}
