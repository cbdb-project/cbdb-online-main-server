import React from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import BasicInfoEditor from '../../components/BasicInfoEditor';
import PersonBanner, { PersonBannerData } from '../../components/PersonEditorShared/PersonBanner';
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
    delete_endpoint: string;
    pinyin_endpoint: string;
    index_url: string;
    breadcrumbs: Array<{ label: string; url?: string }>;
    person_banner: PersonBannerData;
    duplicate_collateral_url: string;
    saveas_url: string;
}

export default function EditV2() {
    const p = usePage<PageProps>().props;
    // BasicInfoEditor 的標籤主要在 biogmains 命名空間（block_*/audit_*/欄位名等），輔以 person/common。
    // 須與 TabContentLoader 內嵌版一致用 biogmains→person→common 鏈，否則獨立編輯頁（尤其 en）會落回中文 fallback。
    const tBio = useTranslation('biogmains');
    const tPerson = useTranslation('person');
    const tCommon = useTranslation('common');
    const t = (k: string): string => {
        const v = tBio(k); if (v && v !== k) return v;
        const v2 = tPerson(k); if (v2 && v2 !== k) return v2;
        const v3 = tCommon(k); return v3 && v3 !== k ? v3 : k;
    };

    const fields: Record<string, string> = {};
    for (const [k, v] of Object.entries(p.initial_fields || {})) {
        fields[k] = v == null ? '' : String(v);
    }

    return (
        <DashboardLayout title={p.person_label} headerAlign="center" breadcrumbs={p.breadcrumbs}>
            <PersonBanner data={p.person_banner} />
            <BasicInfoEditor
                personId={p.personId}
                personLabel={p.person_label}
                initialFields={fields}
                initialLabels={p.initial_labels || {}}
                canEdit={p.can_edit}
                canPropose={p.can_propose}
                mutateEndpoint={p.mutate_endpoint}
                deleteEndpoint={p.delete_endpoint}
                pinyinEndpoint={p.pinyin_endpoint}
                indexUrl={p.index_url}
                duplicateCollateralUrl={p.duplicate_collateral_url}
                saveasUrl={p.saveas_url}
                t={(k) => t(k)}
            />
        </DashboardLayout>
    );
}
