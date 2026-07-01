import React, { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Input } from '../../components/ui/Input';
import { useTranslation } from '../../hooks/useTranslation';

interface ViewDefinition {
    key: string;
    primary_alias: string;
    title: string;
    title_en: string;
    title_chn: string;
    description: string;
    aliases: string[];
    column_count: number;
    app_url: string;
}

interface Props {
    views: ViewDefinition[];
}

export default function List({ views }: Props) {
    const [query, setQuery] = useState('');
    const t = useTranslation('views');

    const filteredViews = useMemo(() => {
        const keyword = query.trim().toLowerCase();
        if (keyword === '') {
            return views;
        }

        return views.filter((view) => {
            const haystack = [
                view.key,
                view.primary_alias,
                view.title_en,
                view.title_chn,
                view.description,
                ...view.aliases,
            ].join(' ').toLowerCase();

            return haystack.includes(keyword);
        });
    }, [query, views]);

    return (
        <DashboardLayout disableContentPadding>
            <div style={{ padding: '24px 24px 48px' }}>
                <div>
                    <h2 style={{ margin: 0, fontSize: '1.55rem', fontWeight: 700 }}>{t('views_overview_title')}</h2>
                </div>

                {/* 結構／搜尋框與 app/codes 列表完全對齊：外層卡片（rounded-lg border p-4）+ 內層圓角邊框表格盒
                    （overflow-x-auto rounded-md border）；搜尋框改用共用 Input 組件（同樣式）。 */}
                <div className="mt-4 rounded-lg border border-border bg-card p-4">
                    <Input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('search_placeholder')}
                        className="mb-3 max-w-md"
                        autoComplete="off"
                    />
                    <div className="overflow-x-auto rounded-md border border-border">
                        <table style={{
                            width: '100%',
                            borderCollapse: 'collapse',
                            fontSize: '1rem',
                            minWidth: 860,
                        }}>
                            <thead>
                                <tr style={{ backgroundColor: 'var(--muted)' }}>
                                    <th style={thStyle}>{t('view_name_en')}</th>
                                    <th style={thStyle}>{t('view_name_zh')}</th>
                                    <th style={thStyle}>{t('description')}</th>
                                    <th style={thStyle}>{t('column_count')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredViews.map((view, index) => (
                                    <tr
                                        key={view.key}
                                        style={{ backgroundColor: index % 2 === 0 ? 'var(--card)' : 'var(--surface-sunken)' }}
                                    >
                                        <td style={tdStyle}>
                                            <Link
                                                href={view.app_url}
                                                style={{ color: 'var(--primary)', textDecoration: 'none', fontFamily: 'monospace', fontSize: '0.85rem', fontWeight: 600 }}
                                            >
                                                {view.primary_alias}
                                            </Link>
                                            <div style={{ color: 'var(--muted-foreground)', fontSize: '0.8rem', marginTop: 3 }}>
                                                {view.title_en}
                                            </div>
                                            <div style={{ color: 'var(--muted-foreground)', fontSize: '0.78rem', marginTop: 2 }}>
                                                key: <code>{view.key}</code>
                                            </div>
                                            {view.aliases.length > 1 && (
                                                <div style={{ color: 'var(--muted-foreground)', fontSize: '0.8rem', marginTop: 6, lineHeight: 1.5 }}>
                                                    {view.aliases.slice(1).join(', ')}
                                                </div>
                                            )}
                                        </td>
                                        <td style={tdStyle}>{view.title_chn}</td>
                                        <td style={tdStyle}>{view.description || '—'}</td>
                                        <td style={{ ...tdStyle, whiteSpace: 'nowrap' }}>{view.column_count}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {filteredViews.length === 0 && (
                        <p className="py-2 text-sm text-muted-foreground">{t('no_views_found')}</p>
                    )}
                </div>
            </div>
        </DashboardLayout>
    );
}

const thStyle: React.CSSProperties = {
    padding: '10px 12px',
    textAlign: 'left',
    fontWeight: 600,
    // 表頭字號與 app/codes 列表對齊：0.92rem。
    fontSize: '0.92rem',
    color: 'var(--muted-foreground)',
    borderBottom: '2px solid var(--border)',
    whiteSpace: 'nowrap',
};

const tdStyle: React.CSSProperties = {
    padding: '8px 12px',
    borderBottom: '1px solid var(--border)',
    verticalAlign: 'top',
};
