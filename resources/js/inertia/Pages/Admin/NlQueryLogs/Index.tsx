import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Pagination } from '../../../components/ui/Pagination';
import { Button } from '../../../components/ui/Button';
import { Input } from '../../../components/ui/Input';
import { Select } from '../../../components/ui/Select';
import { FormField } from '../../../components/ui/FormField';
import { useDataTableQuery } from '../../../components/data-table/useDataTableQuery';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';
import { cn } from '../../../lib/utils';
import { LOG_TONE, LOG_CARD_BASE, LOG_HEADER_BASE, LOG_PILL_BASE } from '../logCardStyles';
import { LogCollapsible } from '../LogCollapsible';

interface LlmSummary {
    model: string | null;
    rounds_count: number | null;
    prompt_tokens: number;
    completion_tokens: number;
    total_tokens: number;
}

interface NlLogRow {
    id: number;
    user_name: string | null;
    user_email: string | null;
    created_at: string;
    execution_time_ms: number | null;
    success: boolean;
    question: string;
    generated_sql: string | null;
    explanation: string | null;
    error_message: string | null;
    llm_prompt: string | null;
    llm_response: string | null;
    llm_summary: LlmSummary | null;
}

interface NlQueryLogsPageProps extends SharedProps {
    logs: {
        data: NlLogRow[];
        meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
    };
    users: { id: number; name: string }[];
    filters: { search: string | null; success: string | null; user_id: string | null };
    playground_url: string;
}

const nf = new Intl.NumberFormat();

