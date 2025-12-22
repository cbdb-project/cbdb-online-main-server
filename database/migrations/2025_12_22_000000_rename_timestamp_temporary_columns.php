<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Rename timestamp temporary columns to their final names.
 *
 * This migration completes the timestamp refactoring by:
 * 1. Dropping the old VARCHAR date columns (c_created_date, c_modified_date)
 * 2. Renaming temporary timestamp columns to their final names
 *
 * Note: This is a breaking change that requires all code to be updated.
 */
class RenameTimestampTemporaryColumns extends Migration {
    /**
     * The tables that need timestamp column renaming.
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
        // Pre-flight validation: Ensure temporary columns exist before we drop old columns
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            // Verify that temporary columns exist (they contain our data)
            if (!Schema::hasColumn($table, 'c_created_date_timestamp_temporary')) {
                throw new \RuntimeException(
                    "Table {$table} is missing required column 'c_created_date_timestamp_temporary'. "
                    . "This column must exist before running this migration to prevent data loss."
                );
            }

            if (!Schema::hasColumn($table, 'c_modified_date_timestamp_temporary')) {
                throw new \RuntimeException(
                    "Table {$table} is missing required column 'c_modified_date_timestamp_temporary'. "
                    . "This column must exist before running this migration to prevent data loss."
                );
            }
        }

        // All validation passed, proceed with migration
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            // Step 1: Drop old VARCHAR columns to avoid naming conflicts
            if (Schema::hasColumn($table, 'c_created_date')) {
                Schema::table($table, function ($blueprint) {
                    $blueprint->dropColumn('c_created_date');
                });
            }

            if (Schema::hasColumn($table, 'c_modified_date')) {
                Schema::table($table, function ($blueprint) {
                    $blueprint->dropColumn('c_modified_date');
                });
            }

            // Step 2: Rename temporary columns to final names
            if (Schema::hasColumn($table, 'c_created_date_timestamp_temporary')) {
                Schema::table($table, function ($blueprint) {
                    $blueprint->renameColumn('c_created_date_timestamp_temporary', 'c_created_date');
                });
            }

            if (Schema::hasColumn($table, 'c_modified_date_timestamp_temporary')) {
                Schema::table($table, function ($blueprint) {
                    $blueprint->renameColumn('c_modified_date_timestamp_temporary', 'c_modified_date');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * CRITICAL WARNING: This rollback is STRUCTURE-ONLY.
     *
     * Due to composite primary keys in most tables (e.g., ALTNAME_DATA has
     * c_alt_name_chn + c_alt_name_type_code + c_personid), automatic data
     * conversion is unsafe and could cause data corruption.
     *
     * This rollback will:
     * 1. Rename TIMESTAMP columns back to temporary names
     * 2. Re-create empty VARCHAR columns
     * 3. Leave data conversion as a MANUAL operation
     *
     * If you need to populate the VARCHAR columns, you must manually run
     * UPDATE statements with proper WHERE clauses for each table's composite key.
     *
     * @return void
     */
    public function down() {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            // Step 1: Rename back to temporary names
            if (Schema::hasColumn($table, 'c_created_date')) {
                Schema::table($table, function ($blueprint) {
                    $blueprint->renameColumn('c_created_date', 'c_created_date_timestamp_temporary');
                });
            }

            if (Schema::hasColumn($table, 'c_modified_date')) {
                Schema::table($table, function ($blueprint) {
                    $blueprint->renameColumn('c_modified_date', 'c_modified_date_timestamp_temporary');
                });
            }

            // Step 2: Re-create empty VARCHAR columns
            // NOTE: Data conversion is NOT performed automatically due to composite key complexity
            if (!Schema::hasColumn($table, 'c_created_date')) {
                Schema::table($table, function ($blueprint) {
                    $blueprint->string('c_created_date', 255)->nullable();
                });
            }

            if (!Schema::hasColumn($table, 'c_modified_date')) {
                Schema::table($table, function ($blueprint) {
                    $blueprint->string('c_modified_date', 255)->nullable();
                });
            }

            // Data conversion is intentionally skipped - see docblock for details
        }
    }
}
