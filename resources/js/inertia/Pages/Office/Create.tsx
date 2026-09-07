import React from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Overlay, ResubmitInfo } from '../../components/EntityBrowser/entityForm';
import { useTranslation } from '../../hooks/useTranslation';
import OfficeForm, { OfficeInitialLabels, OfficeUrls } from './OfficeForm';

interface Props {
    urls: OfficeUrls;
    initial_labels: OfficeInitialLabels;
    can_edit?: boolean;
    can_propose?: boolean;
    /** 修改提案模式（?proposal={id}）：提案內容與 resubmit 端點；否則為空物件。 */
    proposal_overlay: Overlay;
    resubmit: ResubmitInfo;
    [key: string]: unknown;
}

export default function OfficeCreate() {
    const { urls, initial_labels, can_edit = true, can_propose = false, proposal_overlay, resubmit } = usePage<Props>().props;
    const t = useTranslation('office');

    return (
        <DashboardLayout title={t('page_title_create')}>
            <div className="max-w-2xl rounded-lg border border-border bg-card p-4">
                <a href={urls.index} className="mb-3 inline-block text-sm text-primary hover:underline">
                    ← {t('btn_back')}
                </a>
                <OfficeForm
                    mode="create"
                    initial={{
                        name: '',
                        name_alt: null,
                        translation: null,
                        translation_alt: null,
                        pinyin: null,
                        pinyin_alt: null,
                        dynasty_code: null,
                        source_id: null,
                        pages: null,
                        notes: null,
                        type_ids: [],
                    }}
                    initialLabels={initial_labels}
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
