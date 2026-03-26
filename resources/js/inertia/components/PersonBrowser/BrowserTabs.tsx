import React from 'react';

interface TabDef {
    key: string;
    label: string;
}

interface Props {
    tabs: TabDef[];
    activeTab: string;
    counts: Record<string, number>;
    onTabChange: (key: string) => void;
}

export default function BrowserTabs({ tabs, activeTab, counts, onTabChange }: Props) {
    const handleTabClick = (event: React.MouseEvent<HTMLButtonElement>, key: string) => {
        event.currentTarget.blur();
        onTabChange(key);
    };

    return (
        <div style={barStyle}>
            {tabs.map((t) => {
                const isActive = t.key === activeTab;
                const count = counts[t.key];
                return (
                    <button
                        type="button"
                        key={t.key}
                        onPointerDown={(event) => event.preventDefault()}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={(event) => handleTabClick(event, t.key)}
                        style={{
                            ...tabStyle,
                            ...(isActive ? activeTabStyle : {}),
                        }}
                    >
                        {t.label}
                        {count != null && count > 0 && t.key !== 'basic_info' && (
                            <span style={badgeStyle}>{count}</span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

const barStyle: React.CSSProperties = {
    display: 'flex',
    flexWrap: 'wrap',
    borderBottom: '2px solid #dee2e6',
    backgroundColor: '#f8f9fa',
    padding: '0 8px',
    gap: 0,
};

const tabStyle: React.CSSProperties = {
    all: 'unset',
    padding: '8px 12px',
    fontSize: '0.8125rem',
    borderStyle: 'solid',
    borderWidth: '0 0 2px 0',
    borderTopColor: 'transparent',
    borderRightColor: 'transparent',
    borderBottomColor: 'transparent',
    borderLeftColor: 'transparent',
    marginBottom: -2,
    background: 'none',
    cursor: 'pointer',
    color: '#495057',
    whiteSpace: 'nowrap',
    display: 'flex',
    alignItems: 'center',
    gap: 4,
    outline: 'none',
    boxShadow: 'none',
    appearance: 'none',
    WebkitAppearance: 'none',
    WebkitTapHighlightColor: 'transparent',
};

const activeTabStyle: React.CSSProperties = {
    borderBottomColor: '#007bff',
    borderTopColor: 'transparent',
    borderRightColor: 'transparent',
    borderLeftColor: 'transparent',
    color: '#007bff',
    fontWeight: 600,
};

const badgeStyle: React.CSSProperties = {
    backgroundColor: '#e9ecef',
    borderRadius: 8,
    padding: '0 5px',
    fontSize: '0.6875rem',
    color: '#495057',
    minWidth: 16,
    textAlign: 'center',
};

export const TAB_DEFINITIONS: TabDef[] = [
    { key: 'basic_info', label: '基本資料' },
    { key: 'addresses', label: '地址' },
    { key: 'alt_names', label: '別名' },
    { key: 'texts', label: '著述' },
    { key: 'postings', label: '官名' },
    { key: 'entries', label: '入仕' },
    { key: 'events', label: '事件' },
    { key: 'statuses', label: '社會區分' },
    { key: 'kinship', label: '親屬' },
    { key: 'associations', label: '社會關係' },
    { key: 'possessions', label: '財產' },
    { key: 'social_institutions', label: '社交機構' },
    { key: 'sources', label: '出處' },
];
