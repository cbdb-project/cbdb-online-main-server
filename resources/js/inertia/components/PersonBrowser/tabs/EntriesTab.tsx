import React from 'react';
import TabCard from '../shared/TabCard';
import MetaRow from '../shared/MetaRow';
import TabPager from '../shared/TabPager';
import EmptyState from '../shared/EmptyState';
import { useTabPager } from '../shared/useTabPager';
import { formatBilingualLabel } from '../shared/formatters';
import { stableKey } from '../shared/stableKey';

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
}

export default function EntriesTab({ data }: Props) {
    const { pageItems, currentPage, totalPages, setCurrentPage } = useTabPager(data.items);

    if (data.items.length === 0) {
        return <EmptyState />;
    }

    return (
        <div style={containerStyle}>
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label="入仕方式" value={formatBilingualLabel(item.entry_desc_chn, item.entry_desc)} />
                    <MetaRow label="年份" value={item.year} />
                    <MetaRow label="親屬關聯" value={item.kin_summary} />
                    <MetaRow label="社會關聯" value={item.assoc_summary} />
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
