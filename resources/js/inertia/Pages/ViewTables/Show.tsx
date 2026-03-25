import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import DataTable from '../../components/DataTable';
import SearchToolbar from '../../components/SearchToolbar';
import SqlDebugPanel from '../../components/SqlDebugPanel';
import PaginationControls, { PaginationData } from '../../components/PaginationControls';

interface Props {
    title: string;
    description: string | null;
    columns: Record<string, string>;
    rows: Record<string, unknown>[];
    key: string;
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
}

export default function Show({
    title,
    description,
    columns,
    rows,
    filters,
    pagination,
    debug,
    pageUrl,
    listUrl,
}: Props) {
    const [search, setSearch] = useState(filters.search || '');

    const navigate = (params: Record<string, string | number | undefined>) => {
        const query: Record<string, string> = {};
        if (params.search) {
            query.search = String(params.search);
        }
        if (params.page && Number(params.page) > 1) {
            query.page = String(params.page);
        }

        const qs = new URLSearchParams(query).toString();
        router.get(pageUrl + (qs ? '?' + qs : ''), {}, { preserveState: false });
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

    // Adapt rows into pagination-compatible structure for PaginationControls
    const paginationData: PaginationData<unknown> = {
        data: rows,
        current_page: pagination.current_page,
        last_page: pagination.last_page,
        per_page: pagination.per_page,
        total: pagination.total,
        from: pagination.from,
        to: pagination.to,
    };

    return (
        <AppShell>
            <div style={{ padding: '24px 24px 48px' }}>
                {/* Breadcrumb */}
                <div style={{ marginBottom: 16 }}>
                    <a
                        href={listUrl}
                        style={{ color: '#007bff', textDecoration: 'none', fontSize: '0.85rem' }}
                    >
                        ← 檢視表總覽
                    </a>
                </div>

                {/* Header */}
                <div style={{ marginBottom: 16 }}>
                    <h2 style={{ fontSize: '1.3rem', fontWeight: 600, margin: 0, color: '#212529' }}>
                        {title}
                    </h2>
                    {description && (
                        <p style={{ color: '#6c757d', fontSize: '0.9rem', marginTop: 6 }}>
                            {description}
                        </p>
                    )}
                </div>

                {/* Content card */}
                <div style={{
                    backgroundColor: '#fff',
                    border: '1px solid #dee2e6',
                    borderRadius: 6,
                    padding: 16,
                }}>
                    {/* Search */}
                    <SearchToolbar
                        search={search}
                        onSearchChange={setSearch}
                        onSubmit={handleSearch}
                        onClear={handleClear}
                        placeholder="Search..."
                    />

                    {/* Results info */}
                    {pagination.total > 0 && (
                        <div style={{ fontSize: '0.8rem', color: '#6c757d', marginBottom: 8 }}>
                            共 {pagination.total} 筆，顯示第 {pagination.from}–{pagination.to} 筆
                        </div>
                    )}

                    {/* Table */}
                    <DataTable columns={columns} rows={rows} />

                    {/* Pagination */}
                    <PaginationControls
                        pagination={paginationData}
                        onPageChange={handlePageChange}
                    />

                    {/* SQL debug panel */}
                    <SqlDebugPanel
                        sql={debug.sql}
                        renderedSql={debug.rendered_sql}
                        bindings={debug.bindings}
                        perPage={debug.per_page}
                        currentPage={debug.current_page}
                    />
                </div>
            </div>
        </AppShell>
    );
}
