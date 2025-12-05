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
            // 如果配置是關聯數組（表名 => 說明），返回鍵（表名）
            if (array_keys($configTables) !== range(0, count($configTables) - 1)) {
                return array_keys($configTables);
            }
            // 如果是索引數組（舊格式），直接返回
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
        $configTables = config('codes.tables', []);

        // 如果配置是關聯數組（表名 => 說明）
        if (!empty($configTables) && array_keys($configTables) !== range(0, count($configTables) - 1)) {
            $result = [];
            foreach ($configTables as $name => $description) {
                $result[] = [
                    'name' => $name,
                    'description' => $description,
                ];
            }
            return $result;
        }

        // 向後兼容：如果是索引數組（舊格式）
        $tables = $this->allowedTables();
        $result = [];
        foreach ($tables as $table) {
            $result[] = [
                'name' => $table,
                'description' => '',
            ];
        }

        return $result;
    }
}
