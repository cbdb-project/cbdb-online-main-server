import React from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { useTranslation } from '../../hooks/useTranslation';
import TextForm, { ExtantOption, TextUrls } from './TextForm';

interface Props {
    extant_options: ExtantOption[];
    urls: TextUrls;
    [key: string]: unknown;
}

export default function TextCreate() {
    const { extant_options, urls } = usePage<Props>().props;
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
                    initialLabels={{ dynasties: {}, source: null }}
                    extantOptions={extant_options}
                    urls={urls}
                />
            </div>
        </DashboardLayout>
    );
}
