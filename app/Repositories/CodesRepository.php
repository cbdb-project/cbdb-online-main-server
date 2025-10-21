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
            $defaultConnection = config('database.default');
            $connectionName = config('codes.connection') ?: $defaultConnection;
            $connection = \Illuminate\Support\Facades\DB::connection($connectionName);

            $tables = $connection->select('SHOW TABLES');
            $databaseName = config('codes.database');
            if (empty($databaseName)) {
                $databaseName = config("database.connections.{$connectionName}.database");
            }
            if (empty($databaseName)) {
                $databaseName = $connection->getDatabaseName();
            }
            $columnName = $databaseName ? "Tables_in_{$databaseName}" : null;

            foreach ($tables as $row) {
                $rowArray = (array) $row;
                if ($columnName && isset($rowArray[$columnName])) {
                    $actual = $rowArray[$columnName];
                } else {
                    $actual = array_values($rowArray)[0] ?? null;
                }
                if (!$actual) {
                    continue;
                }
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
