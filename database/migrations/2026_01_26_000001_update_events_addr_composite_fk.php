<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * 此 Migration 對 EVENTS_ADDR 表進行以下修改：
     * 1. 將 c_event_record_id 重命名為 c_event_code
     * 2. 添加 c_sequence 欄位，並從 EVENTS_DATA 回填正確的值
     * 3. 更新主鍵為 (c_addr_id, c_personid, c_sequence, c_event_code)
     * 4. 重建外鍵約束指向 EVENT_CODES.c_event_code
     *
     * 背景：c_event_record_id 實際上存儲的是 c_event_code 的值
     * （原外鍵約束指向 EVENT_CODES.c_event_code），因此可以直接重命名。
     */
    public function up(): void {
        if (is_mysql()) {
            // MySQL：先刪除外鍵約束和索引
            try {
                DB::statement('ALTER TABLE `EVENTS_ADDR` DROP FOREIGN KEY `EVENTS_ADDR_ibfk_3`');
            } catch (\Exception $e) {
                // 外鍵可能不存在，忽略錯誤
            }

            try {
                DB::statement('ALTER TABLE `EVENTS_ADDR` DROP INDEX `c_event_record_id_EVENTS_ADDR_index`');
            } catch (\Exception $e) {
                // 索引可能不存在，忽略錯誤
            }

            // 刪除主鍵
            try {
                DB::statement('ALTER TABLE `EVENTS_ADDR` DROP PRIMARY KEY');
            } catch (\Exception $e) {
                // 主鍵可能不存在
            }

            // 重命名 c_event_record_id 為 c_event_code
            DB::statement('ALTER TABLE `EVENTS_ADDR` CHANGE `c_event_record_id` `c_event_code` INT NOT NULL DEFAULT 0');

            // 添加 c_sequence 欄位（先預設為 0）
            Schema::table('EVENTS_ADDR', function (Blueprint $table) {
                $table->smallInteger('c_sequence')->default(0)->after('c_personid');
            });

            // 從 EVENTS_DATA 回填正確的 c_sequence 值
            // 如果同一 (c_personid, c_event_code) 有多筆記錄，取 sequence 最小的
            DB::statement('
                UPDATE EVENTS_ADDR ea
                JOIN (
                    SELECT c_personid, c_event_code, MIN(c_sequence) as c_sequence
                    FROM EVENTS_DATA
                    GROUP BY c_personid, c_event_code
                ) ed ON ea.c_personid = ed.c_personid AND ea.c_event_code = ed.c_event_code
                SET ea.c_sequence = ed.c_sequence
            ');

            // 添加新的複合主鍵和索引
            DB::statement('ALTER TABLE `EVENTS_ADDR` ADD PRIMARY KEY (`c_addr_id`, `c_personid`, `c_sequence`, `c_event_code`)');
            DB::statement('CREATE INDEX `c_sequence_EVENTS_ADDR_index` ON `EVENTS_ADDR` (`c_sequence`)');
            DB::statement('CREATE INDEX `c_event_code_EVENTS_ADDR_index` ON `EVENTS_ADDR` (`c_event_code`)');

            // 重建外鍵約束
            DB::statement('
                ALTER TABLE `EVENTS_ADDR`
                ADD CONSTRAINT `EVENTS_ADDR_ibfk_3`
                FOREIGN KEY (`c_event_code`) REFERENCES `EVENT_CODES` (`c_event_code`)
                ON DELETE RESTRICT ON UPDATE CASCADE
            ');
        } else {
            // SQLite：需要重建表
            $this->rebuildTableForSqlite();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        if (is_mysql()) {
            // 刪除外鍵約束
            try {
                DB::statement('ALTER TABLE `EVENTS_ADDR` DROP FOREIGN KEY `EVENTS_ADDR_ibfk_3`');
            } catch (\Exception $e) {
            }

            // 刪除主鍵
            try {
                DB::statement('ALTER TABLE `EVENTS_ADDR` DROP PRIMARY KEY');
            } catch (\Exception $e) {
            }

            // 刪除新索引
            try {
                DB::statement('ALTER TABLE `EVENTS_ADDR` DROP INDEX `c_sequence_EVENTS_ADDR_index`');
            } catch (\Exception $e) {
            }

            try {
                DB::statement('ALTER TABLE `EVENTS_ADDR` DROP INDEX `c_event_code_EVENTS_ADDR_index`');
            } catch (\Exception $e) {
            }

            // 刪除 c_sequence 欄位
            Schema::table('EVENTS_ADDR', function (Blueprint $table) {
                $table->dropColumn('c_sequence');
            });

            // 重命名回 c_event_record_id
            DB::statement('ALTER TABLE `EVENTS_ADDR` CHANGE `c_event_code` `c_event_record_id` INT NOT NULL');

            // 恢復原主鍵和索引
            DB::statement('ALTER TABLE `EVENTS_ADDR` ADD PRIMARY KEY (`c_addr_id`, `c_event_record_id`, `c_personid`)');
            DB::statement('CREATE INDEX `c_event_record_id_EVENTS_ADDR_index` ON `EVENTS_ADDR` (`c_event_record_id`)');

            // 恢復原外鍵約束
            DB::statement('
                ALTER TABLE `EVENTS_ADDR`
                ADD CONSTRAINT `EVENTS_ADDR_ibfk_3`
                FOREIGN KEY (`c_event_record_id`) REFERENCES `EVENT_CODES` (`c_event_code`)
                ON DELETE RESTRICT ON UPDATE CASCADE
            ');
        } else {
            $this->rebuildTableForSqliteDown();
        }
    }

    /**
     * SQLite 重建表（up）
     */
    private function rebuildTableForSqlite(): void {
        disable_foreign_keys();

        try {
            // 1. 創建新表（c_event_record_id 重命名為 c_event_code，添加 c_sequence）
            DB::statement('
                CREATE TABLE EVENTS_ADDR_new (
                    c_personid INT NOT NULL,
                    c_sequence SMALLINT NOT NULL DEFAULT 0,
                    c_event_code INT NOT NULL DEFAULT 0,
                    c_addr_id INT NOT NULL,
                    c_year SMALLINT DEFAULT NULL,
                    c_nh_code SMALLINT DEFAULT NULL,
                    c_nh_year SMALLINT DEFAULT NULL,
                    c_yr_range SMALLINT DEFAULT NULL,
                    c_intercalary SMALLINT DEFAULT NULL,
                    c_month SMALLINT DEFAULT NULL,
                    c_day SMALLINT DEFAULT NULL,
                    c_day_ganzhi SMALLINT DEFAULT NULL,
                    PRIMARY KEY (c_addr_id, c_personid, c_sequence, c_event_code)
                )
            ');

            // 2. 複製資料並從 EVENTS_DATA 回填 c_sequence
            // 如果同一 (c_personid, c_event_code) 有多筆記錄，取 sequence 最小的
            DB::statement('
                INSERT INTO EVENTS_ADDR_new (
                    c_personid, c_sequence, c_event_code, c_addr_id,
                    c_year, c_nh_code, c_nh_year, c_yr_range,
                    c_intercalary, c_month, c_day, c_day_ganzhi
                )
                SELECT
                    ea.c_personid,
                    COALESCE(ed.c_sequence, 0),
                    ea.c_event_record_id,
                    ea.c_addr_id,
                    ea.c_year, ea.c_nh_code, ea.c_nh_year, ea.c_yr_range,
                    ea.c_intercalary, ea.c_month, ea.c_day, ea.c_day_ganzhi
                FROM EVENTS_ADDR ea
                LEFT JOIN (
                    SELECT c_personid, c_event_code, MIN(c_sequence) as c_sequence
                    FROM EVENTS_DATA
                    GROUP BY c_personid, c_event_code
                ) ed ON ea.c_personid = ed.c_personid AND ea.c_event_record_id = ed.c_event_code
            ');

            // 3. 刪除舊表
            DB::statement('DROP TABLE EVENTS_ADDR');

            // 4. 重命名新表
            DB::statement('ALTER TABLE EVENTS_ADDR_new RENAME TO EVENTS_ADDR');

            // 5. 重建索引
            DB::statement('CREATE INDEX c_personid_EVENTS_ADDR_index ON EVENTS_ADDR (c_personid)');
            DB::statement('CREATE INDEX c_sequence_EVENTS_ADDR_index ON EVENTS_ADDR (c_sequence)');
            DB::statement('CREATE INDEX c_event_code_EVENTS_ADDR_index ON EVENTS_ADDR (c_event_code)');
            DB::statement('CREATE INDEX c_addr_id_EVENTS_ADDR_index ON EVENTS_ADDR (c_addr_id)');
            DB::statement('CREATE INDEX c_nh_code_EVENTS_ADDR_index ON EVENTS_ADDR (c_nh_code)');
            DB::statement('CREATE INDEX c_day_ganzhi_EVENTS_ADDR_index ON EVENTS_ADDR (c_day_ganzhi)');
            DB::statement('CREATE INDEX c_yr_range_EVENTS_ADDR_index ON EVENTS_ADDR (c_yr_range)');
        } finally {
            enable_foreign_keys();
        }
    }

    /**
     * SQLite 重建表（down）
     */
    private function rebuildTableForSqliteDown(): void {
        disable_foreign_keys();

        try {
            // 1. 創建舊結構的表
            DB::statement('
                CREATE TABLE EVENTS_ADDR_new (
                    c_event_record_id INT NOT NULL,
                    c_personid INT NOT NULL,
                    c_addr_id INT NOT NULL,
                    c_year SMALLINT DEFAULT NULL,
                    c_nh_code SMALLINT DEFAULT NULL,
                    c_nh_year SMALLINT DEFAULT NULL,
                    c_yr_range SMALLINT DEFAULT NULL,
                    c_intercalary SMALLINT DEFAULT NULL,
                    c_month SMALLINT DEFAULT NULL,
                    c_day SMALLINT DEFAULT NULL,
                    c_day_ganzhi SMALLINT DEFAULT NULL,
                    PRIMARY KEY (c_addr_id, c_event_record_id, c_personid)
                )
            ');

            // 2. 複製資料（c_event_code -> c_event_record_id）
            DB::statement('
                INSERT INTO EVENTS_ADDR_new (
                    c_event_record_id, c_personid, c_addr_id,
                    c_year, c_nh_code, c_nh_year, c_yr_range,
                    c_intercalary, c_month, c_day, c_day_ganzhi
                )
                SELECT
                    c_event_code, c_personid, c_addr_id,
                    c_year, c_nh_code, c_nh_year, c_yr_range,
                    c_intercalary, c_month, c_day, c_day_ganzhi
                FROM EVENTS_ADDR
            ');

            // 3. 刪除舊表
            DB::statement('DROP TABLE EVENTS_ADDR');

            // 4. 重命名新表
            DB::statement('ALTER TABLE EVENTS_ADDR_new RENAME TO EVENTS_ADDR');

            // 5. 重建索引
            DB::statement('CREATE INDEX c_event_record_id_EVENTS_ADDR_index ON EVENTS_ADDR (c_event_record_id)');
            DB::statement('CREATE INDEX c_personid_EVENTS_ADDR_index ON EVENTS_ADDR (c_personid)');
            DB::statement('CREATE INDEX c_addr_id_EVENTS_ADDR_index ON EVENTS_ADDR (c_addr_id)');
            DB::statement('CREATE INDEX c_nh_code_EVENTS_ADDR_index ON EVENTS_ADDR (c_nh_code)');
            DB::statement('CREATE INDEX c_day_ganzhi_EVENTS_ADDR_index ON EVENTS_ADDR (c_day_ganzhi)');
            DB::statement('CREATE INDEX c_yr_range_EVENTS_ADDR_index ON EVENTS_ADDR (c_yr_range)');
        } finally {
            enable_foreign_keys();
        }
    }
};
