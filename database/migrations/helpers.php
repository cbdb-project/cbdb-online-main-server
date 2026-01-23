<?php

/**
 * 数据库兼容性辅助函数
 *
 * 本文件提供了一组辅助函数，用于编写同时兼容 MySQL/MariaDB 和 SQLite 的 Migration 文件。
 * 这是项目的核心规范，所有 Migration 必须遵循。
 *
 * ## 为什么需要兼容性处理？
 *
 * - **生产环境**：使用 MySQL/MariaDB
 * - **测试环境**：使用 SQLite（速度快、无需额外服务）
 * - **CI/CD**：使用 SQLite in-memory 数据库
 *
 * ## 核心原则
 *
 * 1. ✅ **优先使用 Laravel Schema Builder**：大多数情况下自动兼容
 * 2. ✅ **需要原始 SQL 时使用本文件提供的 helper functions**
 * 3. ✅ **使用 `is_mysql()` 和 `is_sqlite()` 进行条件判断**
 * 4. ❌ **避免直接使用 `DB::getDriverName()`**：使用 helper 保持一致性
 *
 * ## 快速示例
 *
 * ```php
 * // ✅ 正确：使用 helper functions
 * public function up(): void {
 *     if (is_mysql()) {
 *         DB::statement("ALTER TABLE users ENGINE=InnoDB");
 *     }
 *     // SQLite 不支持 ENGINE，跳过
 * }
 *
 * // ✅ 正确：移除 SQLite 不支持的语法
 * public function up(): void {
 *     $sql = "CREATE TABLE users (id INT COMMENT 'User ID')";
 *
 *     if (is_sqlite()) {
 *         // SQLite 不支持 COMMENT
 *         $sql = preg_replace('/COMMENT\s+\'[^\']*\'/i', '', $sql);
 *     }
 *
 *     DB::statement($sql);
 * }
 *
 * // ✅ 正确：处理外键约束检查
 * public function up(): void {
 *     disable_foreign_keys();
 *
 *     try {
 *         // ... 你的 schema 修改
 *     } finally {
 *         enable_foreign_keys();
 *     }
 * }
 *
 * // ❌ 错误：直接使用 DB::getDriverName()
 * if (DB::getDriverName() === 'sqlite') { // 不要这样做！
 *     // ...
 * }
 * ```
 *
 * ## 常见兼容性问题
 *
 * | MySQL 特性 | SQLite 行为 | 解决方案 |
 * |-----------|------------|---------|
 * | `COMMENT 'text'` | 不支持 | 使用 preg_replace 移除 |
 * | `ENGINE=InnoDB` | 不支持 | 使用 `if (is_mysql())` 条件执行 |
 * | `USING BTREE` | 不支持 | 使用 preg_replace 移除 |
 * | `AUTO_INCREMENT` | 使用 `AUTOINCREMENT` | Laravel Schema Builder 自动处理 |
 * | `UNSIGNED` | 不支持 | Laravel Schema Builder 自动处理 |
 * | 外键约束检查 | `PRAGMA foreign_keys` | 使用 `disable/enable_foreign_keys()` |
 *
 * ## 完整的 Migration 示例
 *
 * ```php
 * <?php
 *
 * use Illuminate\Database\Migrations\Migration;
 * use Illuminate\Database\Schema\Blueprint;
 * use Illuminate\Support\Facades\Schema;
 * use Illuminate\Support\Facades\DB;
 *
 * return new class extends Migration {
 *     public function up(): void {
 *         // 方式 1：使用 Schema Builder（推荐，自动兼容）
 *         Schema::create('users', function (Blueprint $table) {
 *             $table->id();
 *             $table->string('name');
 *             $table->timestamps();
 *         });
 *
 *         // 方式 2：需要原始 SQL 时的兼容性处理
 *         $sql = "
 *             CREATE TABLE logs (
 *                 id INT AUTO_INCREMENT PRIMARY KEY COMMENT '日志ID',
 *                 message TEXT
 *             ) ENGINE=InnoDB
 *         ";
 *
 *         if (is_sqlite()) {
 *             // 移除 SQLite 不支持的语法
 *             $sql = preg_replace('/COMMENT\s+\'[^\']*\'/i', '', $sql);
 *             $sql = preg_replace('/ENGINE\s*=\s*[a-zA-Z0-9_]+/i', '', $sql);
 *         }
 *
 *         DB::statement($sql);
 *
 *         // 方式 3：数据库特定的优化（可选）
 *         if (is_mysql()) {
 *             // 仅在 MySQL 执行的优化
 *             DB::statement("ALTER TABLE users ROW_FORMAT=DYNAMIC");
 *         }
 *     }
 *
 *     public function down(): void {
 *         Schema::dropIfExists('logs');
 *         Schema::dropIfExists('users');
 *     }
 * };
 * ```
 *
 * @see .claude/skills/migration-guide.md 完整的 Migration 编写指南
 * @see AGENTS.md 项目开发规范
 */

use Illuminate\Support\Facades\DB;

