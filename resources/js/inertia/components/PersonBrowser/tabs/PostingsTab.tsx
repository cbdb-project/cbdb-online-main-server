import React from 'react';
import TabCard from '../shared/TabCard';
import MetaRow from '../shared/MetaRow';
import TabPager from '../shared/TabPager';
import EmptyState from '../shared/EmptyState';
import LegacyCreateButton from '../shared/LegacyCreateButton';
import LegacyEditButton from '../shared/LegacyEditButton';
import { useTabPager } from '../shared/useTabPager';
import { formatBilingualLabel, formatYearRange } from '../shared/formatters';
import { stableKey } from '../shared/stableKey';
import { formatTextTitle } from '../shared/textLookup';
import { useTextCodes } from '../shared/useTextCodes';

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
    canEdit: boolean;
    postCE?: boolean;
}

export default function PostingsTab({ data, canEdit, postCE }: Props) {
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.source_id));

    return (
        <div style={containerStyle}>
            <LegacyCreateButton tabKey="postings" canEdit={canEdit} />
            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label="序號" value={item.sequence ?? '—'} />
                    <MetaRow label="官職 ID" value={item.office_id} />
                    <MetaRow label="官名" value={formatBilingualLabel(item.office_chn, item.office)} />
                    <MetaRow label="任期" value={item.tenure_summary} />
                    <MetaRow label="時間範圍" value={formatYearRange(item.first_year, item.last_year, postCE)} />
                    <MetaRow label="地址" value={item.address_summary} />
                    <MetaRow label="出處" value={formatTextTitle(textRecords[item.source_id ?? 0], item.source_id)} />
                    <MetaRow label="頁碼" value={item.pages} />
                    <MetaRow label="備註" value={item.notes} />
                    <LegacyEditButton tabKey="postings" pk={item.pk} canEdit={canEdit} />
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
