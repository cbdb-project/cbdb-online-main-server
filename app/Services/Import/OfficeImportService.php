<?php

namespace App\Services\Import;

use App\Repositories\OperationRepository;
use App\Services\AuditLogService;
use App\Services\Import\Concerns\SharesImportHelpers;
use Illuminate\Support\Facades\DB;

/**
 * 「新增一個官職（OFFICE_CODES + OFFICE_CODE_TYPE_REL）」的存儲過程之單一真源。
 *
 * 由 admin 批量表單（AdminBatchLoadOfficesController）與 mutation API（OfficeImportHandler）共用，
 * 確保派生（拼音/朝代碼）、自動 id、配套關聯行與審計（operations + AuditLog）只有一份實作、不漂移。
 * 共用的派生／校驗／審計基元見 SharesImportHelpers（與 SocialInstituteImportService 共用）。
 *
 * 一次「加官職」= 原子寫入兩表：
 *  - OFFICE_CODES：c_office_id(max+1)、c_office_pinyin(派生)、c_dy、c_office_chn/trans、c_source
 *  - OFFICE_CODE_TYPE_REL：(c_office_id, c_office_tree_id=type_id)；官職可屬多個類型節點，故此為集合。
 *
 * OFFICE_CODES 無 c_created_by/date 審計欄（見生產 DB schema），故直接 plain insert、
 * 不走 ToolsRepository::timestamp（否則會 INSERT 不存在的欄位而 500）；審計改由
 * operations + audit_log 承載。與 SocialInstituteImportService 一致。
 *
 * 除 create() 外亦提供 load()／update()／delete()，作為「官職實體」聚合 CRUD 的單一真源，
 * 供 mutation API（通用 EntityAggregate*Handler → OfficeAggregateDefinition）與前端
 * 聚合編輯頁共用。update() 對 OFFICE_CODE_TYPE_REL 做集合對賬（僅增刪差異、不整批重寫），
 * 避免多類型官職被靜默刪去其他類型。delete() 前須先 referenceCount() 檢查是否仍被人物任官引用。
 *
 * 寫入方法皆不自開交易，於呼叫端交易內執行以保留「全有或全無」語意；呼叫端須先過濾非法輸入。
 */
class OfficeImportService implements EntityAggregateService {
    use SharesImportHelpers;

    public function __construct(
        protected OperationRepository $operationRepository,
        protected AuditLogService $auditLogService
    ) {
    }

    /** 校驗官職類型節點存在於 OFFICE_TYPE_TREE；回傳缺失的 id。 */
    public function missingOfficeTypes(array $typeIds): array {
        $unique = array_values(array_unique(array_filter($typeIds, fn ($v) => $v !== '' && $v !== null)));
        if (empty($unique)) {
            return [];
        }
        $found = DB::table('OFFICE_TYPE_TREE')->whereIn('c_office_type_node_id', $unique)->pluck('c_office_type_node_id')->all();

        return array_values(array_diff($unique, $found));
    }

    /**
     * 官職被人物任官（POSTED_TO_OFFICE_DATA）引用的筆數；>0 表示不可刪除。
     *
     * ⚠ 安全關鍵：POSTED_TO_OFFICE_DATA.c_office_id → OFFICE_CODES 的外鍵為 ON DELETE CASCADE，
     * 若在有引用時刪除官職，資料庫會連帶刪掉這些人物的任官紀錄（人物資料靜默損毀）。
     * 呼叫端（handler）務必先以本方法擋下有引用者，勿移除此護欄。
     * （POSTED_TO_ADDR_DATA 依附於 POSTED_TO_OFFICE_DATA，擋下後者即涵蓋。）
     */
    public function referenceCount(int $officeId): int {
        return $this->countReferences([['POSTED_TO_OFFICE_DATA', 'c_office_id']], $officeId);
    }

