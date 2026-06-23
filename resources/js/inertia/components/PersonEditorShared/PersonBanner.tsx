import React from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from '../../hooks/useTranslation';

/**
 * 人物 banner（對齊 legacy biogmains/banner.blade.php）：人物名標題 + 13 子資源分頁導航
 * （圖示 + 標籤 + 計數徽章 + 當前頁高亮）。供各 editv2 編輯器頁與詳情中樞共用，
 * 修正先前 React 編輯器頁丟失「人物基本資訊頭 + 子資源導航」的退化。
 *
 * 分頁點擊導向 React 詳情中樞對應分頁（/app/basicinformation/{id}?tab=<key>）。
 */
export interface PersonBannerData {
    person_id: number;
    name_chn: string;
    name: string;
    dynasty?: string;
    active_tab: string;
    counts: Record<string, number>;
    /** 是否可看稽核歷史（superadmin/canViewAuditLogs）；驅動「查看歷史」連結（對齊 legacy history-button）。 */
    can_view_audit_logs?: boolean;
}

// hub 分頁 key → audit-logs 的 history_page（對齊 BasicInformationHistory 的 page 值）。
const TAB_HISTORY_PAGE: Record<string, string> = {
    basic_info: 'basic', addresses: 'addresses', alt_names: 'altnames', texts: 'texts',
    postings: 'offices', entries: 'entries', events: 'events', statuses: 'statuses',
    kinship: 'kinship', associations: 'assoc', possessions: 'possession',
    social_institutions: 'socialinst', sources: 'sources',
};

// legacy banner 的分頁順序 / 圖示 / person.tab_* 翻譯鍵（hub tab key）。
const TABS: Array<{ key: string; icon: string; labelKey: string }> = [
    { key: 'basic_info', icon: 'fas fa-user', labelKey: 'tab_basic_info' },
    { key: 'addresses', icon: 'fas fa-map-marker-alt', labelKey: 'tab_addresses' },
    { key: 'alt_names', icon: 'fas fa-id-card', labelKey: 'tab_alt_names' },
    { key: 'texts', icon: 'fas fa-book', labelKey: 'tab_texts' },
    { key: 'postings', icon: 'fas fa-briefcase', labelKey: 'tab_postings' },
    { key: 'entries', icon: 'fas fa-door-open', labelKey: 'tab_entries' },
    { key: 'events', icon: 'fas fa-calendar-alt', labelKey: 'tab_events' },
    { key: 'statuses', icon: 'fas fa-users', labelKey: 'tab_statuses' },
    { key: 'kinship', icon: 'fas fa-user-friends', labelKey: 'tab_kinship' },
    { key: 'associations', icon: 'fas fa-network-wired', labelKey: 'tab_associations' },
    { key: 'possessions', icon: 'fas fa-coins', labelKey: 'tab_possessions' },
    { key: 'social_institutions', icon: 'fas fa-building', labelKey: 'tab_social_institutions' },
    { key: 'sources', icon: 'fas fa-file-alt', labelKey: 'tab_sources' },
];

/**
 * @param onTabSelect 提供時（詳情中樞 SPA）：點分頁改為呼叫此回呼切換分頁，不整頁導航；
 *                    未提供時（editv2 編輯器頁）：router.visit 導向中樞 ?tab=<key>。
 */
export default function PersonBanner({ data, onTabSelect }: { data: PersonBannerData; onTabSelect?: (key: string) => void }) {
    const t = useTranslation('person');
    const tb = useTranslation('biogmains');
    // 不重複顯示姓名/拼音/ID（DashboardLayout 標題與麵包屑已顯示）；僅補朝代（標題未含）+ 子資源導航。

    const go = (key: string) => {
        if (onTabSelect) {
            onTabSelect(key);
            return;
        }
        router.visit(`/app/basicinformation/${data.person_id}?tab=${key}`);
    };

    // 「查看歷史」連結：對齊 legacy history-button（每頁一顆，連到該子資源 audit-logs，另開分頁）。
    const historyPage = TAB_HISTORY_PAGE[data.active_tab];

    return (
        <div style={wrapStyle}>
            {data.can_view_audit_logs && historyPage ? (
                <div style={historyRowStyle}>
                    <a
                        href={`/admin/audit-logs?c_personid=${data.person_id}&history_page=${historyPage}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        style={historyLinkStyle}
                    >
                        <i className="fas fa-history" aria-hidden="true" style={{ marginRight: 4 }} />
                        {tb('view_history')}
                    </a>
                </div>
            ) : null}
            <div style={navStyle} role="tablist">
                {TABS.map((tab) => {
                    const active = tab.key === data.active_tab;
                    // 計數僅在 >0 時顯示 badge（basic_info 無計數）；無記錄不顯示 0（使用者指定，較 legacy 精簡）。
                    const count = tab.key === 'basic_info' ? 0 : (data.counts?.[tab.key] ?? 0);
                    return (
                        <button
                            key={tab.key}
                            type="button"
                            role="tab"
                            aria-selected={active}
                            onClick={() => go(tab.key)}
                            style={{ ...tabStyle, ...(active ? tabActiveStyle : {}) }}
                        >
                            <i className={tab.icon} aria-hidden="true" style={{ marginRight: 4 }} />
                            {t(tab.labelKey)}
                            {count > 0 ? <span style={badgeStyle}>{count}</span> : null}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

const wrapStyle: React.CSSProperties = { marginBottom: 16 };
const historyRowStyle: React.CSSProperties = { display: 'flex', justifyContent: 'flex-end', marginBottom: 4 };
const historyLinkStyle: React.CSSProperties = {
    display: 'inline-flex', alignItems: 'center', padding: '3px 10px', fontSize: '0.78rem',
    borderRadius: 4, border: '1px solid #c9d5e2', background: '#f8fafc', color: '#204467', textDecoration: 'none',
};
const navStyle: React.CSSProperties = { display: 'flex', flexWrap: 'wrap', gap: 2, borderBottom: '1px solid #dee2e6', paddingBottom: 0 };
const tabStyle: React.CSSProperties = {
    display: 'inline-flex', alignItems: 'center', gap: 2, padding: '8px 12px',
    border: '1px solid transparent', borderTopLeftRadius: 6, borderTopRightRadius: 6,
    background: 'none', color: '#255f93', fontSize: '0.85rem', cursor: 'pointer',
    marginBottom: -1,
};
const tabActiveStyle: React.CSSProperties = {
    color: '#495057', background: '#fff', borderColor: '#dee2e6 #dee2e6 #fff',
    fontWeight: 700,
};
const badgeStyle: React.CSSProperties = {
    marginLeft: 5, padding: '1px 7px', borderRadius: 10, background: '#e9ecef',
    color: '#495057', fontSize: '0.72rem', fontWeight: 600,
};
