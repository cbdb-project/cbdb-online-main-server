import React from 'react';
import TabCard from '../shared/TabCard';
import MetaRow from '../shared/MetaRow';
import TabPager from '../shared/TabPager';
import EmptyState from '../shared/EmptyState';
import { useTabPager } from '../shared/useTabPager';

interface AddressItem {
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
            {pageItems.map((item, i) => (
                <TabCard key={i}>
                    <MetaRow
                        label="地址"
                        value={
                            item.addr_chn
                                ? item.addr
                                    ? `${item.addr_chn}（${item.addr}）`
                                    : item.addr_chn
                                : item.addr
                        }
                    />
                    <MetaRow
                        label="類型"
                        value={
                            item.type_label_chn
                                ? item.type_label
                                    ? `${item.type_label_chn}（${item.type_label}）`
                                    : item.type_label_chn
                                : item.type_label
                        }
                    />
                    <MetaRow label="年份" value={formatYearRange(item.first_year, item.last_year)} />
                    <MetaRow label="備註" value={item.notes} />
                </TabCard>
            ))}
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} />
        </div>
    );
}

function formatYearRange(first: number | null, last: number | null): string | null {
    if (first && last) return `${first}–${last}`;
    if (first) return `${first}–`;
    if (last) return `–${last}`;
    return null;
}

const containerStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 8,
};