export default function NlQueryLogsIndex() {
    const props = usePage<NlQueryLogsPageProps>().props;
    const { logs, users, filters, playground_url } = props;
    const t = useTranslation('query');
    const tc = useTranslation('common');
    const tOps = useTranslation('operations');

    const [form, setForm] = useState({
        search: filters.search ?? '',
        success: filters.success ?? '',
        user_id: filters.user_id ?? '',
    });

    const applied = {
        search: filters.search ?? '',
        success: filters.success ?? '',
        user_id: filters.user_id ?? '',
    };

    const { onPageChange, onFilterChange } = useDataTableQuery({
        params: applied,
        only: ['logs', 'filters'],
    });

    const hasFilters = Boolean(form.search || form.success !== '' || form.user_id);

    return (
        <DashboardLayout
            title={t('log_page_title')}
            breadcrumbs={[{ label: t('log_page_title') }]}
        >
            <div className="mb-3">
                <a
                    href={playground_url}
                    className="inline-flex items-center gap-1 rounded-md border border-input px-3 py-1.5 text-sm hover:bg-muted"
                >
                    <i className="fas fa-arrow-left" aria-hidden /> {t('log_back_link')}
                </a>
            </div>

            <form
                className="mb-4 grid grid-cols-1 gap-3 md:grid-cols-12"
                onSubmit={(e) => {
                    e.preventDefault();
                    onFilterChange({ ...form });
                }}
            >
                <FormField className="md:col-span-5" label={t('log_keyword_search')} htmlFor="search">
                    <Input id="search" value={form.search} placeholder={t('log_search_placeholder')} onChange={(e) => setForm((f) => ({ ...f, search: e.target.value }))} />
                </FormField>
                <FormField className="md:col-span-3" label={tOps('status_label')} htmlFor="success">
                    <Select id="success" value={form.success} onChange={(e) => setForm((f) => ({ ...f, success: e.target.value }))}>
                        <option value="">{t('log_status_all')}</option>
                        <option value="1">{t('log_status_success')}</option>
                        <option value="0">{t('log_status_failure')}</option>
                    </Select>
                </FormField>
                <FormField className="md:col-span-3" label={t('log_user_label')} htmlFor="user_id">
                    <Select id="user_id" value={form.user_id} onChange={(e) => setForm((f) => ({ ...f, user_id: e.target.value }))}>
                        <option value="">{t('log_all_users')}</option>
                        {users.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                            </option>
                        ))}
                    </Select>
                </FormField>
                <div className="flex items-end gap-2 md:col-span-12">
                    <Button type="submit">
                        <i className="fas fa-search" aria-hidden /> {tc('search')}
                    </Button>
                    {hasFilters && (
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => {
                                setForm({ search: '', success: '', user_id: '' });
                                onFilterChange({ search: null, success: null, user_id: null });
                            }}
                        >
                            <i className="fas fa-times" aria-hidden /> {tOps('clear_filter')}
                        </Button>
                    )}
                </div>
            </form>

            <div className="mb-3 rounded border border-blue-300 bg-blue-50 px-4 py-2 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200">
                <i className="fas fa-info-circle mr-1" aria-hidden />
                {t('log_record_count', {
                    total: String(logs.meta.total),
                    first: String(logs.meta.from ?? 0),
                    last: String(logs.meta.to ?? 0),
                })}
            </div>

            {logs.data.length === 0 ? (
                <div className="rounded border border-yellow-300 bg-yellow-50 px-4 py-3 text-sm text-yellow-800 dark:border-yellow-900 dark:bg-yellow-950/40 dark:text-yellow-200">
                    <i className="fas fa-exclamation-triangle mr-1" aria-hidden /> {t('log_no_records')}
                </div>
            ) : (
                <div className="space-y-4">
                    {logs.data.map((log) => {
                        const tone = LOG_TONE[log.success ? 'success' : 'danger'];
                        return (
                        <article key={log.id} className={cn(LOG_CARD_BASE, tone.card)}>
                            <header className={cn(LOG_HEADER_BASE, tone.header)}>
                                <span className="font-mono font-semibold text-muted-foreground">#{log.id}</span>
                                <span>
                                    <i className="fas fa-user mr-1 text-muted-foreground" aria-hidden />
                                    <span className="font-medium text-foreground">{log.user_name ?? tc('unknown')}</span>
                                    {log.user_email && <small className="ml-1 text-muted-foreground">({log.user_email})</small>}
                                </span>
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
                                <span className={cn(LOG_PILL_BASE, tone.pill)}>
                                    <i className={cn('fas', log.success ? 'fa-check-circle' : 'fa-times-circle')} aria-hidden />
                                    {log.success ? t('log_status_success') : t('log_status_failure')}
                                </span>
                            </header>

                            <div className="space-y-3 p-4">
                                <div>
                                    <h6 className="mb-1 font-medium">
                                        <i className="fas fa-question-circle mr-1 text-primary" aria-hidden /> {t('log_user_question')}
                                    </h6>
                                    <div className="rounded bg-muted/40 px-3 py-2 text-sm">{log.question}</div>
                                </div>

                                {log.success ? (
                                    <>
                                        {log.generated_sql && (
                                            <div>
                                                <h6 className="mb-1 font-medium">
                                                    <i className="fas fa-code mr-1 text-emerald-600" aria-hidden /> {t('log_generated_sql')}
                                                </h6>
                                                <pre className="max-h-52 overflow-auto rounded bg-muted/40 p-2 text-xs">{log.generated_sql}</pre>
                                            </div>
                                        )}
                                        {log.explanation && (
                                            <div>
                                                <h6 className="mb-1 font-medium">
                                                    <i className="fas fa-info-circle mr-1 text-blue-600" aria-hidden /> {t('log_explanation')}
                                                </h6>
                                                <p className="text-sm text-muted-foreground">{log.explanation}</p>
                                            </div>
                                        )}
                                    </>
                                ) : (
                                    log.error_message && (
                                        <div>
                                            <h6 className="mb-1 font-medium">
                                                <i className="fas fa-exclamation-triangle mr-1 text-destructive" aria-hidden /> {t('log_error_message')}
                                            </h6>
                                            <div className="rounded border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">{log.error_message}</div>
                                        </div>
                                    )
                                )}

                                {log.llm_summary && (
                                    <div className="flex flex-wrap gap-1 text-xs">
                                        {log.llm_summary.model && (
                                            <span className="rounded bg-muted px-2 py-0.5">{t('log_model')}: {log.llm_summary.model}</span>
                                        )}
                                        {log.llm_summary.rounds_count != null && (
                                            <span className="rounded bg-muted px-2 py-0.5">{t('log_total_rounds')}: {log.llm_summary.rounds_count}</span>
                                        )}
                                        {log.llm_summary.total_tokens > 0 && (
                                            <span className="rounded bg-muted px-2 py-0.5">{t('log_token_total')}: {nf.format(log.llm_summary.total_tokens)}</span>
                                        )}
                                    </div>
                                )}

                                {log.llm_prompt && (
                                    <LogCollapsible label={t('log_llm_prompt')}>
                                        <pre className="max-h-96 overflow-auto bg-muted/40 p-2 text-xs">{log.llm_prompt}</pre>
                                    </LogCollapsible>
                                )}
                                {log.llm_response && (
                                    <LogCollapsible label={t('log_llm_response')}>
                                        <pre className="max-h-[32rem] overflow-auto bg-muted/40 p-2 text-xs">{log.llm_response}</pre>
                                    </LogCollapsible>
                                )}
                            </div>
                        </article>
                        );
                    })}

                    <Pagination
                        meta={logs.meta}
                        onPageChange={onPageChange}
                        summaryTemplate="{from}–{to} / {total}"
                        labels={{ previous: tc('previous'), next: tc('next') }}
                    />
                </div>
            )}
        </DashboardLayout>
    );
}
