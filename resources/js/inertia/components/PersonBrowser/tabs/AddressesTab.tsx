import React from 'react';
import TabCard from '../shared/TabCard';
import MetaRow from '../shared/MetaRow';
import TabPager from '../shared/TabPager';
import EmptyState from '../shared/EmptyState';
import LegacyCreateButton from '../shared/LegacyCreateButton';
import LegacyEditButton from '../shared/LegacyEditButton';
import LegacyDeleteButton from '../shared/LegacyDeleteButton';
import CardActions from '../shared/CardActions';
import { useTabPager } from '../shared/useTabPager';
import { formatBilingualLabel, formatYearRange } from '../shared/formatters';
import { stableKey } from '../shared/stableKey';
import AddressDisplayWithMap from '../shared/AddressDisplayWithMap';

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
    admin_cat_code: number | null;
    admin_cat_label: string | null;
    type_code: number | null;
    type_label_chn: string | null;
    type_label: string | null;
    first_year: number | null;
    last_year: number | null;
    longitude: number | null;
    latitude: number | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; person_index_year?: number | null; items: AddressItem[] };
    canEdit: boolean;
    postCE?: boolean;
}

export default function AddressesTab({ data, canEdit, postCE }: Props) {
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);

    return (
        <div style={containerStyle}>
            <LegacyCreateButton tabKey="addresses" canEdit={canEdit} />
            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label="序號" value={item.sequence ?? '—'} />
                    <MetaRow label="地址 ID" value={item.addr_id} />
                    <MetaRow
                        label="地址"
                        value={(
                            <AddressDisplayWithMap
                                labelChn={item.addr_chn}
                                labelEng={item.addr}
                                adminCatCode={item.admin_cat_code}
                                adminCatLabel={item.admin_cat_label}
                                latitude={item.latitude}
                                longitude={item.longitude}
                                year={inferDisplayYear(item.first_year, item.last_year, data.person_index_year ?? null)}
                            />
                        )}
                    />
                    <MetaRow label="類型" value={formatBilingualLabel(item.type_label_chn, item.type_label)} />
                    <MetaRow label="時間範圍" value={formatYearRange(item.first_year, item.last_year, postCE)} />
                    <MetaRow
                        label="經緯度"
                        value={item.latitude !== null && item.longitude !== null ? `${item.longitude}, ${item.latitude}` : null}
                    />
                    <MetaRow label="備註" value={item.notes} />
                    <CardActions>
                        <LegacyEditButton tabKey="addresses" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="addresses" pk={item.pk} canEdit={canEdit} />
                    </CardActions>
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

function inferDisplayYear(firstYear: number | null, lastYear: number | null, fallbackYear: number | null): number | null {
    if (firstYear !== null && lastYear !== null) {
        return Math.round((firstYear + lastYear) / 2);
    }

    return firstYear ?? lastYear ?? fallbackYear;
}
