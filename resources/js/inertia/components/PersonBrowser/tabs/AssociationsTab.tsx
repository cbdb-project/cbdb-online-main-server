import React from 'react';
import TabCard from '../shared/TabCard';
import MetaRow from '../shared/MetaRow';
import TabPager from '../shared/TabPager';
import EmptyState from '../shared/EmptyState';
import { useTabPager } from '../shared/useTabPager';

interface AssociationItem {
    assoc_code: number | null;
    assoc_desc_chn: string | null;
    assoc_desc: string | null;
    assoc_person_id: number | null;
    assoc_person_name_chn: string | null;
    assoc_person_name: string | null;
    first_year: number | null;
    last_year: number | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: AssociationItem[] };
}

export default function AssociationsTab({ data }: Props) {
    const { pageItems, currentPage, totalPages, setCurrentPage } = useTabPager(data.items);

    if (data.items.length === 0) {
        return <EmptyState />;
    }

    return (
        <div style={containerStyle}>
            {pageItems.map((item, i) => (
                <TabCard key={i}>
                    <MetaRow
                        label="關係"
                        value={
                            item.assoc_desc_chn
                                ? item.assoc_desc
                                    ? `${item.assoc_desc_chn}（${item.assoc_desc}）`
                                    : item.assoc_desc_chn
                                : item.assoc_desc
                        }
                    />
                    <MetaRow
                        label="關聯人物"
                        value={formatPersonLabel(item.assoc_person_id, item.assoc_person_name_chn, item.assoc_person_name)}
                    />
                    <MetaRow label="年份" value={formatYearRange(item.first_year, item.last_year)} />
                    <MetaRow label="出處" value={item.source_id} />
                    <MetaRow label="頁碼" value={item.pages} />
                    <MetaRow label="備註" value={item.notes} />
                </TabCard>
            ))}
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} />
        </div>
    );
}

function formatPersonLabel(id: number | null, nameChn: string | null, name: string | null): string | null {
    if (!id) return null;
    const parts: string[] = [];
    if (nameChn) parts.push(nameChn);
    if (name) parts.push(name);
    const label = parts.join(' / ') || String(id);
    return `[${id}] ${label}`;
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
