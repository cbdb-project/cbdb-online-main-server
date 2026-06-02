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

interface EventItem {
    pk: {
        c_personid: number;
        c_sequence: number | null;
        c_event_code: number | null;
    };
    sequence: number | null;
    event_code: number | null;
    event_chn: string | null;
    event: string | null;
    year: number | null;
    month: number | null;
    day: number | null;
    date_summary: string | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: EventItem[] };
    canEdit: boolean;
}

export default function EventsTab({ data, canEdit }: Props) {
    const t = useTranslation('person');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.source_id));

    return (
        <div style={containerStyle}>
            <LegacyCreateButton tabKey="events" canEdit={canEdit} />
            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label={t('seq_no')} value={item.sequence ?? '—'} />
                    <MetaRow label={t('event_label')} value={formatBilingualLabel(item.event_chn, item.event)} />
                    <MetaRow label={t('event_code')} value={item.event_code} />
                    <MetaRow label={t('date_label')} value={item.date_summary} />
                    <MetaRow label={t('source_label')} value={formatTextTitle(textRecords[item.source_id ?? 0], item.source_id)} />
                    <MetaRow label={t('pages_label')} value={item.pages} />
                    <MetaRow label={t('remarks')} value={item.notes} />
                    <CardActions>
                        <LegacyEditButton tabKey="events" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="events" pk={item.pk} canEdit={canEdit} />
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
