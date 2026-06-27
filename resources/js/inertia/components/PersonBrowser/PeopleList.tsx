import React from 'react';

export type SortOrder = 'asc' | 'desc';

export interface PersonListItem {
    c_personid: number;
    c_name_chn: string | null;
    c_name: string | null;
    c_dynasty_chn: string | null;
    c_index_year: number | null;
    index_addr_chn: string | null;
    alt_name_zi?: string | null;
    alt_name_hao?: string | null;
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
    sortOrder: SortOrder;
    onSelect: (personId: number) => void;
    onPageChange: (page: number) => void;
    onSortChange: (sort: SortOrder) => void;
}

function buildPersonUrl(personId: number): string {
    const url = new URL(window.location.href);
    url.searchParams.set('person_id', String(personId));
    return url.pathname + url.search;
}

const VISITED_RESET_CLASS = 'pb-person-link';

export default function PeopleList({ people, pagination, selectedId, loading, sortOrder, onSelect, onPageChange, onSortChange }: Props) {
    const handleCardClick = (event: React.MouseEvent<HTMLAnchorElement>, personId: number) => {
        event.preventDefault();
        event.currentTarget.blur();
        onSelect(personId);
    };

    const handlePagerClick = (event: React.MouseEvent<HTMLButtonElement>, page: number) => {
        event.currentTarget.blur();
        onPageChange(page);
    };

    if (loading) {
        return <div style={msgStyle}>載入中…</div>;
    }

    if (people.length === 0) {
        return <div style={msgStyle}>無結果</div>;
    }

    return (
        <div style={containerStyle}>
            <style>{`.${VISITED_RESET_CLASS}:visited { color: inherit; }`}</style>
            <div style={listStyle}>
                {people.map((p) => (
                    <a
                        key={p.c_personid}
                        href={buildPersonUrl(p.c_personid)}
                        className={VISITED_RESET_CLASS}
                        style={{
                            ...itemStyle,
                            ...(p.c_personid === selectedId ? selectedItemStyle : {}),
                        }}
                        onPointerDown={(event) => event.preventDefault()}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={(event) => handleCardClick(event, p.c_personid)}
                    >
                        <div style={topRowStyle}>
                            <span style={idBadgeStyle}>#{p.c_personid}</span>
                            <div style={nameBlockStyle}>
                                <strong style={chnNameStyle}>{p.c_name_chn || '—'}</strong>
                                {p.c_name && <span style={romanNameStyle}>{p.c_name}</span>}
                            </div>
                        </div>
                        <div style={tagRowStyle}>
                            <MiniTag label="朝" value={p.c_dynasty_chn} />
                            <MiniTag label="年" value={p.c_index_year != null ? String(p.c_index_year) : null} />
                            <MiniTag label="地" value={p.index_addr_chn} />
                            <MiniTag label="字" value={p.alt_name_zi} />
                            <MiniTag label="號" value={p.alt_name_hao} />
                        </div>
                    </a>
                ))}
            </div>

            <div style={footerStyle}>
                <div style={sortAreaStyle}>
                    <button
                        type="button"
                        style={sortToggleBtnStyle}
                        onClick={() => onSortChange(sortOrder === 'asc' ? 'desc' : 'asc')}
                        title="Toggle ID sort order"
                    >
                        ⇅ ID
                    </button>
                    <span style={sortHintStyle}>
                        {sortOrder === 'asc' ? 'ASC' : 'DESC'}
                    </span>
                </div>
                {pagination && pagination.last_page > 1 && (
                    <div style={pagerStyle}>
                        <button
                            disabled={pagination.current_page <= 1}
                            onPointerDown={(event) => event.preventDefault()}
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={(event) => handlePagerClick(event, pagination.current_page - 1)}
                            style={pagerBtnStyle}
                        >
                            ‹ 上頁
                        </button>
                        <span style={pagerInfoStyle}>
                            {pagination.current_page} / {pagination.last_page}
                        </span>
                        <button
                            disabled={pagination.current_page >= pagination.last_page}
                            onPointerDown={(event) => event.preventDefault()}
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={(event) => handlePagerClick(event, pagination.current_page + 1)}
                            style={pagerBtnStyle}
                        >
                            下頁 ›
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

function MiniTag({ label, value }: { label: string; value: string | null | undefined }) {
    if (!value) return null;

    return (
        <span style={miniTagStyle}>
            <span style={miniTagLabelStyle}>{label}</span>
            <span style={miniTagValueStyle}>{value}</span>
        </span>
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
    display: 'block',
    textDecoration: 'none',
    color: 'inherit',
    margin: '8px 10px 0',
    padding: '12px 14px',
    borderStyle: 'solid',
    borderWidth: 1,
    borderTopColor: 'var(--border)',
    borderRightColor: 'var(--border)',
    borderBottomColor: 'var(--border)',
    borderLeftColor: 'var(--border)',
    borderRadius: 10,
    backgroundColor: 'var(--card)',
    cursor: 'pointer',
    transition: 'background-color 0.15s, border-color 0.15s, box-shadow 0.15s',
    outline: 'none',
    WebkitTapHighlightColor: 'transparent',
    userSelect: 'none',
};

const selectedItemStyle: React.CSSProperties = {
    backgroundColor: 'var(--danger-subtle)',
    borderTopColor: 'var(--danger-border)',
    borderRightColor: 'var(--danger-border)',
    borderBottomColor: 'var(--danger-border)',
    borderLeftColor: 'var(--danger-border)',
    boxShadow: '0 2px 6px rgba(165, 28, 48, 0.08)',
};

const topRowStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    gap: 10,
};

const idBadgeStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    padding: '4px 8px',
    borderRadius: 999,
    backgroundColor: 'var(--muted)',
    color: 'var(--muted-foreground)',
    fontSize: '0.74rem',
    fontWeight: 700,
    letterSpacing: '0.01em',
    flexShrink: 0,
};

const nameBlockStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'baseline',
    justifyContent: 'flex-start',
    gap: 8,
    minWidth: 0,
    flexWrap: 'wrap',
    textAlign: 'left',
};

const chnNameStyle: React.CSSProperties = {
    fontSize: '1rem',
    color: 'var(--foreground)',
};

const romanNameStyle: React.CSSProperties = {
    fontSize: '0.78rem',
    color: 'var(--muted-foreground)',
    maxWidth: '100%',
    wordBreak: 'break-word',
};

const tagRowStyle: React.CSSProperties = {
    display: 'flex',
    flexWrap: 'wrap',
    gap: 4,
    marginTop: 8,
};

const miniTagStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    borderRadius: 4,
    overflow: 'hidden',
    fontSize: '0.82rem',
    lineHeight: 1,
    border: '1px solid var(--danger-border)',
};

const miniTagLabelStyle: React.CSSProperties = {
    padding: '3px 6px',
    backgroundColor: 'var(--danger-subtle)',
    color: 'var(--danger-subtle-foreground)',
    fontWeight: 600,
    whiteSpace: 'nowrap',
};

const miniTagValueStyle: React.CSSProperties = {
    padding: '3px 6px',
    backgroundColor: 'var(--card)',
    color: 'var(--foreground)',
    fontWeight: 600,
    whiteSpace: 'nowrap',
    maxWidth: 80,
    overflow: 'hidden',
    textOverflow: 'ellipsis',
};

const footerStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 8,
    padding: '6px 8px',
    borderTop: '1px solid var(--border)',
    flexShrink: 0,
};

const sortAreaStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    gap: 6,
    flexShrink: 0,
};

const sortToggleBtnStyle: React.CSSProperties = {
    padding: '6px 10px',
    fontSize: '0.85rem',
    fontWeight: 600,
    border: '1px solid var(--border)',
    borderRadius: 6,
    backgroundColor: 'var(--card)',
    color: 'var(--muted-foreground)',
    cursor: 'pointer',
    whiteSpace: 'nowrap',
    minHeight: 34,
};

const sortHintStyle: React.CSSProperties = {
    fontSize: '0.75rem',
    color: 'var(--muted-foreground)',
    whiteSpace: 'nowrap',
};

const pagerStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    fontSize: '0.8125rem',
};

const pagerBtnStyle: React.CSSProperties = {
    all: 'unset',
    padding: '3px 10px',
    borderStyle: 'solid',
    borderWidth: 1,
    borderTopColor: 'var(--border)',
    borderRightColor: 'var(--border)',
    borderBottomColor: 'var(--border)',
    borderLeftColor: 'var(--border)',
    borderRadius: 4,
    backgroundColor: 'var(--card)',
    cursor: 'pointer',
    fontSize: '0.8125rem',
    outline: 'none',
    boxShadow: 'none',
    appearance: 'none',
    WebkitAppearance: 'none',
    WebkitTapHighlightColor: 'transparent',
};

const pagerInfoStyle: React.CSSProperties = {
    color: 'var(--muted-foreground)',
};

const msgStyle: React.CSSProperties = {
    padding: 16,
    textAlign: 'center',
    color: 'var(--muted-foreground)',
    fontSize: '0.875rem',
};
