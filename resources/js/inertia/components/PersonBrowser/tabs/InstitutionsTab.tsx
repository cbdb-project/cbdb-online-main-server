import React from 'react';
import TabCard from '../shared/TabCard';
import MetaRow from '../shared/MetaRow';
import TabPager from '../shared/TabPager';
import EmptyState from '../shared/EmptyState';
import { useTabPager } from '../shared/useTabPager';
import { formatBilingualLabel } from '../shared/formatters';

interface InstitutionItem {
    pk: {
        c_personid: number;
        c_inst_code: number | null;
        c_inst_name_code: number | null;
        c_bi_role_code: number | null;
    };
    role_code: number | null;
    role_chn: string | null;
    role: string | null;
    inst_code: number | null;
    inst_name_code: number | null;
    inst_name_chn: string | null;
    inst_name: string | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: InstitutionItem[] };
}

export default function InstitutionsTab({ data }: Props) {
    const { pageItems, currentPage, totalPages, setCurrentPage } = useTabPager(data.items);

    if (data.items.length === 0) {
        return <EmptyState />;
    }

    return (
        <div style={containerStyle}>
            {pageItems.map((item, i) => (
                <TabCard key={i}>
                    <MetaRow label="機構" value={formatBilingualLabel(item.inst_name_chn, item.inst_name)} />
                    <MetaRow label="角色" value={formatBilingualLabel(item.role_chn, item.role)} />
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
