import React from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import BasicInfoEditor from '../../components/BasicInfoEditor';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

/**
 * Task 27 重做：基本資料編輯器頁（對齊 legacy /basicinformation/{id}/edit）。
 * 渲染 BasicInfoEditor（不依賴 person-browser）。獨立驗證頁，整合前不上線。
 */
interface PageProps extends SharedProps {
    personId: number;
    person_label: string;
    initial_fields: Record<string, unknown>;
    initial_labels: Record<string, string>;
    can_edit: boolean;
    can_propose: boolean;
    mutate_endpoint: string;
    pinyin_endpoint: string;
}

export default function EditV2() {
    const p = usePage<PageProps>().props;
    const t = useTranslation('person');

    const fields: Record<string, string> = {};
    for (const [k, v] of Object.entries(p.initial_fields || {})) {
        fields[k] = v == null ? '' : String(v);
    }

    return (
        <DashboardLayout title={p.person_label} breadcrumbs={[{ label: p.person_label }]}>
            <BasicInfoEditor
                personId={p.personId}
                personLabel={p.person_label}
                initialFields={fields}
                initialLabels={p.initial_labels || {}}
                canEdit={p.can_edit}
                canPropose={p.can_propose}
                mutateEndpoint={p.mutate_endpoint}
                pinyinEndpoint={p.pinyin_endpoint}
                t={(k) => t(k)}
            />
        </DashboardLayout>
    );
}
