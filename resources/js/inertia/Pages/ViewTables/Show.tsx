import React, { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { useTranslation } from '../../hooks/useTranslation';
import DataTable from '../../components/DataTable';
import SearchToolbar from '../../components/SearchToolbar';
import SqlDebugPanel from '../../components/SqlDebugPanel';
import PaginationControls, { PaginationData } from '../../components/PaginationControls';

interface ViewOption {
    key: string;
    title: string;
    primary_alias: string;
    app_url: string;
}

interface Props {
    title: string;
    description: string | null;
    columns: Record<string, string>;
    rows: Record<string, unknown>[];
    key: string;
    primary_alias: string;
    aliases: string[];
    column_count: number;
    filters: {
        search: string;
    };
    pagination: PaginationData<unknown>;
    debug: {
        sql: string;
        rendered_sql: string;
        bindings: unknown[];
        per_page: number;
        current_page: number;
    };
    pageUrl: string;
    listUrl: string;
    availableViews: ViewOption[];
}

export default function Show({
    title,
    description,
    columns,
    rows,
    primary_alias,
    aliases,
    column_count,
    filters,
    pagination,
    debug,
    pageUrl,
    listUrl,
    availableViews,
}: Props) {
    // `key` 是 React 保留 prop，會被 React 從函式組件 props 中剝除（故先前顯示 undefined）；
    // 改從 Inertia 原始 page props 取得。
    const { key } = usePage<Props>().props;
    const t = useTranslation('views');
    const [search, setSearch] = useState(filters.search || '');

    useEffect(() => {
        setSearch(filters.search || '');
    }, [filters.search]);

    const navigate = (params: Record<string, string | number | undefined>) => {
        router.get(buildUrl(pageUrl, params), {}, { preserveScroll: true });
    };

    const alternateAliases = aliases.slice(1);
    const searchSummary = filters.search ? `「${filters.search}」` : t('filter_applied');
    const resultsLabel = pagination.total > 0
        ? t('result_range', { from: String(pagination.from), to: String(pagination.to) })
        : t('no_data_in_view');

    const paginationData: PaginationData<unknown> = {
        data: rows,
        current_page: pagination.current_page,
        last_page: pagination.last_page,
        per_page: pagination.per_page,
        total: pagination.total,
        from: pagination.from,
        to: pagination.to,
    };

    const summaryCards = [
        {
            label: t('current_view_label'),
            value: primary_alias,
            help: `key: ${key}`,
        },
        {
            label: t('column_count'),
            value: String(column_count),
            help: t('column_count_help'),
        },
        {
            label: t('result_count_label'),
            value: String(pagination.total),
            help: resultsLabel,
        },
        {
            label: t('search_condition_label'),
            value: searchSummary,
            help: t('view_pagination', { current: String(pagination.current_page), total: String(pagination.last_page || 1) }),
        },
    ];

    const handleViewChange = (nextUrl: string) => {
        if (nextUrl === '') {
            return;
        }

        router.get(nextUrl);
    };

    const handleSearch = () => {
        navigate({ search: search.trim(), page: 1 });
    };

    const handleClear = () => {
        setSearch('');
        navigate({});
    };

    const handlePageChange = (page: number) => {
        navigate({ search: filters.search, page });
    };

    return (
        <DashboardLayout disableContentPadding>
            <div style={{ padding: '24px 24px 48px' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
                    <Link href={listUrl} style={{ color: '#007bff', textDecoration: 'none', fontSize: '0.85rem', fontWeight: 500 }}>
                        {t('back_to_overview')}
                    </Link>

                    <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                        <label style={{ fontSize: '0.82rem', color: '#6c757d' }}>
                            {t('quick_switch')}
                        </label>
                        <select
                            value={pageUrl}
                            onChange={(event) => handleViewChange(event.target.value)}
                            style={selectStyle}
                        >
                            {availableViews.map((view) => (
                                <option key={view.key} value={view.app_url}>
                                    {view.primary_alias} / {view.title}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <div style={sectionStyle}>
                    <div style={sectionHeaderStyle}>{t('view_info')}</div>
                    <div style={sectionBodyStyle}>
                        <h2 style={{ margin: 0, fontSize: '1.55rem', fontWeight: 700, color: '#212529' }}>
                            {title}
                        </h2>
                        <div style={{ marginTop: 8, color: '#6c757d', fontSize: '0.85rem', lineHeight: 1.7 }}>
                            <div>{t('view_name_label')}<code>{primary_alias}</code></div>
                            <div>key：<code>{key}</code></div>
                            {alternateAliases.length > 0 && (
                                <div>{t('alias_label')}{alternateAliases.map((alias) => <code key={alias} style={{ marginRight: 8 }}>{alias}</code>)}</div>
                            )}
                        </div>
                        {description && (
                            <div style={{ marginTop: 10, color: '#495057', fontSize: '0.9rem' }}>
                                {description}
                            </div>
                        )}
                    </div>
                </div>

                <div style={sectionStyle}>
                    <div style={sectionHeaderStyle}>{t('current_status')}</div>
                    <div style={summaryGridStyle}>
                        {summaryCards.map((card) => (
                            <div key={card.label} style={summaryItemStyle}>
                                <div style={summaryLabelStyle}>{card.label}</div>
                                <div style={summaryValueStyle}>{card.value}</div>
                                <div style={summaryHelpStyle}>{card.help}</div>
                            </div>
                        ))}
                    </div>
                </div>

                <div style={sectionStyle}>
                    <div style={sectionHeaderStyle}>{t('view_content')}</div>
                    <div style={sectionBodyStyle}>
                        <SearchToolbar
                            search={search}
                            onSearchChange={setSearch}
                            onSubmit={handleSearch}
                            onClear={handleClear}
                            placeholder={t('search_in_view')}
                        />

                        {pagination.total > 0 && (
                            <div style={{ fontSize: '0.8rem', color: '#6c757d', marginBottom: 8 }}>
                                {t('total_records', { total: String(pagination.total), from: String(pagination.from), to: String(pagination.to) })}
                            </div>
                        )}

                        <DataTable columns={columns} rows={rows} />

                        <PaginationControls
                            pagination={paginationData}
                            onPageChange={handlePageChange}
                        />

                        <div style={{ marginTop: 16 }}>
                            <SqlDebugPanel
                                sql={debug.sql}
                                renderedSql={debug.rendered_sql}
                                bindings={debug.bindings}
                                perPage={debug.per_page}
                                currentPage={debug.current_page}
                            />
                        </div>
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}

function buildUrl(baseUrl: string, params: Record<string, string | number | undefined>) {
    const query = new URLSearchParams();

    Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') {
            return;
        }

        query.set(key, String(value));
    });

    const qs = query.toString();

    return baseUrl + (qs ? `?${qs}` : '');
}

const sectionStyle: React.CSSProperties = {
    backgroundColor: '#fff',
    border: '1px solid #dee2e6',
    borderRadius: 4,
    overflow: 'hidden',
    marginBottom: 16,
};

const sectionHeaderStyle: React.CSSProperties = {
    padding: '10px 14px',
    borderBottom: '1px solid #dee2e6',
    backgroundColor: '#f8f9fa',
    color: '#495057',
    fontSize: '0.9rem',
    fontWeight: 600,
};

const sectionBodyStyle: React.CSSProperties = {
    padding: 14,
};

const summaryGridStyle: React.CSSProperties = {
    display: 'flex',
    gap: 12,
    flexWrap: 'wrap',
    padding: 14,
};

const summaryItemStyle: React.CSSProperties = {
    flex: '1 1 180px',
    minWidth: 180,
    backgroundColor: '#f8f9fa',
    border: '1px solid #e9ecef',
    borderRadius: 4,
    padding: '14px 16px',
};

const summaryLabelStyle: React.CSSProperties = {
    fontSize: '0.78rem',
    color: '#6c757d',
    marginBottom: 6,
};

const summaryValueStyle: React.CSSProperties = {
    fontSize: '1.1rem',
    fontWeight: 600,
    color: '#212529',
    lineHeight: 1.4,
    wordBreak: 'break-word',
};

const summaryHelpStyle: React.CSSProperties = {
    marginTop: 6,
    fontSize: '0.76rem',
    color: '#6c757d',
};

const selectStyle: React.CSSProperties = {
    minWidth: 240,
    maxWidth: '100%',
    padding: '8px 12px',
    border: '1px solid #ced4da',
    borderRadius: 4,
    fontSize: '0.9rem',
    backgroundColor: '#fff',
};
