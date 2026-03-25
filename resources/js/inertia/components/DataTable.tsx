import React from 'react';

interface Props {
    columns: Record<string, string>;
    rows: Record<string, unknown>[];
    emptyMessage?: string;
}

export default function DataTable({ columns, rows, emptyMessage = '無資料' }: Props) {
    const columnEntries = Object.entries(columns);

    return (
        <div style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch' }}>
            <table style={{
                width: '100%',
                borderCollapse: 'collapse',
                fontSize: '0.85rem',
                minWidth: Math.max(600, columnEntries.length * 120),
            }}>
                <thead>
                    <tr>
                        {columnEntries.map(([key, label]) => (
                            <th
                                key={key}
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
                                {label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 ? (
                        <tr>
                            <td
                                colSpan={columnEntries.length}
                                style={{
                                    padding: '24px 10px',
                                    textAlign: 'center',
                                    color: '#6c757d',
                                    borderBottom: '1px solid #dee2e6',
                                }}
                            >
                                {emptyMessage}
                            </td>
                        </tr>
                    ) : (
                        rows.map((row, rowIndex) => (
                            <tr
                                key={rowIndex}
                                style={{ backgroundColor: rowIndex % 2 === 0 ? '#fff' : '#f8f9fa' }}
                            >
                                {columnEntries.map(([key]) => (
                                    <td
                                        key={key}
                                        style={{
                                            padding: '6px 10px',
                                            borderBottom: '1px solid #dee2e6',
                                            maxWidth: 300,
                                            overflow: 'hidden',
                                            textOverflow: 'ellipsis',
                                        }}
                                    >
                                        {row[key] != null ? String(row[key]) : ''}
                                    </td>
                                ))}
                            </tr>
                        ))
                    )}
                </tbody>
            </table>
        </div>
    );
}
