import React, { useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import type { ColumnDef, SortingState } from '@tanstack/react-table';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { DataTable } from '../../../components/data-table/DataTable';
import { useDataTableQuery } from '../../../components/data-table/useDataTableQuery';
import { Button } from '../../../components/ui/Button';
import { Input } from '../../../components/ui/Input';
import type { PaginationMeta } from '../../../components/ui/Pagination';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';

interface WikiRecord {
    c_personid: number;
    c_name_chn: string | null;
    c_dynasty_chn: string | null;
    c_index_year: number | null;
    c_index_addr_chn: string | null;
    c_textid: number;
    c_pages: string | null;
    link: string | null;
}
interface SourceInfo { id: number; name: string; count: number; icon: string; color: string }

// Tailwind 的 JIT 掃描器抓不到動態組出的 class 名稱（例如樣板字串插值），
// 必須以完整字面量列出才會被打包進最終 CSS。
const SOURCE_COLOR_CLASSES: Record<string, string> = {
    blue: 'bg-blue-500',
    green: 'bg-green-500',
    orange: 'bg-orange-500',
    purple: 'bg-purple-500',
    pink: 'bg-pink-500',
    cyan: 'bg-cyan-500',
    teal: 'bg-teal-500',
    indigo: 'bg-indigo-500',
};

interface WikiPageProps extends SharedProps {
    records: WikiRecord[];
    current_source_id: number;
    sources: SourceInfo[];
    pagination: PaginationMeta;
    filters: { search: string };
    sort: string;
    direction: 'asc' | 'desc';
    urls: { index: string };
}

export default function WikiMaintenanceIndex() {
    const props = usePage<WikiPageProps>().props;
    const { records, current_source_id, sources, pagination, filters, sort, direction, urls } = props;
    const t = useTranslation('admin');
    const tc = useTranslation('common');

    // 搜尋草稿：送出（Enter／按鈕）才觸發 reload；props.filters.search 是已生效值。
    const [search, setSearch] = useState(filters.search ?? '');

    const sorting: SortingState = useMemo(
        () => (sort ? [{ id: sort, desc: direction === 'desc' }] : []),
        [sort, direction]
    );

    // 換頁／排序／搜尋／切換來源都經由此 hook 同步進 URL query，連結可直接分享復現。
    const { visit, onPageChange, onSortingChange } = useDataTableQuery({
        params: {
            source_id: current_source_id,
            search: filters.search || undefined,
            sort: sort || undefined,
            direction: sort ? direction : undefined,
        },
        url: urls.index,
        sorting,
    });

    const goSource = (id: number) => visit({ source_id: id, page: 1 });
    const doSearch = (e: React.FormEvent) => {
        e.preventDefault();
        visit({ search: search.trim() || null, page: 1 });
    };
    const resetSearch = () => {
        setSearch('');
        visit({ search: null, page: 1 });
    };

    const columns = useMemo<ColumnDef<WikiRecord, unknown>[]>(() => [
        {
            accessorKey: 'c_personid',
            header: t('wiki_col_person_id'),
            enableSorting: true,
            cell: ({ row }) => (
                <a
                    className="text-primary hover:underline"
                    href={`/app/basicinformation/${row.original.c_personid}/sources/edit-v2`}
                    target="_blank"
                    rel="noreferrer"
                >
                    {row.original.c_personid}
                </a>
            ),
        },
        {
            accessorKey: 'c_name_chn',
            header: t('wiki_col_name_chn'),
            enableSorting: true,
            cell: ({ row }) => row.original.c_name_chn ?? '-',
        },
        {
            accessorKey: 'c_dynasty_chn',
            header: t('wiki_col_dynasty'),
            cell: ({ row }) => row.original.c_dynasty_chn ?? '-',
        },
        {
            accessorKey: 'c_index_year',
            header: t('wiki_col_index_year'),
            enableSorting: true,
            cell: ({ row }) => row.original.c_index_year ?? '-',
        },
        {
            accessorKey: 'c_index_addr_chn',
            header: t('wiki_col_index_addr'),
            cell: ({ row }) => row.original.c_index_addr_chn ?? '-',
        },
        { accessorKey: 'c_textid', header: t('wiki_col_text_id') },
        {
            accessorKey: 'c_pages',
            header: t('wiki_col_page'),
            enableSorting: true,
            cell: ({ row }) =>
                row.original.link ? (
                    <a className="text-primary hover:underline" href={row.original.link} target="_blank" rel="noreferrer">
                        {row.original.c_pages}
                    </a>
                ) : (
                    row.original.c_pages ?? '-'
                ),
        },
    ], [t]);

    return (
        <DashboardLayout title={t('wiki_page_title')} breadcrumbs={[{ label: t('wiki_page_title') }]}>
            <div className="space-y-5">
                {/* 統計來源選擇 */}
                <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                    {sources.map((s) => (
                        <button
                            key={s.id}
                            type="button"
                            onClick={() => goSource(s.id)}
                            className={`flex items-center gap-3 rounded-lg border p-3 text-left transition hover:shadow ${s.id === current_source_id ? 'border-sky-500 ring-2 ring-sky-200' : 'border-border'}`}
                        >
                            <span className={`flex h-12 w-12 items-center justify-center rounded text-white ${SOURCE_COLOR_CLASSES[s.color] ?? 'bg-gray-500'}`}>
                                <i className={s.icon} aria-hidden />
                            </span>
                            <span>
                                <span className="block text-sm text-muted-foreground">{s.name}</span>
                                <span className="block text-lg font-semibold">{s.count.toLocaleString()} {t('wiki_records_unit')}</span>
                            </span>
                        </button>
                    ))}
                </div>

                <p className="text-sm text-muted-foreground">{t('wiki_maintenance_desc')}</p>

                <DataTable
                    columns={columns}
                    data={records}
                    meta={pagination}
                    onPageChange={onPageChange}
                    sorting={sorting}
                    onSortingChange={onSortingChange}
                    getRowId={(r) => `${r.c_personid}-${r.c_textid}-${r.c_pages}`}
                    toolbar={
                        <form onSubmit={doSearch} className="flex items-center gap-1">
                            <Input
                                value={search}
                                placeholder={t('wiki_search_placeholder')}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-72"
                            />
                            <Button type="submit" variant="secondary" size="sm">{tc('search')}</Button>
                            {filters.search && (
                                <Button type="button" variant="secondary" size="sm" onClick={resetSearch}>{tc('reset')}</Button>
                            )}
                        </form>
                    }
                    labels={{
                        empty: t('wiki_no_records'),
                        loading: tc('loading'),
                        exportCsv: 'CSV',
                        print: tc('print'),
                        previous: tc('previous'),
                        next: tc('next'),
                        // 以 {from}/{to}/{total} 佔位符版的 wiki_showing 當摘要模板，保留本地化文案。
                        summaryTemplate: t('wiki_showing', { from: '{from}', to: '{to}', total: '{total}' }),
                    }}
                />
            </div>
        </DashboardLayout>
    );
}
