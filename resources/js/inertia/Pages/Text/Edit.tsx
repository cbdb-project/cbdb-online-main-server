import React, { useState } from 'react';
import { usePage, router } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Overlay, ResubmitInfo } from '../../components/EntityBrowser/entityForm';
import { getCsrfToken } from '../../components/PersonBrowser/shared/csrf';
import { Button } from '../../components/ui/Button';
import { useTranslation } from '../../hooks/useTranslation';
import TextForm, { ExtantOption, TextAggregate, TextInitialLabels, TextUrls } from './TextForm';

interface Props {
    text: TextAggregate;
    reference_count: number;
    initial_labels: TextInitialLabels;
    extant_options: ExtantOption[];
    urls: TextUrls;
    can_edit?: boolean;
    can_propose?: boolean;
    /** 修改提案模式（?proposal={id}）：提案內容與 resubmit 端點；否則為空物件。 */
    proposal_overlay: Overlay;
    resubmit: ResubmitInfo;
    [key: string]: unknown;
}

export default function TextEdit() {
    const { text, reference_count, initial_labels, extant_options, urls, can_edit = true, can_propose = false, proposal_overlay, resubmit } = usePage<Props>().props;
    const isResubmit = !!resubmit?.resubmit_proposal_id;
    const t = useTranslation('text_entity');
    const [busy, setBusy] = useState(false);
    const [err, setErr] = useState<string | null>(null);

    const deleteLocked = reference_count > 0;

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
                body: JSON.stringify({ resource: 'text-entity', person_id: 0, target: { pk: { c_textid: text.textid } } }),
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
        <DashboardLayout title={`${t('page_title_edit')} #${text.textid}`}>
            <div className="max-w-3xl space-y-4 rounded-lg border border-border bg-card p-4">
                <a href={urls.index} className="inline-block text-sm text-primary hover:underline">
                    ← {t('btn_back')}
                </a>
                {err && <div className="rounded border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">{err}</div>}
                <TextForm
                    mode="edit"
                    textId={text.textid}
                    initial={text}
                    initialLabels={initial_labels}
                    extantOptions={extant_options}
                    urls={urls}
                    canEdit={can_edit}
                    canPropose={can_propose}
                    overlay={proposal_overlay}
                    resubmit={resubmit}
                />
                {can_edit && !isResubmit && (
                    <div className="border-t border-border pt-3">
                        <Button type="button" variant="destructive" disabled={busy || deleteLocked} onClick={del}>
                            {t('btn_delete')}
                        </Button>
                        {deleteLocked && (
                            <p className="mt-1 text-xs text-muted-foreground">{t('delete_locked_hint', { n: String(reference_count) })}</p>
                        )}
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
}
