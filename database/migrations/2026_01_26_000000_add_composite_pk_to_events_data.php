<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * 此 Migration 對 EVENTS_DATA 表進行以下修改：
     * 1. 刪除 c_event_record_id 欄位（如果存在）
     * 2. 為相同 c_personid 的記錄分配遞增的 c_sequence（0, 1, 2, ...）
     * 3. 將 c_event_code 欄位中的 NULL 值更新為 0
     * 4. 將 c_personid 欄位中的 NULL 值更新為 0（如果存在）
     * 5. 設置複合主鍵 (c_personid, c_sequence, c_event_code)
     */
    public function up(): void {
        if (is_mysql()) {
            DB::statement('ALTER TABLE EVENTS_DATA DROP FOREIGN KEY EVENTS_DATA_ibfk_3');
            DB::statement('ALTER TABLE EVENTS_DATA DROP FOREIGN KEY EVENTS_DATA_ibfk_6');
        }

        // 步驟 0：刪除 c_event_record_id 欄位（如果存在）
        if (Schema::hasColumn('EVENTS_DATA', 'c_event_record_id')) {
            if (is_mysql()) {
                // MySQL：先刪除外鍵約束再刪除欄位
                try {
                    DB::statement('ALTER TABLE `EVENTS_DATA` DROP FOREIGN KEY `EVENTS_DATA_ibfk_4`');
                } catch (\Exception $e) {
                    // 外鍵可能不存在，忽略錯誤
                }

                try {
                    DB::statement('ALTER TABLE `EVENTS_DATA` DROP INDEX `c_event_record_id_EVENTS_DATA_index`');
                } catch (\Exception $e) {
                    // 索引可能不存在，忽略錯誤
                }
                Schema::table('EVENTS_DATA', function (Blueprint $table) {
                    $table->dropColumn('c_event_record_id');
                });
            }
            // SQLite：會在重建表時處理
        }

        // 步驟 1：更新 c_personid 和 c_event_code 的 NULL 值為 0
        DB::table('EVENTS_DATA')
            ->whereNull('c_personid')
            ->update(['c_personid' => 0]);

        DB::table('EVENTS_DATA')
            ->whereNull('c_event_code')
            ->update(['c_event_code' => 0]);

        // 步驟 2：為相同 c_personid 的記錄分配遞增的 c_sequence
        $this->assignSequenceNumbers();

        // 步驟 3：添加複合主鍵（需要根據數據庫類型處理）
        if (is_mysql()) {
            // MySQL：修改欄位為 NOT NULL（含 DEFAULT 0）並添加主鍵
            DB::statement('ALTER TABLE `EVENTS_DATA` MODIFY `c_personid` INT NOT NULL');
            DB::statement('ALTER TABLE `EVENTS_DATA` MODIFY `c_sequence` SMALLINT NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE `EVENTS_DATA` MODIFY `c_event_code` INT NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE `EVENTS_DATA` ADD PRIMARY KEY (`c_personid`, `c_sequence`, `c_event_code`)');
        } else {
            // SQLite：需要重建表（SQLite 不支持 ALTER TABLE ADD PRIMARY KEY）
            $this->rebuildTableForSqlite();
        }

        if (is_mysql()) {
            DB::statement('
                ALTER TABLE EVENTS_DATA
                ADD CONSTRAINT EVENTS_DATA_ibfk_3
                FOREIGN KEY (c_event_code)
                REFERENCES EVENT_CODES(c_event_code)
                ON DELETE CASCADE
                ON UPDATE CASCADE
            ');
            DB::statement('
                ALTER TABLE EVENTS_DATA
                ADD CONSTRAINT EVENTS_DATA_ibfk_6
                FOREIGN KEY (c_personid)
                REFERENCES BIOG_MAIN(c_personid)
                ON DELETE CASCADE
                ON UPDATE CASCADE
            ');
        }
    }

    /**
     * 為相同 c_personid 的記錄分配遞增的 c_sequence
     */
    private function assignSequenceNumbers(): void {
        if (is_mysql()) {
            // MySQL 8.0+：使用窗口函數和臨時表
            DB::statement('
                CREATE TEMPORARY TABLE temp_events_sequence AS
                SELECT
                    c_personid,
                    c_event_code,
                    c_role,
                    c_year,
                    c_nh_code,
                    c_nh_year,
                    c_yr_range,
                    c_intercalary,
                    c_month,
                    c_day,
                    c_day_ganzhi,
                    c_addr_id,
                    c_source,
                    c_pages,
                    c_event,
                    c_notes,
                    c_created_by,
                    c_created_date,
                    c_modified_by,
                    c_modified_date,
                    (ROW_NUMBER() OVER (PARTITION BY c_personid ORDER BY c_event_code, c_year, c_month, c_day) - 1) AS new_sequence
                FROM EVENTS_DATA
            ');

            // 清空原表並重新插入
            DB::statement('DELETE FROM EVENTS_DATA');
            DB::statement('
                INSERT INTO EVENTS_DATA (
                    c_personid, c_sequence, c_event_code, c_role, c_year, c_nh_code, c_nh_year,
                    c_yr_range, c_intercalary, c_month, c_day, c_day_ganzhi, c_addr_id,
                    c_source, c_pages, c_event, c_notes, c_created_by, c_created_date,
                    c_modified_by, c_modified_date
                )
                SELECT
                    c_personid, new_sequence, c_event_code, c_role, c_year, c_nh_code, c_nh_year,
                    c_yr_range, c_intercalary, c_month, c_day, c_day_ganzhi, c_addr_id,
                    c_source, c_pages, c_event, c_notes, c_created_by, c_created_date,
                    c_modified_by, c_modified_date
                FROM temp_events_sequence
            ');

            DB::statement('DROP TEMPORARY TABLE temp_events_sequence');
        } else {
            // SQLite：使用窗口函數
            DB::statement('
                CREATE TABLE EVENTS_DATA_temp AS
                SELECT
                    c_personid,
                    (ROW_NUMBER() OVER (PARTITION BY c_personid ORDER BY c_event_code, c_year, c_month, c_day) - 1) AS c_sequence,
                    c_event_code,
                    c_role,
                    c_year,
                    c_nh_code,
                    c_nh_year,
                    c_yr_range,
                    c_intercalary,
                    c_month,
                    c_day,
                    c_day_ganzhi,
                    c_addr_id,
                    c_source,
                    c_pages,
                    c_event,
                    c_notes,
                    c_created_by,
                    c_created_date,
                    c_modified_by,
                    c_modified_date
                FROM EVENTS_DATA
            ');

            DB::statement('DROP TABLE EVENTS_DATA');
            DB::statement('ALTER TABLE EVENTS_DATA_temp RENAME TO EVENTS_DATA');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        if (is_mysql()) {
            // 移除主鍵
            DB::statement('ALTER TABLE `EVENTS_DATA` DROP PRIMARY KEY');

            // 將欄位改回允許 NULL
            DB::statement('ALTER TABLE `EVENTS_DATA` MODIFY `c_personid` INT DEFAULT NULL');
            DB::statement('ALTER TABLE `EVENTS_DATA` MODIFY `c_sequence` SMALLINT DEFAULT NULL');
            DB::statement('ALTER TABLE `EVENTS_DATA` MODIFY `c_event_code` INT DEFAULT NULL');

            // 重新添加 c_event_record_id 欄位
            Schema::table('EVENTS_DATA', function (Blueprint $table) {
                $table->integer('c_event_record_id')->nullable()->after('c_sequence');
                $table->index('c_event_record_id', 'c_event_record_id_EVENTS_DATA_index');
            });
        } else {
            // SQLite：重建表移除主鍵約束並恢復 c_event_record_id
            $this->rebuildTableForSqliteDown();
        }
    }

    /**
     * SQLite 重建表以添加主鍵（不含 c_event_record_id）
     */
    private function rebuildTableForSqlite(): void {
        disable_foreign_keys();

        try {
            // 1. 創建新表（包含主鍵約束，不含 c_event_record_id）
            DB::statement('
                CREATE TABLE EVENTS_DATA_new (
                    c_personid INT NOT NULL,
                    c_sequence SMALLINT NOT NULL DEFAULT 0,
                    c_event_code INT NOT NULL DEFAULT 0,
                    c_role VARCHAR(255) DEFAULT NULL,
                    c_year SMALLINT DEFAULT NULL,
                    c_nh_code SMALLINT DEFAULT NULL,
                    c_nh_year SMALLINT DEFAULT NULL,
                    c_yr_range SMALLINT DEFAULT NULL,
                    c_intercalary SMALLINT DEFAULT NULL,
                    c_month SMALLINT DEFAULT NULL,
                    c_day SMALLINT DEFAULT NULL,
                    c_day_ganzhi SMALLINT DEFAULT NULL,
                    c_addr_id INT DEFAULT NULL,
                    c_source INT DEFAULT NULL,
                    c_pages VARCHAR(255) DEFAULT NULL,
                    c_event TEXT DEFAULT NULL,
                    c_notes VARCHAR(255) DEFAULT NULL,
                    c_created_by VARCHAR(255) DEFAULT NULL,
                    c_created_date VARCHAR(255) DEFAULT NULL,
                    c_modified_by VARCHAR(255) DEFAULT NULL,
                    c_modified_date VARCHAR(255) DEFAULT NULL,
                    PRIMARY KEY (c_personid, c_sequence, c_event_code)
                )
            ');

            // 2. 複製數據（排除 c_event_record_id）
            DB::statement('
                INSERT INTO EVENTS_DATA_new (
                    c_personid, c_sequence, c_event_code, c_role, c_year, c_nh_code, c_nh_year,
                    c_yr_range, c_intercalary, c_month, c_day, c_day_ganzhi, c_addr_id,
                    c_source, c_pages, c_event, c_notes, c_created_by, c_created_date,
                    c_modified_by, c_modified_date
                )
                SELECT
                    c_personid, c_sequence, c_event_code, c_role, c_year, c_nh_code, c_nh_year,
                    c_yr_range, c_intercalary, c_month, c_day, c_day_ganzhi, c_addr_id,
                    c_source, c_pages, c_event, c_notes, c_created_by, c_created_date,
                    c_modified_by, c_modified_date
                FROM EVENTS_DATA
            ');

            // 3. 刪除舊表
            DB::statement('DROP TABLE EVENTS_DATA');

            // 4. 重命名新表
            DB::statement('ALTER TABLE EVENTS_DATA_new RENAME TO EVENTS_DATA');

            // 5. 重建索引（不含 c_event_record_id）
            DB::statement('CREATE INDEX c_personid_EVENTS_DATA_index ON EVENTS_DATA (c_personid)');
            DB::statement('CREATE INDEX c_event_code_EVENTS_DATA_index ON EVENTS_DATA (c_event_code)');
            DB::statement('CREATE INDEX c_nh_code_EVENTS_DATA_index ON EVENTS_DATA (c_nh_code)');
            DB::statement('CREATE INDEX c_addr_id_EVENTS_DATA_index ON EVENTS_DATA (c_addr_id)');
            DB::statement('CREATE INDEX c_day_ganzhi_EVENTS_DATA_index ON EVENTS_DATA (c_day_ganzhi)');
            DB::statement('CREATE INDEX c_source_EVENTS_DATA_index ON EVENTS_DATA (c_source)');
            DB::statement('CREATE INDEX c_yr_range_EVENTS_DATA_index ON EVENTS_DATA (c_yr_range)');
        } finally {
            enable_foreign_keys();
        }
    }

    /**
     * SQLite 重建表以移除主鍵並恢復 c_event_record_id（回滾用）
     */
    private function rebuildTableForSqliteDown(): void {
        disable_foreign_keys();

        try {
            // 1. 創建新表（無主鍵約束，包含 c_event_record_id）
            DB::statement('
                CREATE TABLE EVENTS_DATA_new (
                    c_personid INT DEFAULT NULL,
                    c_sequence SMALLINT DEFAULT NULL,
                    c_event_record_id INT DEFAULT NULL,
                    c_event_code INT DEFAULT NULL,
                    c_role VARCHAR(255) DEFAULT NULL,
                    c_year SMALLINT DEFAULT NULL,
                    c_nh_code SMALLINT DEFAULT NULL,
                    c_nh_year SMALLINT DEFAULT NULL,
                    c_yr_range SMALLINT DEFAULT NULL,
                    c_intercalary SMALLINT DEFAULT NULL,
                    c_month SMALLINT DEFAULT NULL,
                    c_day SMALLINT DEFAULT NULL,
                    c_day_ganzhi SMALLINT DEFAULT NULL,
                    c_addr_id INT DEFAULT NULL,
                    c_source INT DEFAULT NULL,
                    c_pages VARCHAR(255) DEFAULT NULL,
                    c_event TEXT DEFAULT NULL,
                    c_notes VARCHAR(255) DEFAULT NULL,
                    c_created_by VARCHAR(255) DEFAULT NULL,
                    c_created_date VARCHAR(255) DEFAULT NULL,
                    c_modified_by VARCHAR(255) DEFAULT NULL,
                    c_modified_date VARCHAR(255) DEFAULT NULL
                )
            ');

            // 2. 複製數據（c_event_record_id 設為 NULL）
            DB::statement('
                INSERT INTO EVENTS_DATA_new (
                    c_personid, c_sequence, c_event_record_id, c_event_code, c_role, c_year,
                    c_nh_code, c_nh_year, c_yr_range, c_intercalary, c_month, c_day,
                    c_day_ganzhi, c_addr_id, c_source, c_pages, c_event, c_notes,
                    c_created_by, c_created_date, c_modified_by, c_modified_date
                )
                SELECT
                    c_personid, c_sequence, NULL, c_event_code, c_role, c_year,
                    c_nh_code, c_nh_year, c_yr_range, c_intercalary, c_month, c_day,
                    c_day_ganzhi, c_addr_id, c_source, c_pages, c_event, c_notes,
                    c_created_by, c_created_date, c_modified_by, c_modified_date
                FROM EVENTS_DATA
            ');

            // 3. 刪除舊表
            DB::statement('DROP TABLE EVENTS_DATA');

            // 4. 重命名新表
            DB::statement('ALTER TABLE EVENTS_DATA_new RENAME TO EVENTS_DATA');

            // 5. 重建索引
            DB::statement('CREATE INDEX c_personid_EVENTS_DATA_index ON EVENTS_DATA (c_personid)');
            DB::statement('CREATE INDEX c_event_record_id_EVENTS_DATA_index ON EVENTS_DATA (c_event_record_id)');
            DB::statement('CREATE INDEX c_event_code_EVENTS_DATA_index ON EVENTS_DATA (c_event_code)');
            DB::statement('CREATE INDEX c_nh_code_EVENTS_DATA_index ON EVENTS_DATA (c_nh_code)');
            DB::statement('CREATE INDEX c_addr_id_EVENTS_DATA_index ON EVENTS_DATA (c_addr_id)');
            DB::statement('CREATE INDEX c_day_ganzhi_EVENTS_DATA_index ON EVENTS_DATA (c_day_ganzhi)');
            DB::statement('CREATE INDEX c_source_EVENTS_DATA_index ON EVENTS_DATA (c_source)');
            DB::statement('CREATE INDEX c_yr_range_EVENTS_DATA_index ON EVENTS_DATA (c_yr_range)');
        } finally {
            enable_foreign_keys();
        }
    }
};
