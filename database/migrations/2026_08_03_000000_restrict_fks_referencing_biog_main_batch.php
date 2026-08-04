<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 去級聯 Phase 1 — 末批：把「引用 BIOG_MAIN／POSTING_DATA／POSSESSION_DATA」的外鍵由
 * ON DELETE CASCADE 翻成 RESTRICT。本批翻完，全庫 ON DELETE CASCADE 歸零
 * （僅餘 1 條既有且正確的 SET NULL：fk_merged_person_source）。
 *
 * 依 docs/ON_DELETE_CASCADE_RISK.md §6.1 與 docs/CASCADE_TO_RESTRICT_MIGRATION_NOTES.md §11，
 * 範式同批次 1–4（資料驅動、範圍鎖定 REFERENCED_TABLES、兩條 ALTER、foreign_key_checks=0、
 * SQLite no-op、down 可逆）。
 *
 * 本批＝BIOG_MAIN(25，含 operations→BIOG_MAIN)＋POSTING_DATA(2)＋POSSESSION_DATA(1)＝28 條。
 *
 * 前置（app-layer-first，見 §11，已上線並經觀察期）：
 *  - BIOG_MAIN 無活的硬刪路徑——人物「刪除」是軟刪除（BiogMainDeleteHandler 走 UPDATE
 *    c_name_chn = '<待删除>'）；唯一產生 DELETE FROM BIOG_MAIN 的是 MergePreviewController
 *    生成、由人工執行的合併腳本，其 $map 已補齊全部 25 個指向 BIOG_MAIN 的欄位（§11.3），
 *    翻轉後漏網引用會被 1451 擋下（fail-closed），優於現行的靜默連坐刪除；
 *  - POSTING_DATA／POSSESSION_DATA 的連帶刪除已搬進應用層並改為先子後父、父列僅在無剩餘
 *    引用時才刪（OfficePostingRepository::deletePostingIfUnreferenced()、
 *    BiogMainRepository::possessionDeleteById、OperationsProposalController::applyDeleteProposal，§11.2）；
 *  - 連帶刪除逐列落 operations／audit_log（ExplicitCascadeLogger，§11.4），
 *    回歸測試見 tests/Feature/ExplicitCascadeDeleteTest.php。
 */
return new class () extends Migration {
    private const REFERENCED_TABLES = ['BIOG_MAIN', 'POSTING_DATA', 'POSSESSION_DATA'];

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

        echo "[restrict-batch-final] {$onDelete}: flipped {$flipped} FK(s) referencing ".
             implode('/', self::REFERENCED_TABLES).PHP_EOL;
    }
};
