<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\Mutations\Concerns\ResolvesKinshipReversePair;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KinshipCreateHandler extends AbstractPersonSubresourceCreateHandler {
    use ResolvesKinshipReversePair;

    /** 暫存本次互逆配對碼：未送覆寫＝權威 c_kin_pair1；送合法覆寫＝使用者選的反向碼（已驗證）。 */
    private $pendingKinPair = null;

    /** #70：本次是否強制收斂對面疑似漂移鏡像（meta.force）；handle() 設定、finally 清除。預設 false＝偵測疑似。 */
    private bool $forceMirror = false;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    /**
     * 覆寫：先讀 client 可選的 c_kinship_pair（反向碼覆寫），再剝除（非 KIN_DATA 欄，否則父類白名單 422）。
     * 反向配對碼解析（resolveReversePair）：未送覆寫→以 KINSHIP_CODES.c_kin_pair1 權威推導；送了覆寫→
     * 須 ∈ 該正向碼的合法配對候選（對齊 searchKinPair）才接受，否則 422 回滾（fail-closed）——既恢復
     * 使用者對歧義反向關係（父→子/女、第幾子…）的選擇權，又杜絕對鏡像列強塞任意反向碼的污染。
     * direct 主列寫入成功後於同交易內由 afterDirectInsert 寫互逆鏡像列。
     */
    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        // 先讀 client 可選的反向碼覆寫，再剝除（c_kinship_pair 非 KIN_DATA 欄）。
        $clientReverse = $changes['c_kinship_pair'] ?? null;
        unset($changes['c_kinship_pair']);
        $kinCode = $changes['c_kin_code'] ?? ($targetPk['c_kin_code'] ?? null);

        try {
            // 未送覆寫→權威 c_kin_pair1；送覆寫→驗證為合法配對否則 422（fail-closed、回滾）。
            $this->pendingKinPair = $this->resolveReversePair($kinCode, $clientReverse);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        // #70：force 旗標——使用者在前端疑似警告中選「強制收斂」時帶 meta.force=true，跳過鏡像疑似偵測直接就地收斂。
        $this->forceMirror = (bool) ($meta['force'] ?? false);

        try {
            return parent::handle($resource, $mode, $operation, $personId, $targetPk, $changes, $meta);
        } finally {
            $this->pendingKinPair = null;
            $this->forceMirror = false;
        }
    }

    /**
     * 寫互逆鏡像列（對齊 legacy kinshipStoreById）：反向親屬碼、對方為主體、原人為客體。
     *
     * #70：不再裸 insert，改委派已嚴格 gate 的 syncKinMirrorOnUpdate（allowBackfill=true＝對面無對應反向列時補建
     * ＝create 預設行為；detectConflict=!force＝對面已有「碼漂移」疑似列時拋 MirrorSuspectedException→409，
     * 由前端跳對面 + 強制收斂）。如此 create 與 update 共用同一份 Option 2 安全判別（碼∈合法 KINSHIP_CODE 的列
     * 視為他段合法關係絕不覆寫；僅就地收斂純漂移垃圾列），避免靜默補出對面重複鏡像列。
     *
     * 反向親屬碼為哨兵 0（正向碼無權威反向配對的極端情形）時，無有效反向可定位／偵測 → 退回 legacy 無條件
     * insert，保持 parity（legacy kinshipStoreById 亦無條件寫 code-0 鏡像）。
     */
    protected function afterDirectInsert(int $personId, array $actualPk, array $rowData, array $insertedArray, ?Operation $operation): void {
        $reverseKinCode = CompositePrimaryKey::emptyToSentinel($this->pendingKinPair);
        $otherPerson = (int) $insertedArray['c_kin_id'];
        $forwardKinCode = $insertedArray['c_kin_code'];
        $autogenNotes = $insertedArray['c_autogen_notes'] ?? null;

        // 反向碼為哨兵 0：無有效反向可定位／偵測疑似 → 退回 legacy 無條件 insert（parity）。
        if ((int) $reverseKinCode === 0) {
            $mirror = $insertedArray;
            unset($mirror['__operation_id'], $mirror['__note']);
            $mirror['c_kin_code'] = $reverseKinCode;
            $mirror['c_personid'] = $otherPerson;
            $mirror['c_kin_id'] = $personId;

            DB::table('KIN_DATA')->insert($mirror);
            $this->auditLogService->write(
                'KIN_DATA',
                'INSERT',
                ['c_personid' => $mirror['c_personid'], 'c_kin_id' => $mirror['c_kin_id'], 'c_kin_code' => $mirror['c_kin_code']],
                null,
                $mirror,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return;
        }

        // 反向碼有效：委派已 gate 的鏡像同步（含 #70 疑似偵測 + Option 2 安全 + #66 衝突偵測）。
        // $dataMirror 不含 c_kin_id（sync backfill 會補回 c_kin_id=$cPersonid=本人）；c_personid=對方、c_kin_code=反向碼。
        $dataMirror = $insertedArray;
        unset($dataMirror['__operation_id'], $dataMirror['__note'], $dataMirror['c_kin_id']);
        $dataMirror['c_kin_code'] = $reverseKinCode;
        $dataMirror['c_personid'] = $otherPerson;

        app(BiogMainRepository::class)->syncKinMirrorOnUpdate(
            $dataMirror,
            $personId,                          // c_kin_id（本人）＝ $cPersonid
            $otherPerson,                       // c_personid（對方）＝ $oldKinId
            $autogenNotes,                      // c_autogen_notes（locator）
            $forwardKinCode,                    // 正向碼（推導合法反向集）
            $operation,
            $this->auditLogService,
            true,                               // allowBackfill：對面無對應列即補建（create 預設）
            !$this->forceMirror,                // detectConflict：非 force 時偵測疑似/衝突
            // #66：非對稱「對面已嚴格命中」時的真分歧基準（正向碼取 forwardKinCode）。
            $this->createMirrorBaselines($dataMirror, $forwardKinCode)
        );
    }

    /**
     * #66（create 路徑的真分歧基準）：對面若已存在「以權威反向碼嚴格命中」的既有反向列（非對稱單邊資料），嚴格命中
     * 分支會 update 覆寫該列——空集會靜默洗掉對面既有內容。故以「本次欲寫入的鏡像內容」為基準：對面內容欄相同→不
     * 分歧（同內容重建可冪等通過）、不同→真分歧→409＋強制覆寫（meta.force）。碼欄基準＝正向親屬碼的合法反向集。
     */
    private function createMirrorBaselines(array $dataMirror, $forwardKinCode): array {
        $contentFields = ['c_notes', 'c_source', 'c_pages'];
        $baselines = [];
        foreach ($contentFields as $f) {
            if (array_key_exists($f, $dataMirror)) {
                $baselines[$f] = $dataMirror[$f];
            }
        }
        if ($vr = $this->validKinReverses($forwardKinCode)) {
            $baselines['c_kin_code'] = $vr;
        }

        return $baselines;
    }

    /** KINSHIP_CODES：正向親屬碼的合法反向集（c_kin_pair1 / c_kin_pair2）。空/0/無反向 → 空陣列（該欄不納入碼分歧）。 */
    private function validKinReverses($code): array {
        if ($code === null || (int) $code === 0) {
            return [];
        }
        $row = DB::table('KINSHIP_CODES')->where('c_kincode', $code)->first();
        if (!$row) {
            return [];
        }

        return array_values(array_filter([$row->c_kin_pair1 ?? null, $row->c_kin_pair2 ?? null], static fn ($v) => $v !== null && (int) $v !== 0));
    }

    /** proposal 模式把配對碼（送的或權威值）存入 __proposal_aux（核准時 applyKinshipProposal 套用）。 */
    protected function proposalAuxiliaryPayload(): array {
        return ['c_kinship_pair' => CompositePrimaryKey::emptyToSentinel($this->pendingKinPair)];
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
            'c_personid',
            'c_kin_id',
            'c_kin_code',
            'c_source',
            'c_pages',
            'c_notes',
            // Task 27 補回：c_autogen_notes 為 KIN_DATA 真實欄（舊表單以 textarea 暴露為可錄入）；
            // 移除幻影 c_supplement（KIN_DATA 無此欄，SHOW COLUMNS 確認）。
            'c_autogen_notes',
        ];
    }

    protected function preprocessCreateData(array $data): array {
        return $this->normalizeSentinelValues($data, ['c_kin_code', 'c_kin_id', 'c_source']);
    }
}
