<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Add column/table comments to BIOG_MAIN name fields and ADDRESSES table.
     * Comments are MySQL/MariaDB only; SQLite does not support COMMENT syntax.
     */
    public function up(): void {
        if (!is_mysql()) {
            return;
        }

        // =====================================================================
        // BIOG_MAIN — name-related columns
        // =====================================================================

        $biogMainComments = [
            // Chinese name fields
            'c_surname_chn' => 'Chinese surname; split from c_name_chn by matching longest known surname in pinyin table',
            'c_mingzi_chn' => 'Chinese given name (excluding surname); remainder of c_name_chn after surname extraction',
            'c_name_chn' => 'Chinese full name; auto-generated: c_surname_chn + c_mingzi_chn (no space)',

            // Hanyu Pinyin fields
            'c_surname' => 'Hanyu Pinyin romanization of the person\'s surname; auto-generated from c_surname_chn via pinyin lookup table',
            'c_mingzi' => 'Hanyu Pinyin romanization of the person\'s given name (excluding surname); auto-generated from c_mingzi_chn',
            'c_name' => 'Hanyu Pinyin full name; auto-generated: c_surname + " " + c_mingzi',

            // Foreign (native-language) name fields
            'c_surname_proper' => 'Surname in the person\'s native language (non-Chinese), if applicable; user-editable',
            'c_mingzi_proper' => 'Given name in the person\'s native language (non-Chinese, excluding surname), if applicable; user-editable',
            'c_name_proper' => 'Full name in the person\'s native language; auto-generated: c_mingzi_proper + " " + c_surname_proper (given-name-first order)',

            // Non-Pinyin romanization fields
            'c_surname_rm' => 'Non-Pinyin romanization of the person\'s surname (e.g. Wade-Giles, McCune-Reischauer), if applicable; user-editable',
            'c_mingzi_rm' => 'Non-Pinyin romanization of the person\'s given name (excluding surname), if applicable; user-editable',
            'c_name_rm' => 'Non-Pinyin romanized full name; auto-generated: c_mingzi_rm + " " + c_surname_rm (given-name-first order)',
        ];

        $this->batchModifyComments('BIOG_MAIN', $biogMainComments);

        // =====================================================================
        // ADDRESSES — table comment + column comments
        // =====================================================================

        DB::statement("ALTER TABLE `ADDRESSES` COMMENT 'Denormalized address hierarchy cache; regenerated from ADDR_CODES + ADDR_BELONGS_DATA via artisan cbdb:regenerate-addresses-table. Used for dynasty-based disambiguation of same-named places.'");

        $addressesComments = [
            'c_addr_id' => 'Address ID; FK to ADDR_CODES.c_addr_id (not enforced). Multiple rows may exist per address for different time segments',
            'c_addr_cbd' => 'Legacy CBDB address code from ADDR_CODES',
            'c_name' => 'Romanized address name from ADDR_CODES.c_name',
            'c_name_chn' => 'Chinese address name from ADDR_CODES.c_name_chn',
            'c_admin_type' => 'Administrative unit type (e.g. zhou, fu, xian) from ADDR_CODES.c_admin_type',
            'c_firstyear' => 'First year this address was active (from ADDR_CODES)',
            'c_lastyear' => 'Last year this address was active (from ADDR_CODES)',
            'c_belongs_firstyear' => 'First year this hierarchy chain is valid; derived as max(addr_first, belongs_first) across all levels',
            'c_belongs_lastyear' => 'Last year this hierarchy chain is valid; derived as min(addr_last, belongs_last) across all levels',
            'x_coord' => 'Longitude (x-coordinate) from ADDR_CODES',
            'y_coord' => 'Latitude (y-coordinate) from ADDR_CODES',
            'belongs1_ID' => 'Level-1 parent address ID (immediate parent)',
            'belongs1_Name' => 'Level-1 parent romanized name',
            'belongs1_Name_chn' => 'Level-1 parent Chinese name',
            'belongs2_ID' => 'Level-2 parent address ID',
            'belongs2_Name' => 'Level-2 parent romanized name',
            'belongs2_Name_chn' => 'Level-2 parent Chinese name',
            'belongs3_ID' => 'Level-3 parent address ID',
            'belongs3_Name' => 'Level-3 parent romanized name',
            'belongs3_Name_chn' => 'Level-3 parent Chinese name',
            'belongs4_ID' => 'Level-4 parent address ID',
            'belongs4_Name' => 'Level-4 parent romanized name',
            'belongs4_Name_chn' => 'Level-4 parent Chinese name',
            'belongs5_ID' => 'Level-5 parent address ID',
            'belongs5_Name' => 'Level-5 parent romanized name',
            'belongs5_Name_chn' => 'Level-5 parent Chinese name',
        ];

        $this->batchModifyComments('ADDRESSES', $addressesComments);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        if (!is_mysql()) {
            return;
        }

        // Remove BIOG_MAIN column comments
        $biogMainColumns = [
            'c_surname_chn', 'c_mingzi_chn', 'c_name_chn',
            'c_surname', 'c_mingzi', 'c_name',
            'c_surname_proper', 'c_mingzi_proper', 'c_name_proper',
            'c_surname_rm', 'c_mingzi_rm', 'c_name_rm',
        ];

        $this->batchRemoveComments('BIOG_MAIN', $biogMainColumns);

        // Remove ADDRESSES table comment and column comments
        DB::statement("ALTER TABLE `ADDRESSES` COMMENT ''");

        $addressesColumns = [
            'c_addr_id', 'c_addr_cbd', 'c_name', 'c_name_chn', 'c_admin_type',
            'c_firstyear', 'c_lastyear', 'c_belongs_firstyear', 'c_belongs_lastyear',
            'x_coord', 'y_coord',
            'belongs1_ID', 'belongs1_Name', 'belongs1_Name_chn',
            'belongs2_ID', 'belongs2_Name', 'belongs2_Name_chn',
            'belongs3_ID', 'belongs3_Name', 'belongs3_Name_chn',
            'belongs4_ID', 'belongs4_Name', 'belongs4_Name_chn',
            'belongs5_ID', 'belongs5_Name', 'belongs5_Name_chn',
        ];

        $this->batchRemoveComments('ADDRESSES', $addressesColumns);
    }

    /**
     * Batch-add comments to multiple columns in a single ALTER TABLE statement.
     *
     * @param array<string, string> $columnComments column => comment
     */
    private function batchModifyComments(string $table, array $columnComments): void {
        $clauses = [];

        foreach ($columnComments as $column => $comment) {
            $colDef = $this->getColumnDefinition($table, $column);
            if ($colDef !== null) {
                $escapedComment = str_replace("'", "\\'", $comment);
                $clauses[] = "MODIFY COLUMN {$colDef} COMMENT '{$escapedComment}'";
            }
        }

        if (!empty($clauses)) {
            DB::statement("ALTER TABLE `{$table}` " . implode(', ', $clauses));
        }
    }

    /**
     * Batch-remove comments from multiple columns in a single ALTER TABLE statement.
     */
    private function batchRemoveComments(string $table, array $columns): void {
        $clauses = [];

        foreach ($columns as $column) {
            $colDef = $this->getColumnDefinition($table, $column);
            if ($colDef !== null) {
                $colDef = preg_replace("/COMMENT\s+'[^']*'/i", '', $colDef);
                $clauses[] = "MODIFY COLUMN {$colDef}";
            }
        }

        if (!empty($clauses)) {
            DB::statement("ALTER TABLE `{$table}` " . implode(', ', $clauses));
        }
    }

    /**
     * Get the full column definition from INFORMATION_SCHEMA for use in ALTER TABLE MODIFY COLUMN.
     *
     * Returns a string like: `c_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
     */
    private function getColumnDefinition(string $table, string $column): ?string {
        $row = DB::selectOne("
            SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                   CHARACTER_SET_NAME, COLLATION_NAME, EXTRA
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ", [$table, $column]);

        if (!$row) {
            return null;
        }

        $def = "`{$row->COLUMN_NAME}` {$row->COLUMN_TYPE}";

        // Character set / collation (only for string types)
        if ($row->CHARACTER_SET_NAME) {
            $def .= " CHARACTER SET {$row->CHARACTER_SET_NAME}";
        }
        if ($row->COLLATION_NAME) {
            $def .= " COLLATE {$row->COLLATION_NAME}";
        }

        // Nullability
        $def .= ($row->IS_NULLABLE === 'YES') ? ' DEFAULT NULL' : ' NOT NULL';

        // Default value (for non-null defaults)
        if ($row->COLUMN_DEFAULT !== null && $row->IS_NULLABLE !== 'YES') {
            $escapedDefault = str_replace("'", "\\'", $row->COLUMN_DEFAULT);
            $def .= " DEFAULT '{$escapedDefault}'";
        }

        // Extra (e.g. AUTO_INCREMENT)
        if (!empty($row->EXTRA)) {
            $def .= " {$row->EXTRA}";
        }

        return $def;
    }
};
