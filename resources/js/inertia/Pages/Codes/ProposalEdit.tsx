import React from 'react';
import { useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Button } from '../../components/ui/Button';
import { FormField } from '../../components/ui/FormField';
import { CodesColumnField, type ColumnBehaviour } from '../../components/Codes/CodesColumnField';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

interface ProposalMeta {
    comment?: string;
    cancel_reason?: string;
    [key: string]: unknown;
}

interface CodesProposalEditPageProps extends SharedProps {
    table: string;
    columns: string[];
    values: Record<string, string | number | null>;
    operation_id: number;
    key_columns: string[];
    proposal_meta: ProposalMeta;
    review_status: string;
    review_comment: string | null;
    is_create_proposal: boolean;
    /** 逐欄行為（此頁只用到稽核欄唯讀；替換預覽不給——核准當下才由審核人蓋章）。 */
    column_behaviour?: Record<string, ColumnBehaviour>;
    urls: { update: string; return: string };
}

export default function CodesProposalEdit() {
    const props = usePage<CodesProposalEditPageProps>().props;
    const { table, columns, values, key_columns, proposal_meta, review_status, review_comment, column_behaviour, urls } = props;
    const behaviourOf = column_behaviour ?? {};
    const t = useTranslation('codes');

    const initial: Record<string, string> = { __proposal_comment: proposal_meta.comment ?? '' };
    columns.forEach((c) => {
        initial[c] = values[c] != null ? String(values[c]) : '';
    });

    const form = useForm<Record<string, string>>(initial);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(urls.update, { preserveScroll: true });
    };

    return (
        <DashboardLayout title={`${table} ${t('proposal_desc')}`} breadcrumbs={[{ label: 'Codes', url: '/app/codes' }, { label: table }, { label: t('update_proposal') }]}>
            {review_status === 'rejected' && review_comment && (
                <div className="mb-3 rounded border border-yellow-300 bg-yellow-50 px-4 py-2 text-sm text-yellow-800">
                    <strong>{t('rejection_reason')}：</strong> {review_comment}
                </div>
            )}
            {review_status === 'cancelled' && proposal_meta.cancel_reason && (
                <div className="mb-3 rounded border border-blue-300 bg-blue-50 px-4 py-2 text-sm text-blue-800">
                    <strong>{t('withdrawal_reason')}：</strong> {proposal_meta.cancel_reason}
                </div>
            )}

            <form onSubmit={submit} className="max-w-3xl space-y-3 rounded-lg border border-border bg-card p-4">
                {columns.map((col) => (
                    <CodesColumnField
                        key={col}
                        column={col}
                        value={form.data[col] ?? ''}
                        onChange={(v) => form.setData(col, v)}
                        error={form.errors[col]}
                        isKey={key_columns.includes(col)}
                        behaviour={behaviourOf[col]}
                    />
                ))}

                <FormField label={t('proposal_desc')} htmlFor="__proposal_comment" hint={t('resubmit_notice')}>
                    <textarea
                        id="__proposal_comment"
                        rows={3}
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        value={form.data.__proposal_comment ?? ''}
                        onChange={(e) => form.setData('__proposal_comment', e.target.value)}
                    />
                </FormField>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>{t('update_proposal')}</Button>
                    <a href={urls.return} className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted">
                        {t('return_to_proposals')}
                    </a>
                </div>
            </form>
        </DashboardLayout>
    );
}
