import React from 'react';

export interface PaginationData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface Props {
    pagination: PaginationData<unknown>;
    onPageChange: (page: number) => void;
}

export default function PaginationControls({ pagination, onPageChange }: Props) {
    if (pagination.last_page <= 1) {
        return null;
    }

    return (
        <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 4, marginTop: 16, flexWrap: 'wrap' }}>
            <button
                disabled={pagination.current_page <= 1}
                onClick={() => onPageChange(pagination.current_page - 1)}
                style={paginationBtnStyle(pagination.current_page <= 1)}
            >
                ‹ 上一頁
            </button>
            {getPageNumbers(pagination.current_page, pagination.last_page).map((page, index) =>
                page === null ? (
                    <span key={`ellipsis-${index}`} style={{ padding: '4px 6px', color: 'var(--muted-foreground)' }}>…</span>
                ) : (
                    <button
                        key={page}
                        onClick={() => onPageChange(page)}
                        style={{
                            ...paginationBtnStyle(false),
                            backgroundColor: page === pagination.current_page ? 'var(--primary)' : 'transparent',
                            color: page === pagination.current_page ? 'var(--primary-foreground)' : 'var(--primary)',
                        }}
                    >
                        {page}
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
    );
}

function paginationBtnStyle(disabled: boolean): React.CSSProperties {
    return {
        padding: '4px 10px',
        border: '1px solid var(--border)',
        borderRadius: 3,
        backgroundColor: 'transparent',
        color: disabled ? 'var(--muted-foreground)' : 'var(--primary)',
        cursor: disabled ? 'default' : 'pointer',
        fontSize: '0.85rem',
    };
}

function getPageNumbers(current: number, last: number): (number | null)[] {
    if (last <= 7) {
        return Array.from({ length: last }, (_, index) => index + 1);
    }

    const pages: (number | null)[] = [1];

    if (current > 3) {
        pages.push(null);
    }

    for (let page = Math.max(2, current - 1); page <= Math.min(last - 1, current + 1); page += 1) {
        pages.push(page);
    }

    if (current < last - 2) {
        pages.push(null);
    }

    pages.push(last);

    return pages;
}
