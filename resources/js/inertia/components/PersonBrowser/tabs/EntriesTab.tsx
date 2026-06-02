import React from 'react';
import TabCard from '../shared/TabCard';
import MetaRow from '../shared/MetaRow';
import TabPager from '../shared/TabPager';
import EmptyState from '../shared/EmptyState';
import LegacyCreateButton from '../shared/LegacyCreateButton';
import LegacyEditButton from '../shared/LegacyEditButton';
import LegacyDeleteButton from '../shared/LegacyDeleteButton';
import CardActions from '../shared/CardActions';
import { useTabPager } from '../shared/useTabPager';
import { formatBilingualLabel } from '../shared/formatters';
import { stableKey } from '../shared/stableKey';
import { useTranslation } from '../../../hooks/useTranslation';

interface EntryItem {
    pk: {
        c_personid: number;
        c_entry_code: number | null;
        c_sequence: number | null;
        c_kin_code: number | null;
        c_assoc_code: number | null;
        c_kin_id: number | null;
        c_year: number | null;
        c_assoc_id: number | null;
        c_inst_code: number | null;
        c_inst_name_code: number | null;
    };
    sequence: number | null;
    entry_code: number | null;
    entry_desc_chn: string | null;
    entry_desc: string | null;
    year: number | null;
    kin_id: number | null;
    kin_summary: string | null;
    assoc_id: number | null;
    assoc_summary: string | null;
}

interface Props {
    data: { tab: string; items: EntryItem[] };
    canEdit: boolean;
}

export default function EntriesTab({ data, canEdit }: Props) {
    const t = useTranslation('person');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);

    return (
        <div style={containerStyle}>
            <LegacyCreateButton tabKey="entries" canEdit={canEdit} />
            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label={t('seq_no')} value={item.sequence ?? '—'} />
                    <MetaRow label={t('entry_type_label')} value={formatBilingualLabel(item.entry_desc_chn, item.entry_desc)} />
                    <MetaRow label={t('entry_code_label')} value={item.entry_code} />
                    <MetaRow label={t('year_label')} value={item.year} />
                    <MetaRow label={t('kin_relation')} value={item.kin_summary} />
                    <MetaRow label={t('social_relation')} value={item.assoc_summary} />
                    <CardActions>
                        <LegacyEditButton tabKey="entries" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="entries" pk={item.pk} canEdit={canEdit} />
                    </CardActions>
                </TabCard>
            ))}
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} showAll={showAll} onToggleShowAll={() => setShowAll(!showAll)} totalItems={totalItems} />
        </div>
    );
}

const containerStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 8,
};
