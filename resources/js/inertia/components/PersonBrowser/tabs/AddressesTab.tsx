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
    longitude: number | null;
    latitude: number | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: AddressItem[] };
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
                    <MetaRow label="地址" value={formatBilingualLabel(item.addr_chn, item.addr)} />
                    <MetaRow label="類型" value={formatBilingualLabel(item.type_label_chn, item.type_label)} />
                    <MetaRow label="時間範圍" value={formatYearRange(item.first_year, item.last_year, postCE)} />
                    <MetaRow
                        label="經緯度"
                        value={
                            item.latitude !== null && item.longitude !== null ? (
                                <a
                                    href={buildOpenStreetMapUrl(item.latitude, item.longitude)}
                                    target="_blank"
                                    rel="noreferrer"
                                    style={linkStyle}
                                >
                                    {`${item.longitude}, ${item.latitude}`}
                                </a>
                            ) : null
                        }
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

const linkStyle: React.CSSProperties = {
    color: '#A51C30',
    textDecoration: 'none',
};

function buildOpenStreetMapUrl(latitude: number, longitude: number): string {
    return `https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=12/${latitude}/${longitude}`;
}
