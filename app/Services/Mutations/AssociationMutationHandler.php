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
     * 「補建缺失鏡像」(maintain) 僅在明確送了 c_assocship_pair（用戶在維護雙向關係）時才啟用，避免非關係編輯臆造鏡像。
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
            'maintain' => $sentAssoc !== null,
        ];

        try {
            // pair-only direct：只送 c_assocship_pair、無任何 ASSOC_DATA 欄變更（顯式修復/補建反向鏡像）。
            // 父類會以「changes 空」拒絕，故獨立處理（對齊 offices address-only 模式）。
            if ($mode === 'direct' && $sentAssoc !== null && $changes === []) {
                return $this->handlePairOnlyMirrorSync($personId, $targetPk);
            }

            return parent::handle($resource, $mode, $operation, $personId, $targetPk, $changes, $meta);
        } finally {
            $this->pendingPairs = null;
        }
    }

    /**
     * direct 主列更新成功後，於同交易內同步反向鏡像列（重用 BiogMainRepository::syncAssocMirrorOnUpdate）。
     * 鏡像關係／親屬配對碼用 pendingPairs（送的或代碼表權威值，非 0）；補建僅在 maintain（送了 c_assocship_pair）時。
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
            (bool) ($pairs['maintain'] ?? false)
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
    private function handlePairOnlyMirrorSync(int $personId, array $targetPk): JsonResponse {
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

        try {
            DB::transaction(function () use ($dataMirror, $personId, $targetPk, $codeRow) {
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
                    true
                );
            });
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
                'updated_fields' => ['c_assocship_pair'],
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
