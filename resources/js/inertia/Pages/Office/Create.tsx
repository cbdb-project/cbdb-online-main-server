import React from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { useTranslation } from '../../hooks/useTranslation';
import OfficeForm, { OfficeUrls } from './OfficeForm';

interface Props {
    urls: OfficeUrls;
    [key: string]: unknown;
}

export default function OfficeCreate() {
    const { urls } = usePage<Props>().props;
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
                    initialLabels={{ dynasty: null, source: null, types: {} }}
                    urls={urls}
                />
            </div>
        </DashboardLayout>
    );
}
