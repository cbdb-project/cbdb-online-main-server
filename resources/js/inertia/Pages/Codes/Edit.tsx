import React, { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { FormField } from '../../components/ui/FormField';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

interface CodesEditPageProps extends SharedProps {
    table: string;
    id: string;
    columns: string[];
    values: Record<string, string | number | null>;
    key_columns: string[];
    can_propose: boolean;
    urls: { update: string; propose: string; destroy: string; show: string };
}

export default function CodesEdit() {
    const props = usePage<CodesEditPageProps>().props;
    const { table, columns, values, key_columns, can_propose, urls } = props;
    const t = useTranslation('codes');
    const tc = useTranslation('common');

    const initial: Record<string, string> = { __proposal_comment: '' };
    columns.forEach((c) => {
        initial[c] = values[c] != null ? String(values[c]) : '';
    });

    const form = useForm<Record<string, string>>(initial);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // app.codes.update 接受 PUT/PATCH；useForm.patch 直接送出。
        form.patch(urls.update, { preserveScroll: true });
    };
    const propose = () => form.post(urls.propose, { preserveScroll: true });

    return (
        <DashboardLayout
            title={`${tc('edit')} — ${table}`}
            breadcrumbs={[{ label: 'Codes', url: '/app/codes' }, { label: table, url: urls.show }, { label: tc('edit') }]}
        >
            <form onSubmit={submit} className="max-w-3xl space-y-3 rounded-lg border border-border bg-card p-4">
                {columns.map((col) => (
                    <FormField
                        key={col}
                        label={col}
                        htmlFor={col}
                        error={form.errors[col]}
                    >
                        <Input
                            id={col}
                            value={form.data[col] ?? ''}
                            onChange={(e) => form.setData(col, e.target.value)}
                        />
                        {key_columns.includes(col) && (
                            <span className="text-xs text-blue-700">PK</span>
                        )}
                    </FormField>
                ))}

                {can_propose && (
                    <FormField label={t('proposal_desc')} htmlFor="__proposal_comment" hint={t('proposal_desc_hint')}>
                        <textarea
                            id="__proposal_comment"
                            rows={3}
                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            placeholder={t('proposal_desc_hint')}
                            value={form.data.__proposal_comment ?? ''}
                            onChange={(e) => form.setData('__proposal_comment', e.target.value)}
                        />
                    </FormField>
                )}

                <div className="flex flex-wrap gap-2">
                    <Button type="submit" disabled={form.processing}>{t('save_direct')}</Button>
                    {can_propose && (
                        <Button type="button" variant="secondary" disabled={form.processing} onClick={propose}>
                            {t('submit_proposal')}
                        </Button>
                    )}
                    <Button type="button" variant="destructive" disabled={form.processing} onClick={() => setConfirmDelete(true)}>
                        {tc('delete')}
                    </Button>
                    <a href={urls.show} className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted">
                        {tc('cancel')}
                    </a>
                </div>
            </form>

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={t('confirm_delete')}
                confirmLabel={tc('delete')}
                cancelLabel={tc('cancel')}
                destructive
                onConfirm={() => {
                    setConfirmDelete(false);
                    router.delete(urls.destroy, { preserveScroll: true });
                }}
            />
        </DashboardLayout>
    );
}
