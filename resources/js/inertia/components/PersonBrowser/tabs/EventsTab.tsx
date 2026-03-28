import React from 'react';
import TabCard from '../shared/TabCard';
import MetaRow from '../shared/MetaRow';
import TabPager from '../shared/TabPager';
import EmptyState from '../shared/EmptyState';
import { useTabPager } from '../shared/useTabPager';
import { formatBilingualLabel } from '../shared/formatters';

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
}

export default function EventsTab({ data }: Props) {
    const { pageItems, currentPage, totalPages, setCurrentPage } = useTabPager(data.items);

    if (data.items.length === 0) {
        return <EmptyState />;
    }

    return (
        <div style={containerStyle}>
            {pageItems.map((item, i) => (
                <TabCard key={i}>
                    <MetaRow label="事件" value={formatBilingualLabel(item.event_chn, item.event)} />
                    <MetaRow label="日期" value={item.date_summary} />
                    <MetaRow label="出處" value={item.source_id} />
                    <MetaRow label="頁碼" value={item.pages} />
                    <MetaRow label="備註" value={item.notes} />
                </TabCard>
            ))}
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} />
        </div>
    );
}

const containerStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 8,
};
