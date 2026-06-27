import React from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import PersonBanner, { PersonBannerData } from '../../components/PersonEditorShared/PersonBanner';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

// #70 子資源「記錄不存在」優雅降級頁（assoc / kinship 等共用）。
// 取代舊版「點 edit 一條不存在的對應列即硬報 404/錯誤」——改為明確訊息 + 返回人物詳情中樞連結。
// 典型情境：從鏡像「疑似匹配」提示跳對面，但該列已被刪除、或主鍵不符。

interface PageProps extends SharedProps {
    person_label: string;
    index_url: string;
    person_banner: PersonBannerData;
}

const boxStyle: React.CSSProperties = {
    maxWidth: 640, margin: '24px auto', padding: '20px 22px', textAlign: 'center',
    border: '1px solid var(--border)', borderRadius: 10, background: 'var(--card)', color: 'var(--muted-foreground)',
};
const linkStyle: React.CSSProperties = {
    display: 'inline-block', marginTop: 14, padding: '6px 16px',
    border: '1px solid var(--primary)', borderRadius: 6, color: 'var(--primary)', textDecoration: 'none', fontWeight: 600,
};

export default function SubresourceNotFound() {
    const p = usePage<PageProps>().props;
    const tb = useTranslation('biogmains');
    const tc = useTranslation('common');
    const t = (k: string, fallback: string): string => {
        const v = tb(k);
        if (v && v !== k) return v;
        const v2 = tc(k);
        if (v2 && v2 !== k) return v2;
        return fallback;
    };

    return (
        <DashboardLayout title={p.person_label} headerAlign="center">
            <PersonBanner data={p.person_banner} />
            <div style={boxStyle} role="alert">
                <div style={{ fontWeight: 600, fontSize: '1.05rem', marginBottom: 8 }}>
                    {t('subresource_not_found_title', '找不到這筆記錄')}
                </div>
                <div>
                    {t('subresource_not_found_desc', '這筆記錄不存在（可能已被刪除，或主鍵不符）。可能是對面記錄已變更。')}
                </div>
                <a href={p.index_url} style={linkStyle}>
                    {t('subresource_not_found_back', '返回列表')}
                </a>
            </div>
        </DashboardLayout>
    );
}
