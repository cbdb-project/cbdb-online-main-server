import React from 'react';
import TabCard from '../shared/TabCard';
import MetaRow from '../shared/MetaRow';
import TabPager from '../shared/TabPager';
import EmptyState from '../shared/EmptyState';
import LegacyCreateButton from '../shared/LegacyCreateButton';
import LegacyEditButton from '../shared/LegacyEditButton';
import { useTabPager } from '../shared/useTabPager';
import { formatBilingualLabel } from '../shared/formatters';
import { stableKey } from '../shared/stableKey';
import { formatTextTitle } from '../shared/textLookup';
import { useTextCodes } from '../shared/useTextCodes';

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
    const { pageItems, currentPage, totalPages, setCurrentPage } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.source_id));

    return (
        <div style={containerStyle}>
            <LegacyCreateButton tabKey="possessions" canEdit={canEdit} />
            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label="記錄 ID" value={item.pk.c_possession_record_id} />
                    <MetaRow label="財產" value={formatBilingualLabel(item.desc_chn, item.desc)} />
                    <MetaRow label="行為" value={formatBilingualLabel(item.act_chn, item.act)} />
                    <MetaRow label="數量" value={item.quantity} />
                    <MetaRow label="年份" value={item.year} />
                    <MetaRow label="出處" value={formatTextTitle(textRecords[item.source_id ?? 0], item.source_id)} />
                    <MetaRow label="頁碼" value={item.pages} />
                    <MetaRow label="備註" value={item.notes} />
                    <LegacyEditButton tabKey="possessions" pk={item.pk} canEdit={canEdit} />
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
