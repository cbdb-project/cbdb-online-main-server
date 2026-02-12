<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/helpers.php';

return new class () extends Migration {
    /**
     * 將 42 個欄位從 INT/DOUBLE 轉換為 SMALLINT (修正版)
     *
     * 修正內容:
     * 1. ✅ 完整的 FK 刪除 (涵蓋 parent 和 child 欄位)
     * 2. ✅ 保留 CASCADE 行為 (ON DELETE/UPDATE)
     * 3. ✅ SQLite 兼容性 (使用 is_sqlite() 判斷)
     */
    public function up(): void {
        // SQLite: 測試環境使用 in-memory 資料庫，每次重建，跳過此 migration
        if (is_sqlite()) {
            return;
        }

        // MySQL/MariaDB: 執行完整的修改流程
        if (is_mysql()) {
            // 1. 移除相關的外鍵約束
            $foreignKeys = $this->dropForeignKeys();

            // 2. 修改欄位型別
            $this->modifyColumnTypes();

            // 3. 重新建立外鍵約束 (包含 CASCADE 行為)
            $this->restoreForeignKeys($foreignKeys);
        }
    }

    /**
     * 移除相關的外鍵約束 (涵蓋 parent 和 child 欄位)
     */
    private function dropForeignKeys(): array {
        $foreignKeys = [];

        // 查詢所有需要處理的外鍵 (包含 CASCADE 行為)
        // 使用 JOIN 取得 UPDATE_RULE 和 DELETE_RULE
        $constraints = DB::select("
            SELECT
                kcu.TABLE_NAME,
                kcu.COLUMN_NAME,
                kcu.CONSTRAINT_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE kcu
            JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
            WHERE kcu.TABLE_SCHEMA = DATABASE()
            AND kcu.CONSTRAINT_NAME != 'PRIMARY'
            AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            AND (
                -- ===== Child FK: 修改的是外鍵欄位 =====
                -- ADDR_CODES
                (kcu.TABLE_NAME = 'ADDR_CODES' AND kcu.COLUMN_NAME = 'c_admin_cat_code')
                -- ASSOC_DATA
                OR (kcu.TABLE_NAME = 'ASSOC_DATA' AND kcu.COLUMN_NAME IN ('c_inst_code', 'c_litgenre_code', 'c_occasion_code', 'c_topic_code'))
                -- BIOG_ADDR_DATA
                OR (kcu.TABLE_NAME = 'BIOG_ADDR_DATA' AND kcu.COLUMN_NAME IN ('c_firstyear', 'c_lastyear'))
                -- BIOG_INST_DATA
                OR (kcu.TABLE_NAME = 'BIOG_INST_DATA' AND kcu.COLUMN_NAME = 'c_inst_code')
                -- ENTRY_DATA
                OR (kcu.TABLE_NAME = 'ENTRY_DATA' AND kcu.COLUMN_NAME = 'c_inst_code')
                -- EVENTS_ADDR
                OR (kcu.TABLE_NAME = 'EVENTS_ADDR' AND kcu.COLUMN_NAME = 'c_event_code')
                -- EVENTS_DATA
                OR (kcu.TABLE_NAME = 'EVENTS_DATA' AND kcu.COLUMN_NAME = 'c_event_code')
                -- POSTED_TO_OFFICE_DATA
                OR (kcu.TABLE_NAME = 'POSTED_TO_OFFICE_DATA' AND kcu.COLUMN_NAME = 'c_inst_code')
                -- SOCIAL_INSTITUTION_ADDR
                OR (kcu.TABLE_NAME = 'SOCIAL_INSTITUTION_ADDR' AND kcu.COLUMN_NAME = 'c_inst_code')
                -- TEXT_INSTANCE_DATA
                OR (kcu.TABLE_NAME = 'TEXT_INSTANCE_DATA' AND kcu.COLUMN_NAME IN ('c_extant', 'c_text_edition_id', 'c_text_instance_id'))

                -- ===== Parent FK: 修改的是被引用欄位 =====
                OR (
                    kcu.REFERENCED_TABLE_NAME IN (
                        'ADMIN_CAT_CODES',
                        'EVENT_CODES',
                        'LITERARYGENRE_CODES',
                        'OCCASION_CODES',
                        'SCHOLARLYTOPIC_CODES',
                        'SOCIAL_INSTITUTION_CODES'
                    )
                    AND kcu.REFERENCED_COLUMN_NAME IN (
                        'c_admin_cat_code',
                        'c_event_code',
                        'c_inst_code',
                        'c_lit_genre_code',
                        'c_occasion_code',
                        'c_topic_code'
                    )
                )
            )
        ");

        // 移除外鍵並記錄以便稍後恢復
        foreach ($constraints as $constraint) {
            $foreignKeys[] = $constraint;
            DB::statement("ALTER TABLE `{$constraint->TABLE_NAME}` DROP FOREIGN KEY `{$constraint->CONSTRAINT_NAME}`");
        }

        return $foreignKeys;
    }

    /**
     * 修改欄位型別為 SMALLINT
     */
    private function modifyColumnTypes(): void {
        // ADDR_CODES
        DB::statement("ALTER TABLE `ADDR_CODES` MODIFY COLUMN `c_admin_cat_code` SMALLINT NOT NULL DEFAULT 0");

        // ADMIN_CAT_CODES
        DB::statement("ALTER TABLE `ADMIN_CAT_CODES` MODIFY COLUMN `c_admin_cat_code` SMALLINT NOT NULL");

        // ASSOC_DATA
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_assoc_first_year` SMALLINT NOT NULL DEFAULT -9999");
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_assoc_last_year` SMALLINT");
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_inst_code` SMALLINT DEFAULT 0");
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_litgenre_code` SMALLINT");
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_occasion_code` SMALLINT");
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_sequence` SMALLINT DEFAULT 0");
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_topic_code` SMALLINT");

        // BIOG_ADDR_CODES
        DB::statement("ALTER TABLE `BIOG_ADDR_CODES` MODIFY COLUMN `c_index_addr_default_rank` SMALLINT");
        DB::statement("ALTER TABLE `BIOG_ADDR_CODES` MODIFY COLUMN `c_index_addr_rank` SMALLINT");

        // BIOG_ADDR_DATA
        DB::statement("ALTER TABLE `BIOG_ADDR_DATA` MODIFY COLUMN `c_firstyear` SMALLINT");
        DB::statement("ALTER TABLE `BIOG_ADDR_DATA` MODIFY COLUMN `c_lastyear` SMALLINT");
        DB::statement("ALTER TABLE `BIOG_ADDR_DATA` MODIFY COLUMN `c_sequence` SMALLINT NOT NULL");

        // BIOG_INST_DATA
        DB::statement("ALTER TABLE `BIOG_INST_DATA` MODIFY COLUMN `c_inst_code` SMALLINT NOT NULL");

        // BIOG_MAIN
        DB::statement("ALTER TABLE `BIOG_MAIN` MODIFY COLUMN `c_index_addr_type_code` SMALLINT");
        DB::statement("ALTER TABLE `BIOG_MAIN` MODIFY COLUMN `c_index_year` SMALLINT");

        // ENTRY_DATA
        DB::statement("ALTER TABLE `ENTRY_DATA` MODIFY COLUMN `c_inst_code` SMALLINT NOT NULL DEFAULT 0");

        // ENTRY_TYPES
        DB::statement("ALTER TABLE `ENTRY_TYPES` MODIFY COLUMN `c_entry_type_level` SMALLINT");
        DB::statement("ALTER TABLE `ENTRY_TYPES` MODIFY COLUMN `c_entry_type_sortorder` SMALLINT");

        // ETHNICITY_TRIBE_CODES
        DB::statement("ALTER TABLE `ETHNICITY_TRIBE_CODES` MODIFY COLUMN `c_altname_code` SMALLINT");
        DB::statement("ALTER TABLE `ETHNICITY_TRIBE_CODES` MODIFY COLUMN `c_group_code` SMALLINT");
        DB::statement("ALTER TABLE `ETHNICITY_TRIBE_CODES` MODIFY COLUMN `c_subgroup_code` SMALLINT");

        // EVENT_CODES
        DB::statement("ALTER TABLE `EVENT_CODES` MODIFY COLUMN `c_event_code` SMALLINT NOT NULL");

        // EVENTS_ADDR
        DB::statement("ALTER TABLE `EVENTS_ADDR` MODIFY COLUMN `c_event_code` SMALLINT NOT NULL DEFAULT 0");

        // EVENTS_DATA
        DB::statement("ALTER TABLE `EVENTS_DATA` MODIFY COLUMN `c_event_code` SMALLINT");

        // LITERARYGENRE_CODES
        DB::statement("ALTER TABLE `LITERARYGENRE_CODES` MODIFY COLUMN `c_lit_genre_code` SMALLINT NOT NULL");
        DB::statement("ALTER TABLE `LITERARYGENRE_CODES` MODIFY COLUMN `c_sortorder` SMALLINT");

        // OCCASION_CODES
        DB::statement("ALTER TABLE `OCCASION_CODES` MODIFY COLUMN `c_occasion_code` SMALLINT NOT NULL");
        DB::statement("ALTER TABLE `OCCASION_CODES` MODIFY COLUMN `c_sortorder` SMALLINT");

        // POSSESSION_DATA
        DB::statement("ALTER TABLE `POSSESSION_DATA` MODIFY COLUMN `c_sequence` SMALLINT");

        // POSTED_TO_OFFICE_DATA
        DB::statement("ALTER TABLE `POSTED_TO_OFFICE_DATA` MODIFY COLUMN `c_inst_code` SMALLINT DEFAULT 0");
        DB::statement("ALTER TABLE `POSTED_TO_OFFICE_DATA` MODIFY COLUMN `c_ly_day` SMALLINT");

        // SCHOLARLYTOPIC_CODES
        DB::statement("ALTER TABLE `SCHOLARLYTOPIC_CODES` MODIFY COLUMN `c_sortorder` SMALLINT");
        DB::statement("ALTER TABLE `SCHOLARLYTOPIC_CODES` MODIFY COLUMN `c_topic_code` SMALLINT NOT NULL");
        DB::statement("ALTER TABLE `SCHOLARLYTOPIC_CODES` MODIFY COLUMN `c_topic_type_code` SMALLINT");

        // SOCIAL_INSTITUTION_ADDR
        DB::statement("ALTER TABLE `SOCIAL_INSTITUTION_ADDR` MODIFY COLUMN `c_inst_code` SMALLINT NOT NULL");

        // SOCIAL_INSTITUTION_CODES
        DB::statement("ALTER TABLE `SOCIAL_INSTITUTION_CODES` MODIFY COLUMN `c_inst_code` SMALLINT NOT NULL");

        // STATUS_DATA
        DB::statement("ALTER TABLE `STATUS_DATA` MODIFY COLUMN `c_sequence` SMALLINT NOT NULL");

        // TEXT_INSTANCE_DATA
        DB::statement("ALTER TABLE `TEXT_INSTANCE_DATA` MODIFY COLUMN `c_extant` SMALLINT");
        DB::statement("ALTER TABLE `TEXT_INSTANCE_DATA` MODIFY COLUMN `c_text_edition_id` SMALLINT NOT NULL");
        DB::statement("ALTER TABLE `TEXT_INSTANCE_DATA` MODIFY COLUMN `c_text_instance_id` SMALLINT NOT NULL");
    }

    /**
     * 重新建立外鍵約束 (包含 CASCADE 行為)
     */
    private function restoreForeignKeys(array $foreignKeys): void {
        foreach ($foreignKeys as $fk) {
            // 建構 ON DELETE 和 ON UPDATE 子句
            $onDelete = $fk->DELETE_RULE ? "ON DELETE {$fk->DELETE_RULE}" : '';
            $onUpdate = $fk->UPDATE_RULE ? "ON UPDATE {$fk->UPDATE_RULE}" : '';

            DB::statement("
                ALTER TABLE `{$fk->TABLE_NAME}`
                ADD CONSTRAINT `{$fk->CONSTRAINT_NAME}`
                FOREIGN KEY (`{$fk->COLUMN_NAME}`)
                REFERENCES `{$fk->REFERENCED_TABLE_NAME}` (`{$fk->REFERENCED_COLUMN_NAME}`)
                {$onDelete}
                {$onUpdate}
            ");
        }
    }

    /**
     * 回滾遷移（將欄位恢復為原始型別）
     */
    public function down(): void {
        // SQLite: 跳過
        if (is_sqlite()) {
            return;
        }

        // MySQL/MariaDB: 執行回滾
        if (is_mysql()) {
            // 移除外鍵
            $foreignKeys = $this->dropForeignKeys();

            // 恢復原始型別
            $this->restoreOriginalTypes();

            // 恢復外鍵
            $this->restoreForeignKeys($foreignKeys);
        }
    }

    /**
     * 恢復原始資料型別
     */
    private function restoreOriginalTypes(): void {
        // ADDR_CODES
        DB::statement("ALTER TABLE `ADDR_CODES` MODIFY COLUMN `c_admin_cat_code` INT(11) NOT NULL DEFAULT 0");

        // ADMIN_CAT_CODES
        DB::statement("ALTER TABLE `ADMIN_CAT_CODES` MODIFY COLUMN `c_admin_cat_code` INT(11) NOT NULL");

        // ASSOC_DATA
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_assoc_first_year` INT(11) NOT NULL DEFAULT -9999");
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_assoc_last_year` INT(11)");
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_inst_code` INT(11) DEFAULT 0");
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_litgenre_code` INT(11)");
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_occasion_code` INT(11)");
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_sequence` INT(11) DEFAULT 0");
        DB::statement("ALTER TABLE `ASSOC_DATA` MODIFY COLUMN `c_topic_code` INT(11)");

        // BIOG_ADDR_CODES
        DB::statement("ALTER TABLE `BIOG_ADDR_CODES` MODIFY COLUMN `c_index_addr_default_rank` INT(6)");
        DB::statement("ALTER TABLE `BIOG_ADDR_CODES` MODIFY COLUMN `c_index_addr_rank` INT(6)");

        // BIOG_ADDR_DATA
        DB::statement("ALTER TABLE `BIOG_ADDR_DATA` MODIFY COLUMN `c_firstyear` INT(11)");
        DB::statement("ALTER TABLE `BIOG_ADDR_DATA` MODIFY COLUMN `c_lastyear` INT(11)");
        DB::statement("ALTER TABLE `BIOG_ADDR_DATA` MODIFY COLUMN `c_sequence` INT(11) NOT NULL");

        // BIOG_INST_DATA
        DB::statement("ALTER TABLE `BIOG_INST_DATA` MODIFY COLUMN `c_inst_code` INT(11) NOT NULL");

        // BIOG_MAIN
        DB::statement("ALTER TABLE `BIOG_MAIN` MODIFY COLUMN `c_index_addr_type_code` INT(6)");
        DB::statement("ALTER TABLE `BIOG_MAIN` MODIFY COLUMN `c_index_year` INT(11)");

        // ENTRY_DATA
        DB::statement("ALTER TABLE `ENTRY_DATA` MODIFY COLUMN `c_inst_code` INT(11) NOT NULL DEFAULT 0");

        // ENTRY_TYPES
        DB::statement("ALTER TABLE `ENTRY_TYPES` MODIFY COLUMN `c_entry_type_level` DOUBLE");
        DB::statement("ALTER TABLE `ENTRY_TYPES` MODIFY COLUMN `c_entry_type_sortorder` DOUBLE");

        // ETHNICITY_TRIBE_CODES
        DB::statement("ALTER TABLE `ETHNICITY_TRIBE_CODES` MODIFY COLUMN `c_altname_code` INT(11)");
        DB::statement("ALTER TABLE `ETHNICITY_TRIBE_CODES` MODIFY COLUMN `c_group_code` INT(11)");
        DB::statement("ALTER TABLE `ETHNICITY_TRIBE_CODES` MODIFY COLUMN `c_subgroup_code` INT(11)");

        // EVENT_CODES
        DB::statement("ALTER TABLE `EVENT_CODES` MODIFY COLUMN `c_event_code` INT(11) NOT NULL");

        // EVENTS_ADDR
        DB::statement("ALTER TABLE `EVENTS_ADDR` MODIFY COLUMN `c_event_code` INT(11) NOT NULL DEFAULT 0");

        // EVENTS_DATA
        DB::statement("ALTER TABLE `EVENTS_DATA` MODIFY COLUMN `c_event_code` INT(11)");

        // LITERARYGENRE_CODES
        DB::statement("ALTER TABLE `LITERARYGENRE_CODES` MODIFY COLUMN `c_lit_genre_code` INT(11) NOT NULL");
        DB::statement("ALTER TABLE `LITERARYGENRE_CODES` MODIFY COLUMN `c_sortorder` INT(11)");

        // OCCASION_CODES
        DB::statement("ALTER TABLE `OCCASION_CODES` MODIFY COLUMN `c_occasion_code` INT(11) NOT NULL");
        DB::statement("ALTER TABLE `OCCASION_CODES` MODIFY COLUMN `c_sortorder` INT(11)");

        // POSSESSION_DATA
        DB::statement("ALTER TABLE `POSSESSION_DATA` MODIFY COLUMN `c_sequence` INT(11)");

        // POSTED_TO_OFFICE_DATA
        DB::statement("ALTER TABLE `POSTED_TO_OFFICE_DATA` MODIFY COLUMN `c_inst_code` INT(11) DEFAULT 0");
        DB::statement("ALTER TABLE `POSTED_TO_OFFICE_DATA` MODIFY COLUMN `c_ly_day` INT(11)");

        // SCHOLARLYTOPIC_CODES
        DB::statement("ALTER TABLE `SCHOLARLYTOPIC_CODES` MODIFY COLUMN `c_sortorder` INT(11)");
        DB::statement("ALTER TABLE `SCHOLARLYTOPIC_CODES` MODIFY COLUMN `c_topic_code` INT(11) NOT NULL");
        DB::statement("ALTER TABLE `SCHOLARLYTOPIC_CODES` MODIFY COLUMN `c_topic_type_code` INT(11)");

        // SOCIAL_INSTITUTION_ADDR
        DB::statement("ALTER TABLE `SOCIAL_INSTITUTION_ADDR` MODIFY COLUMN `c_inst_code` INT(11) NOT NULL");

        // SOCIAL_INSTITUTION_CODES
        DB::statement("ALTER TABLE `SOCIAL_INSTITUTION_CODES` MODIFY COLUMN `c_inst_code` INT(11) NOT NULL");

        // STATUS_DATA
        DB::statement("ALTER TABLE `STATUS_DATA` MODIFY COLUMN `c_sequence` INT(11) NOT NULL");

        // TEXT_INSTANCE_DATA
        DB::statement("ALTER TABLE `TEXT_INSTANCE_DATA` MODIFY COLUMN `c_extant` INT(11)");
        DB::statement("ALTER TABLE `TEXT_INSTANCE_DATA` MODIFY COLUMN `c_text_edition_id` INT(11) NOT NULL");
        DB::statement("ALTER TABLE `TEXT_INSTANCE_DATA` MODIFY COLUMN `c_text_instance_id` INT(11) NOT NULL");
    }
};
