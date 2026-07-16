import React, { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import type { FormDataConvertible } from '@inertiajs/core';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Pagination, type PaginationMeta } from '../../components/ui/Pagination';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';
import { cn } from '../../lib/utils';

type Row = Record<string, unknown>;
type Params = Record<string, FormDataConvertible>;

// 安全開關：停用碼表刪除的前端入口。多數碼表（DYNASTIES/GANZHI_CODES/TEXT_CODES/
// OFFICE_CODES/SOCIAL_INSTITUTION_* 等）被人物資料以 ON DELETE CASCADE 外鍵引用，刪一列
// 可能連帶刪除數萬筆人物列且無法乾淨復原。刪除功能尚無引用護欄前一律隱藏入口。
// 注意：此為純前端隱藏，後端刪除路由仍存在，之後須另補後端護欄。
const RISKY_DELETE_DISABLED = true;

interface CursorData {
    rows: Row[];
    first_id: number | string | null;
    last_id: number | string | null;
    has_more_pages: boolean;
    has_prev_pages: boolean;
    next_cursor: number | string | null;
    prev_cursor: number | string | null;
}

interface CodesShowPageProps extends SharedProps {
    table: string;
    thead: string[];
    rows: Row[];
    cursor: CursorData | null;
    meta: PaginationMeta | null;
    use_cursor: boolean;
    search: string;
    dynasty_map: Record<string, string>;
    is_read_only: boolean;
    exportable: boolean;
    key_columns: string[];
    joined_columns: string[];
    computed_columns: string[];
    copyright_note: string | null;
    filters: Record<string, string>;
    sort_by: string;
    sort_dir: 'asc' | 'desc';
    boolean_enabled: boolean;
    boolean_filter_available: boolean;
    filter_errors: Record<string, string>;
    filter_descriptions: Record<string, string>;
    can_edit: boolean;
    urls: {
        index: string;
        create: string;
        export: string;
        edit_template: string;
        destroy_template: string;
    };
}

const nf = new Intl.NumberFormat();

