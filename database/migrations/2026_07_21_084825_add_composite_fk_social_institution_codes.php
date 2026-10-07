<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 把「參照 SOCIAL_INSTITUTION_CODES.c_inst_code」的 5 條入邊 FK 由單欄升級成複合欄位
 * (c_inst_name_code, c_inst_code)。
 *
 * 背景：SOCIAL_INSTITUTION_CODES 主鍵是複合的 (c_inst_code, c_inst_name_code)，但既有
 * 入邊只單獨參照 c_inst_code（MariaDB/InnoDB 允許 FK 參照非唯一索引，不要求對齊唯一鍵）。
 * 這只保證「該 c_inst_code 值存在過」，不保證跟子表自己那筆的 c_inst_name_code 配對得上
 * ——同一個 c_inst_code 可能對應多個不同的 c_inst_name_code（如 3885、3909），單欄 FK 無法
 * 攔下「兩欄各自合法、但搭配錯誤」的髒資料。
 *
 * 入邊（information_schema 實測，皆 ON DELETE CASCADE ON UPDATE CASCADE，皆已含
 * c_inst_name_code 欄位）：ASSOC_DATA、BIOG_INST_DATA、ENTRY_DATA、
 * POSTED_TO_OFFICE_DATA、SOCIAL_INSTITUTION_ADDR。
 *
 * 複合 FK 欄位順序採 (c_inst_name_code, c_inst_code)。SOCIAL_INSTITUTION_CODES 現有主鍵
 * 順序相反（c_inst_code, c_inst_name_code），沒有這個順序的索引可用，因此本 migration
 * 先在父表補一個 UNIQUE (c_inst_name_code, c_inst_code) 索引再建 FK。這個欄位組合本來就
 * 唯一（PK 已保證，只是宣告順序不同），補索引不會有資料衝突風險。
 *
 * 刻意不關閉 foreign_key_checks：ADD CONSTRAINT 時讓 MySQL/MariaDB 實際驗證現有資料，
 * 若還有「c_inst_code 存在但配對不上 c_inst_name_code」的孤兒列，migration 會直接失敗
 * （1452），而不是靜默放行一個其實不成立的新約束保證。
 *
 * ON DELETE/ON UPDATE 行為維持原樣（CASCADE/CASCADE），本次只調整參照欄位組成，
 * 不屬於去級聯 phase 1 的 ON DELETE 行為翻轉範圍。
 *
 * SQLite 測試環境無外鍵 → MySQL-only，SQLite 為 no-op。
 */
return new class () extends Migration {
    private const TABLES = [
        'ASSOC_DATA',
        'BIOG_INST_DATA',
        'ENTRY_DATA',
        'POSTED_TO_OFFICE_DATA',
        'SOCIAL_INSTITUTION_ADDR',
    ];

    private const SUPPORT_INDEX = 'idx_social_institution_codes_name_code_inst_code';

    public function up(): void {
        $this->ensureSupportIndex();
        $this->migrate(toComposite: true);
    }

    public function down(): void {
        $this->migrate(toComposite: false);
        $this->dropSupportIndex();
    }

    /**
     * 父表補 UNIQUE (c_inst_name_code, c_inst_code) 索引，供複合 FK 參照（冪等）。
     */
    private function ensureSupportIndex(): void {
        if (!is_mysql()) {
            return;
        }

        $exists = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            ['SOCIAL_INSTITUTION_CODES', self::SUPPORT_INDEX]
        );

        if ($exists->n > 0) {
            return;
        }

        DB::statement(
            'ALTER TABLE `SOCIAL_INSTITUTION_CODES` '.
            'ADD UNIQUE INDEX `'.self::SUPPORT_INDEX.'` (`c_inst_name_code`, `c_inst_code`)'
        );
    }

    private function dropSupportIndex(): void {
        if (!is_mysql()) {
            return;
        }

        $exists = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            ['SOCIAL_INSTITUTION_CODES', self::SUPPORT_INDEX]
        );

        if ($exists->n === 0) {
            return;
        }

        DB::statement('ALTER TABLE `SOCIAL_INSTITUTION_CODES` DROP INDEX `'.self::SUPPORT_INDEX.'`');
    }

    private function migrate(bool $toComposite): void {
        if (!is_mysql()) {
            return; // SQLite 無外鍵
        }

        $inTables = implode(',', array_fill(0, count(self::TABLES), '?'));

        $fks = DB::select(
            'SELECT rc.CONSTRAINT_NAME AS name, rc.TABLE_NAME AS tbl,
                    rc.DELETE_RULE AS delete_rule, rc.UPDATE_RULE AS update_rule
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
               AND rc.REFERENCED_TABLE_NAME = \'SOCIAL_INSTITUTION_CODES\'
               AND rc.TABLE_NAME IN ('.$inTables.')',
            self::TABLES
        );

        $migrated = 0;

        foreach ($fks as $fk) {
            $cols = DB::select(
                'SELECT COLUMN_NAME AS col
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND CONSTRAINT_NAME = ? AND TABLE_NAME = ?
                   AND REFERENCED_TABLE_NAME IS NOT NULL
                 ORDER BY ORDINAL_POSITION',
                [$fk->name, $fk->tbl]
            );

            $isComposite = count($cols) === 2;

            // 冪等：up 只動還是單欄的、down 只動已經是複合的
            if ($toComposite === $isComposite) {
                continue;
            }

            $newCols = $toComposite ? ['c_inst_name_code', 'c_inst_code'] : ['c_inst_code'];
            $colList = implode(', ', array_map(fn ($c) => "`{$c}`", $newCols));

            DB::statement("ALTER TABLE `{$fk->tbl}` DROP FOREIGN KEY `{$fk->name}`");
            DB::statement(
                "ALTER TABLE `{$fk->tbl}` ADD CONSTRAINT `{$fk->name}` ".
                "FOREIGN KEY ({$colList}) REFERENCES `SOCIAL_INSTITUTION_CODES` ({$colList}) ".
                "ON DELETE {$fk->delete_rule} ON UPDATE {$fk->update_rule}"
            );
            $migrated++;
        }

        $dir = $toComposite ? 'single->composite' : 'composite->single';
        echo "[social-institution-composite-fk] {$dir}: migrated {$migrated} FK(s)".PHP_EOL;
    }
};
