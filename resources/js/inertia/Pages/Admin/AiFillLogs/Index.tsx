import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Pagination } from '../../../components/ui/Pagination';
import { Button } from '../../../components/ui/Button';
import { Input } from '../../../components/ui/Input';
import { Select } from '../../../components/ui/Select';
import { Modal } from '../../../components/ui/Modal';
import { FormField } from '../../../components/ui/FormField';
import { useDataTableQuery } from '../../../components/data-table/useDataTableQuery';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';
import { cn } from '../../../lib/utils';

interface ComparisonRow {
    field: string;
    field_key: string;
    ai_value: string;
    ai_type: 'matched' | 'suggested' | 'empty';
    user_value: string;
    matches: boolean;
}

interface Statistics {
    matched_count?: number;
    suggested_count?: number;
    not_found_count?: number;
    empty_count?: number;
}

interface AiLogRow {
    id: number;
    category: string;
    user_name: string | null;
    user_email: string | null;
    c_personid: number | null;
    person_url: string | null;
    created_at: string;
    execution_time_ms: number | null;
    has_submission: boolean;
    source_text: string;
    statistics: Statistics | null;
    route_name: string | null;
    route_url: string | null;
    comparison_rows: ComparisonRow[] | null;
    ai_raw_pretty: string | null;
    ai_matched_pretty: string | null;
    user_submitted_pretty: string | null;
}

interface AiFillLogsPageProps extends SharedProps {
    logs: {
        data: AiLogRow[];
        meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
    };
    users: { id: number; name: string }[];
    filters: { search: string | null; user_id: string | null; category: string | null };
}

const CATEGORY_BADGE: Record<string, string> = {
    posting: 'bg-blue-100 text-blue-800',
    assoc: 'bg-cyan-100 text-cyan-800',
    status: 'bg-yellow-100 text-yellow-800',
};

function Collapsible({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <details className="mb-2 rounded border border-border">
            <summary className="cursor-pointer px-3 py-1.5 text-sm font-medium hover:bg-muted">{label}</summary>
            <div className="border-t border-border p-2">{children}</div>
        </details>
    );
}

