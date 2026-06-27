import React, { useState } from 'react';

interface Props {
    sql: string;
    renderedSql: string;
    bindings: unknown[];
    perPage: number;
    currentPage: number;
}

export default function SqlDebugPanel({ sql, renderedSql, bindings, perPage, currentPage }: Props) {
    const [open, setOpen] = useState(false);

    return (
        <div style={{ marginTop: 16 }}>
            <button
                onClick={() => setOpen(!open)}
                style={{
                    padding: '6px 14px',
                    fontSize: '0.8rem',
                    border: '1px solid var(--border)',
                    borderRadius: 4,
                    backgroundColor: open ? 'var(--muted)' : 'var(--card)',
                    color: 'var(--muted-foreground)',
                    cursor: 'pointer',
                }}
            >
                {open ? '收起 SQL ▲' : '顯示 SQL ▼'}
            </button>

            {open && (
                <div style={{
                    marginTop: 8,
                    border: '1px solid var(--border)',
                    borderRadius: 6,
                    backgroundColor: 'var(--surface-sunken)',
                    padding: 16,
                }}>
                    <p style={{ color: 'var(--muted-foreground)', fontSize: '0.85rem', margin: '0 0 12px' }}>
                        每頁 {perPage} 筆，當前第 {currentPage} 頁
                    </p>

                    <div style={{ marginBottom: 12 }}>
                        <strong style={{ fontSize: '0.85rem' }}>SQL</strong>
                        <pre style={{
                            whiteSpace: 'pre-wrap',
                            wordBreak: 'break-all',
                            backgroundColor: 'var(--card)',
                            color: 'var(--foreground)',
                            border: '1px solid var(--border)',
                            borderRadius: 4,
                            padding: 12,
                            fontSize: '0.8rem',
                            marginTop: 4,
                            maxHeight: 300,
                            overflow: 'auto',
                        }}>
                            {renderedSql}
                        </pre>
                    </div>

                    <div>
                        <strong style={{ fontSize: '0.85rem' }}>Bindings</strong>
                        <pre style={{
                            whiteSpace: 'pre-wrap',
                            wordBreak: 'break-all',
                            backgroundColor: 'var(--card)',
                            color: 'var(--foreground)',
                            border: '1px solid var(--border)',
                            borderRadius: 4,
                            padding: 12,
                            fontSize: '0.8rem',
                            marginTop: 4,
                            maxHeight: 200,
                            overflow: 'auto',
                        }}>
                            {bindings.length > 0 ? JSON.stringify(bindings, null, 2) : '(none)'}
                        </pre>
                    </div>
                </div>
            )}
        </div>
    );
}
