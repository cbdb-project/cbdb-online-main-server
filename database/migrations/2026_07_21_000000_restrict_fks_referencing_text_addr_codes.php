<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 去級聯 Phase 1 — 批次 2：把「引用 TEXT_CODES／ADDR_CODES」的外鍵由 ON DELETE CASCADE 翻成 RESTRICT。
 *
 * 依 docs/ON_DELETE_CASCADE_RISK.md §6.1 與 docs/CASCADE_TO_RESTRICT_MIGRATION_NOTES.md
 * （批次單位＝被引用表、兩條 ALTER、foreign_key_checks=0、SQLite no-op），範式同批次 1
 * （2026_07_20_000000_restrict_fks_referencing_dynasty_batch.php）。
 *
 * 本批＝入邊數次高的兩張詞表（baseline 共 33 條入邊 FK，皆單欄、皆 CASCADE）：
 *   TEXT_CODES(22)、ADDR_CODES(11)。
 *
 * 前置（app-layer-first）：兩表經 UI 的刪除路徑均已封堵（CodesController::performDestroy、
 * CodeTableDeleteHandler 皆無條件擋下）；唯一活路徑 AdminBatchLoadBookTitlesController::undo()
 * 只刪本批次剛建立的 TEXT_CODES 列，已同步補上 1451 友好報錯垫片（同 commit）。翻轉後任何
 * 漏網硬刪一律 fail-closed（1451），零資料損失。
 */
return new class () extends Migration {
    private const REFERENCED_TABLES = ['TEXT_CODES', 'ADDR_CODES'];

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

        echo "[restrict-batch-2] {$onDelete}: flipped {$flipped} FK(s) referencing ".
             implode('/', self::REFERENCED_TABLES).PHP_EOL;
    }
};
