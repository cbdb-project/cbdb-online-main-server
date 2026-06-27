import React from 'react';
import PaginationControls, { PaginationData } from './PaginationControls';

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
    c_index_year_type_label: string | null;
    c_entry_code: number;
    c_entry_desc_chn: string | null;
    c_entry_desc: string | null;
    c_year: number | null;
    c_sequence: number | null;
    c_entry_addr_id: number | null;
    c_entry_addr_name: string | null;
    c_entry_addr_chn: string | null;
    c_entry_nianhao_label: string | null;
    c_entry_nh_year: number | null;
    c_entry_range_label: string | null;
    c_exam_rank: string | null;
    c_parental_status_label: string | null;
    c_source_label: string | null;
    c_notes: string | null;
    c_posting_notes: string | null;
    c_sex_label: string | null;
}

interface Props {
    rows: ResultRow[];
    pagination: PaginationData<ResultRow>;
    onPageChange: (page: number) => void;
}

export default function SearchResultTable({ rows, pagination, onPageChange }: Props) {
    if (rows.length === 0) {
        return (
            <div style={{ padding: 24, textAlign: 'center', color: 'var(--muted-foreground)' }}>
                無符合條件的入仕記錄
            </div>
        );
    }

    return (
        <div>
            <div style={{ marginBottom: 12, color: 'var(--muted-foreground)', fontSize: '0.875rem' }}>
                共找到 <strong>{pagination.total}</strong> 筆入仕記錄
                （顯示第 {pagination.from ?? 0} - {pagination.to ?? 0} 筆）
            </div>

            <div style={{ overflowX: 'auto', overflowY: 'auto', maxHeight: '58vh' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.85rem' }}>
                    <thead>
                        <tr style={{ backgroundColor: 'var(--muted)' }}>
                            {['人物 ID', '姓名', '朝代', '指數年', '指數年類型', '性別', '索引地址', '入仕地址', '入仕代碼', '入仕方式', '入仕年', '年號', '範圍', '考試等第', '父母狀態', '來源', '備註', '任官備註', '操作'].map((heading) => (
                                <th key={heading} style={headerCellStyle}>{heading}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, index) => (
                            <tr key={`${row.c_personid}-${row.c_entry_code}-${row.c_sequence}-${index}`} style={{ borderBottom: '1px solid var(--border)' }}>
                                <td style={cellStyle}>{row.c_personid}</td>
                                <td style={cellStyle}>
                                    <div>{row.c_name_chn || '—'}</div>
                                    <div style={subTextStyle}>{row.c_name || '—'}</div>
                                </td>
                                <td style={cellStyle}>{row.c_dynasty_chn ?? row.c_dynasty ?? '—'}</td>
                                <td style={cellStyle}>{row.c_index_year ?? '—'}</td>
                                <td style={cellStyle}>{row.c_index_year_type_label ?? '—'}</td>
                                <td style={cellStyle}>{row.c_sex_label ?? '—'}</td>
                                <td style={cellStyle}>{row.c_index_addr_chn ?? row.c_index_addr_name ?? '—'}</td>
                                <td style={cellStyle}>{row.c_entry_addr_chn ?? row.c_entry_addr_name ?? '—'}</td>
                                <td style={cellStyle}>{row.c_entry_code}</td>
                                <td style={cellStyle}>{row.c_entry_desc_chn ?? row.c_entry_desc ?? '—'}</td>
                                <td style={cellStyle}>{row.c_year ?? '—'}</td>
                                <td style={cellStyle}>
                                    {row.c_entry_nianhao_label ? `${row.c_entry_nianhao_label}${row.c_entry_nh_year ? ` ${row.c_entry_nh_year}年` : ''}` : '—'}
                                </td>
                                <td style={cellStyle}>{row.c_entry_range_label ?? '—'}</td>
                                <td style={cellStyle}>{row.c_exam_rank ?? '—'}</td>
                                <td style={cellStyle}>{row.c_parental_status_label ?? '—'}</td>
                                <td style={cellStyle}>{row.c_source_label ?? '—'}</td>
                                <td style={cellStyle}>{row.c_notes ?? '—'}</td>
                                <td style={cellStyle}>{row.c_posting_notes ?? '—'}</td>
                                <td style={cellStyle}>
                                    <a
                                        href={`/app/basicinformation/${row.c_personid}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        style={actionLinkStyle}
                                    >
                                        查看
                                    </a>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <PaginationControls pagination={pagination} onPageChange={onPageChange} />
        </div>
    );
}

const headerCellStyle: React.CSSProperties = {
    padding: '8px 10px',
    borderBottom: '2px solid var(--border)',
    whiteSpace: 'nowrap',
    textAlign: 'left',
    position: 'sticky',
    top: 0,
    backgroundColor: 'var(--muted)',
    zIndex: 1,
};

const cellStyle: React.CSSProperties = {
    padding: '6px 10px',
    verticalAlign: 'top',
};

const subTextStyle: React.CSSProperties = {
    color: 'var(--muted-foreground)',
    fontSize: '0.78rem',
};

const actionLinkStyle: React.CSSProperties = {
    padding: '2px 10px',
    backgroundColor: 'var(--info)',
    color: 'var(--info-foreground)',
    borderRadius: 4,
    textDecoration: 'none',
    fontSize: '0.8rem',
    whiteSpace: 'nowrap',
};
