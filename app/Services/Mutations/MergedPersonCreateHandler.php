<?php

namespace App\Services\Mutations;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;

/**
 * 合併人物記錄（MERGED_PERSON_DATA）的 create handler。
 *
 * 用途：補錄「某已刪 CBDB 人物併入了哪個存活人物」的合併映射（CBDB API 的 buildMergeHint 依此把
 * 對已刪 id 的查詢導向 survivor）。PK = (c_personid=survivor, c_merged_from_personid=已刪 id)。
 *
 * 語意注意：`c_merged_from_personid` 是**刻意的已刪 id**（本就不在 BIOG_MAIN），故沿用基底的
 * 「person_id 僅與 PK 內 c_personid 一致性校驗、不做 BIOG_MAIN 存在性檢查」行為——person_id 對應
 * survivor。可寫欄：c_notes（合併原因，會展示給使用者）、c_source（證據出處 textid）、c_pages。
 */
class MergedPersonCreateHandler extends AbstractPersonSubresourceCreateHandler {
    public function __construct(
        OperationRepository $operationRepository,
        AuditLogService $auditLogService
    ) {
        parent::__construct($operationRepository, $auditLogService);
    }

    protected function resourceName(): string {
        return 'merged-person';
    }

    protected function tableName(): string {
        return 'MERGED_PERSON_DATA';
    }

    protected function displayName(): string {
        return '合併人物記錄';
    }

    protected function resourceAliases(): array {
        return ['merged-person', 'merged_person', 'merged_person_data', 'mergedperson'];
    }

    protected function keyColumns(): array {
        return ['c_personid', 'c_merged_from_personid'];
    }

    protected function allowedFields(): array {
        return [
            'c_personid',
            'c_merged_from_personid',
            'c_notes',
            'c_source',
            'c_pages',
        ];
    }

    // c_source 為 nullable FK（on delete set null），空值應留 NULL（非 0=Unknown 語意），故不做
    // normalizeEmptyCodeFields；缺 c_source 時交由 DB 預設 NULL。preprocessCreateData 維持基底 no-op。
}