if (!function_exists('disable_foreign_keys')) {
    /**
     * 禁用外键约束检查（兼容 MySQL 和 SQLite）
     *
     * 在需要修改有外键约束的表时使用。务必在操作完成后调用 enable_foreign_keys()。
     *
     * 使用场景：
     * - 修改外键引用的列
     * - 导入大量数据时提升性能
     * - 临时删除或截断有外键约束的表
     *
     * @example
     * ```php
     * public function up(): void {
     *     disable_foreign_keys();
     *
     *     try {
     *         // 修改有外键约束的表
     *         Schema::table('posts', function (Blueprint $table) {
     *             $table->unsignedBigInteger('user_id')->change();
     *         });
     *     } finally {
     *         // 确保即使出错也能重新启用
     *         enable_foreign_keys();
     *     }
     * }
     * ```
     *
     * @return void
     */
    function disable_foreign_keys() {
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
     * 必须与 disable_foreign_keys() 配对使用。建议使用 try-finally 确保执行。
     *
     * @example
     * ```php
     * disable_foreign_keys();
     * try {
     *     // 你的操作
     * } finally {
     *     enable_foreign_keys();
     * }
     * ```
     *
     * @return void
     * @see disable_foreign_keys()
     */
    function enable_foreign_keys() {
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
     * 用于需要在原始 SQL 中插入当前时间戳时。
     * 注意：大多数情况下应使用 Laravel 的 now() 或 Carbon。
     *
     * @example
     * ```php
     * // 在原始 SQL 中使用
     * $timestamp = get_current_timestamp_sql();
     * DB::statement("
     *     UPDATE users
     *     SET last_login = $timestamp
     *     WHERE id = ?
     * ", [$userId]);
     *
     * // 建议：优先使用 Laravel 的方式
     * DB::table('users')
     *     ->where('id', $userId)
     *     ->update(['last_login' => now()]);
     * ```
     *
     * @return string SQL 时间戳函数名称
     */
    function get_current_timestamp_sql() {
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
     * 检查当前数据库是否为 MySQL/MariaDB
     *
     * 用于执行 MySQL 特定的优化或语法。
     * 注意：Laravel 的 'mysql' driver 同时支持 MySQL 和 MariaDB。
     *
     * 使用场景：
     * - MySQL 特定的表引擎设置（ENGINE=InnoDB）
     * - MySQL 特定的索引类型（USING BTREE）
     * - MySQL 特定的优化（ROW_FORMAT=DYNAMIC）
     *
     * @example
     * ```php
     * public function up(): void {
     *     Schema::create('users', function (Blueprint $table) {
     *         $table->id();
     *         $table->string('name');
     *     });
     *
     *     // MySQL 特定的表优化
     *     if (is_mysql()) {
     *         DB::statement("ALTER TABLE users ENGINE=InnoDB ROW_FORMAT=DYNAMIC");
     *     }
     * }
     *
     * // 处理 MySQL 特定的 SQL 语法
     * public function up(): void {
     *     $sql = "CREATE TABLE logs (id INT COMMENT 'Log ID') ENGINE=InnoDB";
     *
     *     if (!is_mysql()) {
     *         // 非 MySQL 数据库：移除专属语法
     *         $sql = preg_replace('/COMMENT\s+\'[^\']*\'/i', '', $sql);
     *         $sql = preg_replace('/ENGINE\s*=\s*[a-zA-Z0-9_]+/i', '', $sql);
     *     }
     *
     *     DB::statement($sql);
     * }
     * ```
     *
     * @return bool 当前数据库是否为 MySQL/MariaDB
     */
    function is_mysql() {
        return DB::getDriverName() === 'mysql';
    }
}

if (!function_exists('is_sqlite')) {
    /**
     * 检查当前数据库是否为 SQLite
     *
     * 用于移除 SQLite 不支持的 MySQL 语法，确保测试环境正常运行。
     *
     * 使用场景：
     * - 移除 COMMENT 注释（SQLite 不支持）
     * - 移除 ENGINE 设置（SQLite 不支持）
     * - 移除 USING BTREE 等索引提示
     * - 处理 SQLite 特定的数据类型或约束
     *
     * @example
     * ```php
     * // 示例 1：移除 SQLite 不支持的语法
     * public function up(): void {
     *     $sql = "
     *         CREATE TABLE users (
     *             id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'User ID',
     *             name VARCHAR(255)
     *         ) ENGINE=InnoDB
     *     ";
     *
     *     if (is_sqlite()) {
     *         // 移除 COMMENT 和 ENGINE
     *         $sql = preg_replace('/COMMENT\s+\'[^\']*\'/i', '', $sql);
     *         $sql = preg_replace('/ENGINE\s*=\s*[a-zA-Z0-9_]+/i', '', $sql);
     *     }
     *
     *     DB::statement($sql);
     * }
     *
     * // 示例 2：条件性跳过 MySQL 特定操作
     * public function up(): void {
     *     Schema::create('users', function (Blueprint $table) {
     *         $table->id();
     *         $table->string('name');
     *     });
     *
     *     // 仅在 MySQL 执行的优化
     *     if (!is_sqlite()) {
     *         DB::statement("ALTER TABLE users ENGINE=InnoDB");
     *     }
     * }
     *
     * // 示例 3：处理复杂的原始 SQL
     * public function up(): void {
     *     $sql = file_get_contents(__DIR__ . '/schema.sql');
     *
     *     if (is_sqlite()) {
     *         // 批量移除 SQLite 不支持的语法
     *         $sql = preg_replace('/COMMENT\s+\'[^\']*\'/i', '', $sql);
     *         $sql = preg_replace('/ENGINE\s*=\s*[a-zA-Z0-9_]+/i', '', $sql);
     *         $sql = preg_replace('/USING\s+BTREE/i', '', $sql);
     *         $sql = preg_replace('/ROW_FORMAT\s*=\s*[a-zA-Z0-9_]+/i', '', $sql);
     *     }
     *
     *     DB::statement($sql);
     * }
     * ```
     *
     * @return bool 当前数据库是否为 SQLite
     */
    function is_sqlite() {
        return DB::getDriverName() === 'sqlite';
    }
}
