<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\Mutations\Concerns\ResolvesKinshipReversePair;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class KinshipMutationHandler extends AbstractPersonSubresourceMutationHandler {
    use ResolvesKinshipReversePair;

    /**
     * 暫存本次更新的鏡像同步資料：反向親屬碼（權威預設 c_kin_pair1 或經驗證的使用者覆寫）、
     * preserveMirrorCode（未送覆寫且正向碼未變時保留既有鏡像碼）、舊定位鍵（舊對方/舊備註/舊碼配對）。
     * handle() 計算、afterDirectUpdate()/proposalAuxiliaryPayload() 取用、finally 清除。
     */
    private ?array $pendingKin = null;

    /** #66：本次是否強制覆寫對面鏡像（meta.force）；handle() 設定、finally 清除。預設 false＝偵測衝突。 */
    private bool $forceMirror = false;

    /** #66：納入鏡像衝突比對的「內容欄」（KIN_DATA 無年份欄）。反向親屬碼 c_kin_code 另以「合法反向集」基準比對。 */
    private const CONTENT_CONFLICT_FIELDS = ['c_notes', 'c_source', 'c_pages'];

    /**
     * #66：建構鏡像衝突偵測的「基準」(欄位 → 基準)，達成「只在對面真分歧時警告」。
     * - 內容欄（本次實際變更者）：基準＝正向「編輯前舊值」(純量)。
     * - 反向親屬碼 c_kin_code：基準＝正向「舊碼」的合法反向集 (c_kin_pair1/pair2)；對面碼 ∈ 集＝同步（pair1↔pair2
     *   互換不誤報）、∉ 集＝被改成無關碼 → 真分歧。空/0 或無合法反向時略過該欄。
     *
     * @param array<string,mixed> $updateData 本次寫入正向列的欄（含稽核欄，需排除）
     * @param array<string,mixed> $forwardOld 正向「編輯前」的列（純量基準與舊碼來源）
     */
    private function conflictBaselines(array $updateData, array $forwardOld): array {
        $changed = array_diff(array_keys($updateData), ['c_modified_by', 'c_modified_date']);
        $baselines = [];
        foreach (array_intersect(self::CONTENT_CONFLICT_FIELDS, $changed) as $f) {
            $baselines[$f] = $forwardOld[$f] ?? null;
        }
        if ($vr = $this->kinValidReverses($forwardOld['c_kin_code'] ?? null)) {
            $baselines['c_kin_code'] = $vr;
        }

        return $baselines;
    }

    /** §8：合法反向碼集收斂於 RelationshipMirrorService（單一真相來源）。 */
    private function kinValidReverses($code): array {
        return app(\App\Services\RelationshipMirrorService::class)->validReverseKinSet($code);
    }

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    /**
     * 覆寫：先讀 client 可選的 c_kinship_pair（反向碼覆寫），再剝除（非 KIN_DATA 欄，否則父類白名單 422）。
     * 反向碼解析同 create：未送→權威 c_kin_pair1；送→驗證為合法配對否則 422（fail-closed）。
     * 另：未送覆寫且正向碼未變時「保留既有鏡像 c_kin_code」（preserveMirrorCode），避免改備註等
     * 非關係編輯把使用者先前手選的反向碼洗回 c_kin_pair1。
     * 為定位既有反向鏡像列，先讀原列取得「舊」c_kin_id / c_autogen_notes 與「舊」正向碼的配對。
     */
    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        // 先讀 client 可選的反向碼覆寫，再剝除（c_kinship_pair 非 KIN_DATA 欄）。
        $pairSent = array_key_exists('c_kinship_pair', $changes); // #88：是否顯式送了反向配對碼。
        $clientReverse = $changes['c_kinship_pair'] ?? null;
        unset($changes['c_kinship_pair']);

        $oldKinCode = $targetPk['c_kin_code'] ?? null;
        $newKinCode = $changes['c_kin_code'] ?? $oldKinCode;

        $oldRow = $this->findKinRow($targetPk);

        try {
            // 未送覆寫→權威 c_kin_pair1；送覆寫→驗證為合法配對否則 422（fail-closed）。
            $mirrorCode = $this->resolveReversePair($newKinCode, $clientReverse);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        // 保留既有鏡像反向碼：當「未送覆寫」且「正向碼未變」時，不可把鏡像 c_kin_code 洗回 c_kin_pair1
        // ——否則改備註等非關係編輯會抹掉使用者先前在 create 時手選的反向碼（如 pair2）。
        $hasOverride = !($clientReverse === null || $clientReverse === '' || (int) $clientReverse === 0);
        $codeChanged = (string) ($newKinCode ?? '') !== (string) ($oldKinCode ?? '');

        $this->pendingKin = [
            // 鏡像列關係碼＝「新」正向碼的反向碼（權威預設或經驗證的使用者覆寫）。
            'mirrorCode' => $mirrorCode,
            // true＝本次不覆寫鏡像 c_kin_code（保留既有值）。
            'preserveMirrorCode' => !$hasOverride && !$codeChanged,
            // 定位既有反向列用「舊」值（舊碼配對查找與缺碼 fail-closed 由 syncKinMirrorOnUpdate 處理）。
            'oldKinId' => $targetPk['c_kin_id'] ?? null,
            'oldAutogen' => $oldRow->c_autogen_notes ?? null,
            'oldKinCode' => $oldKinCode,
        ];
        // #66：force 旗標——使用者在前端衝突警告中選「強制覆寫」時帶 meta.force=true，跳過鏡像衝突偵測。
        $this->forceMirror = (bool) ($meta['force'] ?? false);

        try {
            // #88：pair-only direct——只送互逆配對碼（c_kinship_pair）、無任何 KIN_DATA 欄變更（顯式修復/改寫反向碼）。
            // 父類會以「changes 空」回 422 拒絕，故獨立處理（對齊 AssociationMutationHandler::handlePairOnlyMirrorSync）。
            if ($mode === 'direct' && $pairSent && $changes === []) {
                return $this->handlePairOnlyMirrorSync($personId, $targetPk);
            }

            return parent::handle($resource, $mode, $operation, $personId, $targetPk, $changes, $meta);
        } finally {
            $this->pendingKin = null;
            $this->forceMirror = false;
        }
    }

    /**
     * #88 pair-only：只改互逆配對碼、無正向欄變更時，於獨立交易內同步既有反向鏡像列的 c_kin_code（不經父類 handleDirect，
     * 因其要求至少一個正向欄變更）。對齊 AssociationMutationHandler::handlePairOnlyMirrorSync。reverseCodeAuthoritative=true：
     * 使用者顯式設定反向碼為權威，對面舊反向碼正被遷移，不以 (b) 窄碼基準誤判衝突；仍偵測對面碼漂移出合法集（真分歧）。
     */
    private function handlePairOnlyMirrorSync(int $personId, array $targetPk): JsonResponse {
        if ($authError = $this->authorizeDirect()) {
            return $authError;
        }
        $original = $this->findKinRow($targetPk);
        if (!$original) {
            return $this->errorResponse('KIN_DATA 記錄不存在', 404);
        }
        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        $kin = $this->pendingKin ?? [];
        $dataMirror = (array) $original;
        unset($dataMirror['c_created_by'], $dataMirror['c_created_date']);
        $dataMirror['c_modified_by'] = \App\Support\AuditActor::currentName();
        $dataMirror['c_modified_date'] = Carbon::now();
        $dataMirror['c_kin_code'] = CompositePrimaryKey::emptyToSentinel($kin['mirrorCode'] ?? null);
        $dataMirror['c_personid'] = $original->c_kin_id; // 反向列主體＝對方
        unset($dataMirror['c_kin_id']);                  // 反向列客體 c_kin_id 維持本人（sync backfill 補回）

        try {
            DB::transaction(function () use ($dataMirror, $personId, $targetPk, $original) {
                app(BiogMainRepository::class)->syncKinMirrorOnUpdate(
                    $dataMirror,
                    $personId,
                    $targetPk['c_kin_id'] ?? null,
                    $original->c_autogen_notes ?? null,
                    $targetPk['c_kin_code'] ?? null,
                    null,
                    $this->auditLogService,
                    true,                 // allowBackfill：顯式維護雙向→缺鏡像即補建
                    !$this->forceMirror,  // #66：非 force 時偵測對面（碼漂移）真分歧
                    $this->conflictBaselines([], (array) $original),
                    true                  // #88：reverseCodeAuthoritative——沿用寬碼基準，不誤擋對面舊反向碼
                );
            });
        } catch (MirrorConflictException $e) {
            return $this->errorResponse($e->getMessage(), 409, [
                'mirror_conflict' => ['table' => $e->mirrorTable, 'pk' => $e->mirrorPk, 'fields' => $e->conflicts],
            ]);
        } catch (MirrorSuspectedException $e) {
            return $this->errorResponse($e->getMessage(), 409, [
                'mirror_suspected' => [
                    'table' => $e->mirrorTable,
                    'candidates' => $e->candidates,
                    'authoritative_code' => $e->authoritativeCode,
                    'count' => $e->count(),
                ],
            ]);
        } catch (MirrorIntegrityException $e) {
            return $this->errorResponse($e->getMessage(), 422, ['mirror_integrity' => ['fail_closed']]);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('鏡像列已存在或主鍵衝突', 409, ['mirror' => ['conflict']]);
        }

        return response()->json([
            'ok' => true,
            'resource' => $this->resourceName(),
            'mode' => 'direct',
            'operation' => 'update',
            'result' => ['pk' => $targetPk, 'updated_fields' => ['c_kinship_pair']],
        ]);
    }

    /**
     * direct 主列更新成功後，於同交易內同步既有反向鏡像列（重用 BiogMainRepository::syncKinMirrorOnUpdate）。
     * 鏡像關係碼用權威反向碼；不補建（allowBackfill=false，對齊 legacy：找不到反向列即跳過，
     * 避免改備註等非關係編輯臆造鏡像；新建資料的反向列已由 create handler 保證存在）。
     */
    protected function afterDirectUpdate(int $personId, array $targetPk, array $updateData, array $newArray, ?Operation $operation): void {
        $kin = $this->pendingKin ?? [];

        $dataMirror = $newArray;
        unset($dataMirror['__operation_id'], $dataMirror['__note'], $dataMirror['c_created_by'], $dataMirror['c_created_date']);
        $dataMirror['c_modified_by'] = \App\Support\AuditActor::currentName();
        $dataMirror['c_modified_date'] = Carbon::now();
        if (!empty($kin['preserveMirrorCode'])) {
            // 未送覆寫且正向碼未變：不動鏡像 c_kin_code（保留使用者先前手選的反向碼，不洗回 c_kin_pair1）。
            unset($dataMirror['c_kin_code']);
        } else {
            $dataMirror['c_kin_code'] = CompositePrimaryKey::emptyToSentinel($kin['mirrorCode'] ?? null);
        }
        $dataMirror['c_personid'] = $newArray['c_kin_id']; // 反向列主體＝（新）對方
        unset($dataMirror['c_kin_id']);                    // 反向列客體 c_kin_id 維持本人，不覆寫

        app(BiogMainRepository::class)->syncKinMirrorOnUpdate(
            $dataMirror,
            $personId,
            $kin['oldKinId'] ?? null,
            $kin['oldAutogen'] ?? null,
            $kin['oldKinCode'] ?? null,
            $operation,
            $this->auditLogService,
            false,
            !$this->forceMirror, // #66：非 force 時偵測對面衝突
            $this->conflictBaselines($updateData, $this->directForwardOld) // #66：內容欄=正向舊值、c_kin_code=合法反向集（真分歧基準）
        );
    }

    /** proposal 模式把反向親屬碼（權威預設或經驗證的覆寫）存入 __proposal_aux（核准時 kinshipUpdateById 讀 c_kinship_pair）。 */
    protected function proposalAuxiliaryPayload(): array {
        return ['c_kinship_pair' => CompositePrimaryKey::emptyToSentinel($this->pendingKin['mirrorCode'] ?? null)];
    }

    private function findKinRow(array $targetPk): ?object {
        $query = DB::table('KIN_DATA');
        foreach ($this->keyColumns() as $col) {
            $query->where($col, $targetPk[$col] ?? null);
        }

        return $query->first();
    }

    protected function resourceName(): string {
        return 'kinship';
    }

    protected function tableName(): string {
        return 'KIN_DATA';
    }

    protected function displayName(): string {
        return '親屬關係';
    }

    protected function resourceAliases(): array {
        return ['kinship', 'kin', 'kin_data'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_kin_id', 'c_kin_code'];
    }

    protected function allowedFields(): array {
        return [
            'c_kin_code',
            'c_kin_id',
            'c_source',
            'c_pages',
            'c_notes',
            'c_autogen_notes',
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, ['c_kin_code', 'c_kin_id', 'c_source']);
        // sentinel 完全幂等：c_source（legacy 哨兵 0=Unknown）的 null/'' 也→0（normalizeSentinelValues 只做 -999）。
        $data = $this->normalizeEmptyCodeFields($data, ['c_source']);

        return $data;
    }
}
