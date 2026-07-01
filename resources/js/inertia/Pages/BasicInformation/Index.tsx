import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Select } from '../../components/ui/Select';
import { Pagination, type PaginationMeta } from '../../components/ui/Pagination';
import { useTranslation } from '../../hooks/useTranslation';
import { formatBilingualLabel } from '../../components/PersonBrowser/shared/formatters';
import type { SharedProps } from '../../types/page';

interface PersonRow {
    c_personid: number;
    c_name_chn: string | null;
    c_name: string | null;
    c_dynasty_chn: string;
    c_dynasty?: string | null;
    c_index_year: number | string | null;
    addr_name_chn: string;
    addr_name?: string | null;
    zi: string;
    hao: string;
}

interface DynastyFacet {
    c_dy: number | string;
    c_dynasty_chn: string;
    c_dynasty?: string | null;
    count: number;
}

interface PersonIndexPageProps extends SharedProps {
    names: { data: PersonRow[]; meta: PaginationMeta };
    q: string;
    c_dy: string;
    dynasty_facets: DynastyFacet[];
    can_add: boolean;
    edit_template: string;
    create_url: string;
}

// 部分欄位以「中文 / 英文（拼音）」雙語呈現（對齊全站 formatBilingualLabel 慣例）；
// 其餘欄位沿用 row[key] 直接顯示。
const COLUMNS: { key: keyof PersonRow; label: string; render?: (row: PersonRow) => React.ReactNode }[] = [
    { key: 'c_personid', label: 'c_personid' },
    { key: 'c_name_chn', label: 'c_name_chn' },
    { key: 'c_name', label: 'c_name' },
    { key: 'c_dynasty_chn', label: 'dynasty', render: (row) => formatBilingualLabel(row.c_dynasty_chn, row.c_dynasty ?? null) ?? '' },
    { key: 'c_index_year', label: 'index year' },
    { key: 'addr_name_chn', label: 'index address', render: (row) => formatBilingualLabel(row.addr_name_chn, row.addr_name ?? null) ?? '' },
    { key: 'zi', label: 'zi' },
    { key: 'hao', label: 'hao' },
];

export default function PersonIndex() {
    const props = usePage<PersonIndexPageProps>().props;
    const { names, dynasty_facets, can_add, edit_template, create_url } = props;
    const t = useTranslation('biogmains');
    const tc = useTranslation('common');
    const tPerson = useTranslation('person');

    const [q, setQ] = useState(props.q ?? '');
    const path = typeof window !== 'undefined' ? window.location.pathname : '/app/basicinformation';

    const reload = (params: Record<string, string | number | undefined>) =>
        router.get(path, params, { preserveState: true, preserveScroll: true, replace: true });

    const search = (e: React.FormEvent) => {
        e.preventDefault();
        reload({ q: q || undefined, c_dy: props.c_dy || undefined });
    };
    const changeDynasty = (c_dy: string) => reload({ q: q || undefined, c_dy: c_dy || undefined });

    const totalFacetCount = dynasty_facets.reduce((acc, f) => acc + f.count, 0);
    const editUrl = (id: number) => edit_template.replace('__ID__', String(id));

    return (
        <DashboardLayout title={tPerson('person_records')}>
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <form onSubmit={search} className="flex flex-1 items-center gap-1">
                    <Input value={q} placeholder={t('search_placeholder')} onChange={(e) => setQ(e.target.value)} className="max-w-md" />
                    {dynasty_facets.length > 0 && (
                        <Select value={props.c_dy} onChange={(e) => changeDynasty(e.target.value)} className="max-w-48">
                            <option value="">
                                {t('all_dynasties_opt')} ({totalFacetCount})
                            </option>
                            {dynasty_facets.map((f) => (
                                <option key={String(f.c_dy)} value={String(f.c_dy)}>
                                    {formatBilingualLabel(f.c_dynasty_chn, f.c_dynasty ?? null) ?? f.c_dynasty_chn} ({f.count})
                                </option>
                            ))}
                        </Select>
                    )}
                    <Button type="submit" variant="secondary" size="sm">
                        <i className="fas fa-search" aria-hidden />
                    </Button>
                </form>
                {can_add && (
                    <a href={create_url} className="inline-flex items-center rounded-md border border-input px-3 py-1.5 text-sm hover:bg-muted">
                        {tc('add')}
                    </a>
                )}
            </div>

            <div className="overflow-x-auto rounded-md border border-border">
                <table className="w-full text-sm">
                    <caption className="px-3 py-2 text-left text-xs text-muted-foreground">
                        {t('total_records', { count: String(names.meta.total) })}
                    </caption>
                    <thead className="bg-muted/50">
                        <tr>
                            {COLUMNS.map((c) => (
                                <th key={c.label} className="px-3 py-2 text-left font-medium">{c.label}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {names.data.length === 0 ? (
                            <tr>
                                <td colSpan={COLUMNS.length} className="px-3 py-6 text-center text-muted-foreground">
                                    {t('no_data_row')}
                                </td>
                            </tr>
                        ) : (
                            names.data.map((row) => (
                                <tr key={row.c_personid} className="border-t border-border hover:bg-muted/30">
                                    {COLUMNS.map((c) => (
                                        <td key={c.label} className="px-3 py-1.5">
                                            <a href={editUrl(row.c_personid)} target="_blank" rel="noreferrer" className="text-primary hover:underline">
                                                {c.render ? c.render(row) : (row[c.key] ?? '')}
                                            </a>
                                        </td>
                                    ))}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <div className="mt-3 flex justify-end">
                <Pagination
                    meta={names.meta}
                    onPageChange={(page) => reload({ q: q || undefined, c_dy: props.c_dy || undefined, page })}
                    summaryTemplate="{from}–{to} / {total}"
                    labels={{ previous: tc('previous'), next: tc('next') }}
                />
            </div>
        </DashboardLayout>
    );
}
