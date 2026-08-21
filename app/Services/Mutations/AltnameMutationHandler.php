<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\BracketNormalizer;
use App\Services\NameSearchIndexService;
use App\Support\PinyinUmlaut;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AltnameMutationHandler extends AbstractPersonSubresourceMutationHandler {
    protected NameSearchIndexService $nameSearchIndexService;

    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService,
        NameSearchIndexService $nameSearchIndexService
    ) {
        parent::__construct($operationRepository, $auditLogService);
        $this->nameSearchIndexService = $nameSearchIndexService;
    }

    protected function resourceName(): string {
        return 'altnames';
    }

    protected function tableName(): string {
        return 'ALTNAME_DATA';
    }

    protected function displayName(): string {
        return '別名';
    }

    protected function resourceAliases(): array {
        return ['altnames', 'altname', 'altname_data'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'];
    }

    protected function allowedFields(): array {
        return [
            'c_alt_name_chn',
            'c_alt_name',
            'c_alt_name_type_code',
            'c_source',
            'c_pages',
            'c_notes',
            'c_sequence',
            'c_alt_name_pinyin',
            'c_alt_name_pinyin2',
            'c_alt_name_pinyin3',
            'c_alt_name_role',
        ];
    }

    protected function preprocessUpdateData(array $data): array {
        // 括號正規化
        $data = BracketNormalizer::normalizeAltname($data);

        // 保存時拼音 v→ü 歸一化（Tier 1；僅 c_alt_name_pinyin/2/3。c_alt_name 走前端 Tier 2、不在此轉）
        $data = PinyinUmlaut::normalizeFields($data, PinyinUmlaut::ALTNAME_PINYIN_V_FIELDS);

        // -999 → 0 轉換
        $data = $this->normalizeSentinelValues($data, ['c_alt_name_type_code', 'c_source']);
        // sentinel 完全幂等：c_source（legacy 哨兵 0=Unknown）的 null/'' 也→0（normalizeSentinelValues 只做 -999）。
        $data = $this->normalizeEmptyCodeFields($data, ['c_source']);

        // 異體字落地替換（c_alt_name_chn 為 strict 模式）已由基底類別的通用掛鉤在
        // 本方法之前完成，替換紀錄收在 $this->variantReplaced、通知由 handle() 掛上。
        // 這裡**不要**再自己呼叫 CharVariantMapService：通用掛鉤先跑過，值已是參考形，
        // 再呼叫一次的 replaced 恆為 []，assign 回去會讓別名替換通知靜默消失。

        return $data;
    }

    protected function performUpdate(array $targetPk, array $updateData): void {
        // 計算更新後的 3-key，若有變動需檢查衝突
        $newPk3 = [
            'c_personid' => $targetPk['c_personid'],
            'c_alt_name_chn' => $updateData['c_alt_name_chn'] ?? $targetPk['c_alt_name_chn'],
            'c_alt_name_type_code' => $updateData['c_alt_name_type_code'] ?? $targetPk['c_alt_name_type_code'],
        ];

        if ($newPk3 !== array_intersect_key($targetPk, $newPk3)) {
            $conflict = DB::table('ALTNAME_DATA')->where([
                ['c_personid', '=', $newPk3['c_personid']],
                ['c_alt_name_chn', '=', $newPk3['c_alt_name_chn']],
                ['c_alt_name_type_code', '=', $newPk3['c_alt_name_type_code']],
            ])->first();
            if ($conflict) {
                throw new \InvalidArgumentException(
                    '此別名經括號格式正規化後，會與現有同類型別名重複，請先手動整理後再儲存。'
                );
            }
        }

        parent::performUpdate($targetPk, $updateData);
    }

    protected function handleDirect(int $personId, array $targetPk, array $updateData, array $originalArray, string $comment): JsonResponse {
        // 記錄原始狀態以便更新搜尋索引
        $originalObject = $this->findOriginalRow($targetPk);

        $response = parent::handleDirect($personId, $targetPk, $updateData, $originalArray, $comment);

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        // 更新搜尋索引
        if ($originalObject) {
            $this->syncAltnameIndexAfterUpdate($originalObject, $updateData, $targetPk);
        }

        return $response;
    }

    /**
     * 更新後同步名字搜尋索引
     *
     * 當別名的中文名稱（c_alt_name_chn）或類型代碼（c_alt_name_type_code）
     * 發生變更時，需從全文索引（CBDB__NAME_FTS）移除舊條目並重新索引新條目，
     * 以確保搜尋結果的正確性。
     */
    protected function syncAltnameIndexAfterUpdate(object $original, array $changes, array $targetPk): void {
        if (!Schema::hasTable('CBDB__NAME_FTS')) {
            return;
        }

        $newName = $changes['c_alt_name_chn'] ?? ($targetPk['c_alt_name_chn'] ?? null);
        $newType = $changes['c_alt_name_type_code'] ?? ($targetPk['c_alt_name_type_code'] ?? null);

        $nameChanged = ($original->c_alt_name_chn ?? null) !== $newName;
        $typeChanged = ($original->c_alt_name_type_code ?? null) !== $newType;

        if (!$nameChanged && !$typeChanged) {
            return;
        }

        if (!empty($original->c_alt_name_chn)) {
            $this->nameSearchIndexService->removeAltname(
                $original->c_personid,
                $original->c_alt_name_type_code,
                $original->c_alt_name_chn
            );
        }

        if (!empty($newName)) {
            $newPk = $this->buildNewPk($targetPk, $changes);
            $this->nameSearchIndexService->indexAltname(
                $newPk['c_personid'] ?? $targetPk['c_personid'],
                $newType,
                $newName
            );
        }
    }
}
