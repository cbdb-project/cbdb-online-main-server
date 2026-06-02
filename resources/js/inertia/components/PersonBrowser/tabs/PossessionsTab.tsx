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

interface PossessionItem {
    pk: {
        c_possession_record_id: number | null;
    };
    act_code: number | null;
    act_chn: string | null;
    act: string | null;
    desc_chn: string | null;
    desc: string | null;
    quantity: string | null;
    year: number | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: PossessionItem[] };
    canEdit: boolean;
}

export default function PossessionsTab({ data, canEdit }: Props) {
    const t = useTranslation('person');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.source_id));

    return (
        <div style={containerStyle}>
            <LegacyCreateButton tabKey="possessions" canEdit={canEdit} />
            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label={t('possession_record_id')} value={item.pk.c_possession_record_id} />
                    <MetaRow label={t('possession_label')} value={formatBilingualLabel(item.desc_chn, item.desc)} />
                    <MetaRow label={t('action_label')} value={formatBilingualLabel(item.act_chn, item.act)} />
                    <MetaRow label={t('quantity_label')} value={item.quantity} />
                    <MetaRow label={t('year_label')} value={item.year} />
                    <MetaRow label={t('source_label')} value={formatTextTitle(textRecords[item.source_id ?? 0], item.source_id)} />
                    <MetaRow label={t('pages_label')} value={item.pages} />
                    <MetaRow label={t('remarks')} value={item.notes} />
                    <CardActions>
                        <LegacyEditButton tabKey="possessions" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="possessions" pk={item.pk} canEdit={canEdit} />
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
