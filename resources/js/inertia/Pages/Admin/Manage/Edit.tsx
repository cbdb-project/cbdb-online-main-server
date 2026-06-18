import React, { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Button } from '../../../components/ui/Button';
import { Select } from '../../../components/ui/Select';
import { FormField } from '../../../components/ui/FormField';
import { ConfirmDialog } from '../../../components/ui/ConfirmDialog';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';

interface ManagedUser {
    id: number;
    name: string;
    email: string;
    institution: string | null;
    is_active: number;
    is_admin: number;
    role_name: string;
}

interface ManageEditPageProps extends SharedProps {
    user: ManagedUser;
    urls: { update: string; index: string };
}

const ROLE_OPTIONS = [
    { value: 0, label: 'manage_role_general' },
    { value: 1, label: 'manage_role_expert' },
    { value: 2, label: 'manage_role_crowdsource' },
    { value: 3, label: 'manage_role_sysadmin' },
];

export default function ManageEdit() {
    const props = usePage<ManageEditPageProps>().props;
    const { user, urls } = props;
    const t = useTranslation('admin');
    const tc = useTranslation('common');

    const form = useForm<{ is_active: number; is_admin: number; delete_user: number }>({
        is_active: user.is_active,
        is_admin: user.is_admin,
        delete_user: 0,
    });
    const [confirmDelete, setConfirmDelete] = useState(false);

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ is_active: d.is_active, is_admin: d.is_admin }));
        form.patch(urls.update, { preserveScroll: true });
    };

    const submitDelete = () => {
        // 軟刪除：只送 delete_user=1（後端 PATCH 依此走刪除分支）。
        form.transform(() => ({ delete_user: 1 }));
        form.patch(urls.update, { preserveScroll: true });
    };

    const roleLabel = (label: string) => {
        const v = t(label);
        return v === label ? label.replace('manage_role_', '') : v;
    };

    return (
        <DashboardLayout
            title={t('manage_edit_desc', { name: user.name })}
            breadcrumbs={[{ label: 'User management', url: urls.index }, { label: user.name }]}
        >
            <div className="max-w-2xl space-y-4">
                <div className="rounded-lg border border-border bg-card p-4 text-sm">
                    <div className="grid grid-cols-2 gap-2">
                        <div><span className="text-muted-foreground">ID:</span> {user.id}</div>
                        <div><span className="text-muted-foreground">Name:</span> {user.name}</div>
                        <div><span className="text-muted-foreground">Email:</span> {user.email}</div>
                        <div><span className="text-muted-foreground">Institution:</span> {user.institution}</div>
                    </div>
                </div>

                <form onSubmit={save} className="space-y-3 rounded-lg border border-border bg-card p-4">
                    <FormField label={t('manage_account_status')} htmlFor="is_active" error={form.errors.is_active}>
                        <Select id="is_active" value={String(form.data.is_active)} onChange={(e) => form.setData('is_active', Number(e.target.value))}>
                            <option value="1">{t('manage_activated_opt')}</option>
                            <option value="0">{t('manage_not_activated_opt')}</option>
                        </Select>
                    </FormField>
                    <FormField label={t('manage_role_col')} htmlFor="is_admin" error={form.errors.is_admin}>
                        <Select id="is_admin" value={String(form.data.is_admin)} onChange={(e) => form.setData('is_admin', Number(e.target.value))}>
                            {ROLE_OPTIONS.map((r) => (
                                <option key={r.value} value={r.value}>{roleLabel(r.label)}</option>
                            ))}
                        </Select>
                    </FormField>

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={form.processing}>{tc('save_changes')}</Button>
                        <a href={urls.index} className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted">
                            {tc('cancel')}
                        </a>
                        <Button type="button" variant="destructive" disabled={form.processing} onClick={() => setConfirmDelete(true)}>
                            {t('manage_delete_user')}
                        </Button>
                    </div>
                </form>
            </div>

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={t('manage_delete_user')}
                confirmLabel={tc('delete')}
                cancelLabel={tc('cancel')}
                destructive
                onConfirm={() => {
                    setConfirmDelete(false);
                    submitDelete();
                }}
            />
        </DashboardLayout>
    );
}
