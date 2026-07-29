import React from 'react';
import { router, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Button } from '../../../components/ui/Button';
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
    pagination: { page: number; per_page: number; total: number; has_next: boolean; has_prev: boolean };
    urls: { index: string };
}

export default function WikiMaintenanceIndex() {
    const props = usePage<WikiPageProps>().props;
    const { records, current_source_id, sources, pagination, urls } = props;
    const t = useTranslation('admin');

    const goSource = (id: number) => router.get(urls.index, { source_id: id }, { preserveScroll: true });
    const goPage = (page: number) => router.get(urls.index, { source_id: current_source_id, page }, { preserveScroll: true });

    const { page, per_page, total, has_next, has_prev } = pagination;
    const lastPage = Math.max(1, Math.ceil(total / per_page));
    const startPage = Math.max(1, page - 2);
    const endPage = Math.min(lastPage, page + 2);
    const pages: number[] = [];
    for (let i = startPage; i <= endPage; i++) pages.push(i);

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

                {/* 記錄列表 */}
                <div className="overflow-x-auto rounded-md border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                <th className="px-3 py-2 text-left font-medium">{t('wiki_col_person_id')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('wiki_col_name_chn')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('wiki_col_text_id')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('wiki_col_page')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {records.length === 0 && (
                                <tr><td colSpan={4} className="px-3 py-6 text-center text-muted-foreground">{t('wiki_no_records')}</td></tr>
                            )}
                            {records.map((r) => (
                                <tr key={`${r.c_personid}-${r.c_textid}-${r.c_pages}`} className="border-t border-border">
                                    <td className="px-3 py-1.5">
                                        <a className="text-primary hover:underline" href={`/app/basicinformation/${r.c_personid}/sources/edit-v2`} target="_blank" rel="noreferrer">{r.c_personid}</a>
                                    </td>
                                    <td className="px-3 py-1.5">{r.c_name_chn ?? '-'}</td>
                                    <td className="px-3 py-1.5">{r.c_textid}</td>
                                    <td className="px-3 py-1.5">
                                        {r.link ? <a className="text-primary hover:underline" href={r.link} target="_blank" rel="noreferrer">{r.c_pages}</a> : (r.c_pages ?? '-')}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* 分頁 */}
                {total > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="text-sm text-muted-foreground">
                            {t('wiki_showing', { from: String((page - 1) * per_page + 1), to: String(Math.min(page * per_page, total)), total: total.toLocaleString() })}
                        </p>
                        <div className="flex items-center gap-1">
                            <Button size="sm" variant="outline" disabled={!has_prev} onClick={() => goPage(page - 1)}>«</Button>
                            {pages.map((i) => (
                                <Button key={i} size="sm" variant={i === page ? 'default' : 'outline'} onClick={() => goPage(i)}>{i}</Button>
                            ))}
                            <Button size="sm" variant="outline" disabled={!has_next} onClick={() => goPage(page + 1)}>»</Button>
                        </div>
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
}
