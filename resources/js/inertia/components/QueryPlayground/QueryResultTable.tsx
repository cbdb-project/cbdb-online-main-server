import React from 'react';
import { useTranslation } from '../../hooks/useTranslation';

interface Props {
    columns: string[];
    rows: Record<string, unknown>[];
    page: number;
    hasMore: boolean;
    loading: boolean;
    onPageChange: (page: number) => void;
}

export default function QueryResultTable({ columns, rows, page, hasMore, loading, onPageChange }: Props) {
    const t = useTranslation('query');

    if (columns.length === 0 && rows.length === 0) {
        return (
            <div style={{ padding: 24, textAlign: 'center', color: '#6c757d', fontSize: '0.9rem' }}>
                {t('results_empty_hint')}
            </div>
        );
    }

    return (
        <div>
            <div style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch' }}>
                <table style={{
                    width: '100%',
                    borderCollapse: 'collapse',
                    fontSize: '0.85rem',
                    minWidth: Math.max(600, columns.length * 120),
                }}>
                    <thead>
                        <tr>
                            {columns.map((col) => (
                                <th
                                    key={col}
                                    style={{
                                        padding: '8px 10px',
                                        borderBottom: '2px solid #dee2e6',
                                        backgroundColor: '#f8f9fa',
                                        textAlign: 'left',
                                        whiteSpace: 'nowrap',
                                        fontWeight: 600,
                                        fontSize: '0.8rem',
                                        color: '#495057',
                                    }}
                                >
                                    {col}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td
                                    colSpan={columns.length}
                                    style={{ padding: '24px 10px', textAlign: 'center', color: '#6c757d', borderBottom: '1px solid #dee2e6' }}
                                >
                                    {t('empty_results')}
                                </td>
                            </tr>
                        ) : (
                            rows.map((row, rowIndex) => (
                                <tr key={rowIndex} style={{ backgroundColor: rowIndex % 2 === 0 ? '#fff' : '#f8f9fa' }}>
                                    {columns.map((col) => (
                                        <td
                                            key={col}
                                            style={{
                                                padding: '6px 10px',
                                                borderBottom: '1px solid #dee2e6',
                                                maxWidth: 300,
                                                overflow: 'hidden',
                                                textOverflow: 'ellipsis',
                                            }}
                                        >
                                            {row[col] != null ? String(row[col]) : ''}
                                        </td>
                                    ))}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {/* Pagination */}
            <div style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                marginTop: 12,
                padding: '8px 0',
                fontSize: '0.85rem',
                color: '#495057',
            }}>
                <span>
                    {hasMore
                        ? t('results_page_info_more', { page: String(page), rows: String(rows.length) })
                        : t('results_page_info', { page: String(page), rows: String(rows.length) })}
                </span>
                <div style={{ display: 'flex', gap: 4 }}>
                    <button
                        disabled={page <= 1 || loading}
                        onClick={() => onPageChange(page - 1)}
                        style={paginationBtnStyle(page <= 1 || loading)}
                    >
                        {t('prev_page')}
                    </button>
                    <button
                        disabled={!hasMore || loading}
                        onClick={() => onPageChange(page + 1)}
                        style={paginationBtnStyle(!hasMore || loading)}
                    >
                        {t('next_page')}
                    </button>
                </div>
            </div>
        </div>
    );
}

function paginationBtnStyle(disabled: boolean): React.CSSProperties {
    return {
        padding: '4px 12px',
        border: '1px solid #dee2e6',
        borderRadius: 3,
        backgroundColor: 'transparent',
        color: disabled ? '#adb5bd' : '#007bff',
        cursor: disabled ? 'default' : 'pointer',
        fontSize: '0.85rem',
    };
}
