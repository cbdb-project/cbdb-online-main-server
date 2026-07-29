import React, { useMemo } from 'react';
import { router, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { DataTable } from '../../../components/data-table/DataTable';
import type { PaginationMeta } from '../../../components/ui/Pagination';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';

interface WikiRecord {
    c_personid: number;
    c_name_chn: string | null;
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
    urls: { index: string };
}

export default function WikiMaintenanceIndex() {
    const props = usePage<WikiPageProps>().props;
    const { records, current_source_id, sources, pagination, urls } = props;
    const t = useTranslation('admin');
    const tc = useTranslation('common');

    const goSource = (id: number) => router.get(urls.index, { source_id: id }, { preserveScroll: true });
    const goPage = (page: number) => router.get(urls.index, { source_id: current_source_id, page }, { preserveScroll: true });

    const columns = useMemo<ColumnDef<WikiRecord, unknown>[]>(() => [
        {
            accessorKey: 'c_personid',
            header: t('wiki_col_person_id'),
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
            cell: ({ row }) => row.original.c_name_chn ?? '-',
        },
        { accessorKey: 'c_textid', header: t('wiki_col_text_id') },
        {
            accessorKey: 'c_pages',
            header: t('wiki_col_page'),
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
                    onPageChange={goPage}
                    getRowId={(r) => `${r.c_personid}-${r.c_textid}-${r.c_pages}`}
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
