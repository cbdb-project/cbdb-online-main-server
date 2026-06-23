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

class KinshipMutationHandler extends AbstractPersonSubresourceMutationHandler {
    /**
     * 暫存本次更新的鏡像同步資料：反向親屬碼（一律權威推導）、舊定位鍵（舊對方/舊備註/舊碼配對）。
     * handle() 計算、afterDirectUpdate()/proposalAuxiliaryPayload() 取用、finally 清除。
     */
    private ?array $pendingKin = null;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    /**
     * 覆寫：剝除 c_kinship_pair（非 KIN_DATA 欄，否則父類白名單 422）。互逆配對碼一律以
     * KINSHIP_CODES.c_kin_pair1 權威推導，「不信任」前端送來的值（同 create）。
     * 為定位既有反向鏡像列，先讀原列取得「舊」c_kin_id / c_autogen_notes 與「舊」正向碼的配對。
     */
    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        unset($changes['c_kinship_pair']);

        $oldKinCode = $targetPk['c_kin_code'] ?? null;
        $newKinCode = $changes['c_kin_code'] ?? $oldKinCode;

        $oldRow = $this->findKinRow($targetPk);

        $this->pendingKin = [
            // 鏡像列關係碼＝「新」正向碼的權威反向碼。
            'mirrorCode' => $this->lookupKinPair($newKinCode),
            // 定位既有反向列用「舊」值（舊碼配對查找與缺碼 fail-closed 由 syncKinMirrorOnUpdate 處理）。
            'oldKinId' => $targetPk['c_kin_id'] ?? null,
            'oldAutogen' => $oldRow->c_autogen_notes ?? null,
            'oldKinCode' => $oldKinCode,
        ];

        try {
            return parent::handle($resource, $mode, $operation, $personId, $targetPk, $changes, $meta);
        } finally {
            $this->pendingKin = null;
        }
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
        $dataMirror['c_modified_by'] = Auth::user()->name ?? '';
        $dataMirror['c_modified_date'] = Carbon::now();
        $dataMirror['c_kin_code'] = CompositePrimaryKey::emptyToSentinel($kin['mirrorCode'] ?? null);
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
            false
        );
    }

    /** proposal 模式把反向親屬碼（權威值）存入 __proposal_aux（核准時 kinshipUpdateById 讀 c_kinship_pair）。 */
    protected function proposalAuxiliaryPayload(): array {
        return ['c_kinship_pair' => CompositePrimaryKey::emptyToSentinel($this->pendingKin['mirrorCode'] ?? null)];
    }

    /** 親屬碼的權威反向碼（KINSHIP_CODES.c_kin_pair1）；0／查無 → null。 */
    private function lookupKinPair($code): ?int {
        if ($code === null || $code === '' || (int) $code === 0) {
            return null;
        }
        $v = DB::table('KINSHIP_CODES')->where('c_kincode', $code)->value('c_kin_pair1');

        return $v !== null ? (int) $v : null;
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
            // Task 27 補回：c_autogen_notes 為 KIN_DATA 真實欄；移除幻影 c_supplement（KIN_DATA 無此欄）。
            'c_autogen_notes',
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        $data = $this->normalizeSentinelValues($data, ['c_kin_code', 'c_kin_id', 'c_source']);

        return $data;
    }
}
