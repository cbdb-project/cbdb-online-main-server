import React, { useMemo, useState } from 'react';
import { usePage, router } from '@inertiajs/react';
import type { FormDataConvertible } from '@inertiajs/core';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { getCsrfToken } from '../../components/PersonBrowser/shared/csrf';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Pagination, type PaginationMeta } from '../../components/ui/Pagination';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';
import { cn } from '../../lib/utils';
import { OfficeUrls } from './OfficeForm';

type Row = Record<string, unknown>;
type Params = Record<string, FormDataConvertible>;

/**
 * 官職實體列表：與 app/codes/OFFICE_CODES 裸表頁 feature parity（全欄位、排序、逐欄／
 * 布林篩選、關鍵字搜尋、朝代標籤、全表匯出），另加聚合特有的 type_count 欄與
 * 走 mutation API 的編輯／刪除入口。排序／篩選 UI 字串沿用 codes 翻譯群組。
 */
interface Props extends SharedProps {
    thead: string[];
    rows: Row[];
    meta: PaginationMeta;
    q: string;
    dynasty_map: Record<string, string>;
    key_columns: string[];
    computed_columns: string[];
    filters: Record<string, string>;
    sort_by: string;
    sort_dir: 'asc' | 'desc';
    boolean_enabled: boolean;
    boolean_filter_available: boolean;
    filter_errors: Record<string, string>;
    filter_descriptions: Record<string, string>;
    can_write: boolean;
    urls: OfficeUrls;
}

