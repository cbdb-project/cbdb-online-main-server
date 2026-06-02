import React from 'react';
import { APP_THEME } from '../../theme';
import { useTranslation } from '../../hooks/useTranslation';

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
    const tPerson = useTranslation('person');

    const handleTabClick = (event: React.MouseEvent<HTMLButtonElement>, key: string) => {
        event.currentTarget.blur();
        onTabChange(key);
    };

    return (
        <div style={barStyle}>
            {tabs.map((tab) => {
                const isActive = tab.key === activeTab;
                const count = counts[tab.key];
                // Try translated label first (tab_<key>), fall back to static label.
                // Check groupDict directly to avoid the fragile string-equality test.
                const translationKey = `tab_${tab.key}`;
                const translated = tPerson(translationKey);
                // tPerson returns the key itself when missing; use !== to detect miss,
                // but also guard against a translation value that happens to equal its key.
                const hasTranlation = translated !== translationKey;
                const label = hasTranlation ? translated : tab.label;
                return (
                    <button
                        type="button"
                        key={tab.key}
                        onPointerDown={(event) => event.preventDefault()}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={(event) => handleTabClick(event, tab.key)}
                        style={{
                            ...tabStyle,
                            ...(isActive ? activeTabStyle : {}),
                        }}
                    >
                        {label}
                        {count != null && count > 0 && tab.key !== 'basic_info' && (
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
    backgroundColor: '#f8f9fa',
    borderBottom: '2px solid #e0e0e0',
    padding: '6px 10px 0',
    gap: 4,
};

const tabStyle: React.CSSProperties = {
    all: 'unset',
    padding: '8px 14px',
    fontSize: '0.875rem',
    borderRadius: '8px 8px 0 0',
    background: 'none',
    border: '1px solid transparent',
    borderBottom: 'none',
    marginBottom: -2,
    cursor: 'pointer',
    color: '#555',
    whiteSpace: 'nowrap',
    display: 'flex',
    alignItems: 'center',
    gap: 5,
    outline: 'none',
    boxShadow: 'none',
    appearance: 'none',
    WebkitAppearance: 'none',
    WebkitTapHighlightColor: 'transparent',
    transition: 'background-color 0.15s, color 0.15s',
};

const activeTabStyle: React.CSSProperties = {
    backgroundColor: '#fff',
    color: APP_THEME.brandText,
    fontWeight: 700,
    border: '1px solid #e0e0e0',
    borderBottom: '2px solid #fff',
};

const badgeStyle: React.CSSProperties = {
    backgroundColor: APP_THEME.brandSurface,
    borderRadius: 8,
    padding: '1px 6px',
    fontSize: '0.72rem',
    fontWeight: 600,
    color: APP_THEME.brandText,
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
