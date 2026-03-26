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
    return (
        <div style={barStyle}>
            {tabs.map((t) => {
                const isActive = t.key === activeTab;
                const count = counts[t.key];
                return (
                    <button
                        key={t.key}
                        onClick={() => onTabChange(t.key)}
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
    padding: '8px 12px',
    fontSize: '0.8125rem',
    border: 'none',
    borderBottom: '2px solid transparent',
    marginBottom: -2,
    background: 'none',
    cursor: 'pointer',
    color: '#495057',
    whiteSpace: 'nowrap',
    display: 'flex',
    alignItems: 'center',
    gap: 4,
};

const activeTabStyle: React.CSSProperties = {
    borderBottomColor: '#007bff',
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
    { key: 'alt_names', label: '別名' },
    { key: 'addresses', label: '地址' },
    { key: 'texts', label: '著述' },
    { key: 'sources', label: '出處' },
    { key: 'entries', label: '入仕' },
    { key: 'events', label: '事件' },
    { key: 'statuses', label: '社會區分' },
    { key: 'associations', label: '社會關係' },
    { key: 'kinship', label: '親屬' },
    { key: 'possessions', label: '財產' },
    { key: 'social_institutions', label: '社交機構' },
    { key: 'postings', label: '官名' },
];
