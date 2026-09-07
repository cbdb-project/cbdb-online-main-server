import React from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Overlay, ResubmitInfo } from '../../components/EntityBrowser/entityForm';
import { useTranslation } from '../../hooks/useTranslation';
import TextForm, { ExtantOption, TextInitialLabels, TextUrls } from './TextForm';

interface Props {
    extant_options: ExtantOption[];
    initial_labels: TextInitialLabels;
    urls: TextUrls;
    can_edit?: boolean;
    can_propose?: boolean;
    /** 修改提案模式（?proposal={id}）：提案內容與 resubmit 端點；否則為空物件。 */
    proposal_overlay: Overlay;
    resubmit: ResubmitInfo;
    [key: string]: unknown;
}

export default function TextCreate() {
    const { extant_options, initial_labels, urls, can_edit = true, can_propose = false, proposal_overlay, resubmit } = usePage<Props>().props;
    const t = useTranslation('text_entity');

    return (
        <DashboardLayout title={t('page_title_create')}>
            <div className="max-w-2xl rounded-lg border border-border bg-card p-4">
                <a href={urls.index} className="mb-3 inline-block text-sm text-primary hover:underline">
                    ← {t('btn_back')}
                </a>
                <TextForm
                    mode="create"
                    initial={null}
                    initialLabels={initial_labels}
                    extantOptions={extant_options}
                    urls={urls}
                    canEdit={can_edit}
                    canPropose={can_propose}
                    overlay={proposal_overlay}
                    resubmit={resubmit}
                />
            </div>
        </DashboardLayout>
    );
}
