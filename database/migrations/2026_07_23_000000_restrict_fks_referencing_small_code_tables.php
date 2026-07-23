<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 去級聯 Phase 1 — 批次 3：把「引用其餘小詞表」的外鍵由 ON DELETE CASCADE 翻成 RESTRICT。
 *
 * 依 docs/CASCADE_TO_RESTRICT_MIGRATION_NOTES.md（批次單位＝被引用表、兩條 ALTER、
 * foreign_key_checks=0、SQLite no-op），範式同批次 1／2。
 *
 * 本批＝剩餘全部小詞表／類型表／樹表（入邊多為 1–6 條，含自引用 pair FK 與 *_TYPE_REL 關聯表入邊）。
 * 名單為顯式鎖定（受控分批）；實際入邊由 information_schema 於執行時讀取，
 * 已被後續 migration 移除的 FK（如 OFFICE_CATEGORIES、APPOINTMENT_TYPES 入邊）自然不會出現。
 *
 * 本批「不含」：
 * - BIOG_MAIN——末批，需配套顯式級聯刪除服務；
 * - SOCIAL_INSTITUTION_CODES／SOCIAL_INSTITUTION_NAME_CODES——「一機構多名」安全前提未完成
 *   （社會機構 step 4）；其 *_TYPES 類型詞表無此顧慮，照常納入本批；
 * - 資料表 POSTING_DATA／POSSESSION_DATA／EVENTS_DATA——app 有活刪除路徑依賴其子表級聯
 *   （OperationsProposalController、OfficePostingRepository、BiogMainRepository、EVENTS_ADDR 複合 FK），
 *   需改為顯式級聯後另批處理。
 *
 * 前置（app-layer-first）：本批詞表經 UI 的刪除路徑均已封堵（CodesController::performDestroy、
 * CodeTableDeleteHandler 皆無條件擋下），app 內亦無其他硬刪呼叫點；唯一活路徑
 * OfficeDeleteHandler → OfficeImportService::delete()（先刪 OFFICE_CODE_TYPE_REL、且有
 * POSTED_TO_OFFICE_DATA 引用護欄），已同步補上 errno 1451 友好報錯垫片（同 commit），
 * 涵蓋 POSTED_TO_ADDR_DATA 殘留引用等漏網情形。翻轉後任何漏網硬刪一律 fail-closed（1451），
 * 零資料損失。
 */
return new class () extends Migration {
    private const REFERENCED_TABLES = [
        // 入邊 2 條以上
        'KINSHIP_CODES',
        'ASSOC_CODES',
        'OFFICE_CODES',
        'EVENT_CODES',
        'TEXT_BIBLCAT_CODES',
        'STATUS_CODES',
        'ENTRY_CODES',
        'COUNTRY_CODES',
        'APPOINTMENT_CODES',
        'OFFICE_TYPE_TREE',
        'ADMIN_CAT_CODES',
        // 入邊 1 條
        'ADMIN_CAT_TYPES',
        'ALTNAME_CODES',
        'APPOINTMENT_TYPES',
        'ASSOC_TYPES',
        'ASSUME_OFFICE_CODES',
        'BIOG_ADDR_CODES',
        'BIOG_INST_CODES',
        'CHORONYM_CODES',
        'ENTRY_TYPES',
        'ETHNICITY_TRIBE_CODES',
        'EXTANT_CODES',
        'HOUSEHOLD_STATUS_CODES',
        'LITERARYGENRE_CODES',
        'MEASURE_CODES',
        'OCCASION_CODES',
        'OFFICE_CATEGORIES',
        'PARENTAL_STATUS_CODES',
        'POSSESSION_ACT_CODES',
        'SCHOLARLYTOPIC_CODES',
        'SOCIAL_INSTITUTION_ADDR_TYPES',
        'SOCIAL_INSTITUTION_TYPES',
        'STATUS_TYPES',
        'TEXT_BIBLCAT_TYPES',
        'TEXT_ROLE_CODES',
    ];

    public function up(): void {
        $this->flip('RESTRICT', ['CASCADE']);
    }

    public function down(): void {
        $this->flip('CASCADE', ['RESTRICT', 'NO ACTION']);
    }

    /**
     * @param string $onDelete 目標 ON DELETE 行為
     * @param array<int,string> $fromRules 只翻目前為這些 DELETE_RULE 的 FK
     */
    private function flip(string $onDelete, array $fromRules): void {
        if (!is_mysql()) {
            return; // SQLite 無外鍵
        }

        $inRefs = implode(',', array_fill(0, count(self::REFERENCED_TABLES), '?'));
        $inRules = implode(',', array_fill(0, count($fromRules), '?'));

        $fks = DB::select(
            'SELECT rc.CONSTRAINT_NAME AS name, rc.TABLE_NAME AS tbl,
                    rc.REFERENCED_TABLE_NAME AS ref_tbl, rc.UPDATE_RULE AS update_rule
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
               AND rc.REFERENCED_TABLE_NAME IN ('.$inRefs.')
               AND rc.DELETE_RULE IN ('.$inRules.')',
            array_merge(self::REFERENCED_TABLES, $fromRules)
        );

        DB::statement('SET SESSION foreign_key_checks = 0');
        $flipped = 0;

        try {
            foreach ($fks as $fk) {
                $cols = DB::select(
                    'SELECT COLUMN_NAME AS col, REFERENCED_COLUMN_NAME AS ref_col
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE CONSTRAINT_SCHEMA = DATABASE()
                       AND CONSTRAINT_NAME = ? AND TABLE_NAME = ?
                       AND REFERENCED_TABLE_NAME IS NOT NULL
                     ORDER BY ORDINAL_POSITION',
                    [$fk->name, $fk->tbl]
                );
                $fkCols = implode(', ', array_map(fn ($x) => "`{$x->col}`", $cols));
                $refCols = implode(', ', array_map(fn ($x) => "`{$x->ref_col}`", $cols));

                DB::statement("ALTER TABLE `{$fk->tbl}` DROP FOREIGN KEY `{$fk->name}`");
                DB::statement(
                    "ALTER TABLE `{$fk->tbl}` ADD CONSTRAINT `{$fk->name}` ".
                    "FOREIGN KEY ({$fkCols}) REFERENCES `{$fk->ref_tbl}` ({$refCols}) ".
                    "ON DELETE {$onDelete} ON UPDATE {$fk->update_rule}"
                );
                $flipped++;
            }
        } finally {
            DB::statement('SET SESSION foreign_key_checks = 1');
        }

        echo "[restrict-batch-3] {$onDelete}: flipped {$flipped} FK(s) referencing ".
             count(self::REFERENCED_TABLES).' small code table(s)'.PHP_EOL;
    }
};
