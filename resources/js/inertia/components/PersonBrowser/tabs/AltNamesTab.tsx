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

interface AltNameItem {
    pk: {
        c_personid: number;
        c_alt_name_chn: string | null;
        c_alt_name_type_code: number | null;
    };
    sequence: number | null;
    name_chn: string | null;
    name: string | null;
    type_code: number | null;
    type_label_chn: string | null;
    type_label: string | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: AltNameItem[] };
    canEdit: boolean;
}

export default function AltNamesTab({ data, canEdit }: Props) {
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.source_id));

    return (
        <div style={containerStyle}>
            <LegacyCreateButton tabKey="alt_names" canEdit={canEdit} />
            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label="序號" value={item.sequence ?? '—'} />
                    <MetaRow label="別名" value={formatBilingualLabel(item.name_chn, item.name)} />
                    <MetaRow label="類型" value={formatBilingualLabel(item.type_label_chn, item.type_label)} />
                    <MetaRow label="出處" value={formatTextTitle(textRecords[item.source_id ?? 0], item.source_id)} />
                    <MetaRow label="頁碼" value={item.pages} />
                    <MetaRow label="備註" value={item.notes} />
                    <LegacyEditButton tabKey="alt_names" pk={item.pk} canEdit={canEdit} />
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
