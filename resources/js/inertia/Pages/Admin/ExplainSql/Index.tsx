import React from 'react';
import { useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Button } from '../../../components/ui/Button';
import { FormField } from '../../../components/ui/FormField';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';
import { cn } from '../../../lib/utils';

interface ExplainSqlPageProps extends SharedProps {
    sql: string;
    results: Record<string, unknown>[] | null;
    columns: string[];
    error: string | null;
    explain_url: string;
}

export default function ExplainSqlIndex() {
    const props = usePage<ExplainSqlPageProps>().props;
    const { results, columns, error, explain_url } = props;
    const t = useTranslation('admin');

    // useForm 提交至 explain_url；後端重新 render 同元件並帶回 results/error。
    const form = useForm<{ sql: string }>({ sql: props.sql ?? '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(explain_url, { preserveScroll: true });
    };

    return (
        <DashboardLayout title="MySQL EXPLAIN" breadcrumbs={[{ label: t('sql_explain') }]}>
            <div className="rounded-lg border border-border bg-card p-4">
                <p className="mb-3 text-sm text-muted-foreground">{t('explain_sql_desc')}</p>

                <form onSubmit={submit}>
                    <FormField label={t('explain_sql_label')} htmlFor="sql" error={form.errors.sql}>
                        <textarea
                            id="sql"
                            rows={5}
                            className={cn(
                                'w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm shadow-sm',
                                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                'aria-[invalid=true]:border-destructive'
                            )}
                            placeholder="SELECT ..."
                            value={form.data.sql}
                            onChange={(e) => form.setData('sql', e.target.value)}
                        />
                    </FormField>
                    <Button type="submit" disabled={form.processing}>
                        {t('explain_sql_btn')}
                    </Button>
                </form>

                {error && (
                    <div className="mt-4 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
                        {error}
                    </div>
                )}

                {Array.isArray(results) && results.length > 0 ? (
                    <div className="mt-5 overflow-x-auto rounded-md border border-border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    {columns.map((c) => (
                                        <th key={c} className="px-3 py-2 text-left font-medium">
                                            {c}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {results.map((row, i) => (
                                    <tr key={i} className="border-t border-border">
                                        {columns.map((c) => (
                                            <td key={c} className="px-3 py-2 align-top">
                                                {row[c] === null || row[c] === undefined ? '' : String(row[c])}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : results !== null ? (
                    <p className="mt-5 text-sm text-muted-foreground">{t('explain_no_results')}</p>
                ) : null}
            </div>
        </DashboardLayout>
    );
}