export default function CodesShow() {
    const props = usePage<CodesShowPageProps>().props;
    const t = useTranslation('codes');
    const tc = useTranslation('common');

    const {
        table, thead, rows, cursor, meta, use_cursor, dynasty_map, key_columns, joined_columns,
        computed_columns, copyright_note, sort_by, sort_dir, boolean_enabled, boolean_filter_available,
        filter_errors, filter_descriptions, can_edit, urls,
    } = props;

    const [search, setSearch] = useState(props.search ?? '');
    const [filters, setFilters] = useState<Record<string, string>>(props.filters ?? {});
    const [deleteId, setDeleteId] = useState<string | null>(null);

    // 排序／篩選需已登入且已啟用的帳號（見 docs/CODES_SORT_FILTER_AUTH_GATE.md）；
    // 純 UX 提示，非防線——後端 guardSortFilterRequiresAuth() 才是唯一有效防線。
    const canSortOrFilter = Boolean(props.auth?.user?.roles?.is_active);

    const path = typeof window !== 'undefined' ? window.location.pathname : urls.index;

    const reload = (params: Params) =>
        router.get(path, params, { preserveState: true, preserveScroll: true, replace: true });

    /** 組合並導覽：保留 search/filters/sort/bool，merge 額外參數（page/after/before）。 */
    const visit = (extra: Params = {}, useFilters = filters) => {
        const params: Params = {};
        if (search) params.search = search;
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
        if (search) params.search = search;
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
        // 切換時保留使用者原始輸入（含尚待修正的欄位），對齊舊頁 §9.2。
        const params: Params = {};
        if (search) params.search = search;
        const applied = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));
        if (Object.keys(applied).length) params.filters = applied;
        if (sort_by) {
            params.sort_by = sort_by;
            params.sort_dir = sort_dir;
        }
        if (!boolean_enabled) params.filter_bool = 1;
        reload(params);
    };

    /** 從一列依主鍵組合 id（對齊 Blade 的 implode('_._')）。 */
    const rowId = (row: Row): string => {
        let parts: string[] = [];
        if (key_columns.length) {
            parts = key_columns.map((c) => String(row[c] ?? '')).filter((v) => v !== '');
        }
        if (!parts.length) {
            for (const v of Object.values(row)) {
                const s = String(v ?? '');
                if (s !== '') parts.push(s);
                if (parts.length >= 2) break;
            }
        }
        return parts.join('_._');
    };

    const cellValue = (row: Row, col: string): string => {
        let v = row[col];
        if (col === 'c_dy' && v !== '' && v != null) {
            const key = String(v);
            if (dynasty_map[key]) v = `${v} - ${dynasty_map[key]}`;
        }
        return v == null ? '' : String(v);
    };

    const colSpan = thead.length + (can_edit ? 1 : 0);

    const filterErrMsg = (code: string) => {
        const key = `filter_err_${code}`;
        const msg = t(key);
        return msg === key ? t('filter_err_unknown') : msg;
    };

    const booleanExamples = useMemo(() => {
        const ex = (props.page_translations?.codes as Record<string, unknown> | undefined)?.filter_chip_examples;
        return Array.isArray(ex) ? (ex as string[]) : [];
    }, [props.page_translations]);

    return (
        <DashboardLayout
            title={table}
            breadcrumbs={[{ label: t('table_name'), url: urls.index }, { label: table }]}
        >
            {copyright_note && (
                <div
                    className="mb-3 rounded border border-blue-300 bg-blue-50 px-4 py-2 text-sm text-blue-800"
                    dangerouslySetInnerHTML={{ __html: copyright_note }}
                />
            )}

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <form onSubmit={doSearch} className="flex items-center gap-1">
                    <Input
                        value={search}
                        placeholder={tc('search')}
                        onChange={(e) => setSearch(e.target.value)}
                        className="w-72"
                    />
                    <Button type="submit" variant="secondary" size="sm">{tc('search')}</Button>
                    {props.search && (
                        <Button type="button" variant="secondary" size="sm" onClick={resetSearch}>{tc('reset')}</Button>
                    )}
                </form>
                {!use_cursor && (
                    <Button
                        type="button"
                        size="sm"
                        onClick={applyFilters}
                        disabled={!canSortOrFilter}
                        title={canSortOrFilter ? undefined : t('sort_filter_requires_login')}
                    >
                        {t('apply_filters')}
                    </Button>
                )}
                {(Object.keys(props.filters).length > 0 || sort_by) && (
                    <Button type="button" size="sm" variant="secondary" onClick={clearFilters}>{t('clear_filters')}</Button>
                )}
                {!canSortOrFilter && (
                    <span className="text-xs text-muted-foreground">{t('sort_filter_requires_login')}</span>
                )}
                {can_edit && (
                    <a href={urls.create} className="inline-flex items-center rounded-md border border-input px-3 py-1.5 text-sm hover:bg-muted">
                        {tc('add')}
                    </a>
                )}
                {props.exportable && (
                    <a href={urls.export} download className="inline-flex items-center gap-1 rounded-md border border-input px-3 py-1.5 text-sm hover:bg-muted">
                        <i className="fas fa-download" aria-hidden /> {t('download_full_table')}
                    </a>
                )}
                {boolean_filter_available && !use_cursor && (
                    <div className="ml-auto flex items-center gap-2">
                        {boolean_enabled ? (
                            <>
                                <span className="rounded bg-primary px-2 py-0.5 text-xs font-semibold text-primary-foreground">{t('advanced_filter_on')}</span>
                                <Button type="button" size="sm" variant="outline" onClick={toggleBoolean}>{t('advanced_filter_disable')}</Button>
                            </>
                        ) : (
                            <Button type="button" size="sm" variant="outline" onClick={toggleBoolean}>{t('advanced_filter')}</Button>
                        )}
                    </div>
                )}
            </div>

            {boolean_enabled && (
                <>
                    {/* 進階篩選語法說明（對齊舊頁：AND/OR/NOT 用法）。 */}
                    <div className="mb-2 text-xs text-muted-foreground">{t('advanced_filter_hint')}</div>
                    {booleanExamples.length > 0 && (
                        <div className="mb-2 hidden text-xs text-muted-foreground md:block">
                            {t('filter_chip_label')}{' '}
                            {booleanExamples.map((chip, i) => (
                                <code key={i} className="mr-1 rounded border px-1">{chip}</code>
                            ))}
                        </div>
                    )}
                    {Object.keys(filter_errors).length > 0 && (
                        <div className="mb-2 rounded border border-yellow-300 bg-yellow-50 px-3 py-2 text-sm text-yellow-800" role="alert">
                            {t('filter_errors_heading', { count: String(Object.keys(filter_errors).length) })}
                            <ul className="mt-1 list-disc pl-5">
                                {Object.entries(filter_errors).map(([col, code]) => (
                                    <li key={col}><code>{col}</code>：{filterErrMsg(code)}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {Object.keys(filter_descriptions).length > 0 && (
                        <div className="mb-2 text-xs text-muted-foreground">
                            {t('filter_applied_label')}：
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
                                    {computed_columns.includes(col) ? (
                                        <span>{col}</span>
                                    ) : (
                                        <button
                                            type="button"
                                            className={cn('hover:underline', !canSortOrFilter && 'cursor-not-allowed opacity-60 hover:no-underline')}
                                            onClick={() => toggleSort(col)}
                                            title={canSortOrFilter ? undefined : t('sort_filter_requires_login')}
                                        >
                                            {joined_columns.includes(col) ? `(${col})` : col} <span aria-hidden>{sortIcon(col)}</span>
                                        </button>
                                    )}
                                    {key_columns.includes(col) && (
                                        <span className="ml-1 rounded bg-blue-100 px-1 text-xs text-blue-800">PK</span>
                                    )}
                                </th>
                            ))}
                            {can_edit && <th className="px-3 py-2 text-left font-medium">{t('actions')}</th>}
                        </tr>
                        {!use_cursor && (
                            <tr>
                                {thead.map((col) => {
                                    if (computed_columns.includes(col)) {
                                        return <th key={col} className="px-2 py-1" />;
                                    }
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
                                {can_edit && <th />}
                            </tr>
                        )}
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td colSpan={colSpan} className="px-3 py-6 text-center text-muted-foreground">{tc('no_data')}</td>
                            </tr>
                        ) : (
                            rows.map((row) => {
                                const id = rowId(row);
                                return (
                                    <tr key={id} className="border-t border-border hover:bg-muted/30">
                                        {thead.map((col) => (
                                            <td key={col} className="px-3 py-1.5">{cellValue(row, col)}</td>
                                        ))}
                                        {can_edit && (
                                            <td className="px-3 py-1.5">
                                                <div className="flex gap-1">
                                                    <a href={urls.edit_template.replace('__ID__', encodeURIComponent(id))} className="rounded bg-sky-100 px-2 py-0.5 text-xs text-sky-800 hover:bg-sky-200">
                                                        {tc('edit')}
                                                    </a>
                                                    {!RISKY_DELETE_DISABLED && (
                                                        <button type="button" onClick={() => setDeleteId(id)} className="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800 hover:bg-red-200">
                                                            {tc('delete')}
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            <div className="mt-3">
                {use_cursor && cursor ? (
                    <div className="flex items-center justify-end gap-2">
                        <Button size="sm" variant="outline" disabled={!cursor.has_prev_pages} onClick={() => visit({ before: cursor.prev_cursor })}>
                            <i className="fas fa-chevron-left" aria-hidden /> {tc('previous_page')}
                        </Button>
                        <span className="text-sm text-muted-foreground">
                            ID: {cursor.first_id != null ? nf.format(Number(cursor.first_id)) : '-'} – {cursor.last_id != null ? nf.format(Number(cursor.last_id)) : '-'}
                        </span>
                        <Button size="sm" variant="outline" disabled={!cursor.has_more_pages} onClick={() => visit({ after: cursor.next_cursor })}>
                            {tc('next_page')} <i className="fas fa-chevron-right" aria-hidden />
                        </Button>
                    </div>
                ) : meta ? (
                    <Pagination
                        meta={meta}
                        onPageChange={(page) => visit({ page })}
                        summaryTemplate="{from}–{to} / {total}"
                        labels={{ previous: tc('previous'), next: tc('next') }}
                    />
                ) : null}
            </div>

            <ConfirmDialog
                open={deleteId !== null}
                onOpenChange={(o) => !o && setDeleteId(null)}
                title={t('confirm_delete')}
                confirmLabel={tc('delete')}
                cancelLabel={tc('cancel')}
                destructive
                onConfirm={() => {
                    if (deleteId !== null) {
                        router.delete(urls.destroy_template.replace('__ID__', encodeURIComponent(deleteId)), {
                            preserveScroll: true,
                            onFinish: () => setDeleteId(null),
                        });
                    }
                }}
            />
        </DashboardLayout>
    );
}
