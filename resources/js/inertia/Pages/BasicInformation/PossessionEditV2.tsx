import React from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import PossessionEditor from '../../components/PossessionEditor';
import PersonBanner, { PersonBannerData } from '../../components/PersonEditorShared/PersonBanner';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

type Fields = Record<string, string>;
interface AddrItem { id: string; label: string }

interface PageProps extends SharedProps {
    person_id: number;
    person_label: string;
    dynasty_code: number | null;
    dynasty_start: string;
    dynasty_end: string;
    edit_mode: 'create' | 'edit';
    initial_fields: Fields;
    initial_labels: Fields;
    initial_addr: AddrItem[];
    can_edit: boolean;
    can_propose: boolean;
    create_endpoint: string;
    mutate_endpoint: string;
    delete_endpoint: string;
    index_url: string;
    person_banner: PersonBannerData;
}

export default function PossessionEditV2() {
    const p = usePage<PageProps>().props;
    const tb = useTranslation('biogmains');
    const tc = useTranslation('common');
    const t = (k: string) => {
        const v = tb(k);
        if (v && v !== k) return v;
        const v2 = tc(k);
        return v2 && v2 !== k ? v2 : k;
    };

    return (
        <DashboardLayout title={p.person_label} headerAlign="center">
            <PersonBanner data={p.person_banner} />
            <PossessionEditor
                personId={p.person_id}
                personLabel={p.person_label}
                dynastyCode={p.dynasty_code}
                dynastyStart={p.dynasty_start}
                dynastyEnd={p.dynasty_end}
                mode={p.edit_mode}
                initialFields={p.initial_fields}
                initialLabels={p.initial_labels}
                initialAddr={p.initial_addr}
                canEdit={p.can_edit}
                canPropose={p.can_propose}
                createEndpoint={p.create_endpoint}
                mutateEndpoint={p.mutate_endpoint}
                deleteEndpoint={p.delete_endpoint}
                indexUrl={p.index_url}
                t={t}
            />
        </DashboardLayout>
    );
}
