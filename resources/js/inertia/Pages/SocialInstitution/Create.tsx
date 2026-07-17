import React from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { useTranslation } from '../../hooks/useTranslation';
import InstitutionForm, { InstitutionUrls, TypeOption } from './InstitutionForm';

interface Props {
    type_options: TypeOption[];
    urls: InstitutionUrls;
    [key: string]: unknown;
}

export default function SocialInstitutionCreate() {
    const { type_options, urls } = usePage<Props>().props;
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
                    initialLabels={{ dynasties: {}, source: null, addresses: {} }}
                    typeOptions={type_options}
                    urls={urls}
                />
            </div>
        </DashboardLayout>
    );
}
