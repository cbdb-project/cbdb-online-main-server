<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 去級聯 Phase 1 — 批次 1：把「引用四張核心朝代/年號詞表」的外鍵由 ON DELETE CASCADE 翻成 RESTRICT。
 *
 * 依 docs/ON_DELETE_CASCADE_RISK.md（RESTRICT 先行、按被引用表分批、按入邊數排序）與
 * docs/CASCADE_TO_RESTRICT_MIGRATION_NOTES.md（MariaDB 10.3 實測結論）。
 *
 * 本批＝入邊數最高的四張詞表（共 66 條入邊 FK，皆單欄、皆 CASCADE）：
 *   NIAN_HAO(24)、YEAR_RANGE_CODES(23)、DYNASTIES(10)、GANZHI_CODES(9)。
 *
 * 作法（§CASCADE_TO_RESTRICT_MIGRATION_NOTES §1–5）：
 *  - 資料驅動但**範圍鎖定** REFERENCED_TABLES：讀 information_schema 實際 FK（含欄位），
 *    避免手抄 66 條、並容忍約束名與表名不一致（如 BIOG_TEXT_DATA 上的 TEXT_DATA_ibfk_1）。
 *  - 10.3 不允許同一 ALTER 內 DROP＋ADD 同名 FK（ERROR 1826）→ 拆兩條 ALTER。
 *  - foreign_key_checks=0 讓 ADD 免掃描（翻轉不改資料、一致性由原約束保證）。
 *  - ON UPDATE 行為本階段不動（保留 CASCADE）。
 *  - SQLite 測試環境無外鍵 → MySQL-only，SQLite 為 no-op。
 *
 * 前置（app-layer-first）：DYNASTIES/GANZHI_CODES 已在 CodesController::$readOnlyTables；
 * NIAN_HAO/YEAR_RANGE_CODES 為研究型詞表、無經 UI 的合法硬刪路徑。翻轉後任何漏網硬刪
 * 一律 fail-closed（1451），零資料損失。
 */
return new class () extends Migration {
    private const REFERENCED_TABLES = ['NIAN_HAO', 'YEAR_RANGE_CODES', 'DYNASTIES', 'GANZHI_CODES'];

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

        echo "[restrict-batch-1] {$onDelete}: flipped {$flipped} FK(s) referencing ".
             implode('/', self::REFERENCED_TABLES).PHP_EOL;
    }
};
