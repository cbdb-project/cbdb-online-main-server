import React, { useCallback } from 'react';
import { formatSql } from '../../../utils/sqlFormatter';
import { useTranslation } from '../../hooks/useTranslation';

interface Props {
    sql: string;
    onSqlChange: (sql: string) => void;
    onExecute: () => void;
    onShare: () => void;
    loading: boolean;
    disabled?: boolean;
}

export default function SqlEditorPanel({ sql, onSqlChange, onExecute, onShare, loading, disabled }: Props) {
    const t = useTranslation('query');

    const handleFormat = useCallback(() => {
        if (sql.trim()) {
            onSqlChange(formatSql(sql));
        }
    }, [sql, onSqlChange]);

    const handleKeyDown = useCallback((e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        // Ctrl/Cmd + Enter to execute
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            onExecute();
        }
    }, [onExecute]);

    return (
        <div>
            <div style={{ marginBottom: 8, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <label style={{ fontWeight: 600, fontSize: '0.9rem', color: 'var(--muted-foreground)' }}>
                    {t('sql_label')}
                </label>
                <span style={{ fontSize: '0.75rem', color: 'var(--muted-foreground)' }}>
                    {t('sql_shortcut')}
                </span>
            </div>
            <textarea
                value={sql}
                onChange={(e) => onSqlChange(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder={t('sql_editor_placeholder')}
                rows={6}
                disabled={disabled}
                style={{
                    width: '100%',
                    padding: 12,
                    border: '1px solid var(--input)',
                    borderRadius: 6,
                    fontFamily: 'SFMono-Regular, Menlo, Monaco, Consolas, monospace',
                    fontSize: '0.85rem',
                    lineHeight: 1.5,
                    resize: 'vertical',
                    backgroundColor: disabled ? 'var(--muted)' : 'var(--card)',
                    color: 'var(--foreground)',
                    boxSizing: 'border-box',
                }}
            />
            <div style={{ marginTop: 8, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <button
                    onClick={onExecute}
                    disabled={loading || !sql.trim() || disabled}
                    style={primaryBtnStyle(loading || !sql.trim() || !!disabled)}
                >
                    {loading ? t('querying') : t('run_query')}
                </button>
                <button
                    onClick={handleFormat}
                    disabled={!sql.trim() || disabled}
                    style={secondaryBtnStyle(!sql.trim() || !!disabled)}
                >
                    {t('sql_format')}
                </button>
                <button
                    onClick={onShare}
                    disabled={!sql.trim()}
                    style={secondaryBtnStyle(!sql.trim())}
                >
                    {t('sql_share')}
                </button>
            </div>
        </div>
    );
}

function primaryBtnStyle(disabled: boolean): React.CSSProperties {
    return {
        padding: '8px 18px',
        fontSize: '0.85rem',
        fontWeight: 600,
        border: 'none',
        borderRadius: 5,
        backgroundColor: disabled ? 'var(--muted-foreground)' : 'var(--primary)',
        color: 'var(--primary-foreground)',
        cursor: disabled ? 'default' : 'pointer',
    };
}

function secondaryBtnStyle(disabled: boolean): React.CSSProperties {
    return {
        padding: '8px 14px',
        fontSize: '0.85rem',
        border: '1px solid var(--border)',
        borderRadius: 5,
        backgroundColor: disabled ? 'var(--muted)' : 'var(--card)',
        color: disabled ? 'var(--muted-foreground)' : 'var(--muted-foreground)',
        cursor: disabled ? 'default' : 'pointer',
    };
}
