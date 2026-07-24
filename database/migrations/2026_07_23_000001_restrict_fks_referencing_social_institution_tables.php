<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 去級聯 Phase 1 — 批次 4：把「引用 SOCIAL_INSTITUTION_CODES／SOCIAL_INSTITUTION_NAME_CODES」
 * 的外鍵由 ON DELETE CASCADE 翻成 RESTRICT。
 *
 * 依 docs/ON_DELETE_CASCADE_RISK.md §6.1 與 docs/CASCADE_TO_RESTRICT_MIGRATION_NOTES.md，
 * 範式同批次 1–3（資料驅動、範圍鎖定 REFERENCED_TABLES、兩條 ALTER、foreign_key_checks=0、
 * SQLite no-op、down 可逆）。
 *
 * 本批＝社會機構兩張詞表（baseline 各 5 條入邊，含 SOCIAL_INSTITUTION_CODES→NAME_CODES 的
 * pair FK；資料表仍為兩支單欄 FK 的 dual-key 形態、未改複合鍵，翻轉不受影響）。
 * 「一機構多名」紅線（SOCIAL_INSTITUTION_ENTITY_MODEL §5.9）：翻成 RESTRICT 後，刪
 * name-entry 的穿透災難變成 fail-closed 報錯。
 *
 * 前置（app-layer-first）皆已就位：
 *  - codes UI 三表封寫（34eab20c step 4，closed_code_tables 推導 readOnly；performDestroy
 *    對全部碼表無條件封刪）；
 *  - 實體刪除走 SocialInstituteImportService::delete()——顯式級聯（先刪 ADDR 子列再刪
 *    CODES 列，逐列 operations／audit），不依賴 DB 級聯；guardWrite 有引用護欄（409）；
 *  - EntityAggregateDeleteHandler 已有通用 1451 友好報錯垫片。
 */
return new class () extends Migration {
    private const REFERENCED_TABLES = ['SOCIAL_INSTITUTION_CODES', 'SOCIAL_INSTITUTION_NAME_CODES'];

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

        echo "[restrict-batch-4] {$onDelete}: flipped {$flipped} FK(s) referencing ".
             implode('/', self::REFERENCED_TABLES).PHP_EOL;
    }
};
