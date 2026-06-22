import React from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import BasicInfoView from '../../components/PersonBrowser/BasicInfoView';
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

interface BasicInfoShowPageProps extends SharedProps {
    person: PersonProps;
    person_label: string;
    can_edit: boolean;
    mutate_endpoint: string;
    pinyin_endpoint: string;
    index_url: string;
    edit_url: string;
}

export default function BasicInformationShow() {
    const props = usePage<BasicInfoShowPageProps>().props;
    const { person, person_label, can_edit, mutate_endpoint, pinyin_endpoint, index_url, edit_url } = props;
    const t = useTranslation('person');
    const tc = useTranslation('common');

    return (
        <DashboardLayout
            title={person_label}
            breadcrumbs={[
                { label: t('person_records'), url: index_url },
                { label: person_label },
            ]}
        >
            {can_edit && (
                <div className="mb-3 flex justify-end">
                    <a
                        href={edit_url}
                        className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted"
                    >
                        {tc('edit')}
                    </a>
                </div>
            )}

            {/* canEdit=false → 純唯讀呈現（不顯示編輯/刪除按鈕）。 */}
            <BasicInfoView
                sections={person.sections}
                form={person.form}
                personId={person.person_id}
                mutateEndpoint={mutate_endpoint}
                pinyinEndpoint={pinyin_endpoint}
                canEdit={false}
            />
        </DashboardLayout>
    );
}
