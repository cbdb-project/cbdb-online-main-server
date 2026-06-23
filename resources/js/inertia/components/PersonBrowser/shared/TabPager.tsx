import React from 'react';
import { APP_THEME } from '../../../theme';

interface Props {
    currentPage: number;
    totalPages: number;
    onPageChange: (page: number) => void;
    showAll?: boolean;
    onToggleShowAll?: () => void;
    totalItems?: number;
}

export default function TabPager({ currentPage, totalPages, onPageChange, showAll, onToggleShowAll, totalItems }: Props) {
    const hasShowAllToggle = onToggleShowAll != null && (totalPages > 1 || showAll);

    if (totalPages <= 1 && !showAll) {
        return null;
    }

    const pages: number[] = [];
    const range = 2;
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - range && i <= currentPage + range)) {
            pages.push(i);
        }
    }

    const display: (number | 'ellipsis')[] = [];
    let prev = 0;
    for (const p of pages) {
        if (prev && p - prev > 1) {
            display.push('ellipsis');
        }
        display.push(p);
        prev = p;
    }

    return (
        <div style={containerStyle}>
            {!showAll ? (
                <>
                    <button
                        type="button"
                        disabled={currentPage <= 1}
                        onClick={() => onPageChange(currentPage - 1)}
                        style={{ ...btnStyle, ...(currentPage <= 1 ? disabledStyle : {}) }}
                    >
                        ‹
                    </button>
                    {display.map((item, idx) =>
                        item === 'ellipsis' ? (
                            <span key={`e-${idx}`} style={ellipsisStyle}>
                                …
                            </span>
                        ) : (
                            <button
                                type="button"
                                key={item}
                                onClick={() => onPageChange(item)}
                                style={{
                                    ...btnStyle,
                                    ...(item === currentPage ? activeStyle : {}),
                                }}
                            >
                                {item}
                            </button>
                        ),
                    )}
                    <button
                        type="button"
                        disabled={currentPage >= totalPages}
                        onClick={() => onPageChange(currentPage + 1)}
                        style={{ ...btnStyle, ...(currentPage >= totalPages ? disabledStyle : {}) }}
                    >
                        ›
                    </button>
                    <span style={infoStyle}>
                        第 {currentPage} / {totalPages} 頁
                    </span>
                </>
            ) : (
                <span style={infoStyle}>
                    共 {totalItems ?? '—'} 筆記錄
                </span>
            )}
            {hasShowAllToggle ? (
                <button
                    type="button"
                    onClick={onToggleShowAll}
                    style={showAllBtnStyle}
                >
                    {showAll ? '分頁顯示' : '顯示全部'}
                </button>
            ) : null}
        </div>
    );
}

const containerStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 2,
    padding: '8px 0',
    flexWrap: 'wrap',
};

const btnStyle: React.CSSProperties = {
    all: 'unset',
    padding: '2px 8px',
    fontSize: '0.8125rem',
    cursor: 'pointer',
    borderRadius: 3,
    textAlign: 'center',
    minWidth: 28,
    color: '#495057',
};

const activeStyle: React.CSSProperties = {
    backgroundColor: APP_THEME.brand,
    color: '#fff',
    fontWeight: 600,
};

const disabledStyle: React.CSSProperties = {
    color: '#adb5bd',
    cursor: 'default',
};

const ellipsisStyle: React.CSSProperties = {
    padding: '2px 4px',
    color: '#6c757d',
    fontSize: '0.8125rem',
};

const infoStyle: React.CSSProperties = {
    marginLeft: 8,
    fontSize: '0.75rem',
    color: '#6c757d',
};

const showAllBtnStyle: React.CSSProperties = {
    all: 'unset',
    marginLeft: 12,
    padding: '3px 10px',
    fontSize: '0.78rem',
    cursor: 'pointer',
    borderRadius: 4,
    border: '1px solid #c9d5e2',
    backgroundColor: '#f8fafc',
    color: '#204467',
    fontWeight: 600,
};
