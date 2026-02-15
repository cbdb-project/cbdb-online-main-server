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
        disable_foreign_keys();

        try {
            $tablesToModify = [
                'OFFICE_CODES' => [
                    'column' => 'c_office_id_old',
                    'index' => 'c_office_id_old_OFFICE_CODES_index',
                ],
                'POSTED_TO_ADDR_DATA' => [
                    'column' => 'c_posting_id_old',
                    'index' => 'c_posting_id_old_POSTED_TO_ADDR_DATA_index',
                ],
                'POSTED_TO_OFFICE_DATA' => [
                    'column' => 'c_posting_id_old',
                    'index' => 'c_posting_id_old_POSTED_TO_OFFICE_DATA_index',
                ],
                'POSTING_DATA' => [
                    'column' => 'c_posting_id_old',
                    'index' => 'c_posting_id_old_POSTING_DATA_index',
                ],
            ];

            foreach ($tablesToModify as $tableName => $data) {
                if (Schema::hasColumn($tableName, $data['column'])) {
                    Schema::table($tableName, function (Blueprint $table) use ($data) {
                        // Drop index first if it exists
                        try {
                            $table->dropIndex($data['index']);
                        } catch (\Exception $e) {
                            // Index might not exist or have a different name; ignore
                        }
                        $table->dropColumn($data['column']);
                    });
                }
            }
        } finally {
            enable_foreign_keys();
        }
    }

    /**
     * Reverse the migrations.
     *
     * Restore the dropped columns and their indexes.
     */
    public function down(): void {
        disable_foreign_keys();

        try {
            // Restore c_office_id_old column and its index to OFFICE_CODES table
            Schema::table('OFFICE_CODES', function (Blueprint $table) {
                $column = $table->integer('c_office_id_old')->nullable();
                if (is_mysql()) {
                    $column->after('c_category_4');
                }
                $table->index('c_office_id_old', 'c_office_id_old_OFFICE_CODES_index');
            });

            // Restore c_posting_id_old column and its index to POSTED_TO_ADDR_DATA table
            Schema::table('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
                $column = $table->integer('c_posting_id_old')->nullable();
                if (is_mysql()) {
                    $column->after('c_addr_id');
                }
                $table->index('c_posting_id_old', 'c_posting_id_old_POSTED_TO_ADDR_DATA_index');
            });

            // Restore c_posting_id_old column and its index to POSTED_TO_OFFICE_DATA table
            Schema::table('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
                $column = $table->integer('c_posting_id_old')->nullable();
                if (is_mysql()) {
                    $column->after('c_posting_id');
                }
                $table->index('c_posting_id_old', 'c_posting_id_old_POSTED_TO_OFFICE_DATA_index');
            });

            // Restore c_posting_id_old column and its index to POSTING_DATA table
            Schema::table('POSTING_DATA', function (Blueprint $table) {
                $column = $table->integer('c_posting_id_old')->nullable();
                if (is_mysql()) {
                    $column->after('c_posting_id');
                }
                $table->index('c_posting_id_old', 'c_posting_id_old_POSTING_DATA_index');
            });
        } finally {
            enable_foreign_keys();
        }
    }
};
