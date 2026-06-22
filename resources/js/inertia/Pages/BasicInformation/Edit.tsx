import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Button } from '../../components/ui/Button';
import { ConfirmDialog } from '../../components/ui/ConfirmDialog';
import BasicInfoView from '../../components/PersonBrowser/BasicInfoView';
import { getCsrfToken } from '../../components/PersonBrowser/shared/csrf';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

interface PersonProps {
    person_id: number;
    sections: Array<{ title: string; fields: Array<{ label: string; value: unknown }> }>;
    form: {
        person_id: number;
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        fields: Record<string, any>;
    } | null;
    name_chn: string;
    name: string;
}

interface BasicInfoEditPageProps extends SharedProps {
    person: PersonProps;
    person_label: string;
    can_edit: boolean;
    can_propose: boolean;
    mutate_endpoint: string;
    delete_endpoint: string;
    pinyin_endpoint: string;
    index_url: string;
    show_url: string;
}

export default function BasicInformationEdit() {
    const props = usePage<BasicInfoEditPageProps>().props;
    const {
        person, person_label, can_edit, can_propose,
        mutate_endpoint, delete_endpoint, pinyin_endpoint, index_url, show_url,
    } = props;
    const t = useTranslation('person');
    const tc = useTranslation('common');

    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // BIOG_MAIN 刪除（軟刪除）走 /api/v2/delete（direct）。proposal-only 用戶（眾包）
    // 後端對 delete proposal 回 501，故僅 can_edit 者顯示刪除入口。
    const doDelete = async () => {
        setDeleting(true);
        setDeleteError(null);
        try {
            const response = await fetch(delete_endpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    resource: 'basicinformation',
                    person_id: person.person_id,
                    mode: 'direct',
                    operation: 'delete',
                    target: { pk: { c_personid: person.person_id } },
                }),
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok || !json?.ok) {
                throw new Error(json?.message || `${tc('delete')} (HTTP ${response.status})`);
            }
            setConfirmDelete(false);
            router.visit(index_url);
        } catch (err) {
            setDeleteError(err instanceof Error ? err.message : tc('delete'));
        } finally {
            setDeleting(false);
        }
    };

    return (
        <DashboardLayout
            title={`${tc('edit')} — ${person_label}`}
            breadcrumbs={[
                { label: t('person_records'), url: index_url },
                { label: person_label, url: show_url },
                { label: tc('edit') },
            ]}
        >
            {deleteError && (
                <div className="mb-3 rounded border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                    {deleteError}
                </div>
            )}

            <BasicInfoView
                sections={person.sections}
                form={person.form}
                personId={person.person_id}
                mutateEndpoint={mutate_endpoint}
                pinyinEndpoint={pinyin_endpoint}
                canEdit={can_edit}
                hideDelete
                onSaved={() => router.reload({ only: ['person'] })}
            />

            <div className="mt-4 flex flex-wrap items-center gap-2">
                <a
                    href={show_url}
                    className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted"
                >
                    {tc('view')}
                </a>
                {can_edit && (
                    <Button type="button" variant="destructive" disabled={deleting} onClick={() => setConfirmDelete(true)}>
                        {t('delete_person')}
                    </Button>
                )}
                {!can_edit && can_propose && (
                    <span className="text-sm text-muted-foreground">{t('save_hint')}</span>
                )}
            </div>

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={(o) => !o && setConfirmDelete(false)}
                title={t('delete_person')}
                description={t('delete_confirm')}
                confirmLabel={tc('delete')}
                cancelLabel={tc('cancel')}
                destructive
                loading={deleting}
                onConfirm={doDelete}
            />
        </DashboardLayout>
    );
}
