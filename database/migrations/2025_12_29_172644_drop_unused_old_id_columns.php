<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Drop unused legacy ID columns from multiple tables:
     * - OFFICE_CODES.c_office_id_old
     * - POSTED_TO_ADDR_DATA.c_posting_id_old
     * - POSTED_TO_OFFICE_DATA.c_posting_id_old
     * - POSTING_DATA.c_posting_id_old
     *
     * These columns are not referenced in any application code and can be safely removed.
     */
    public function up(): void {
        // Skip this migration in SQLite environment.
        // Reason: SQLite rebuilds the entire table when executing ALTER TABLE DROP COLUMN,
        // which triggers complex foreign key constraint issues. This migration is designed
        // for production environment (MySQL/MariaDB) only.
        if ($this->isSqliteConnection()) {
            return;
        }

        // Drop c_office_id_old column and its index from OFFICE_CODES table
        if (Schema::hasColumn('OFFICE_CODES', 'c_office_id_old')) {
            Schema::table('OFFICE_CODES', function (Blueprint $table) {
                // Try to drop the index if it exists; gracefully ignore if it doesn't
                try {
                    $table->dropIndex('c_office_id_old_OFFICE_CODES_index');
                } catch (\Exception $e) {
                    // Index may not exist in all environments; continue with column drop
                }
                $table->dropColumn('c_office_id_old');
            });
        }

        // Drop c_posting_id_old column and its index from POSTED_TO_ADDR_DATA table
        if (Schema::hasColumn('POSTED_TO_ADDR_DATA', 'c_posting_id_old')) {
            Schema::table('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
                try {
                    $table->dropIndex('c_posting_id_old_POSTED_TO_ADDR_DATA_index');
                } catch (\Exception $e) {
                    // Index may not exist in all environments; continue with column drop
                }
                $table->dropColumn('c_posting_id_old');
            });
        }

        // Drop c_posting_id_old column and its index from POSTED_TO_OFFICE_DATA table
        if (Schema::hasColumn('POSTED_TO_OFFICE_DATA', 'c_posting_id_old')) {
            Schema::table('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
                try {
                    $table->dropIndex('c_posting_id_old_POSTED_TO_OFFICE_DATA_index');
                } catch (\Exception $e) {
                    // Index may not exist in all environments; continue with column drop
                }
                $table->dropColumn('c_posting_id_old');
            });
        }

        // Drop c_posting_id_old column and its index from POSTING_DATA table
        if (Schema::hasColumn('POSTING_DATA', 'c_posting_id_old')) {
            Schema::table('POSTING_DATA', function (Blueprint $table) {
                try {
                    $table->dropIndex('c_posting_id_old_POSTING_DATA_index');
                } catch (\Exception $e) {
                    // Index may not exist in all environments; continue with column drop
                }
                $table->dropColumn('c_posting_id_old');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Restore the dropped columns and their indexes.
     */
    public function down(): void {
        // Skip this migration in SQLite environment
        if ($this->isSqliteConnection()) {
            return;
        }

        // Restore c_office_id_old column and its index to OFFICE_CODES table
        Schema::table('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id_old')->nullable()->after('c_category_4');
            $table->index('c_office_id_old', 'c_office_id_old_OFFICE_CODES_index');
        });

        // Restore c_posting_id_old column and its index to POSTED_TO_ADDR_DATA table
        Schema::table('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_posting_id_old')->nullable()->after('c_addr_id');
            $table->index('c_posting_id_old', 'c_posting_id_old_POSTED_TO_ADDR_DATA_index');
        });

        // Restore c_posting_id_old column and its index to POSTED_TO_OFFICE_DATA table
        Schema::table('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_posting_id_old')->nullable()->after('c_posting_id');
            $table->index('c_posting_id_old', 'c_posting_id_old_POSTED_TO_OFFICE_DATA_index');
        });

        // Restore c_posting_id_old column and its index to POSTING_DATA table
        Schema::table('POSTING_DATA', function (Blueprint $table) {
            $table->integer('c_posting_id_old')->nullable()->after('c_posting_id');
            $table->index('c_posting_id_old', 'c_posting_id_old_POSTING_DATA_index');
        });
    }

    private function isSqliteConnection(): bool {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }
};
