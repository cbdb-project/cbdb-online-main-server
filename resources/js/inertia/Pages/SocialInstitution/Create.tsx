import React from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Overlay, ResubmitInfo } from '../../components/EntityBrowser/entityForm';
import { useTranslation } from '../../hooks/useTranslation';
import InstitutionForm, { InstitutionInitialLabels, InstitutionUrls, TypeOption } from './InstitutionForm';

interface Props {
    type_options: TypeOption[];
    initial_labels: InstitutionInitialLabels;
    urls: InstitutionUrls;
    can_edit?: boolean;
    can_propose?: boolean;
    /** 修改提案模式（?proposal={id}）：提案內容與 resubmit 端點；否則為空物件。 */
    proposal_overlay: Overlay;
    resubmit: ResubmitInfo;
    [key: string]: unknown;
}

export default function SocialInstitutionCreate() {
    const { type_options, initial_labels, urls, can_edit = true, can_propose = false, proposal_overlay, resubmit } = usePage<Props>().props;
    const t = useTranslation('social_institution');

    return (
        <DashboardLayout title={t('page_title_create')}>
            <div className="max-w-2xl rounded-lg border border-border bg-card p-4">
                <a href={urls.index} className="mb-3 inline-block text-sm text-primary hover:underline">
                    ← {t('btn_back')}
                </a>
                <InstitutionForm
                    mode="create"
                    initial={null}
                    initialLabels={initial_labels}
                    typeOptions={type_options}
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
