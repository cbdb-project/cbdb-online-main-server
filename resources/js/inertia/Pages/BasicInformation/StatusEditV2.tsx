import React from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import StatusEditor from '../../components/StatusEditor';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

type Fields = Record<string, string>;

interface PageProps extends SharedProps {
    person_id: number;
    person_label: string;
    dynasty_code: number | null;
    edit_mode: 'create' | 'edit';
    initial_fields: Fields;
    initial_labels: Fields;
    can_edit: boolean;
    can_propose: boolean;
    ai_enabled: boolean;
    ai_model: string;
    ai_suggest_endpoint: string;
    create_endpoint: string;
    mutate_endpoint: string;
    delete_endpoint: string;
    index_url: string;
    route_name: string;
}

export default function StatusEditV2() {
    const p = usePage<PageProps>().props;
    const tb = useTranslation('biogmains');
    const tp = useTranslation('person');
    const tc = useTranslation('common');
    const t = (k: string) => {
        const v = tb(k);
        if (v && v !== k) return v;
        const v2 = tp(k);
        if (v2 && v2 !== k) return v2;
        const v3 = tc(k);
        return v3 && v3 !== k ? v3 : k;
    };

    return (
        <DashboardLayout title={p.person_label} breadcrumbs={[{ label: p.person_label }]}>
            <StatusEditor
                personId={p.person_id}
                personLabel={p.person_label}
                dynastyCode={p.dynasty_code}
                mode={p.edit_mode}
                initialFields={p.initial_fields}
                initialLabels={p.initial_labels}
                canEdit={p.can_edit}
                canPropose={p.can_propose}
                aiEnabled={p.ai_enabled}
                aiModel={p.ai_model}
                aiSuggestEndpoint={p.ai_suggest_endpoint}
                createEndpoint={p.create_endpoint}
                mutateEndpoint={p.mutate_endpoint}
                deleteEndpoint={p.delete_endpoint}
                indexUrl={p.index_url}
                routeName={p.route_name}
                t={t}
            />
        </DashboardLayout>
    );
}
