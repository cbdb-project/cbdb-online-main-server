import React, { useState } from 'react';
import { usePage, router } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { getCsrfToken } from '../../components/PersonBrowser/shared/csrf';
import { Button } from '../../components/ui/Button';
import { useTranslation } from '../../hooks/useTranslation';
import { OfficeUrls } from './OfficeForm';

interface Row {
    office_id: number;
    name: string;
    pinyin: string;
    translation: string | null;
    dynasty_code: number | null;
    dynasty_label: string | null;
    type_count: number;
    source_id: number | null;
}

interface Pagination {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

interface Props {
    rows: Row[];
    q: string;
    pagination: Pagination;
    can_write: boolean;
    urls: OfficeUrls;
    [key: string]: unknown;
}

export default function OfficeIndex() {
    const { rows, q, pagination, can_write, urls } = usePage<Props>().props;
    const t = useTranslation('office');
    const [search, setSearch] = useState(q);
    const [busyId, setBusyId] = useState<number | null>(null);

    const editUrl = (id: number) => urls.edit_template.replace('__ID__', String(id));

    const doSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(urls.index, { q: search }, { preserveState: true, preserveScroll: true });
    };
    const gotoPage = (p: number) => router.get(urls.index, { q, page: p }, { preserveState: true, preserveScroll: true });

    const del = async (id: number) => {
        if (!window.confirm(t('delete_confirm'))) return;
        setBusyId(id);
        try {
            const res = await fetch(urls.api_delete, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'office', person_id: 0, target: { pk: { c_office_id: id } } }),
            });
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                alert(res.status === 409 ? t('delete_blocked') : (json?.message ?? t('save_failed')));
                setBusyId(null);
                return;
            }
            router.reload({ only: ['rows', 'pagination'] });
        } catch (e) {
            alert(String(e));
        }
        setBusyId(null);
    };

    return (
        <DashboardLayout title={t('page_title_index')}>
            <div className="rounded-lg border border-border bg-card p-4">
                <p className="mb-3 text-sm text-muted-foreground">{t('intro')}</p>

                <div className="mb-3 flex flex-wrap items-center gap-2">
                    <form onSubmit={doSearch} className="flex flex-1 gap-2">
                        <input
                            className="w-full max-w-md rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            placeholder={t('search_placeholder')}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <Button type="submit" variant="outline">
                            {t('search')}
                        </Button>
                    </form>
                    {can_write && (
                        <a
                            href={urls.create}
                            className="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                        >
                            {t('btn_create')}
                        </a>
                    )}
                </div>

                <p className="mb-2 text-xs text-muted-foreground">{t('total_count', { n: String(pagination.total) })}</p>

                <div className="overflow-x-auto rounded-md border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                <th className="px-3 py-2 text-left font-medium">{t('col_id')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('col_name')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('col_pinyin')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('col_dynasty')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('col_types')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('col_actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                                        {t('empty_list')}
                                    </td>
                                </tr>
                            ) : (
                                rows.map((r) => (
                                    <tr key={r.office_id} className="border-t border-border">
                                        <td className="px-3 py-1.5">{r.office_id}</td>
                                        <td className="px-3 py-1.5">{r.name}</td>
                                        <td className="px-3 py-1.5">{r.pinyin}</td>
                                        <td className="px-3 py-1.5">{r.dynasty_label ?? r.dynasty_code ?? ''}</td>
                                        <td className="px-3 py-1.5">{r.type_count}</td>
                                        <td className="px-3 py-1.5">
                                            <div className="flex gap-2">
                                                <a href={editUrl(r.office_id)} className="text-primary hover:underline">
                                                    {t('btn_edit')}
                                                </a>
                                                {can_write && (
                                                    <button
                                                        type="button"
                                                        className="text-red-600 hover:underline disabled:opacity-50"
                                                        disabled={busyId === r.office_id}
                                                        onClick={() => del(r.office_id)}
                                                    >
                                                        {t('btn_delete')}
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {pagination.last_page > 1 && (
                    <div className="mt-3 flex items-center justify-center gap-2 text-sm">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={pagination.current_page <= 1}
                            onClick={() => gotoPage(pagination.current_page - 1)}
                        >
                            ‹
                        </Button>
                        <span className="text-muted-foreground">
                            {pagination.current_page} / {pagination.last_page}
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={pagination.current_page >= pagination.last_page}
                            onClick={() => gotoPage(pagination.current_page + 1)}
                        >
                            ›
                        </Button>
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
}
