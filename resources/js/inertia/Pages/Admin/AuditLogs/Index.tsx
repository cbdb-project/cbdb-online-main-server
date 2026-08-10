import React, { useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import type { SortingState } from '@tanstack/react-table';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { DataTable, type DataTableColumn } from '../../../components/data-table/DataTable';
import { useDataTableQuery } from '../../../components/data-table/useDataTableQuery';
import { Button } from '../../../components/ui/Button';
import { Input } from '../../../components/ui/Input';
import { Select } from '../../../components/ui/Select';
import { Modal } from '../../../components/ui/Modal';
import { FormField } from '../../../components/ui/FormField';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';
import type { ExportColumn } from '../../../components/data-table/export';
import { cn } from '../../../lib/utils';

interface DiffRow {
    field: string;
    before: string;
    after: string;
}

interface AuditRow {
    id: number;
    table_name: string;
    operation: string;
    actor_type: string;
    actor_id: string | number | null;
    operation_id: string | null;
    pk_description: string;
    occurred_at_display: string;
    occurred_at_iso: string;
    created_at_display: string;
    created_at_iso: string;
    show_created: boolean;
    old_data: Record<string, unknown> | null;
    new_data: Record<string, unknown> | null;
    diff_rows: DiffRow[] | null;
}

interface HistoryContext {
    person_id: number;
    page: string;
    label: string;
}

interface AuditLogsPageProps extends SharedProps {
    logs: {
        data: AuditRow[];
        meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
    };
    table_names: string[];
    actor_types: string[];
    history_context: HistoryContext | null;
    filters: {
        search: string | null;
        table_name: string | null;
        operation: string | null;
        actor_type: string | null;
        actor_id: string | null;
    };
    sort: string;
    direction: 'asc' | 'desc';
}

const OPERATION_BADGE: Record<string, string> = {
    DELETE: 'bg-danger-subtle text-danger-subtle-foreground',
    INSERT: 'bg-success-subtle text-success-subtle-foreground',
    UPDATE: 'bg-warning-subtle text-warning-subtle-foreground',
};

/** 以本地時區顯示時間；解析失敗則回退顯示字串。 */
function formatLocal(iso: string, fallback: string): string {
    if (!iso) {
        return fallback;
    }
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? fallback : d.toLocaleString();
}

export default function AuditLogsIndex() {
    const props = usePage<AuditLogsPageProps>().props;
    const { logs, table_names, actor_types, history_context, filters, sort, direction } = props;
    const t = useTranslation('admin');
    const tc = useTranslation('common');

    // 本地篩選輸入狀態（送出時才觸發 Inertia reload，對齊舊頁 GET 表單行為）。
    const [form, setForm] = useState({
        search: filters.search ?? '',
        table_name: filters.table_name ?? '',
        operation: filters.operation ?? '',
        actor_type: filters.actor_type ?? '',
        actor_id: filters.actor_id ?? '',
    });

    // 歷史脈絡參數需在每次 reload 保留。
    const baseParams = useMemo(
        () => ({
            ...(history_context
                ? { c_personid: history_context.person_id, history_page: history_context.page }
                : {}),
            sort,
            direction,
        }),
        [history_context, sort, direction]
    );

    // hook 的 params 用「已套用」的篩選（props.filters），而非草稿輸入 form，
    // 確保換頁/排序時帶的是已生效的篩選，不會把使用者半輸入的草稿一起送出。
    // 草稿只在按下搜尋（applyFilters）時透過 onFilterChange 覆寫送出。
    const appliedFilters = {
        search: filters.search ?? '',
        table_name: filters.table_name ?? '',
        operation: filters.operation ?? '',
        actor_type: filters.actor_type ?? '',
        actor_id: filters.actor_id ?? '',
    };

    const { onPageChange, onFilterChange, onSortingChange } = useDataTableQuery({
        params: { ...baseParams, ...appliedFilters },
        only: ['logs', 'filters', 'sort', 'direction', 'table_names', 'actor_types'],
        sorting: sort ? [{ id: sort, desc: direction === 'desc' }] : [],
    });

    const hasFilters = Boolean(
        form.search || form.table_name || form.operation || form.actor_type || form.actor_id
    );

    const sorting: SortingState = sort ? [{ id: sort, desc: direction === 'desc' }] : [];

    // 檢視 modal 狀態：{row, view}
    const [modal, setModal] = useState<{ row: AuditRow; view: 'diff' | 'old' | 'new' } | null>(null);

    const columns = useMemo<DataTableColumn<AuditRow>[]>(
        () => [
            { accessorKey: 'id', header: '#', enableSorting: true },
            {
                accessorKey: 'occurred_at',
                header: t('audit_col_time'),
                enableSorting: true,
                cell: ({ row }) => (
                    <div>
                        <div title={row.original.occurred_at_iso}>
                            {formatLocal(row.original.occurred_at_iso, row.original.occurred_at_display)}
                        </div>
                        {row.original.show_created && (
                            <small className="text-muted-foreground" title={row.original.created_at_iso}>
                                {t('audit_written_at')}
                                {formatLocal(row.original.created_at_iso, row.original.created_at_display)}
                            </small>
                        )}
                    </div>
                ),
            },
            { accessorKey: 'table_name', header: t('audit_col_table'), enableSorting: true },
            {
                accessorKey: 'operation',
                header: t('audit_col_operation'),
                enableSorting: true,
                cell: ({ row }) => (
                    <span
                        className={cn(
                            'rounded px-2 py-0.5 text-xs font-semibold',
                            OPERATION_BADGE[row.original.operation] ?? 'bg-muted text-foreground'
                        )}
                    >
                        {row.original.operation}
                    </span>
                ),
            },
            {
                accessorKey: 'actor_type',
                header: t('audit_col_actor'),
                enableSorting: true,
                cell: ({ row }) => (
                    <div>
                        <div>{row.original.actor_type}</div>
                        <small className="text-muted-foreground">{row.original.actor_id}</small>
                    </div>
                ),
            },
            {
                id: 'pk',
                header: 'PK',
                cell: ({ row }) =>
                    row.original.pk_description ? (
                        <div className="whitespace-pre-line font-mono text-xs">{row.original.pk_description}</div>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            },
            {
                accessorKey: 'operation_id',
                header: 'operation_id',
                enableSorting: false, // 後端未將 operation_id 列入排序白名單，避免無效排序入口
                cell: ({ row }) => <span className="font-mono text-xs">{row.original.operation_id}</span>,
            },
            {
                id: 'data',
                header: t('audit_col_data'),
                cell: ({ row }) => {
                    const r = row.original;
                    return (
                        <div className="flex flex-wrap gap-1">
                            <Button size="sm" variant="outline" disabled={!r.diff_rows} onClick={() => setModal({ row: r, view: 'diff' })}>
                                {t('audit_diff_btn')}
                            </Button>
                            <Button size="sm" variant="outline" disabled={!r.old_data} onClick={() => setModal({ row: r, view: 'old' })}>
                                old_data
                            </Button>
                            <Button size="sm" variant="outline" disabled={!r.new_data} onClick={() => setModal({ row: r, view: 'new' })}>
                                new_data
                            </Button>
                        </div>
                    );
                },
            },
        ],
        [t]
    );

    const exportColumns: ExportColumn<AuditRow>[] = useMemo(
        () => [
            { header: '#', value: (r) => r.id },
            { header: t('audit_col_time'), value: (r) => r.occurred_at_display },
            { header: t('audit_col_table'), value: (r) => r.table_name },
            { header: t('audit_col_operation'), value: (r) => r.operation },
            { header: t('audit_col_actor'), value: (r) => `${r.actor_type} ${r.actor_id ?? ''}`.trim() },
            { header: 'PK', value: (r) => r.pk_description.replace(/\n/g, '; ') },
            { header: 'operation_id', value: (r) => r.operation_id },
        ],
        [t]
    );

    const applyFilters = () => onFilterChange({ ...form });
    const clearFilters = () => {
        setForm({ search: '', table_name: '', operation: '', actor_type: '', actor_id: '' });
        onFilterChange({ search: null, table_name: null, operation: null, actor_type: null, actor_id: null });
    };

    return (
        <DashboardLayout title={t('audit_logs')} breadcrumbs={[{ label: t('audit_logs') }]}>
            {history_context && (
                <div className="mb-3 rounded border border-info-border bg-info-subtle px-4 py-2 text-sm text-info-subtle-foreground">
                    <i className="fas fa-info-circle mr-1" aria-hidden />
                    {t('audit_history_context', {
                        person_id: String(history_context.person_id),
                        label: history_context.label,
                    })}
                </div>
            )}

            <form
                className="mb-4 grid grid-cols-1 gap-3 md:grid-cols-12"
                onSubmit={(e) => {
                    e.preventDefault();
                    applyFilters();
                }}
            >
                <FormField className="md:col-span-4" label={t('audit_keyword')} htmlFor="search">
                    <Input
                        id="search"
                        value={form.search}
                        placeholder="operation_id / row_pk_text / table_name"
                        onChange={(e) => setForm((f) => ({ ...f, search: e.target.value }))}
                    />
                </FormField>
                <FormField className="md:col-span-2" label={t('audit_table')} htmlFor="table_name">
                    <Select
                        id="table_name"
                        value={form.table_name}
                        onChange={(e) => setForm((f) => ({ ...f, table_name: e.target.value }))}
                    >
                        <option value="">{t('audit_all')}</option>
                        {table_names.map((name) => (
                            <option key={name} value={name}>
                                {name}
                            </option>
                        ))}
                    </Select>
                </FormField>
                <FormField className="md:col-span-2" label={t('audit_operation')} htmlFor="operation">
                    <Select
                        id="operation"
                        value={form.operation}
                        onChange={(e) => setForm((f) => ({ ...f, operation: e.target.value }))}
                    >
                        <option value="">{t('audit_all')}</option>
                        {['INSERT', 'UPDATE', 'DELETE'].map((op) => (
                            <option key={op} value={op}>
                                {op}
                            </option>
                        ))}
                    </Select>
                </FormField>
                <FormField className="md:col-span-2" label={t('audit_actor_type')} htmlFor="actor_type">
                    <Select
                        id="actor_type"
                        value={form.actor_type}
                        onChange={(e) => setForm((f) => ({ ...f, actor_type: e.target.value }))}
                    >
                        <option value="">{t('audit_all')}</option>
                        {actor_types.map((at) => (
                            <option key={at} value={at}>
                                {at}
                            </option>
                        ))}
                    </Select>
                </FormField>
                <FormField className="md:col-span-2" label={t('audit_actor_id')} htmlFor="actor_id">
                    <Input
                        id="actor_id"
                        value={form.actor_id}
                        placeholder={t('audit_actor_id_placeholder')}
                        onChange={(e) => setForm((f) => ({ ...f, actor_id: e.target.value }))}
                    />
                </FormField>
                <div className="flex items-end gap-2 md:col-span-12">
                    <Button type="submit">
                        <i className="fas fa-search" aria-hidden /> {t('audit_search_btn')}
                    </Button>
                    {hasFilters && (
                        <Button type="button" variant="secondary" onClick={clearFilters}>
                            <i className="fas fa-times" aria-hidden /> {t('audit_clear_btn')}
                        </Button>
                    )}
                </div>
            </form>

            <div className="mb-3 rounded border border-info-border bg-info-subtle px-4 py-2 text-sm text-info-subtle-foreground">
                <i className="fas fa-info-circle mr-1" aria-hidden />
                {t('audit_summary', {
                    total: String(logs.meta.total),
                    first: String(logs.meta.from ?? 0),
                    last: String(logs.meta.to ?? 0),
                })}
            </div>

            <DataTable<AuditRow>
                columns={columns}
                data={logs.data}
                meta={logs.meta}
                onPageChange={onPageChange}
                sorting={sorting}
                onSortingChange={onSortingChange}
                labels={{
                    empty: t('audit_no_records'),
                    loading: tc('loading'),
                    exportCsv: 'CSV',
                    print: tc('print'),
                    previous: tc('previous'),
                    next: tc('next'),
                    summaryTemplate: '{from}–{to} / {total}',
                }}
                exportColumns={exportColumns}
                exportFilename="audit-logs"
                exportTitle={t('audit_logs')}
            />

            <Modal
                open={modal !== null}
                onOpenChange={(open) => !open && setModal(null)}
                title={
                    modal?.view === 'diff' ? t('audit_diff_btn') : modal?.view === 'old' ? 'old_data' : 'new_data'
                }
                className="max-w-3xl"
                footer={
                    <Button variant="outline" onClick={() => setModal(null)}>
                        {tc('close')}
                    </Button>
                }
            >
                {modal && <AuditDetail row={modal.row} view={modal.view} />}
            </Modal>
        </DashboardLayout>
    );
}

/** diff / old_data / new_data 的鍵值表呈現。 */
function AuditDetail({ row, view }: { row: AuditRow; view: 'diff' | 'old' | 'new' }) {
    if (view === 'diff') {
        const rows = row.diff_rows ?? [];
        return (
            <div className="max-h-[60vh] overflow-auto">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50">
                        <tr>
                            <th className="px-2 py-1 text-left">field</th>
                            <th className="px-2 py-1 text-left">before</th>
                            <th className="px-2 py-1 text-left">after</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((d) => (
                            <tr key={d.field} className="border-t border-border align-top">
                                <td className="px-2 py-1 font-mono text-xs">{d.field}</td>
                                <td className="px-2 py-1 break-all">{d.before}</td>
                                <td className="px-2 py-1 break-all">{d.after}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        );
    }

    const data = view === 'old' ? row.old_data : row.new_data;
    const entries = data ? Object.entries(data) : [];
    return (
        <div className="max-h-[60vh] overflow-auto">
            <table className="w-full text-sm">
                <tbody>
                    {entries.map(([k, v]) => (
                        <tr key={k} className="border-t border-border align-top">
                            <td className="px-2 py-1 font-mono text-xs">{k}</td>
                            <td className="px-2 py-1 break-all">
                                {v === null ? '(null)' : typeof v === 'object' ? JSON.stringify(v) : String(v)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