    /** 載入單一官職聚合（供編輯頁與 API 讀取）；不存在回 null。 */
    public function load(int $officeId): ?array {
        $row = DB::table('OFFICE_CODES')->where('c_office_id', $officeId)->first();
        if (!$row) {
            return null;
        }
        $typeIds = DB::table('OFFICE_CODE_TYPE_REL')
            ->where('c_office_id', $officeId)
            ->orderBy('c_office_tree_id')
            ->pluck('c_office_tree_id')
            ->map(fn ($v) => (string) $v)
            ->all();

        return [
            'office_id' => (int) $row->c_office_id,
            'name' => (string) ($row->c_office_chn ?? ''),
            'name_alt' => $row->c_office_chn_alt !== null ? (string) $row->c_office_chn_alt : null,
            'translation' => $row->c_office_trans !== null ? (string) $row->c_office_trans : null,
            'translation_alt' => $row->c_office_trans_alt !== null ? (string) $row->c_office_trans_alt : null,
            'pinyin' => (string) ($row->c_office_pinyin ?? ''),
            'pinyin_alt' => $row->c_office_pinyin_alt !== null ? (string) $row->c_office_pinyin_alt : null,
            'dynasty_code' => $row->c_dy !== null ? (int) $row->c_dy : null,
            'source_id' => $row->c_source !== null ? (int) $row->c_source : null,
            'pages' => $row->c_pages !== null ? (string) $row->c_pages : null,
            'notes' => $row->c_notes !== null ? (string) $row->c_notes : null,
            'type_ids' => $typeIds,
        ];
    }

    /**
     * 由業務輸入組出 OFFICE_CODES 各欄位（create／update 共用，確保欄位語意一致）。
     * 拼音留空則自動依對應中文派生（name→c_office_pinyin、name_alt→c_office_pinyin_alt）；
     * 給值則逐字採用。其餘選填欄空字串折成 null。
     *
     * @param array{name:string,name_alt?:?string,translation?:?string,translation_alt?:?string,pinyin?:?string,pinyin_alt?:?string,dynasty_code:int,source_id:int,pages?:?string,notes?:?string} $input
     */
    protected function officeColumns(array $input): array {
        $name = (string) $input['name'];
        $nameAlt = (isset($input['name_alt']) && trim((string) $input['name_alt']) !== '') ? (string) $input['name_alt'] : null;

        $pinyin = (isset($input['pinyin']) && trim((string) $input['pinyin']) !== '')
            ? (string) $input['pinyin']
            : $this->buildPinyin($name);
        $pinyinAlt = (isset($input['pinyin_alt']) && trim((string) $input['pinyin_alt']) !== '')
            ? (string) $input['pinyin_alt']
            : ($nameAlt !== null ? $this->buildPinyin($nameAlt) : null);

        $opt = fn ($v) => (isset($v) && $v !== '') ? (string) $v : null;

        return [
            'c_dy' => (int) $input['dynasty_code'],
            'c_office_pinyin' => $pinyin,
            'c_office_chn' => $name,
            'c_office_pinyin_alt' => $pinyinAlt,
            'c_office_chn_alt' => $nameAlt,
            'c_office_trans' => $opt($input['translation'] ?? null),
            'c_office_trans_alt' => $opt($input['translation_alt'] ?? null),
            'c_source' => (int) $input['source_id'],
            'c_pages' => $opt($input['pages'] ?? null),
            'c_notes' => $opt($input['notes'] ?? null),
        ];
    }

    /**
     * 執行「新增一個官職」過程（於呼叫端交易內）。輸入須已驗證。
     * 類型可給單值（type_id，向後相容批量匯入）或多值（type_ids[]）。
     *
     * @param array{name:string,translation:?string,dynasty_code:int,type_id?:string|int,type_ids?:array,source_id:int} $input
     * @return array{office_id:int,pinyin:string,type_ids:array,operation_id_office:?int,operation_id_rel:?int}
     */
    public function create(array $input, int $actorPersonId = 0): array {
        $officeId = $this->allocateNextId('OFFICE_CODES', 'c_office_id');
        $columns = $this->officeColumns($input);
        $pinyin = $columns['c_office_pinyin'];

        $officePayload = array_merge(['c_office_id' => $officeId], $columns);

        DB::table('OFFICE_CODES')->insert($officePayload);
        $officeOp = $this->recordOp('OFFICE_CODES', ['c_office_id' => $officeId], $officePayload, $actorPersonId);

        $typeIds = $this->typeIdList($input);
        $relOps = [];
        foreach ($typeIds as $tid) {
            $relPayload = ['c_office_id' => $officeId, 'c_office_tree_id' => $tid];
            DB::table('OFFICE_CODE_TYPE_REL')->insert($relPayload);
            $relOps[] = $this->recordOp('OFFICE_CODE_TYPE_REL', $relPayload, $relPayload, $actorPersonId);
        }

        return [
            'office_id' => $officeId,
            'pinyin' => $pinyin,
            'type_ids' => $typeIds,
            'operation_id_office' => $officeOp?->id,
            'operation_id_rel' => $relOps[0]?->id ?? null,
        ];
    }

