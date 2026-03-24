import React from 'react';

export interface ResultRow {
    c_personid: number;
    c_name_chn: string | null;
    c_name: string | null;
    c_dy: string | null;
    c_dynasty: string | null;
    c_dynasty_chn: string | null;
    c_index_year: number | null;
    c_index_addr_id: number | null;
    c_index_addr_name: string | null;
    c_index_addr_chn: string | null;
    c_entry_code: number;
    c_entry_desc_chn: string | null;
    c_entry_desc: string | null;
    c_year: number | null;
    c_sequence: number | null;
}

export interface PaginationData {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface Props {
    rows: ResultRow[];
    pagination: PaginationData | null;
    onPageChange: (page: number) => void;
}

export default function SearchResultTable({ rows, pagination, onPageChange }: Props) {
    if (rows.length === 0) {
        return (
            <div style={{ padding: 24, textAlign: 'center', color: '#6c757d' }}>
                無符合條件的結果
            </div>
        );
    }

    return (
        <div>
            {pagination && (
                <div style={{ marginBottom: 12, color: '#6c757d', fontSize: '0.875rem' }}>
                    共找到 <strong>{pagination.total}</strong> 筆結果
                    （顯示第 {pagination.from ?? 0} - {pagination.to ?? 0} 筆）
                </div>
            )}

            <div style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.85rem' }}>
                    <thead>
                        <tr style={{ backgroundColor: '#f8f9fa' }}>
                            {['人物 ID', '中文名', '英文名', '朝代', '索引年', '索引地址', '入仕代碼', '入仕途徑（中文）', '入仕途徑（英文）', '入仕年份', '序號', '操作'].map((h) => (
                                <th key={h} style={{ padding: '8px 10px', borderBottom: '2px solid #dee2e6', whiteSpace: 'nowrap', textAlign: 'left' }}>
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, i) => (
                            <tr key={`${row.c_personid}-${row.c_entry_code}-${row.c_sequence}-${i}`} style={{ borderBottom: '1px solid #dee2e6' }}>
                                <td style={{ padding: '6px 10px' }}>{row.c_personid}</td>
                                <td style={{ padding: '6px 10px' }}>{row.c_name_chn}</td>
                                <td style={{ padding: '6px 10px' }}>{row.c_name}</td>
                                <td style={{ padding: '6px 10px' }}>{row.c_dynasty_chn ?? row.c_dynasty}</td>
                                <td style={{ padding: '6px 10px' }}>{row.c_index_year}</td>
                                <td style={{ padding: '6px 10px' }}>{row.c_index_addr_chn ?? row.c_index_addr_name}</td>
                                <td style={{ padding: '6px 10px' }}>{row.c_entry_code}</td>
                                <td style={{ padding: '6px 10px' }}>{row.c_entry_desc_chn}</td>
                                <td style={{ padding: '6px 10px' }}>{row.c_entry_desc}</td>
                                <td style={{ padding: '6px 10px' }}>{row.c_year}</td>
                                <td style={{ padding: '6px 10px' }}>{row.c_sequence}</td>
                                <td style={{ padding: '6px 10px' }}>
                                    <a
                                        href={`/basicinformation/${row.c_personid}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        style={{
                                            padding: '2px 10px',
                                            backgroundColor: '#17a2b8',
                                            color: '#fff',
                                            borderRadius: 3,
                                            textDecoration: 'none',
                                            fontSize: '0.8rem',
                                            whiteSpace: 'nowrap',
                                        }}
                                    >
                                        查看
                                    </a>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {pagination && pagination.last_page > 1 && (
                <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 4, marginTop: 16, flexWrap: 'wrap' }}>
                    <button
                        disabled={pagination.current_page <= 1}
                        onClick={() => onPageChange(pagination.current_page - 1)}
                        style={paginationBtnStyle(pagination.current_page <= 1)}
                    >
                        ‹ 上一頁
                    </button>
                    {getPageNumbers(pagination.current_page, pagination.last_page).map((p, i) =>
                        p === null ? (
                            <span key={`ellipsis-${i}`} style={{ padding: '4px 6px', color: '#6c757d' }}>…</span>
                        ) : (
                            <button
                                key={p}
                                onClick={() => onPageChange(p)}
                                style={{
                                    ...paginationBtnStyle(false),
                                    backgroundColor: p === pagination.current_page ? '#007bff' : 'transparent',
                                    color: p === pagination.current_page ? '#fff' : '#007bff',
                                }}
                            >
                                {p}
                            </button>
                        )
                    )}
                    <button
                        disabled={pagination.current_page >= pagination.last_page}
                        onClick={() => onPageChange(pagination.current_page + 1)}
                        style={paginationBtnStyle(pagination.current_page >= pagination.last_page)}
                    >
                        下一頁 ›
                    </button>
                </div>
            )}
        </div>
    );
}

function paginationBtnStyle(disabled: boolean): React.CSSProperties {
    return {
        padding: '4px 10px',
        border: '1px solid #dee2e6',
        borderRadius: 3,
        backgroundColor: 'transparent',
        color: disabled ? '#adb5bd' : '#007bff',
        cursor: disabled ? 'default' : 'pointer',
        fontSize: '0.85rem',
    };
}

function getPageNumbers(current: number, last: number): (number | null)[] {
    if (last <= 7) {
        return Array.from({ length: last }, (_, i) => i + 1);
    }
    const pages: (number | null)[] = [1];
    if (current > 3) pages.push(null);
    for (let i = Math.max(2, current - 1); i <= Math.min(last - 1, current + 1); i++) {
        pages.push(i);
    }
    if (current < last - 2) pages.push(null);
    pages.push(last);
    return pages;
}
