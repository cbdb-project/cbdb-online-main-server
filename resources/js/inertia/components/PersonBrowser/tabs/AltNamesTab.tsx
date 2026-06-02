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
import { formatTextTitle } from '../shared/textLookup';
import { useTextCodes } from '../shared/useTextCodes';
import { useTranslation } from '../../../hooks/useTranslation';

interface AltNameItem {
    pk: {
        c_personid: number;
        c_alt_name_chn: string | null;
        c_alt_name_type_code: number | null;
    };
    sequence: number | null;
    name_chn: string | null;
    name: string | null;
    type_code: number | null;
    type_label_chn: string | null;
    type_label: string | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: AltNameItem[] };
    canEdit: boolean;
}

export default function AltNamesTab({ data, canEdit }: Props) {
    const t = useTranslation('person');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.source_id));

    return (
        <div style={containerStyle}>
            <LegacyCreateButton tabKey="alt_names" canEdit={canEdit} />
            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label={t('seq_no')} value={item.sequence ?? '—'} />
                    <MetaRow label={t('alt_name')} value={formatBilingualLabel(item.name_chn, item.name)} />
                    <MetaRow label={t('type_label')} value={formatBilingualLabel(item.type_label_chn, item.type_label)} />
                    <MetaRow label={t('source_label')} value={formatTextTitle(textRecords[item.source_id ?? 0], item.source_id)} />
                    <MetaRow label={t('pages_label')} value={item.pages} />
                    <MetaRow label={t('remarks')} value={item.notes} />
                    <CardActions>
                        <LegacyEditButton tabKey="alt_names" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="alt_names" pk={item.pk} canEdit={canEdit} />
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
