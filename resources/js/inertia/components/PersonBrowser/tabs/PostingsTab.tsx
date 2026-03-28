import React from 'react';
import TabCard from '../shared/TabCard';
import MetaRow from '../shared/MetaRow';
import TabPager from '../shared/TabPager';
import EmptyState from '../shared/EmptyState';
import { useTabPager } from '../shared/useTabPager';
import { formatBilingualLabel } from '../shared/formatters';
import { stableKey } from '../shared/stableKey';

interface PostingItem {
    pk: {
        c_office_id: number | null;
        c_posting_id: number | null;
    };
    sequence: number | null;
    office_id: number | null;
    posting_id: number | null;
    office_chn: string | null;
    office: string | null;
    first_year: number | null;
    last_year: number | null;
    tenure_summary: string | null;
    addresses: Array<{ addr_chn: string | null; addr: string | null }>;
    address_summary: string | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: PostingItem[] };
}

export default function PostingsTab({ data }: Props) {
    const { pageItems, currentPage, totalPages, setCurrentPage } = useTabPager(data.items);

    if (data.items.length === 0) {
        return <EmptyState />;
    }

    return (
        <div style={containerStyle}>
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label="官名" value={formatBilingualLabel(item.office_chn, item.office)} />
                    <MetaRow label="任期" value={item.tenure_summary} />
                    <MetaRow label="地址" value={item.address_summary} />
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
