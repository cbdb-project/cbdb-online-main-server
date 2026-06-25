<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 人物子資源 create handler 共用基底類
 *
 * 抽象出 repeated-form create 的共通邏輯：
 * - 驗證 composite PK
 * - 驗證 person_id 一致性
 * - 驗證白名單欄位
 * - 檢查目標 PK 是否已存在
 * - direct 新增（含 transaction + operation + audit_log）
 * - proposal 寫 operation
 * - 統一 response shape
 */
abstract class AbstractPersonSubresourceCreateHandler extends AbstractMutationHandler {
    protected OperationRepository $operationRepository;
    protected AuditLogService $auditLogService;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        $this->operationRepository = $operationRepository;
        $this->auditLogService = $auditLogService;
    }

    // ── 子類必須實作 ─────────────────────────────────────────

    /** 資源名稱（回傳用），例如 'altnames' */
    abstract protected function resourceName(): string;

    /** 資料表名稱，例如 'ALTNAME_DATA' */
    abstract protected function tableName(): string;

    /** 顯示名稱（proposal meta 用），例如 '別名' */
    abstract protected function displayName(): string;

    /** 可接受的 resource alias 列表（含主名） */
    abstract protected function resourceAliases(): array;

    /** 允許寫入的欄位白名單（含 key 與非 key 欄位） */
    abstract protected function allowedFields(): array;

    /** __key_columns（proposal meta 用），通常與 CompositePrimaryKey SCHEMAS 一致 */
    abstract protected function keyColumns(): array;

    /** person_id 在主鍵中的欄位名，預設 'c_personid' */
    protected function personIdColumn(): string {
        return 'c_personid';
    }

    // ── supports ─────────────────────────────────────────────

    public function supports(string $resource, string $mode, string $operation): bool {
        return in_array($resource, $this->resourceAliases(), true)
            && in_array($mode, ['direct', 'proposal'], true)
            && $operation === 'create';
    }

    // ── handle ───────────────────────────────────────────────

    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        // 1. 授權
        $authorizationError = $mode === 'proposal' ? $this->authorizeProposal() : $this->authorizeDirect();
        if ($authorizationError) {
            return $authorizationError;
        }

        // 2. 驗證 PK 格式
        try {
            CompositePrimaryKey::validateOrFail($targetPk, $this->tableName());
        } catch (\Throwable $e) {
            return $this->errorResponse('主鍵格式不正確', 422, ['pk' => [$e->getMessage()]]);
        }

        // 3. person_id 與 PK 一致性
        $pkValidationError = $this->validatePersonIdInPk($personId, $targetPk);
        if ($pkValidationError) {
            return $pkValidationError;
        }

        // 4. 拒絕白名單外的欄位（changes）
        if (!empty($changes)) {
            $disallowedFields = array_diff(array_keys($changes), $this->allowedFields());
            if (!empty($disallowedFields)) {
                return $this->errorResponse('包含不允許的欄位', 422, [
                    'changes' => ['disallowed_fields: ' . implode(', ', $disallowedFields)],
                ]);
            }
        }

        // 5. 組出完整 row：以 PK 為基底，合併 changes
        $rowData = array_merge($targetPk, $changes ?? []);

        // 6. 只保留白名單 + key 欄位
        $allowedKeys = array_unique(array_merge($this->allowedFields(), $this->keyColumns()));
        $rowData = array_intersect_key($rowData, array_flip($allowedKeys));

        // 7. 欄位值驗證（子類可覆寫）
        $validationErrors = $this->validateFields($rowData);
        if (!empty($validationErrors)) {
            return $this->errorResponse('參數校驗失敗', 422, $validationErrors);
        }

        // 8. 前處理（子類可覆寫，如 -999 → 0 轉換）
        $rowData = $this->preprocessCreateData($rowData);

        // 8.1 從正規化後的 rowData 提取實際 PK（前處理可能改變 key 值）
        $actualPk = $this->extractPkFromRow($rowData);

        // 9. 檢查目標 PK 是否已存在（使用正規化後的 PK）
        $existing = $this->findExistingRow($actualPk);
        if ($existing) {
            return $this->errorResponse('目標主鍵已存在', 409, ['target.pk' => ['conflict']]);
        }

        $comment = is_string($meta['comment'] ?? null) ? trim($meta['comment']) : '';

        // 10. 分派到 direct / proposal
        if ($mode === 'proposal') {
            return $this->handleProposal($personId, $actualPk, $rowData, $comment);
        }

        return $this->handleDirect($personId, $actualPk, $rowData, $comment);
    }

    /**
     * 驗證 person_id 與 PK 中的 c_personid 一致性
     */
    protected function validatePersonIdInPk(int $personId, array $targetPk): ?JsonResponse {
        $pkPersonId = $targetPk[$this->personIdColumn()] ?? null;
        if ((string) $pkPersonId !== (string) $personId) {
            return $this->errorResponse('person_id 與 target.pk.' . $this->personIdColumn() . ' 不一致', 422, [
                'person_id' => ['mismatch'],
            ]);
        }

        return null;
    }

    // ── Direct Create ────────────────────────────────────────

    protected function handleDirect(int $personId, array $actualPk, array $rowData, string $comment): JsonResponse {
        $operationId = (string) Str::ulid();
        /** @var Operation|null $operation */
        $operation = null;
        $insertedArray = [];

        // 填充 c_created_by / c_created_date
        $toolsRepo = app(ToolsRepository::class);
        $rowData = $toolsRepo->timestamp($rowData, true);

        try {
            DB::transaction(function () use ($personId, $actualPk, $rowData, $comment, $operationId, &$operation, &$insertedArray) {
                // 寫入資料表
                $this->performInsert($rowData);

                // 讀回新增的資料（使用正規化後的 PK）
                $insertedRow = $this->findExistingRow($actualPk);
                $insertedArray = $this->auditLogService->normalizeRow($insertedRow);

                $resourceId = CompositePrimaryKey::buildStoredResourceId($actualPk);

                // 寫 operation
                $resourceData = array_merge($insertedArray, ['__operation_id' => $operationId]);
                if ($comment !== '') {
                    $resourceData['__note'] = $comment;
                }

                $operation = $this->operationRepository->store(
                    Auth::id(),
                    $personId,
                    Operation::TYPE_CREATE,
                    $this->tableName(),
                    $resourceId,
                    $resourceData,
                    []
                );

                // 寫 audit_log（使用正規化後的 PK）
                $this->auditLogService->write(
                    $this->tableName(),
                    'INSERT',
                    $actualPk,
                    null,
                    $insertedArray,
                    'user',
                    (string) Auth::id(),
                    $operation ? (string) $operation->id : null
                );

                // 子類在同一交易內的後續處理（例如社會關係/親屬寫互逆鏡像列），保證原子性
                $this->afterDirectInsert($personId, $actualPk, $rowData, $insertedArray, $operation);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->errorResponse('目標主鍵已存在', 409, ['target.pk' => ['conflict']]);
            }

            throw $e;
        } catch (MirrorConflictException $e) {
            // #66：建立反向鏡像時，對面對應列已有不同內容 → 整筆交易已回滾（含正向列），回 409 + 衝突明細 + 對面鏡像 PK，
            // 供前端彈警告 + 可點連結跳對面 edit-v2 + 提供「強制覆寫」(meta.force) 重送。
            return $this->errorResponse($e->getMessage(), 409, [
                'mirror_conflict' => [
                    'table' => $e->mirrorTable,
                    'pk' => $e->mirrorPk,
                    'fields' => $e->conflicts,
                ],
            ]);
        } catch (MirrorIntegrityException $e) {
            // #70：鏡像同步資料完整性 fail-closed（無權威反向碼可收斂）→ 整筆已回滾，回結構化 422，
            // 而非裸 RuntimeException 漏成 500。防禦性：現行 create 在反向碼為哨兵 0 時就走無條件 insert、不進 sync，
            // 故 sync 內「缺權威反向碼」分支於 create 不可達；保留此 catch 以防 sync 日後演進拋出，不致漏成 500。
            return $this->errorResponse($e->getMessage(), 422, ['mirror_integrity' => ['fail_closed']]);
        } catch (MirrorSuspectedException $e) {
            // #70（create 路徑）：建立反向鏡像時，對面已有疑似同一關係的漂移列（碼∉合法反向集，非嚴格命中）→ 整筆已回滾，
            // 回 409 + 疑似列 PK 清單 + 權威反向碼，供前端彈「對面有 N 條疑似」警告 + 跳對面連結 + 強制收斂（meta.force）。
            return $this->errorResponse($e->getMessage(), 409, [
                'mirror_suspected' => [
                    'table' => $e->mirrorTable,
                    'candidates' => $e->candidates,
                    'authoritative_code' => $e->authoritativeCode,
                    'count' => $e->count(),
                ],
            ]);
        }

        return response()->json([
            'ok' => true,
            'resource' => $this->resourceName(),
            'mode' => 'direct',
            'operation' => 'create',
            'result' => [
                'pk' => $actualPk,
                'operation_id' => $operation?->id,
                'row' => $insertedArray,
            ],
        ]);
    }

    // ── Proposal Create ──────────────────────────────────────

    protected function handleProposal(int $personId, array $actualPk, array $rowData, string $comment): JsonResponse {
        $resourceId = CompositePrimaryKey::buildStoredResourceId($actualPk);

        // 檢查：相同 resource_id 不得已有待審核的新增提案
        if ($this->operationRepository->hasPendingCreateProposal($this->tableName(), $resourceId)) {
            return $this->errorResponse('相同主鍵已有待審核的新增提案', 409, [
                'target.pk' => ['pending_proposal_exists'],
            ]);
        }

        $proposalData = array_merge($rowData, [
            '__proposal_meta' => [
                'action' => 'create',
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

        // 子類可附加副表/鏡像提案資料（例如社會關係的互逆配對碼），核准時據以建立鏡像列。
        $auxiliaryPayload = $this->proposalAuxiliaryPayload();
        if ($auxiliaryPayload !== []) {
            $proposalData['__proposal_aux'] = $auxiliaryPayload;
        }

        $operation = $this->operationRepository->store(
            Auth::id(),
            $personId,
            Operation::TYPE_PROPOSAL_CREATE,
            $this->tableName(),
            $resourceId,
            $proposalData,
            []
        );

        return response()->json([
            'ok' => true,
            'resource' => $this->resourceName(),
            'mode' => 'proposal',
            'operation' => 'create',
            'result' => [
                'pk' => $actualPk,
                'status' => 'proposal_created',
                'operation_id' => $operation?->id,
            ],
        ]);
    }

    // ── 可覆寫的 helper ──────────────────────────────────────

    /** 從 rowData 中提取 PK 欄位（使用正規化後的值） */
    protected function extractPkFromRow(array $rowData): array {
        $pk = [];
        foreach ($this->keyColumns() as $col) {
            $pk[$col] = $rowData[$col] ?? null;
        }

        return $pk;
    }

    /** 查詢是否已有同 PK 的記錄 */
    protected function findExistingRow(array $pk): ?object {
        $query = DB::table($this->tableName());
        foreach ($this->keyColumns() as $col) {
            $query->where($col, $pk[$col] ?? null);
        }

        return $query->first();
    }

    /** 執行資料表新增 */
    protected function performInsert(array $rowData): void {
        DB::table($this->tableName())->insert($rowData);
    }

    /**
     * direct 新增成功後、仍在同一交易內的後續處理鉤子（預設無動作）。
     * 子類可覆寫以在原子交易內寫入互逆鏡像列（例如 AssociationCreateHandler 寫 ASSOC_DATA 反向關係）。
     */
    protected function afterDirectInsert(int $personId, array $actualPk, array $rowData, array $insertedArray, ?Operation $operation): void {
    }

    /**
     * proposal 新增時附加的副表/鏡像提案資料（預設空）。
     * 子類可覆寫以把互逆配對碼寫入 __proposal_aux，核准時據以建立鏡像列。
     */
    protected function proposalAuxiliaryPayload(): array {
        return [];
    }

    /** 欄位值驗證（預設無驗證，子類可覆寫） */
    protected function validateFields(array $data): array {
        return [];
    }

    /** 前處理新增資料（預設無處理，子類可覆寫） */
    protected function preprocessCreateData(array $data): array {
        return $data;
    }

    /**
     * 將指定欄位中的 -999 轉換為 '0'
     */
    protected function normalizeSentinelValues(array $data, array $fields): array {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && ($data[$field] === -999 || $data[$field] === '-999')) {
                $data[$field] = '0';
            }
        }

        return $data;
    }

    /**
     * 判斷 QueryException 是否為唯一性約束衝突
     */
    private function isUniqueConstraintViolation(\Illuminate\Database\QueryException $e): bool {
        $code = (int) ($e->errorInfo[1] ?? 0);
        if (in_array($code, [1062, 19], true)) {
            return true;
        }
        $msg = $e->getMessage();

        return str_contains($msg, 'UNIQUE') || str_contains($msg, 'Duplicate entry');
    }
}