export default function AiFillLogsIndex() {
    const props = usePage<AiFillLogsPageProps>().props;
    const { logs, users, filters } = props;
    const t = useTranslation('admin');
    const tc = useTranslation('common');

    const [form, setForm] = useState({
        search: filters.search ?? '',
        user_id: filters.user_id ?? '',
        category: filters.category ?? '',
    });

    const applied = {
        search: filters.search ?? '',
        user_id: filters.user_id ?? '',
        category: filters.category ?? '',
    };

    const { onPageChange, onFilterChange } = useDataTableQuery({
        params: applied,
        only: ['logs', 'filters'],
    });

    const hasFilters = Boolean(form.search || form.user_id || form.category);
    const [compareRow, setCompareRow] = useState<AiLogRow | null>(null);

    const categoryLabel = (c: string) =>
        c === 'assoc' ? t('ai_log_cat_assoc') : c === 'status' ? t('ai_log_cat_status') : t('ai_log_cat_posting');

    return (
        <DashboardLayout title={t('ai_fill_logs')} breadcrumbs={[{ label: t('ai_fill_logs') }]}>
            <form
                className="mb-4 grid grid-cols-1 gap-3 md:grid-cols-12"
                onSubmit={(e) => {
                    e.preventDefault();
                    onFilterChange({ ...form });
                }}
            >
                <FormField className="md:col-span-5" label={t('ai_log_keyword')} htmlFor="search">
                    <Input
                        id="search"
                        value={form.search}
                        placeholder={t('ai_log_search_placeholder')}
                        onChange={(e) => setForm((f) => ({ ...f, search: e.target.value }))}
                    />
                </FormField>
                <FormField className="md:col-span-4" label={t('ai_log_user')} htmlFor="user_id">
                    <Select
                        id="user_id"
                        value={form.user_id}
                        onChange={(e) => setForm((f) => ({ ...f, user_id: e.target.value }))}
                    >
                        <option value="">{t('ai_log_all_users')}</option>
                        {users.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                            </option>
                        ))}
                    </Select>
                </FormField>
                <FormField className="md:col-span-2" label={t('ai_log_category')} htmlFor="category">
                    <Select
                        id="category"
                        value={form.category}
                        onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))}
                    >
                        <option value="">{t('ai_log_all_categories')}</option>
                        <option value="posting">{t('ai_log_cat_posting')}</option>
                        <option value="assoc">{t('ai_log_cat_assoc')}</option>
                        <option value="status">{t('ai_log_cat_status')}</option>
                    </Select>
                </FormField>
                <div className="flex items-end gap-2 md:col-span-12">
                    <Button type="submit">
                        <i className="fas fa-search" aria-hidden /> {t('ai_log_search_btn')}
                    </Button>
                    {hasFilters && (
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => {
                                setForm({ search: '', user_id: '', category: '' });
                                onFilterChange({ search: null, user_id: null, category: null });
                            }}
                        >
                            <i className="fas fa-times" aria-hidden /> {t('ai_log_clear_btn')}
                        </Button>
                    )}
                </div>
            </form>

            <div className="mb-3 rounded border border-blue-300 bg-blue-50 px-4 py-2 text-sm text-blue-800">
                <i className="fas fa-info-circle mr-1" aria-hidden />
                {t('ai_log_summary', {
                    total: String(logs.meta.total),
                    first: String(logs.meta.from ?? 0),
                    last: String(logs.meta.to ?? 0),
                })}
            </div>

            {logs.data.length === 0 ? (
                <div className="rounded border border-yellow-300 bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
                    <i className="fas fa-exclamation-triangle mr-1" aria-hidden /> {t('ai_log_no_records')}
                </div>
            ) : (
                <div className="space-y-4">
                    {logs.data.map((log) => (
                        <div
                            key={log.id}
                            className={cn(
                                'rounded-lg border bg-card',
                                log.has_submission ? 'border-green-400' : 'border-blue-400'
                            )}
                        >
                            <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2 text-sm">
                                <strong>#{log.id}</strong>
                                <span className={cn('rounded px-2 py-0.5 text-xs font-semibold', CATEGORY_BADGE[log.category] ?? 'bg-gray-100 text-gray-800')}>
                                    {categoryLabel(log.category)}
                                </span>
                                <span>
                                    <i className="fas fa-user mr-1" aria-hidden />
                                    {log.user_name ?? t('ai_log_unknown_user')}
                                    {log.user_email && <small className="ml-1 text-muted-foreground">({log.user_email})</small>}
                                </span>
                                {log.person_url && (
                                    <a href={log.person_url} target="_blank" rel="noreferrer" className="text-primary hover:underline">
                                        <i className="fas fa-id-badge mr-1" aria-hidden />
                                        {t('ai_log_person', { id: String(log.c_personid) })}
                                    </a>
                                )}
                                <span className="text-muted-foreground">
                                    <i className="fas fa-clock mr-1" aria-hidden />
                                    {log.created_at}
                                </span>
                                {log.execution_time_ms != null && (
                                    <span className="text-muted-foreground">
                                        <i className="fas fa-stopwatch mr-1" aria-hidden />
                                        {log.execution_time_ms}ms
                                    </span>
                                )}
                                <span className="ml-auto rounded bg-muted px-2 py-0.5 text-xs">
                                    {log.has_submission ? t('ai_log_submitted') : t('ai_log_not_submitted')}
                                </span>
                            </div>

                            <div className="space-y-3 p-4">
                                <div>
                                    <h6 className="mb-1 font-medium">
                                        <i className="fas fa-file-alt mr-1 text-primary" aria-hidden /> {t('ai_log_source_text')}
                                    </h6>
                                    <div className="rounded bg-muted/40 px-3 py-2 text-sm">{log.source_text}</div>
                                </div>

                                {log.statistics && (
                                    <div className="flex flex-wrap gap-1 text-xs">
                                        <span className="rounded bg-green-100 px-2 py-0.5 text-green-800">
                                            {t('ai_log_matched', { count: String(log.statistics.matched_count ?? 0) })}
                                        </span>
                                        {(log.statistics.suggested_count ?? 0) > 0 && (
                                            <span className="rounded bg-yellow-100 px-2 py-0.5 text-yellow-800">
                                                {t('ai_log_suggested', { count: String(log.statistics.suggested_count) })}
                                            </span>
                                        )}
                                        {(log.statistics.not_found_count ?? 0) > 0 && (
                                            <span className="rounded bg-blue-100 px-2 py-0.5 text-blue-800">
                                                {t('ai_log_not_matched', { count: String(log.statistics.not_found_count) })}
                                            </span>
                                        )}
                                        <span className="rounded bg-gray-100 px-2 py-0.5 text-gray-800">
                                            {t('ai_log_empty', { count: String(log.statistics.empty_count ?? 0) })}
                                        </span>
                                    </div>
                                )}

                                {(log.route_name || log.route_url) && (
                                    <div className="text-xs text-muted-foreground">
                                        <i className="fas fa-route mr-1" aria-hidden />
                                        {log.route_name}
                                        {log.route_url && ` (${log.route_url})`}
                                    </div>
                                )}

                                {log.comparison_rows && log.comparison_rows.length > 0 && (
                                    <Button size="sm" variant="outline" onClick={() => setCompareRow(log)}>
                                        <i className="fas fa-columns mr-1" aria-hidden /> {t('ai_log_compare_btn')}
                                    </Button>
                                )}

                                {log.ai_raw_pretty && (
                                    <Collapsible label={t('ai_log_ai_raw')}>
                                        <pre className="max-h-96 overflow-auto bg-muted/40 p-2 text-xs">{log.ai_raw_pretty}</pre>
                                    </Collapsible>
                                )}
                                {log.ai_matched_pretty && (
                                    <Collapsible label={t('ai_log_ai_matched')}>
                                        <pre className="max-h-96 overflow-auto bg-muted/40 p-2 text-xs">{log.ai_matched_pretty}</pre>
                                    </Collapsible>
                                )}
                                {log.user_submitted_pretty && (
                                    <Collapsible label={t('ai_log_user_submitted')}>
                                        <pre className="max-h-96 overflow-auto bg-muted/40 p-2 text-xs">{log.user_submitted_pretty}</pre>
                                    </Collapsible>
                                )}
                            </div>
                        </div>
                    ))}

                    <Pagination
                        meta={logs.meta}
                        onPageChange={onPageChange}
                        summaryTemplate="{from}–{to} / {total}"
                        labels={{ previous: tc('previous'), next: tc('next') }}
                    />
                </div>
            )}

            <Modal
                open={compareRow !== null}
                onOpenChange={(open) => !open && setCompareRow(null)}
                title={compareRow ? t('ai_log_modal_title', { id: String(compareRow.id) }) : ''}
                className="max-w-4xl"
                footer={
                    <Button variant="outline" onClick={() => setCompareRow(null)}>
                        {t('ai_log_close')}
                    </Button>
                }
            >
                {compareRow && (
                    <div className="max-h-[60vh] overflow-auto">
                        <div className="mb-3 rounded bg-muted/40 px-3 py-2 text-sm">
                            <strong>
                                <i className="fas fa-file-alt mr-1 text-primary" aria-hidden /> {t('ai_log_modal_source')}
                            </strong>{' '}
                            {compareRow.source_text}
                        </div>
                        <table className="w-full text-sm">
                            <tbody>
                                {(compareRow.comparison_rows ?? []).map((r) => (
                                    <tr key={r.field_key} className="border-t border-border align-top">
                                        <td className="px-2 py-1 font-medium">{r.field}</td>
                                        <td
                                            className={cn(
                                                'px-2 py-1 break-all',
                                                r.ai_type === 'matched' && 'text-green-700',
                                                r.ai_type === 'suggested' && 'text-yellow-700'
                                            )}
                                        >
                                            {r.ai_value}
                                        </td>
                                        <td className={cn('px-2 py-1 break-all', r.matches && 'text-green-700')}>
                                            {r.user_value}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Modal>
        </DashboardLayout>
    );
}
