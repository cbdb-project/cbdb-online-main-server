<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/9/8
 * Time: 15:52
 */

namespace App\Repositories;


class CodesRepository
{
    /**
     * Get the list of allowed code tables.
     *
     * @return array
     */
    public function allowedTables(): array
    {
        $configTables = config('codes.tables', []);
        if (!empty($configTables)) {
            return array_values(array_unique($configTables));
        }

        $envTables = env('CODES_TABLES');
        if (!empty($envTables)) {
            return array_values(array_unique(array_filter(array_map('trim', explode(',', $envTables)))));
        }

        return [];
    }

    /**
     * Return mapping of uppercase table name to actual table name in database.
     *
     * @return array<string,string>
     */
    public function allowedTableMap(): array
    {
        $allowed = $this->allowedTables();
        if (empty($allowed)) {
            return [];
        }

        $map = [];
        foreach ($allowed as $table) {
            $map[strtoupper($table)] = $table;
        }

        try {
            $tables = \Illuminate\Support\Facades\DB::select('SHOW TABLES');
            foreach ($tables as $row) {
                $actual = array_values((array) $row)[0];
                $upper = strtoupper($actual);
                if (isset($map[$upper])) {
                    $map[$upper] = $actual;
                }
            }
        } catch (\Exception $e) {
            // Ignore DB errors; fallback to configured casing.
        }

        return $map;
    }

    public function codes()
    {
        return $this->allowedTables();
    }
}
