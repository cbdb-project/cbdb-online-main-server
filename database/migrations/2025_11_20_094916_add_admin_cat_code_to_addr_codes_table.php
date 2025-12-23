<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddAdminCatCodeToAddrCodesTable extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        $this->ensureDefaultAdminCategoryRow();

        if (!Schema::hasColumn('ADDR_CODES', 'c_admin_cat_code')) {
            Schema::table('ADDR_CODES', function (Blueprint $table) {
                $table->integer('c_admin_cat_code')
                    ->default(0)
                    ->after('c_admin_type');
            });
        }

        $this->ensureValidAdminCategoryCodes();

        if (!$this->foreignKeyExists('ADDR_CODES', 'fk_addr_codes_admin_cat_code')) {
            Schema::table('ADDR_CODES', function (Blueprint $table) {
                $table->foreign('c_admin_cat_code', 'fk_addr_codes_admin_cat_code')
                    ->references('c_admin_cat_code')
                    ->on('ADMIN_CAT_CODES')
                    ->onUpdate('cascade')
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        if ($this->foreignKeyExists('ADDR_CODES', 'fk_addr_codes_admin_cat_code')) {
            Schema::table('ADDR_CODES', function (Blueprint $table) {
                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign('fk_addr_codes_admin_cat_code');
                }
            });
        }

        if (Schema::hasColumn('ADDR_CODES', 'c_admin_cat_code')) {
            Schema::table('ADDR_CODES', function (Blueprint $table) {
                $table->dropColumn('c_admin_cat_code');
            });
        }
    }

    /**
     * Determine if a foreign key already exists on the table.
     */
    protected function foreignKeyExists(string $table, string $keyName): bool {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite doesn't store named constraints in a systemic table like information_schema.
            // We check for the existence of a foreign key targeting the specific table and column.
            $foreignKeys = DB::select("PRAGMA foreign_key_list(`{$table}`)");
            foreach ($foreignKeys as $fk) {
                // In this specific migration, we check for c_admin_cat_code -> ADMIN_CAT_CODES
                if ($fk->table === 'ADMIN_CAT_CODES' && $fk->from === 'c_admin_cat_code') {
                    return true;
                }
            }

            return false;
        }

        $database = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $keyName)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    /**
     * Normalize existing ADDR_CODES rows so they reference valid categories.
     */
    protected function ensureValidAdminCategoryCodes(): void {
        if (!Schema::hasColumn('ADDR_CODES', 'c_admin_cat_code')) {
            return;
        }

        $defaultCode = DB::table('ADMIN_CAT_CODES')
            ->orderBy('c_admin_cat_code')
            ->value('c_admin_cat_code');

        if ($defaultCode === null) {
            throw new \RuntimeException('ADMIN_CAT_CODES is empty; seed it before adding the foreign key constraint.');
        }

        // Normalize NULL or zero entries first.
        DB::table('ADDR_CODES')
            ->where(function ($query) {
                $query->whereNull('c_admin_cat_code')
                    ->orWhere('c_admin_cat_code', 0);
            })
            ->update(['c_admin_cat_code' => $defaultCode]);

        // Ensure every remaining value exists in ADMIN_CAT_CODES.
        DB::table('ADDR_CODES')
            ->whereNotExists(function ($subQuery) {
                $subQuery->select(DB::raw(1))
                    ->from('ADMIN_CAT_CODES')
                    ->whereColumn('ADMIN_CAT_CODES.c_admin_cat_code', 'ADDR_CODES.c_admin_cat_code');
            })
            ->update(['c_admin_cat_code' => $defaultCode]);
    }

    /**
     * Make sure ADMIN_CAT_CODES has at least the fallback record.
     */
    protected function ensureDefaultAdminCategoryRow(): void {
        if (!Schema::hasTable('ADMIN_CAT_CODES')) {
            return;
        }

        DB::table('ADMIN_CAT_CODES')->updateOrInsert(
            ['c_admin_cat_code' => 0],
            [
                'c_admin_cat_py' => 'unknown',
                'c_admin_cat_hz' => '未分類',
                'c_admin_cat_trans' => 'Unknown category',
                'c_notes' => 'Auto-generated fallback to satisfy ADDR_CODES foreign key.',
            ]
        );
    }
}
