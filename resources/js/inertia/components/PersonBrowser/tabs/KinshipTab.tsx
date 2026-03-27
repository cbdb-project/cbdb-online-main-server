import React from 'react';
import TabCard from '../shared/TabCard';
import MetaRow from '../shared/MetaRow';
import TabPager from '../shared/TabPager';
import EmptyState from '../shared/EmptyState';
import { useTabPager } from '../shared/useTabPager';
import { formatBilingualLabel, formatPersonLabel } from '../shared/formatters';

interface KinshipItem {
    kin_code: number | null;
    relation_chn: string | null;
    relation: string | null;
    kin_person_id: number | null;
    kin_person_name_chn: string | null;
    kin_person_name: string | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: KinshipItem[] };
}

/**
 * 親屬關係列表。
 * 僅顯示直接關係，不做親屬的親屬展開（kinship network expansion）。
 */
export default function KinshipTab({ data }: Props) {
    const { pageItems, currentPage, totalPages, setCurrentPage } = useTabPager(data.items);

    if (data.items.length === 0) {
        return <EmptyState />;
    }

    return (
        <div style={containerStyle}>
            {pageItems.map((item, i) => (
                <TabCard key={i}>
                    <MetaRow label="關係" value={formatBilingualLabel(item.relation_chn, item.relation)} />
                    <MetaRow
                        label="親屬"
                        value={formatPersonLabel(item.kin_person_id, item.kin_person_name_chn, item.kin_person_name)}
                    />
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
