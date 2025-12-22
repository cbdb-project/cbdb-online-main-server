<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
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
     * WARNING: This rollback involves DATA LOSS.
     * - Time components (H:i:s) will be permanently lost during rollback
     * - Only date components (Y-m-d) are preserved in Ymd format
     * - Consider this a destructive emergency-only operation
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

            // Step 2: Re-create VARCHAR columns
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

            // Step 3: Convert timestamp data back to VARCHAR Ymd format
            // Use database-agnostic approach with Query Builder for portability
            // Time components will be permanently lost during this conversion
            if (Schema::hasColumn($table, 'c_created_date_timestamp_temporary')) {
                // Process in chunks to handle large tables without memory issues
                $primaryKey = $this->getPrimaryKeyColumn($table);
                DB::table($table)
                    ->whereNotNull('c_created_date_timestamp_temporary')
                    ->orderBy($primaryKey)
                    ->chunk(1000, function ($rows) use ($table, $primaryKey) {
                        foreach ($rows as $row) {
                            $timestamp = $row->c_created_date_timestamp_temporary;
                            if ($timestamp) {
                                try {
                                    $ymd = \Carbon\Carbon::parse($timestamp)->format('Ymd');
                                    DB::table($table)
                                        ->where($primaryKey, $row->{$primaryKey})
                                        ->update(['c_created_date' => $ymd]);
                                } catch (\Exception $e) {
                                    // Skip unparseable timestamps
                                    continue;
                                }
                            }
                        }
                    });
            }

            if (Schema::hasColumn($table, 'c_modified_date_timestamp_temporary')) {
                $primaryKey = $this->getPrimaryKeyColumn($table);
                DB::table($table)
                    ->whereNotNull('c_modified_date_timestamp_temporary')
                    ->orderBy($primaryKey)
                    ->chunk(1000, function ($rows) use ($table, $primaryKey) {
                        foreach ($rows as $row) {
                            $timestamp = $row->c_modified_date_timestamp_temporary;
                            if ($timestamp) {
                                try {
                                    $ymd = \Carbon\Carbon::parse($timestamp)->format('Ymd');
                                    DB::table($table)
                                        ->where($primaryKey, $row->{$primaryKey})
                                        ->update(['c_modified_date' => $ymd]);
                                } catch (\Exception $e) {
                                    // Skip unparseable timestamps
                                    continue;
                                }
                            }
                        }
                    });
            }
        }
    }

    /**
     * Get the primary key column for a table.
     * Most tables use c_personid, but some have different primary keys.
     *
     * @param string $table
     * @return string
     */
    protected function getPrimaryKeyColumn(string $table): string {
        // Map of tables to their primary key columns
        $primaryKeys = [
            'TEXT_CODES' => 'c_textid',
            'TEXT_INSTANCE_DATA' => 'c_inst_id',
        ];

        return $primaryKeys[$table] ?? 'c_personid';
    }
}
