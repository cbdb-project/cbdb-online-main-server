<?php

/**
 * 数据库兼容性辅助函数
 *
 * 这些函数用于编写兼容 MySQL 和 SQLite 的迁移文件
 */

use Illuminate\Support\Facades\DB;

if (!function_exists('disable_foreign_keys')) {
    /**
     * 禁用外键约束检查（兼容 MySQL 和 SQLite）
     *
     * @return void
     */
    function disable_foreign_keys()
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }
    }
}

if (!function_exists('enable_foreign_keys')) {
    /**
     * 启用外键约束检查（兼容 MySQL 和 SQLite）
     *
     * @return void
     */
    function enable_foreign_keys()
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
}

if (!function_exists('get_current_timestamp_sql')) {
    /**
     * 获取当前时间戳的 SQL 表达式（兼容 MySQL 和 SQLite）
     *
     * @return string
     */
    function get_current_timestamp_sql()
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            return 'NOW()';
        } elseif ($driver === 'sqlite') {
            return "datetime('now')";
        }

        return 'CURRENT_TIMESTAMP';
    }
}

if (!function_exists('is_mysql')) {
    /**
     * 检查当前数据库是否为 MySQL
     *
     * @return bool
     */
    function is_mysql()
    {
        return DB::getDriverName() === 'mysql';
    }
}

if (!function_exists('is_sqlite')) {
    /**
     * 检查当前数据库是否为 SQLite
     *
     * @return bool
     */
    function is_sqlite()
    {
        return DB::getDriverName() === 'sqlite';
    }
}
