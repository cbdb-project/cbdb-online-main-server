import React from 'react';

export interface PersonListItem {
    c_personid: number;
    c_name_chn: string | null;
    c_name: string | null;
    c_dynasty_chn: string | null;
    c_index_year: number | null;
    index_addr_chn: string | null;
}

export interface Pagination {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Props {
    people: PersonListItem[];
    pagination: Pagination | null;
    selectedId: number | null;
    loading: boolean;
    onSelect: (personId: number) => void;
    onPageChange: (page: number) => void;
}

export default function PeopleList({ people, pagination, selectedId, loading, onSelect, onPageChange }: Props) {
    if (loading) {
        return <div style={msgStyle}>載入中…</div>;
    }

    if (people.length === 0) {
        return <div style={msgStyle}>無結果</div>;
    }

    return (
        <div style={containerStyle}>
            <div style={listStyle}>
                {people.map((p) => (
                    <div
                        key={p.c_personid}
                        style={{
                            ...itemStyle,
                            backgroundColor: p.c_personid === selectedId ? '#e8f0fe' : undefined,
                            borderLeft: p.c_personid === selectedId ? '3px solid #007bff' : '3px solid transparent',
                        }}
                        onClick={() => onSelect(p.c_personid)}
                    >
                        <div style={nameRowStyle}>
                            <span style={idStyle}>#{p.c_personid}</span>
                            <strong style={chnNameStyle}>{p.c_name_chn || '—'}</strong>
                        </div>
                        <div style={subStyle}>
                            {p.c_name && <span>{p.c_name}</span>}
                            {p.c_dynasty_chn && <span style={tagStyle}>{p.c_dynasty_chn}</span>}
                        </div>
                        <div style={subStyle}>
                            {p.c_index_year != null && <span>Index Year: {p.c_index_year}</span>}
                            {p.index_addr_chn && <span style={{ marginLeft: 8 }}>{p.index_addr_chn}</span>}
                        </div>
                    </div>
                ))}
            </div>

            {pagination && pagination.last_page > 1 && (
                <div style={pagerStyle}>
                    <button
                        disabled={pagination.current_page <= 1}
                        onClick={() => onPageChange(pagination.current_page - 1)}
                        style={pagerBtnStyle}
                    >
                        ‹ 上頁
                    </button>
                    <span style={pagerInfoStyle}>
                        {pagination.current_page} / {pagination.last_page}
                    </span>
                    <button
                        disabled={pagination.current_page >= pagination.last_page}
                        onClick={() => onPageChange(pagination.current_page + 1)}
                        style={pagerBtnStyle}
                    >
                        下頁 ›
                    </button>
                </div>
            )}
        </div>
    );
}

const containerStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    flex: 1,
    overflow: 'hidden',
};

const listStyle: React.CSSProperties = {
    flex: 1,
    overflowY: 'auto',
};

const itemStyle: React.CSSProperties = {
    padding: '8px 10px',
    borderBottom: '1px solid #eee',
    cursor: 'pointer',
    transition: 'background-color 0.15s',
};

const nameRowStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'baseline',
    gap: 6,
};

const idStyle: React.CSSProperties = {
    fontSize: '0.75rem',
    color: '#6c757d',
    minWidth: 50,
};

const chnNameStyle: React.CSSProperties = {
    fontSize: '0.9375rem',
};

const subStyle: React.CSSProperties = {
    fontSize: '0.75rem',
    color: '#6c757d',
    marginTop: 2,
    display: 'flex',
    gap: 6,
    flexWrap: 'wrap',
};

const tagStyle: React.CSSProperties = {
    backgroundColor: '#e9ecef',
    borderRadius: 3,
    padding: '0 4px',
    fontSize: '0.6875rem',
};

const pagerStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    padding: '6px 8px',
    borderTop: '1px solid #dee2e6',
    fontSize: '0.8125rem',
};

const pagerBtnStyle: React.CSSProperties = {
    padding: '3px 10px',
    border: '1px solid #ced4da',
    borderRadius: 4,
    backgroundColor: '#fff',
    cursor: 'pointer',
    fontSize: '0.8125rem',
};

const pagerInfoStyle: React.CSSProperties = {
    color: '#495057',
};

const msgStyle: React.CSSProperties = {
    padding: 16,
    textAlign: 'center',
    color: '#6c757d',
    fontSize: '0.875rem',
};
