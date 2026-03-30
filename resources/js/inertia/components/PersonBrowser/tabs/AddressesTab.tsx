import React from 'react';
import TabCard from '../shared/TabCard';
import MetaRow from '../shared/MetaRow';
import TabPager from '../shared/TabPager';
import EmptyState from '../shared/EmptyState';
import LegacyEditButton from '../shared/LegacyEditButton';
import { useTabPager } from '../shared/useTabPager';
import { formatBilingualLabel, formatYearRange } from '../shared/formatters';
import { stableKey } from '../shared/stableKey';

interface AddressItem {
    pk: {
        c_personid: number;
        c_addr_id: number | null;
        c_addr_type: number | null;
        c_sequence: number | null;
    };
    sequence: number | null;
    addr_id: number | null;
    addr_chn: string | null;
    addr: string | null;
    type_code: number | null;
    type_label_chn: string | null;
    type_label: string | null;
    first_year: number | null;
    last_year: number | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: AddressItem[] };
}

export default function AddressesTab({ data }: Props) {
    const { pageItems, currentPage, totalPages, setCurrentPage } = useTabPager(data.items);

    if (data.items.length === 0) {
        return <EmptyState />;
    }

    return (
        <div style={containerStyle}>
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label="地址" value={formatBilingualLabel(item.addr_chn, item.addr)} />
                    <MetaRow label="類型" value={formatBilingualLabel(item.type_label_chn, item.type_label)} />
                    <MetaRow label="年份" value={formatYearRange(item.first_year, item.last_year)} />
                    <MetaRow label="備註" value={item.notes} />
                    <LegacyEditButton tabKey="addresses" pk={item.pk} />
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