    /**
     * 更新官職聚合（於呼叫端交易內）。輸入須已驗證，且官職須存在。
     * OFFICE_CODES 欄位整體覆寫；OFFICE_CODE_TYPE_REL 做集合對賬（僅增刪差異）。
     *
     * @param array{name:string,translation:?string,dynasty_code:int,type_ids:array,source_id:int} $input
     * @return array{office_id:int,pinyin:string,type_ids:array,types_added:array,types_removed:array,operation_id_office:?int}
     */
    public function update(int $officeId, array $input, int $actorPersonId = 0): array {
        $before = (array) DB::table('OFFICE_CODES')->where('c_office_id', $officeId)->lockForUpdate()->first();
        $after = $this->officeColumns($input);
        $pinyin = $after['c_office_pinyin'];
        DB::table('OFFICE_CODES')->where('c_office_id', $officeId)->update($after);
        $officeOp = $this->recordUpdate(
            'OFFICE_CODES',
            ['c_office_id' => $officeId],
            $before,
            array_merge(['c_office_id' => $officeId], $after),
            $actorPersonId
        );

        // REL 集合對賬（reconcileRowSet）：只刪不再需要的、只加尚未存在的，逐筆記 op
        // （可個別復原）。純關聯表無非鍵欄，同鍵永不改寫（updatableColumns=null）。
        $desired = $this->typeIdList($input);
        $current = DB::table('OFFICE_CODE_TYPE_REL')
            ->where('c_office_id', $officeId)
            ->pluck('c_office_tree_id')
            ->map(fn ($v) => (string) $v)
            ->all();
        $toAdd = array_values(array_diff($desired, $current));
        $toRemove = array_values(array_diff($current, $desired));

        $relRow = fn (string $tid) => ['c_office_id' => $officeId, 'c_office_tree_id' => $tid];
        $this->reconcileRowSet(
            'OFFICE_CODE_TYPE_REL',
            array_combine($current, array_map($relRow, $current)),
            array_combine($desired, array_map($relRow, $desired)),
            fn (array $row) => $row,
            fn (array $row) => $row,
            null,
            $actorPersonId
        );

        return [
            'office_id' => $officeId,
            'pinyin' => $pinyin,
            'type_ids' => $desired,
            'types_added' => $toAdd,
            'types_removed' => $toRemove,
            'operation_id_office' => $officeOp?->id,
        ];
    }

    /**
     * 刪除官職聚合（於呼叫端交易內）：先刪 OFFICE_CODE_TYPE_REL 各行、再刪 OFFICE_CODES，逐筆記 op。
     * 呼叫端須先以 referenceCount() 確認未被人物任官引用。
     *
     * @return array{office_id:int,rel_deleted:int,operation_id_office:?int}
     */
    public function delete(int $officeId, int $actorPersonId = 0): array {
        $officeBefore = (array) DB::table('OFFICE_CODES')->where('c_office_id', $officeId)->first();
        $rels = DB::table('OFFICE_CODE_TYPE_REL')->where('c_office_id', $officeId)->get();
        foreach ($rels as $rel) {
            $pk = ['c_office_id' => $officeId, 'c_office_tree_id' => (string) $rel->c_office_tree_id];
            DB::table('OFFICE_CODE_TYPE_REL')
                ->where('c_office_id', $officeId)
                ->where('c_office_tree_id', $rel->c_office_tree_id)
                ->delete();
            $this->recordDelete('OFFICE_CODE_TYPE_REL', $pk, (array) $rel, $actorPersonId);
        }
        DB::table('OFFICE_CODES')->where('c_office_id', $officeId)->delete();
        $officeOp = $this->recordDelete('OFFICE_CODES', ['c_office_id' => $officeId], $officeBefore, $actorPersonId);

        return [
            'office_id' => $officeId,
            'rel_deleted' => count($rels),
            'operation_id_office' => $officeOp?->id,
        ];
    }

    /** 從輸入取類型 id 集合：優先 type_ids[]，否則單值 type_id；正規化為去重、非空字串。 */
    protected function typeIdList(array $input): array {
        if (isset($input['type_ids']) && is_array($input['type_ids'])) {
            $ids = $input['type_ids'];
        } elseif (isset($input['type_id']) && $input['type_id'] !== null && $input['type_id'] !== '') {
            $ids = [$input['type_id']];
        } else {
            $ids = [];
        }
        $out = [];
        foreach ($ids as $v) {
            $v = (string) $v;
            if ($v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        return $out;
    }
}
