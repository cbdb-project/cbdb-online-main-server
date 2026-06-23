<?php

namespace App\Services\Mutations;

use App\Models\Operation;
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

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    /**
     * 覆寫：先把互逆配對碼從 changes 抽出（非 ASSOC_DATA 欄，否則父類白名單會 422），暫存供建鏡像／存 aux，
     * 再委派父類處理主列；direct 主列寫入成功後於同交易內由 afterDirectInsert 寫互逆鏡像列（原子）。
     */
    public function handle(string $resource, string $mode, string $operation, int $personId, array $targetPk, array $changes, array $meta = []): JsonResponse {
        $this->pendingPairs = [
            'assoc' => $changes['c_assocship_pair'] ?? null,
            'kin' => $changes['c_kinship_pair'] ?? null,
            'assocKin' => $changes['c_assoc_kinship_pair'] ?? null,
        ];
        unset($changes['c_assocship_pair'], $changes['c_kinship_pair'], $changes['c_assoc_kinship_pair']);

        try {
            return parent::handle($resource, $mode, $operation, $personId, $targetPk, $changes, $meta);
        } finally {
            $this->pendingPairs = null;
        }
    }

    /**
     * 寫互逆鏡像列（對齊 legacy BiogMainRepository::assocStoreById）：反向關係碼、對方為主體、原人為客體，
     * kin/assoc_kin 用對應配對碼、雙方 id 皆為原人。無條件寫入＝永遠雙向同步（create 無 legacy 的選擇性跳過問題）。
     */
    protected function afterDirectInsert(int $personId, array $actualPk, array $rowData, array $insertedArray, ?Operation $operation): void {
        $pairs = $this->pendingPairs ?? [];
        $mirror = $insertedArray;
        unset($mirror['__operation_id'], $mirror['__note']);
        $mirror['c_assoc_code'] = CompositePrimaryKey::emptyToSentinel($pairs['assoc'] ?? null);
        $mirror['c_personid'] = $insertedArray['c_assoc_id'];
        $mirror['c_assoc_id'] = $personId;
        $mirror['c_kin_code'] = CompositePrimaryKey::emptyToSentinel($pairs['kin'] ?? null);
        $mirror['c_assoc_kin_code'] = CompositePrimaryKey::emptyToSentinel($pairs['assocKin'] ?? null);
        $mirror['c_kin_id'] = $personId;
        $mirror['c_assoc_kin_id'] = $personId;

        DB::table('ASSOC_DATA')->insert($mirror);

        $mirrorPk = [
            'c_personid' => $mirror['c_personid'],
            'c_assoc_code' => $mirror['c_assoc_code'],
            'c_assoc_id' => $mirror['c_assoc_id'],
            'c_kin_code' => $mirror['c_kin_code'],
            'c_kin_id' => $mirror['c_kin_id'],
            'c_assoc_kin_code' => $mirror['c_assoc_kin_code'],
            'c_assoc_kin_id' => $mirror['c_assoc_kin_id'],
            'c_text_title' => $mirror['c_text_title'] ?? '',
            'c_assoc_first_year' => $mirror['c_assoc_first_year'] ?? '-9999',
        ];
        $this->auditLogService->write(
            'ASSOC_DATA',
            'INSERT',
            $mirrorPk,
            null,
            $mirror,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );
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
