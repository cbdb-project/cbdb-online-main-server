<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AssociationMutationHandler extends AbstractPersonSubresourceMutationHandler {
    /**
     * 暫存表單送來的互逆配對碼（c_assocship_pair / c_kinship_pair / c_assoc_kinship_pair，皆非 ASSOC_DATA 欄）；
     * handle() 抽出、afterDirectUpdate()/proposalAuxiliaryPayload() 取用、finally 清除。
     */
    private ?array $pendingPairs = null;

    /** #66：本次是否強制覆寫對面鏡像（meta.force）；handle() 設定、finally 清除。預設 false＝偵測衝突。 */
    private bool $forceMirror = false;

    /** #66：納入鏡像衝突比對的「內容欄」（自由文本/可手填，真實數據丟失風險）。關係/配對碼另以「合法反向集」基準比對。 */
    private const CONTENT_CONFLICT_FIELDS = ['c_notes', 'c_source', 'c_pages', 'c_assoc_first_year', 'c_assoc_last_year'];

    /**
     * #66：建構鏡像衝突偵測的「基準」(欄位 → 基準)，達成「只在對面真分歧時警告」。
     * - 內容欄（本次實際變更者）：基準＝正向「編輯前舊值」(純量)；對面 ≠ 此 → 被獨立改過。
     * - 關係/配對碼（c_assoc_code/c_kin_code/c_assoc_kin_code）：基準＝正向「舊碼」的合法反向集 (pair1/pair2)；
     *   對面碼 ∈ 集＝仍是合法反向（同步，pair1↔pair2 互換不誤報）、∉ 集＝被改成無關碼 → 真分歧。
     *   碼總是納入（in-sync 必 ∈ 集、空/0 由 detect 跳過、無合法反向時略過該欄不檢），不需「是否送 pair」門控。
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
        if ($vr = $this->assocValidReverses($forwardOld['c_assoc_code'] ?? null)) {
            $baselines['c_assoc_code'] = $vr;
        }
        if ($vr = $this->kinValidReverses($forwardOld['c_kin_code'] ?? null)) {
            $baselines['c_kin_code'] = $vr;
        }
        if ($vr = $this->kinValidReverses($forwardOld['c_assoc_kin_code'] ?? null)) {
            $baselines['c_assoc_kin_code'] = $vr;
        }

        return $baselines;
    }

    /** §8：合法反向碼集收斂於 RelationshipMirrorService（單一真相來源）。 */
    private function assocValidReverses($code): array {
        return app(\App\Services\RelationshipMirrorService::class)->validReverseAssocSet($code);
    }

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
     * 覆寫：先把互逆配對碼從 changes 抽出（c_assocship_pair/c_kinship_pair/c_assoc_kinship_pair，非 ASSOC_DATA 欄，
     * 否則父類白名單會 422）。**未送者一律以代碼表權威反向碼補齊**（ASSOC_CODES.c_assoc_pair /
     * KINSHIP_CODES.c_kin_pair1），而非以 0 補：否則只改備註等的更新／提案核准會把既有鏡像的關係／親屬配對碼洗成 0。
     * 「補建缺失鏡像」(maintain) 在明確送了任一互逆配對碼（c_assocship_pair / c_kinship_pair /
     * c_assoc_kinship_pair）時啟用——用戶顯式編輯任一方向的反向碼即表達「維護雙向關係」意圖（含 #58 新增的
     * 兩組親屬反向碼），與畫面「會建立鏡像關係／親屬關係」文案一致；僅改備註等非配對欄則不臆造鏡像。
     */
    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $sentAssoc = $changes['c_assocship_pair'] ?? null;
        $sentKin = $changes['c_kinship_pair'] ?? null;
        $sentAssocKin = $changes['c_assoc_kinship_pair'] ?? null;
        unset($changes['c_assocship_pair'], $changes['c_kinship_pair'], $changes['c_assoc_kinship_pair']);

        $assocCode = $changes['c_assoc_code'] ?? ($targetPk['c_assoc_code'] ?? null);
        $kinCode = $changes['c_kin_code'] ?? ($targetPk['c_kin_code'] ?? null);
        $assocKinCode = $changes['c_assoc_kin_code'] ?? ($targetPk['c_assoc_kin_code'] ?? null);

        $this->pendingPairs = [
            'assoc' => $sentAssoc ?? $this->lookupAssocPair($assocCode),
            'kin' => $sentKin ?? $this->lookupKinPair($kinCode),
            'assocKin' => $sentAssocKin ?? $this->lookupKinPair($assocKinCode),
            'maintain' => ($sentAssoc !== null || $sentKin !== null || $sentAssocKin !== null),
        ];
        // #66：force 旗標——使用者在前端衝突警告中選「強制覆寫」時帶 meta.force=true，跳過鏡像衝突偵測。
        $this->forceMirror = (bool) ($meta['force'] ?? false);

        // 本次明確送出的互逆配對欄（社會／親屬／關聯親屬任一），供 pair-only 判斷與回應 updated_fields。
        $sentPairFields = array_keys(array_filter([
            'c_assocship_pair' => $sentAssoc,
            'c_kinship_pair' => $sentKin,
            'c_assoc_kinship_pair' => $sentAssocKin,
        ], static fn ($v) => $v !== null));

        try {
            // pair-only direct：只送互逆配對碼（c_assocship_pair / c_kinship_pair / c_assoc_kinship_pair 任一）、
            // 無任何 ASSOC_DATA 欄變更（顯式修復/補建反向鏡像）。父類會以「changes 空」拒絕，故獨立處理（對齊 offices address-only 模式）。
            if ($mode === 'direct' && $sentPairFields !== [] && $changes === []) {
                return $this->handlePairOnlyMirrorSync($personId, $targetPk, $sentPairFields);
            }

            return parent::handle($resource, $mode, $operation, $personId, $targetPk, $changes, $meta);
        } finally {
            $this->pendingPairs = null;
            $this->forceMirror = false;
        }
    }

    /**
     * direct 主列更新成功後，於同交易內同步反向鏡像列（重用 BiogMainRepository::syncAssocMirrorOnUpdate）。
     * 鏡像關係／親屬配對碼用 pendingPairs（送的或代碼表權威值，非 0）；補建僅在 maintain（顯式送了任一互逆配對碼）時。
     */
    protected function afterDirectUpdate(int $personId, array $targetPk, array $updateData, array $newArray, ?Operation $operation): void {
        $pairs = $this->pendingPairs ?? [];

        $dataMirror = $newArray;
        unset($dataMirror['__operation_id'], $dataMirror['__note'], $dataMirror['c_created_by'], $dataMirror['c_created_date']);
        $dataMirror['c_modified_by'] = Auth::user()->name ?? '';
        $dataMirror['c_modified_date'] = Carbon::now();
        $dataMirror['c_kin_code'] = CompositePrimaryKey::emptyToSentinel($pairs['kin'] ?? null);
        $dataMirror['c_assoc_kin_code'] = CompositePrimaryKey::emptyToSentinel($pairs['assocKin'] ?? null);
        $dataMirror['c_kin_id'] = $personId;
        $dataMirror['c_assoc_kin_id'] = $personId;
        $dataMirror['c_assoc_code'] = CompositePrimaryKey::emptyToSentinel($pairs['assoc'] ?? null);
        $dataMirror['c_personid'] = $newArray['c_assoc_id'];
        $dataMirror['c_assoc_id'] = $personId;

        $oldCode = $targetPk['c_assoc_code'] ?? null;
        $codeRow = DB::table('ASSOC_CODES')->where('c_assoc_code', $oldCode)->first();

        app(BiogMainRepository::class)->syncAssocMirrorOnUpdate(
            $dataMirror,
            $personId,
            $targetPk['c_assoc_id'] ?? null,
            $targetPk['c_text_title'] ?? '',
            $targetPk['c_assoc_first_year'] ?? '-9999',
            $codeRow->c_assoc_pair ?? null,
            $codeRow->c_assoc_pair2 ?? null,
            $operation,
            $this->auditLogService,
            (bool) ($pairs['maintain'] ?? false),
            !$this->forceMirror, // #66：非 force 時偵測對面衝突
            $this->conflictBaselines($updateData, $this->directForwardOld) // #66：內容欄=正向舊值、碼=合法反向集（真分歧基準）
        );
    }

    /** proposal 模式把三個互逆配對碼（送的或代碼表權威值，非 0）存入 __proposal_aux（核准時 assocUpdateById 無條件讀三鍵）。 */
    protected function proposalAuxiliaryPayload(): array {
        $p = $this->pendingPairs ?? [];

        return [
            'c_assocship_pair' => CompositePrimaryKey::emptyToSentinel($p['assoc'] ?? null),
            'c_kinship_pair' => CompositePrimaryKey::emptyToSentinel($p['kin'] ?? null),
            'c_assoc_kinship_pair' => CompositePrimaryKey::emptyToSentinel($p['assocKin'] ?? null),
        ];
    }

    /** 關係碼的權威反向碼（ASSOC_CODES.c_assoc_pair）；未送 c_assocship_pair 時用。 */
    private function lookupAssocPair($code): ?int {
        if ($code === null || $code === '') {
            return null;
        }
        $v = DB::table('ASSOC_CODES')->where('c_assoc_code', $code)->value('c_assoc_pair');

        return $v !== null ? (int) $v : null;
    }

    /** 親屬碼的權威反向碼（KINSHIP_CODES.c_kin_pair1）；未送 kin/assoc_kin 配對碼時用。 */
    private function lookupKinPair($code): ?int {
        if ($code === null || $code === '') {
            return null;
        }
        $v = DB::table('KINSHIP_CODES')->where('c_kincode', $code)->value('c_kin_pair1');

        return $v !== null ? (int) $v : null;
    }

    /**
     * pair-only direct：不改正向欄位，僅依送來的配對碼修復／補建反向鏡像列（同交易 + audit）。
     * 用於「正向已存在但反向鏡像缺失」的修復場景。自帶授權與 person_id 檢查。
     */
    private function handlePairOnlyMirrorSync(int $personId, array $targetPk, array $sentPairFields = ['c_assocship_pair']): JsonResponse {
        if ($authError = $this->authorizeDirect()) {
            return $authError;
        }
        $original = $this->findAssocRow($targetPk);
        if (!$original) {
            return $this->errorResponse('ASSOC_DATA 記錄不存在', 404);
        }
        if ((string) ($original->c_personid ?? '') !== (string) $personId) {
            return $this->errorResponse('person_id 與目標記錄不一致', 422, ['person_id' => ['mismatch']]);
        }

        $pairs = $this->pendingPairs ?? [];
        $dataMirror = (array) $original;
        unset($dataMirror['c_created_by'], $dataMirror['c_created_date']);
        $dataMirror['c_modified_by'] = Auth::user()->name ?? '';
        $dataMirror['c_modified_date'] = Carbon::now();
        $dataMirror['c_kin_code'] = CompositePrimaryKey::emptyToSentinel($pairs['kin'] ?? null);
        $dataMirror['c_assoc_kin_code'] = CompositePrimaryKey::emptyToSentinel($pairs['assocKin'] ?? null);
        $dataMirror['c_kin_id'] = $personId;
        $dataMirror['c_assoc_kin_id'] = $personId;
        $dataMirror['c_assoc_code'] = CompositePrimaryKey::emptyToSentinel($pairs['assoc'] ?? null);
        $dataMirror['c_personid'] = $original->c_assoc_id;
        $dataMirror['c_assoc_id'] = $personId;

        $oldCode = $targetPk['c_assoc_code'] ?? null;
        $codeRow = DB::table('ASSOC_CODES')->where('c_assoc_code', $oldCode)->first();
        // #66：pair-only 是顯式設反向碼的修復；以正向（未變更）既有列的舊碼合法反向集為基準，
        // 偵測對面碼是否被改成無關碼（真分歧）。無內容欄變更，故僅碼基準。
        $baselines = $this->conflictBaselines([], (array) $original);

        try {
            DB::transaction(function () use ($dataMirror, $personId, $targetPk, $codeRow, $baselines) {
                app(BiogMainRepository::class)->syncAssocMirrorOnUpdate(
                    $dataMirror,
                    $personId,
                    $targetPk['c_assoc_id'] ?? null,
                    $targetPk['c_text_title'] ?? '',
                    $targetPk['c_assoc_first_year'] ?? '-9999',
                    $codeRow->c_assoc_pair ?? null,
                    $codeRow->c_assoc_pair2 ?? null,
                    null,
                    $this->auditLogService,
                    true,
                    !$this->forceMirror, // #66：非 force 時偵測對面（碼）真分歧
                    $baselines
                );
            });
        } catch (MirrorConflictException $e) {
            // #66（修 S2）：pair-only 路徑自帶交易、不經 handleDirect，須在此自行把鏡像衝突轉 409，否則逃逸成 500。
            return $this->errorResponse($e->getMessage(), 409, [
                'mirror_conflict' => ['table' => $e->mirrorTable, 'pk' => $e->mirrorPk, 'fields' => $e->conflicts],
            ]);
        } catch (MirrorSuspectedException $e) {
            // #70（同 S2）：pair-only 路徑同樣須自行把「疑似漂移鏡像」轉 409，否則逃逸成 500。
            return $this->errorResponse($e->getMessage(), 409, [
                'mirror_suspected' => [
                    'table' => $e->mirrorTable,
                    'candidates' => $e->candidates,
                    'authoritative_code' => $e->authoritativeCode,
                    'count' => $e->count(),
                ],
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('鏡像列已存在或主鍵衝突', 409, ['mirror' => ['conflict']]);
        }

        return response()->json([
            'ok' => true,
            'resource' => 'associations',
            'mode' => 'direct',
            'operation' => 'update',
            'result' => [
                'pk' => array_intersect_key($targetPk, array_flip($this->keyColumns())),
                'updated_fields' => $sentPairFields,
            ],
        ]);
    }

    private function findAssocRow(array $targetPk): ?object {
        $query = DB::table('ASSOC_DATA');
        foreach ($this->keyColumns() as $col) {
            $query->where($col, $targetPk[$col] ?? null);
        }

        return $query->first();
    }

    protected function resourceName(): string {
        return 'associations';
    }

    protected function tableName(): string {
        return 'ASSOC_DATA';
    }

    protected function displayName(): string {
        return '社會關係';
    }

    protected function resourceAliases(): array {
        return ['associations', 'association', 'assoc_data'];
    }

    protected function keyColumns(): array {
        return [
            'c_personid',
            'c_assoc_code',
            'c_assoc_id',
            'c_kin_code',
            'c_kin_id',
            'c_assoc_kin_code',
            'c_assoc_kin_id',
            'c_text_title',
            'c_assoc_first_year',
        ];
    }

    protected function allowedFields(): array {
        return [
            'c_assoc_code',
            'c_assoc_id',
            'c_kin_code',
            'c_kin_id',
            'c_assoc_kin_code',
            'c_assoc_kin_id',
            'c_text_title',
            'c_assoc_first_year',
            'c_assoc_last_year',
            // era 農曆欄（legacy x-inline-time-fields 送出；之前白名單漏掉＝靜默流失，同 offices 31a）。
            'c_assoc_fy_nh_code', 'c_assoc_fy_nh_year', 'c_assoc_fy_range',
            'c_assoc_fy_intercalary', 'c_assoc_fy_month', 'c_assoc_fy_day', 'c_assoc_fy_day_gz',
            'c_assoc_ly_nh_code', 'c_assoc_ly_nh_year', 'c_assoc_ly_range',
            'c_assoc_ly_intercalary', 'c_assoc_ly_month', 'c_assoc_ly_day', 'c_assoc_ly_day_gz',
            'c_source',
            'c_pages',
            'c_notes',
            // ⚠️ 移除幻影 c_supplement（ASSOC_DATA 無此欄）。
            'c_sequence',
            'c_assoc_count',
            // Task 27：補回舊表單可錄入欄位（皆 ASSOC_DATA 真實欄）。
            'c_topic_code',
            'c_occasion_code',
            'c_tertiary_personid',
            'c_tertiary_type_notes',
            'c_assoc_claimer_id',
            'c_addr_id',
            'c_inst_code',
            'c_inst_name_code',
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, [
            'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id',
            'c_assoc_kin_code', 'c_assoc_kin_id', 'c_source',
        ]);
        // sentinel 完全幂等：c_source（legacy 哨兵 0=Unknown）的 null/'' 也→0（normalizeSentinelValues 只做 -999；下方 PK 欄另用 emptyToSentinel）。
        $data = $this->normalizeEmptyCodeFields($data, ['c_source']);

        // 與 AssociationCreateHandler 及 legacy BasicInformationAssocController 對齊：
        // c_text_title / c_assoc_first_year 為 NOT NULL 複合主鍵欄位，空值須轉哨兵，
        // 否則編輯一筆「未知出處/未知年份」記錄（'[n/a]' / '-9999'）時，前端送空（middleware 轉 null）
        // 會把 PK 寫成 '' / 0，造成主鍵漂移。emptyToSentinel 同時涵蓋 null / '' / -999。
        if (array_key_exists('c_text_title', $data)) {
            $data['c_text_title'] = CompositePrimaryKey::emptyToSentinel($data['c_text_title'], '[n/a]');
        }
        if (array_key_exists('c_assoc_first_year', $data)) {
            $data['c_assoc_first_year'] = CompositePrimaryKey::emptyToSentinel($data['c_assoc_first_year'], '-9999');
        }

        return $data;
    }
}
