import React, { useState } from 'react';
import { usePage, router } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { getCsrfToken } from '../../components/PersonBrowser/shared/csrf';
import { Button } from '../../components/ui/Button';
import { useTranslation } from '../../hooks/useTranslation';
import OfficeForm, { OfficeUrls, OfficeInitialLabels } from './OfficeForm';

interface OfficeAggregate {
    office_id: number;
    name: string;
    name_alt: string | null;
    translation: string | null;
    translation_alt: string | null;
    pinyin: string;
    pinyin_alt: string | null;
    dynasty_code: number | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
    type_ids: string[];
}

interface Props {
    office: OfficeAggregate;
    initial_labels: OfficeInitialLabels;
    urls: OfficeUrls;
    can_edit?: boolean;
    can_propose?: boolean;
    [key: string]: unknown;
}

export default function OfficeEdit() {
    const { office, initial_labels, urls, can_edit = true, can_propose = false } = usePage<Props>().props;
    const t = useTranslation('office');
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState<string | null>(null);

    const del = async () => {
        if (!window.confirm(t('delete_confirm'))) return;
        setBusy(true);
        setErr(null);
        try {
            const res = await fetch(urls.api_delete, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'office', person_id: 0, target: { pk: { c_office_id: office.office_id } } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                setErr(res.status === 409 ? t('delete_blocked') : (json?.message ?? t('save_failed')));
                setBusy(false);
                return;
            }
            router.visit(urls.index);
        } catch (e) {
            setErr(String(e));
            setBusy(false);
        }
    };

    return (
        <DashboardLayout title={`${t('page_title_edit')} #${office.office_id}`}>
            <div className="max-w-2xl space-y-4 rounded-lg border border-border bg-card p-4">
                <a href={urls.index} className="inline-block text-sm text-primary hover:underline">
                    ← {t('btn_back')}
                </a>
                {err && <div className="rounded border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">{err}</div>}
                <OfficeForm
                    mode="edit"
                    officeId={office.office_id}
                    initial={{
                        name: office.name,
                        name_alt: office.name_alt,
                        translation: office.translation,
                        translation_alt: office.translation_alt,
                        pinyin: office.pinyin,
                        pinyin_alt: office.pinyin_alt,
                        dynasty_code: office.dynasty_code,
                        source_id: office.source_id,
                        pages: office.pages,
                        notes: office.notes,
                        type_ids: office.type_ids,
                    }}
                    initialLabels={initial_labels}
                    urls={urls}
                    canEdit={can_edit}
                    canPropose={can_propose}
                />
                {can_edit && (
                    <div className="border-t border-border pt-3">
                        <Button type="button" variant="destructive" disabled={busy} onClick={del}>
                            {t('btn_delete')}
                        </Button>
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
}
