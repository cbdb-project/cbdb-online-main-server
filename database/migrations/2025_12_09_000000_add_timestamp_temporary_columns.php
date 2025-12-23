<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add temporary timestamp columns and convert YYYYMMDD dates to proper timestamps.
 *
 * Note on transactions:
 * - DDL operations (ALTER TABLE) in MySQL are auto-committed and cannot be rolled back
 * - DML operations (UPDATE) are wrapped in a transaction for atomicity
 * - If the migration fails during data conversion, columns will exist but some may be empty
 * - This is safe because the original data in c_created_date/c_modified_date is preserved
 */
class AddTimestampTemporaryColumns extends Migration {
    /**
     * The tables that need timestamp conversion.
     *
     * @var array
     */
    protected $tables = [
        'ALTNAME_DATA',
        'ASSOC_DATA',
        'BIOG_ADDR_DATA',
        'BIOG_INST_DATA',
        'BIOG_MAIN',
        'BIOG_TEXT_DATA',
        'ENTRY_DATA',
        'EVENTS_DATA',
        'KIN_DATA',
        'MERGED_PERSON_DATA',
        'POSSESSION_DATA',
        'POSTED_TO_OFFICE_DATA',
        'STATUS_DATA',
        'TEXT_CODES',
        'TEXT_INSTANCE_DATA',
    ];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        // Step 1: Add all columns first (DDL operations - cannot be rolled back in MySQL)
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            // Add the new timestamp columns
            if (!Schema::hasColumn($table, 'c_created_date_timestamp_temporary')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->timestamp('c_created_date_timestamp_temporary')->nullable();
                });
            }

            if (!Schema::hasColumn($table, 'c_modified_date_timestamp_temporary')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->timestamp('c_modified_date_timestamp_temporary')->nullable();
                });
            }
        }

        // Step 2: Convert and populate the data (DML operations - wrapped in transaction)
        DB::transaction(function () {
            foreach ($this->tables as $table) {
                if (!Schema::hasTable($table)) {
                    continue;
                }

                $this->convertDates($table);
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasColumn($table, 'c_created_date_timestamp_temporary')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('c_created_date_timestamp_temporary');
                });
            }

            if (Schema::hasColumn($table, 'c_modified_date_timestamp_temporary')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('c_modified_date_timestamp_temporary');
                });
            }
        }
    }

    /**
     * Convert YYYYMMDD format dates to timestamps.
     *
     * @param string $table
     * @return void
     */
    protected function convertDates(string $table): void {
        $isSqlite = DB::getDriverName() === 'sqlite';

        // Update c_created_date_timestamp_temporary
        if (Schema::hasColumn($table, 'c_created_date')) {
            if ($isSqlite) {
                DB::statement("
                    UPDATE `{$table}`
                    SET `c_created_date_timestamp_temporary` = datetime(
                        substr(`c_created_date`, 1, 4) || '-' || 
                        substr(`c_created_date`, 5, 2) || '-' || 
                        substr(`c_created_date`, 7, 2) || ' 00:00:00'
                    )
                    WHERE `c_created_date` IS NOT NULL
                      AND `c_created_date` != ''
                      AND length(`c_created_date`) = 8
                      AND `c_created_date` GLOB '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]'
                ");
            } else {
                DB::statement("
                    UPDATE `{$table}`
                    SET `c_created_date_timestamp_temporary` = STR_TO_DATE(`c_created_date`, '%Y%m%d')
                    WHERE `c_created_date` IS NOT NULL
                      AND `c_created_date` != ''
                      AND `c_created_date` REGEXP '^[0-9]{8}$'
                ");
            }
        }

        // Update c_modified_date_timestamp_temporary
        if (Schema::hasColumn($table, 'c_modified_date')) {
            if ($isSqlite) {
                DB::statement("
                    UPDATE `{$table}`
                    SET `c_modified_date_timestamp_temporary` = datetime(
                        substr(`c_modified_date`, 1, 4) || '-' || 
                        substr(`c_modified_date`, 5, 2) || '-' || 
                        substr(`c_modified_date`, 7, 2) || ' 00:00:00'
                    )
                    WHERE `c_modified_date` IS NOT NULL
                      AND `c_modified_date` != ''
                      AND length(`c_modified_date`) = 8
                      AND `c_modified_date` GLOB '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]'
                ");
            } else {
                DB::statement("
                    UPDATE `{$table}`
                    SET `c_modified_date_timestamp_temporary` = STR_TO_DATE(`c_modified_date`, '%Y%m%d')
                    WHERE `c_modified_date` IS NOT NULL
                      AND `c_modified_date` != ''
                      AND `c_modified_date` REGEXP '^[0-9]{8}$'
                ");
            }
        }
    }
}
