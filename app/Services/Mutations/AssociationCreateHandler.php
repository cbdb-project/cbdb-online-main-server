<?php

namespace App\Services\Mutations;

use App\Models\Operation;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AssociationCreateHandler extends AbstractPersonSubresourceCreateHandler {
    /**
     * 暫存表單送來的互逆配對碼（c_assocship_pair / c_kinship_pair / c_assoc_kinship_pair），
     * 皆非 ASSOC_DATA 欄；於 handle() 抽出、afterDirectInsert()/proposalAuxiliaryPayload() 取用、finally 清除。
     */
    private ?array $pendingPairs = null;

    /** #70：本次是否強制收斂對面疑似漂移鏡像（meta.force）；handle() 設定、finally 清除。預設 false＝偵測疑似。 */
    private bool $forceMirror = false;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    /**
     * 覆寫：先把互逆配對碼從 changes 抽出（非 ASSOC_DATA 欄，否則父類白名單會 422），暫存供建鏡像／存 aux，
     * 再委派父類處理主列；direct 主列寫入成功後於同交易內由 afterDirectInsert 寫互逆鏡像列（原子）。
     *
     * ⚠️ 鏡像關係碼修正（惡性 bug）：未送 c_assocship_pair 時**必須以代碼表權威反向碼補齊**
     * （ASSOC_CODES.c_assoc_pair / KINSHIP_CODES.c_kin_pair1），而非以哨兵 0 補——否則建立的反向
     * 鏡像列關係碼被洗成 0（「未详」），對方人物出現一條無意義的成對關係。create 模式 c_assoc_code 等
     * 落在 $targetPk（PK 段），故須由 targetPk 取碼查表（對齊 AssociationMutationHandler 的 lookupAssocPair）。
     */
    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $assocCode = $changes['c_assoc_code'] ?? ($targetPk['c_assoc_code'] ?? null);
        $kinCode = $changes['c_kin_code'] ?? ($targetPk['c_kin_code'] ?? null);
        $assocKinCode = $changes['c_assoc_kin_code'] ?? ($targetPk['c_assoc_kin_code'] ?? null);

        $this->pendingPairs = [
            'assoc' => $changes['c_assocship_pair'] ?? $this->lookupAssocPair($assocCode),
            'kin' => $changes['c_kinship_pair'] ?? $this->lookupKinPair($kinCode),
            'assocKin' => $changes['c_assoc_kinship_pair'] ?? $this->lookupKinPair($assocKinCode),
        ];
        unset($changes['c_assocship_pair'], $changes['c_kinship_pair'], $changes['c_assoc_kinship_pair']);

        // #70：force 旗標——使用者在前端疑似警告中選「強制收斂」時帶 meta.force=true，跳過鏡像疑似偵測直接就地收斂。
        $this->forceMirror = (bool) ($meta['force'] ?? false);

        try {
            return parent::handle($resource, $mode, $operation, $personId, $targetPk, $changes, $meta);
        } finally {
            $this->pendingPairs = null;
            $this->forceMirror = false;
        }
    }

    /** 關係碼的權威反向碼（ASSOC_CODES.c_assoc_pair）；未送 c_assocship_pair 時用。0／空／查無 → null（→ 哨兵 0）。 */
    private function lookupAssocPair($code): ?int {
        if ($code === null || $code === '' || (int) $code === 0) {
            return null;
        }
        $v = DB::table('ASSOC_CODES')->where('c_assoc_code', $code)->value('c_assoc_pair');

        return $v !== null ? (int) $v : null;
    }

    /** 親屬碼的權威反向碼（KINSHIP_CODES.c_kin_pair1）；未送 kin/assoc_kin 配對碼時用。0／空／查無 → null（→ 哨兵 0）。 */
    private function lookupKinPair($code): ?int {
        if ($code === null || $code === '' || (int) $code === 0) {
            return null;
        }
        $v = DB::table('KINSHIP_CODES')->where('c_kincode', $code)->value('c_kin_pair1');

        return $v !== null ? (int) $v : null;
    }

    /**
     * 寫互逆鏡像列（對齊 legacy BiogMainRepository::assocStoreById）：反向關係碼、對方為主體、原人為客體，
     * kin/assoc_kin 用對應配對碼、雙方 id 皆為原人。
     *
     * #70：不再裸 insert，改委派已嚴格 gate 的 syncAssocMirrorOnUpdate（allowBackfill=true＝對面無對應反向列時補建
     * ＝create 預設行為；detectConflict=!force＝對面已有「碼漂移」疑似列時拋 MirrorSuspectedException→409，
     * 由前端跳對面 + 強制收斂）。如此 create 與 update 共用同一份 Option 2 安全判別（碼∈合法 code 的列視為他段
     * 合法關係絕不覆寫；僅就地收斂純漂移垃圾列），避免靜默補出對面重複鏡像列。
     *
     * 反向關係碼為哨兵 0（正向碼無權威反向配對的極端情形）時，無有效反向可定位／偵測 → 退回 legacy 無條件
     * insert，保持 parity（legacy assocStoreById 亦無條件寫 code-0 鏡像）。
     */
    protected function afterDirectInsert(int $personId, array $actualPk, array $rowData, array $insertedArray, ?Operation $operation): void {
        $pairs = $this->pendingPairs ?? [];
        $reverseAssocCode = CompositePrimaryKey::emptyToSentinel($pairs['assoc'] ?? null);

        $mirror = $insertedArray;
        unset($mirror['__operation_id'], $mirror['__note']);
        $mirror['c_assoc_code'] = $reverseAssocCode;
        $mirror['c_personid'] = $insertedArray['c_assoc_id'];
        $mirror['c_assoc_id'] = $personId;
        $mirror['c_kin_code'] = CompositePrimaryKey::emptyToSentinel($pairs['kin'] ?? null);
        $mirror['c_assoc_kin_code'] = CompositePrimaryKey::emptyToSentinel($pairs['assocKin'] ?? null);
        $mirror['c_kin_id'] = $personId;
        $mirror['c_assoc_kin_id'] = $personId;

        // 反向碼為哨兵 0：無有效反向可定位／偵測疑似 → 退回 legacy 無條件 insert（parity）。
        if ((int) $reverseAssocCode === 0) {
            DB::table('ASSOC_DATA')->insert($mirror);
            $this->auditLogService->write(
                'ASSOC_DATA',
                'INSERT',
                $this->auditLogService->buildRowPkFromData('ASSOC_DATA', $mirror),
                null,
                $mirror,
                'user',
                (string) Auth::id(),
                $operation ? (string) $operation->id : null
            );

            return;
        }

        // 反向碼有效：委派已 gate 的鏡像同步（含 #70 疑似偵測 + Option 2 安全 + #66 衝突偵測）。
        // 定位參數對齊 update 場景：c_assoc_id=本人、c_personid=對方、書名/首年相符、c_assoc_code=反向碼。
        app(BiogMainRepository::class)->syncAssocMirrorOnUpdate(
            $mirror,
            $personId,                                          // c_assoc_id（本人）
            (int) $insertedArray['c_assoc_id'],                 // c_personid（對方）
            $insertedArray['c_text_title'] ?? '',
            $insertedArray['c_assoc_first_year'] ?? '-9999',
            $reverseAssocCode,                                  // 反向碼（locator c_assoc_code）
            null,
            $operation,
            $this->auditLogService,
            true,                                               // allowBackfill：對面無對應列即補建（create 預設）
            !$this->forceMirror,                                // detectConflict：非 force 時偵測疑似/衝突
            // #66：非對稱「對面已嚴格命中」時的真分歧基準（正向碼取 insertedArray 的 c_assoc_code）。
            $this->createMirrorBaselines($mirror, $insertedArray['c_assoc_code'] ?? null)
        );
    }

    /**
     * #66（create 路徑的真分歧基準）：create 正向列本無「編輯前舊值」，但若對面已存在「以權威反向碼嚴格命中」
     * 的既有反向列（非對稱單邊資料：對面有、本側缺），嚴格命中分支會 update 覆寫該列——若不設基準（空集）就會
     * **靜默洗掉對面既有內容**（與 legacy 既有不會靜默覆寫不一致、亦違反 #66 資料安全模型）。
     *
     * 故以「本次欲寫入的鏡像內容」為基準：對面既有內容欄 == 欲寫入 → 視為等價、不分歧（重建同內容鏡像可冪等通過）；
     * != → 真分歧 → 拋 MirrorConflictException → 409，前端彈警告 + 跳對面 + 強制覆寫（meta.force）。碼欄基準＝正向碼
     * 的合法反向集（對面碼∈集即合法反向、不誤報）。
     */
    private function createMirrorBaselines(array $mirror, $forwardAssocCode): array {
        $contentFields = ['c_notes', 'c_source', 'c_pages', 'c_assoc_first_year', 'c_assoc_last_year'];
        $baselines = [];
        foreach ($contentFields as $f) {
            if (array_key_exists($f, $mirror)) {
                $baselines[$f] = $mirror[$f];
            }
        }
        // 碼欄合法反向集（正向社會關係碼→ASSOC_CODES.c_assoc_pair/pair2）：對面碼∈集即合法反向、不誤報為分歧。
        if ($vr = $this->validAssocReverses($forwardAssocCode)) {
            $baselines['c_assoc_code'] = $vr;
        }

        return $baselines;
    }

    /** §8：合法反向碼集收斂於 RelationshipMirrorService（單一真相來源）。 */
    private function validAssocReverses($code): array {
        return app(\App\Services\RelationshipMirrorService::class)->validReverseAssocSet($code);
    }

    /**
     * proposal 模式把互逆配對碼存入 __proposal_aux，核准時由 applyAssocProposal 併回 request 交給
     * assocStoreById 建鏡像列。核准時 assocStoreById 會「無條件」讀取全部三個配對碼，故此處必須
     * 一律輸出三鍵並以哨兵 0 補缺（對齊 legacy 表單恆送三個 select），否則缺鍵會在核准時變 null、
     * 寫出非 legacy 鏡像或撞 NOT NULL。
     */
    protected function proposalAuxiliaryPayload(): array {
        $p = $this->pendingPairs ?? [];

        return [
            'c_assocship_pair' => CompositePrimaryKey::emptyToSentinel($p['assoc'] ?? null),
            'c_kinship_pair' => CompositePrimaryKey::emptyToSentinel($p['kin'] ?? null),
            'c_assoc_kinship_pair' => CompositePrimaryKey::emptyToSentinel($p['assocKin'] ?? null),
        ];
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
            'c_personid',
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

    protected function preprocessCreateData(array $data): array {
        // 與 legacy BasicInformationAssocController::store() 對齊：補齊 NOT NULL 複合主鍵欄位的哨兵。
        // emptyToSentinel 同時涵蓋 null / '' / -999 / '-999'，較 normalizeSentinelValues（僅 -999）更完整，
        // 確保 v2 create 能等價表達 legacy「未知出處 / 未知年份」主鍵，且不把空值寫進 NOT NULL PK。
        foreach ([
            'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id',
            'c_assoc_kin_code', 'c_assoc_kin_id', 'c_source',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = CompositePrimaryKey::emptyToSentinel($data[$field]);
            }
        }

        // c_text_title（varchar PK）以 '[n/a]' 為未知出處哨兵；c_assoc_first_year 以 '-9999' 為未知年份哨兵。
        if (array_key_exists('c_text_title', $data)) {
            $data['c_text_title'] = CompositePrimaryKey::emptyToSentinel($data['c_text_title'], '[n/a]');
        }
        if (array_key_exists('c_assoc_first_year', $data)) {
            $data['c_assoc_first_year'] = CompositePrimaryKey::emptyToSentinel($data['c_assoc_first_year'], '-9999');
        }

        return $data;
    }
}
