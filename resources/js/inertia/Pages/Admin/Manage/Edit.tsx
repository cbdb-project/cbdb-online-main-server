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
    created_at: string | null;
    updated_at: string | null;
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

const ROLE_DESCRIPTIONS = [
    { key: 'general', title: 'manage_role_desc_general_title', body: 'manage_role_desc_general_body' },
    { key: 'expert', title: 'manage_role_desc_expert_title', body: 'manage_role_desc_expert_body' },
    { key: 'crowdsource', title: 'manage_role_desc_crowdsource_title', body: 'manage_role_desc_crowdsource_body' },
    { key: 'sysadmin', title: 'manage_role_desc_sysadmin_title', body: 'manage_role_desc_sysadmin_body' },
] as const;

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
    // 刪除帳號不可逆，沿用舊版 manage/edit.blade.php 的**兩段確認**（第一段說明不可恢復、
    // 第二段最後確認）。React 版遷移時收斂成單一 modal，這裡補回第二道閘。
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [confirmDeleteFinal, setConfirmDeleteFinal] = useState(false);

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ is_active: d.is_active, is_admin: d.is_admin }));
        form.patch(urls.update, { preserveScroll: true });
    };

    const submitDelete = () => {
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
            breadcrumbs={[{ label: t('manage_breadcrumb_index'), url: urls.index }, { label: user.name }]}
        >
            <div className="max-w-2xl space-y-4">
                <div className="rounded-lg border border-border bg-card p-4 text-sm">
                    <div className="grid grid-cols-2 gap-2">
                        <div><span className="text-muted-foreground">{t('manage_user_id')}:</span> {user.id}</div>
                        <div><span className="text-muted-foreground">{tc('name')}:</span> {user.name}</div>
                        <div><span className="text-muted-foreground">{tc('email')}:</span> {user.email}</div>
                        <div><span className="text-muted-foreground">{tc('institution')}:</span> {user.institution}</div>
                        <div><span className="text-muted-foreground">{t('manage_registered_at')}:</span> {user.created_at ?? '-'}</div>
                        <div><span className="text-muted-foreground">{t('manage_updated_at')}:</span> {user.updated_at ?? '-'}</div>
                    </div>
                </div>

                <form onSubmit={save} className="space-y-3 rounded-lg border border-border bg-card p-4">
                    <FormField label={t('manage_account_status')} htmlFor="is_active" error={form.errors.is_active}>
                        <Select id="is_active" value={String(form.data.is_active)} onChange={(e) => form.setData('is_active', Number(e.target.value))}>
                            <option value="1">{t('manage_activated_opt')}</option>
                            <option value="0">{t('manage_not_activated_opt')}</option>
                        </Select>
                    </FormField>
                    <p className="-mt-1 text-xs text-muted-foreground">{t('manage_inactive_login_hint')}</p>

                    <FormField label={t('manage_role_col')} htmlFor="is_admin" error={form.errors.is_admin}>
                        <Select id="is_admin" value={String(form.data.is_admin)} onChange={(e) => form.setData('is_admin', Number(e.target.value))}>
                            {ROLE_OPTIONS.map((r) => (
                                <option key={r.value} value={r.value}>{roleLabel(r.label)}</option>
                            ))}
                        </Select>
                    </FormField>

                    <div className="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground">
                        <div className="mb-1 font-medium">{t('manage_role_desc_title')}</div>
                        <ul className="list-disc space-y-1 pl-4">
                            {ROLE_DESCRIPTIONS.map((role) => (
                                <li key={role.key}>
                                    <span className="font-medium">{t(role.title)}</span>
                                    {' - '}
                                    <span>{t(role.body)}</span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={form.processing}>{tc('save_changes')}</Button>
                        <a href={urls.index} className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted">
                            {tc('cancel')}
                        </a>
                    </div>

                    <div className="mt-2 rounded-md border border-destructive/40 bg-destructive/5 p-3">
                        <div className="mb-1 text-sm font-medium text-destructive">{t('manage_dangerous_ops')}</div>
                        <p className="mb-2 text-xs text-muted-foreground">{t('manage_delete_warning')}</p>
                        <Button type="button" variant="destructive" disabled={form.processing} onClick={() => setConfirmDelete(true)}>
                            {t('manage_delete_user')}
                        </Button>
                    </div>
                </form>
            </div>

            {/* 第一段：說明此操作不可恢復（對齊舊版 manage_confirm_delete_1）。 */}
            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={t('manage_delete_user')}
                // 翻譯字串本身帶 \n\n（舊版走 window.confirm，空行就是重點強調），
                // HTML 會把換行摺成空白，故用 whitespace-pre-line 保住斷行。
                description={<span className="whitespace-pre-line">{t('manage_confirm_delete_1')}</span>}
                confirmLabel={tc('continue')}
                cancelLabel={tc('cancel')}
                destructive
                onConfirm={() => {
                    setConfirmDelete(false);
                    setConfirmDeleteFinal(true);
                }}
            />

            {/* 第二段：最後確認才真的送出（對齊舊版 manage_confirm_delete_2）。 */}
            <ConfirmDialog
                open={confirmDeleteFinal}
                onOpenChange={setConfirmDeleteFinal}
                title={t('manage_delete_user')}
                description={t('manage_confirm_delete_2')}
                confirmLabel={tc('delete')}
                cancelLabel={tc('cancel')}
                destructive
                // 不傳 loading：onConfirm 會先關掉這個對話框再送出，loading 態永遠不會被看到。
                // 送出期間的重複點擊由表單裡「刪除此用戶」鈕的 disabled={form.processing} 擋住。
                onConfirm={() => {
                    setConfirmDeleteFinal(false);
                    submitDelete();
                }}
            />
        </DashboardLayout>
    );
}
