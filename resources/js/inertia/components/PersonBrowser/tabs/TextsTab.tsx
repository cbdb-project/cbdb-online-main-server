import React from 'react';
import TabCard from '../shared/TabCard';
import MetaRow from '../shared/MetaRow';
import TabPager from '../shared/TabPager';
import EmptyState from '../shared/EmptyState';
import LegacyEditButton from '../shared/LegacyEditButton';
import { useTabPager } from '../shared/useTabPager';
import { formatBilingualLabel } from '../shared/formatters';
import { stableKey } from '../shared/stableKey';

interface TextItem {
    pk: {
        c_personid: number;
        c_textid: number | null;
        c_role_id: number | null;
    };
    text_id: number | null;
    title_chn: string | null;
    title: string | null;
    year: number | null;
    role_id: number | null;
    role_chn: string | null;
    role: string | null;
}

interface Props {
    data: { tab: string; items: TextItem[] };
}

export default function TextsTab({ data }: Props) {
    const { pageItems, currentPage, totalPages, setCurrentPage } = useTabPager(data.items);

    if (data.items.length === 0) {
        return <EmptyState />;
    }

    return (
        <div style={containerStyle}>
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label="著作" value={formatBilingualLabel(item.title_chn, item.title)} />
                    <MetaRow label="年份" value={item.year} />
                    <MetaRow label="角色" value={formatBilingualLabel(item.role_chn, item.role)} />
                    <LegacyEditButton tabKey="texts" pk={item.pk} />
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
