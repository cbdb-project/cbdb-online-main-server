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
import { formatBilingualLabel } from '../shared/formatters';
import { stableKey } from '../shared/stableKey';
import { formatTextTitle } from '../shared/textLookup';
import { useTextCodes } from '../shared/useTextCodes';
import { APP_THEME } from '../../../theme';

interface KinshipItem {
    pk: {
        c_personid: number;
        c_kin_id: number | null;
        c_kin_code: number | null;
    };
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
    canEdit: boolean;
    onSelectPerson?: (personId: number) => void;
}

/**
 * 親屬關係列表。
 * 僅顯示直接關係，不做親屬的親屬展開（kinship network expansion）。
 */
export default function KinshipTab({ data, canEdit, onSelectPerson }: Props) {
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.source_id));

    return (
        <div style={containerStyle}>
            <LegacyCreateButton tabKey="kinship" canEdit={canEdit} />
            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label="關係" value={formatBilingualLabel(item.relation_chn, item.relation)} />
                    <MetaRow label="關係代碼" value={item.kin_code} />
                    <MetaRow label="親屬" value={renderKinshipPerson(item, onSelectPerson)} />
                    <MetaRow label="出處" value={formatTextTitle(textRecords[item.source_id ?? 0], item.source_id)} />
                    <MetaRow label="頁碼" value={item.pages} />
                    <MetaRow label="備註" value={item.notes} />
                    <CardActions>
                        <LegacyEditButton tabKey="kinship" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="kinship" pk={item.pk} canEdit={canEdit} />
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

function renderKinshipPerson(item: KinshipItem, onSelectPerson?: (personId: number) => void): React.ReactNode {
    if (!item.kin_person_id) {
        return null;
    }

    const label = formatBilingualLabel(item.kin_person_name_chn, item.kin_person_name);

    return (
        <>
            <button
                type="button"
                onClick={() => onSelectPerson?.(item.kin_person_id as number)}
                style={linkButtonStyle}
            >
                [{item.kin_person_id}]
            </button>
            {label ? ` ${label}` : null}
        </>
    );
}

const linkButtonStyle: React.CSSProperties = {
    border: 'none',
    background: 'none',
    padding: 0,
    color: APP_THEME.brandText,
    textDecoration: 'none',
    cursor: 'pointer',
    font: 'inherit',
};