export default function OfficeIndex() {
    const props = usePage<Props>().props;
    const {
        thead, rows, meta, dynasty_map, key_columns, computed_columns,
        sort_by, sort_dir, boolean_enabled, boolean_filter_available,
        filter_errors, filter_descriptions, can_write, urls,
    } = props;
    const t = useTranslation('office');
    const tCodes = useTranslation('codes');
    const tc = useTranslation('common');

    const [search, setSearch] = useState(props.q ?? '');
    const [filters, setFilters] = useState<Record<string, string>>(props.filters ?? {});
    const [busyId, setBusyId] = useState<number | null>(null);

    // 排序／篩選需已登入且已啟用的帳號（純 UX 提示；後端 guard 才是防線）。
    const canSortOrFilter = Boolean(props.auth?.user?.roles?.is_active);

    const editUrl = (id: number) => urls.edit_template.replace('__ID__', String(id));

    const reload = (params: Params) =>
        router.get(urls.index, params, { preserveState: true, preserveScroll: true, replace: true });

    /** 組合並導覽：保留 q/filters/sort/bool，merge 額外參數（page）。 */
    const visit = (extra: Params = {}, useFilters = filters) => {
        const params: Params = {};
        if (search) params.q = search;
        const applied = Object.fromEntries(Object.entries(useFilters).filter(([, v]) => v !== ''));
        if (Object.keys(applied).length) params.filters = applied;
        if (sort_by) {
            params.sort_by = sort_by;
            params.sort_dir = sort_dir;
        }
        if (boolean_enabled) params.filter_bool = 1;
        Object.assign(params, extra);
        reload(params);
    };

    const doSearch = (e: React.FormEvent) => {
        e.preventDefault();
        visit({ page: undefined });
    };
    const resetSearch = () => {
        setSearch('');
        const params: Params = {};
        if (boolean_enabled) params.filter_bool = 1;
        reload(params);
    };
    const applyFilters = () => {
        if (!canSortOrFilter) return;
        visit({ page: 1 });
    };
    const clearFilters = () => {
        setFilters({});
        const params: Params = {};
        if (search) params.q = search;
        if (boolean_enabled) params.filter_bool = 1;
        reload(params);
    };

    const toggleSort = (col: string) => {
        if (!canSortOrFilter) return;
        let nextBy = col;
        let nextDir: 'asc' | 'desc' | '' = 'asc';
        if (sort_by === col) {
            if (sort_dir === 'asc') {
                nextDir = 'desc';
            } else {
                nextBy = '';
                nextDir = '';
            }
        }
        visit({ sort_by: nextBy || undefined, sort_dir: nextDir || undefined, page: 1 });
    };

    const sortIcon = (col: string) => (sort_by !== col ? '⇅' : sort_dir === 'asc' ? '▲' : '▼');

    const toggleBoolean = () => {
        const params: Params = {};
        if (search) params.q = search;
        const applied = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));
        if (Object.keys(applied).length) params.filters = applied;
        if (sort_by) {
            params.sort_by = sort_by;
            params.sort_dir = sort_dir;
        }
        if (!boolean_enabled) params.filter_bool = 1;
        reload(params);
    };

    const cellValue = (row: Row, col: string): string => {
        let v = row[col];
        if (col === 'c_dy' && v !== '' && v != null) {
            const key = String(v);
            if (dynasty_map[key]) v = `${v} - ${dynasty_map[key]}`;
        }
        return v == null ? '' : String(v);
    };

    const colSpan = thead.length + 1;

    const filterErrMsg = (code: string) => {
        const key = `filter_err_${code}`;
        const msg = tCodes(key);
        return msg === key ? tCodes('filter_err_unknown') : msg;
    };

    const booleanExamples = useMemo(() => {
        const ex = (props.page_translations?.codes as Record<string, unknown> | undefined)?.filter_chip_examples;
        return Array.isArray(ex) ? (ex as string[]) : [];
    }, [props.page_translations]);

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
            router.reload({ only: ['rows', 'meta'] });
        } catch (e) {
            alert(String(e));
        }
        setBusyId(null);
    };

    return (
        <DashboardLayout title={t('page_title_index')}>
            <p className="mb-3 text-sm text-muted-foreground">{t('intro')}</p>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <form onSubmit={doSearch} className="flex items-center gap-1">
                    <Input
                        value={search}
                        placeholder={t('search_placeholder')}
                        onChange={(e) => setSearch(e.target.value)}
                        className="w-72"
                    />
                    <Button type="submit" variant="secondary" size="sm">{tc('search')}</Button>
                    {props.q && (
                        <Button type="button" variant="secondary" size="sm" onClick={resetSearch}>{tc('reset')}</Button>
                    )}
                </form>
                <Button
                    type="button"
                    size="sm"
                    onClick={applyFilters}
                    disabled={!canSortOrFilter}
                    title={canSortOrFilter ? undefined : tCodes('sort_filter_requires_login')}
                >
                    {tCodes('apply_filters')}
                </Button>
                {(Object.keys(props.filters).length > 0 || sort_by) && (
                    <Button type="button" size="sm" variant="secondary" onClick={clearFilters}>{tCodes('clear_filters')}</Button>
                )}
                {!canSortOrFilter && (
                    <span className="text-xs text-muted-foreground">{tCodes('sort_filter_requires_login')}</span>
                )}
                {can_write && (
                    <a href={urls.create} className="inline-flex items-center rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90">
                        {t('btn_create')}
                    </a>
                )}
                <a href={urls.export} download className="inline-flex items-center gap-1 rounded-md border border-input px-3 py-1.5 text-sm hover:bg-muted">
                    <i className="fas fa-download" aria-hidden /> {tCodes('download_full_table')}
                </a>
                {boolean_filter_available && (
                    <div className="ml-auto flex items-center gap-2">
                        {boolean_enabled ? (
                            <>
                                <span className="rounded bg-primary px-2 py-0.5 text-xs font-semibold text-primary-foreground">{tCodes('advanced_filter_on')}</span>
                                <Button type="button" size="sm" variant="outline" onClick={toggleBoolean}>{tCodes('advanced_filter_disable')}</Button>
                            </>
                        ) : (
                            <Button type="button" size="sm" variant="outline" onClick={toggleBoolean}>{tCodes('advanced_filter')}</Button>
                        )}
                    </div>
                )}
            </div>

            {boolean_enabled && (
                <>
                    <div className="mb-2 text-xs text-muted-foreground">{tCodes('advanced_filter_hint')}</div>
                    {booleanExamples.length > 0 && (
                        <div className="mb-2 hidden text-xs text-muted-foreground md:block">
                            {tCodes('filter_chip_label')}{' '}
                            {booleanExamples.map((chip, i) => (
                                <code key={i} className="mr-1 rounded border px-1">{chip}</code>
                            ))}
                        </div>
                    )}
                    {Object.keys(filter_errors).length > 0 && (
                        <div className="mb-2 rounded border border-yellow-300 bg-yellow-50 px-3 py-2 text-sm text-yellow-800" role="alert">
                            {tCodes('filter_errors_heading', { count: String(Object.keys(filter_errors).length) })}
                            <ul className="mt-1 list-disc pl-5">
                                {Object.entries(filter_errors).map(([col, code]) => (
                                    <li key={col}><code>{col}</code>：{filterErrMsg(code)}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {Object.keys(filter_descriptions).length > 0 && (
                        <div className="mb-2 text-xs text-muted-foreground">
                            {tCodes('filter_applied_label')}：
                            {Object.entries(filter_descriptions).map(([col, desc]) => (
                                <span key={col} className="ml-1 rounded border px-1"><code>{col}</code>：{desc}</span>
                            ))}
                        </div>
                    )}
                </>
            )}

            <div className="overflow-x-auto rounded-md border border-border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50">
                        <tr>
                            {thead.map((col) => (
                                <th key={col} className="whitespace-nowrap px-3 py-2 text-left font-medium">
                                    <button
                                        type="button"
                                        className={cn('hover:underline', !canSortOrFilter && 'cursor-not-allowed opacity-60 hover:no-underline')}
                                        onClick={() => toggleSort(col)}
                                        title={canSortOrFilter ? undefined : tCodes('sort_filter_requires_login')}
                                    >
                                        {computed_columns.includes(col) ? `(${col})` : col} <span aria-hidden>{sortIcon(col)}</span>
                                    </button>
                                    {key_columns.includes(col) && (
                                        <span className="ml-1 rounded bg-blue-100 px-1 text-xs text-blue-800">PK</span>
                                    )}
                                </th>
                            ))}
                            <th className="px-3 py-2 text-left font-medium">{t('col_actions')}</th>
                        </tr>
                        <tr>
                            {thead.map((col) => {
                                const hasErr = boolean_enabled && col in filter_errors;
                                return (
                                    <th key={col} className="px-2 py-1">
                                        <Input
                                            value={filters[col] ?? ''}
                                            placeholder={col}
                                            aria-label={col}
                                            aria-invalid={hasErr || undefined}
                                            onChange={(e) => setFilters((f) => ({ ...f, [col]: e.target.value }))}
                                            onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                                            className={cn('h-7 text-xs', hasErr && 'border-destructive')}
                                        />
                                    </th>
                                );
                            })}
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td colSpan={colSpan} className="px-3 py-6 text-center text-muted-foreground">{t('empty_list')}</td>
                            </tr>
                        ) : (
                            rows.map((row) => {
                                const id = Number(row.c_office_id);
                                return (
                                    <tr key={id} className="border-t border-border hover:bg-muted/30">
                                        {thead.map((col) => (
                                            <td key={col} className="px-3 py-1.5">{cellValue(row, col)}</td>
                                        ))}
                                        <td className="px-3 py-1.5">
                                            {can_write && (
                                                <div className="flex gap-2 whitespace-nowrap">
                                                    <a href={editUrl(id)} className="text-primary hover:underline">
                                                        {t('btn_edit')}
                                                    </a>
                                                    <button
                                                        type="button"
                                                        className="text-red-600 hover:underline disabled:opacity-50"
                                                        disabled={busyId === id}
                                                        onClick={() => del(id)}
                                                    >
                                                        {t('btn_delete')}
                                                    </button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            <div className="mt-3">
                <Pagination
                    meta={meta}
                    onPageChange={(page) => visit({ page })}
                    summaryTemplate="{from}–{to} / {total}"
                    labels={{ previous: tc('previous'), next: tc('next') }}
                />
            </div>
        </DashboardLayout>
    );
}
