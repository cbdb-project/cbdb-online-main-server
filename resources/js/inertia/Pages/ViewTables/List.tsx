import React, { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
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

                <div style={sectionStyle}>
                    <div style={sectionBodyStyle}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
                            <div style={{ color: 'var(--muted-foreground)', fontSize: '0.85rem' }}>
                                {t('views_count_summary', { total: String(views.length), shown: String(filteredViews.length) })}
                            </div>
                            <div style={filterBarStyle}>
                                <input
                                    type="text"
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    placeholder={t('search_placeholder')}
                                    style={searchInputStyle}
                                />
                                {query !== '' && (
                                    <button type="button" onClick={() => setQuery('')} style={clearButtonStyle}>
                                        {t('clear')}
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                <div style={sectionStyle}>
                    <div style={sectionHeaderStyle}>{t('view_list')}</div>
                    <div style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch' }}>
                        <table style={{
                            width: '100%',
                            borderCollapse: 'collapse',
                            fontSize: '0.9rem',
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
                                {filteredViews.length === 0 && (
                                    <tr>
                                        <td colSpan={4} style={{ ...tdStyle, textAlign: 'center', color: 'var(--muted-foreground)', padding: '28px 12px' }}>
                                            {t('no_views_found')}
                                        </td>
                                    </tr>
                                )}
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
                </div>
            </div>
        </DashboardLayout>
    );
}

const thStyle: React.CSSProperties = {
    padding: '10px 12px',
    textAlign: 'left',
    fontWeight: 600,
    fontSize: '0.85rem',
    color: 'var(--muted-foreground)',
    borderBottom: '2px solid var(--border)',
    whiteSpace: 'nowrap',
};

const tdStyle: React.CSSProperties = {
    padding: '8px 12px',
    borderBottom: '1px solid var(--border)',
    verticalAlign: 'top',
};

const sectionStyle: React.CSSProperties = {
    backgroundColor: 'var(--card)',
    border: '1px solid var(--border)',
    borderRadius: 4,
    overflow: 'hidden',
    marginBottom: 16,
};

const sectionHeaderStyle: React.CSSProperties = {
    padding: '10px 14px',
    borderBottom: '1px solid var(--border)',
    backgroundColor: 'var(--muted)',
    color: 'var(--muted-foreground)',
    fontSize: '0.9rem',
    fontWeight: 600,
};

const sectionBodyStyle: React.CSSProperties = {
    padding: 14,
};

const filterBarStyle: React.CSSProperties = {
    display: 'flex',
    gap: 8,
    alignItems: 'center',
    flexWrap: 'wrap',
};

const searchInputStyle: React.CSSProperties = {
    width: 320,
    maxWidth: '100%',
    padding: '8px 12px',
    border: '1px solid var(--input)',
    borderRadius: 4,
    fontSize: '0.9rem',
};

const clearButtonStyle: React.CSSProperties = {
    padding: '8px 14px',
    border: '1px solid var(--border)',
    borderRadius: 4,
    backgroundColor: 'var(--card)',
    color: 'var(--muted-foreground)',
    cursor: 'pointer',
    fontSize: '0.9rem',
};
