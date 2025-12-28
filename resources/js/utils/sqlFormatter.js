/**
 * SQL Formatter Utility
 * 使用 sql-formatter 库格式化 SQL 查询
 */
import { format } from 'sql-formatter';

/**
 * 格式化 SQL 查询
 * @param {string} sql - 原始 SQL 查询
 * @param {object} options - 格式化选项
 * @returns {string} 格式化后的 SQL
 */
export function formatSql(sql, options = {}) {
    const defaultOptions = {
        language: 'mysql',
        tabWidth: 2,
        keywordCase: 'upper',
        indentStyle: 'standard',
        linesBetweenQueries: 2,
    };

    const mergedOptions = { ...defaultOptions, ...options };

    try {
        return format(sql, mergedOptions);
    } catch (error) {
        console.error('SQL 格式化失败:', error);
        // 如果格式化失败，返回原始 SQL
        return sql;
    }
}

/**
 * 將格式化功能暴露到全局（供 Blade 模板中的內聯腳本使用）
 */
if (typeof window !== 'undefined') {
    window.formatSql = formatSql;
}
