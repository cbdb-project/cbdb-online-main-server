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
import { formatTextTitle } from '../shared/textLookup';
import { useTextCodes } from '../shared/useTextCodes';
import { APP_THEME } from '../../../theme';
import { useTranslation } from '../../../hooks/useTranslation';

interface AssociationItem {
    pk: {
        c_personid: number;
        c_assoc_code: number | null;
        c_assoc_id: number | null;
        c_kin_code: number | null;
        c_kin_id: number | null;
        c_assoc_kin_code: number | null;
        c_assoc_kin_id: number | null;
        c_text_title: string | null;
        c_assoc_first_year: number | null;
    };
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
    canEdit: boolean;
    postCE?: boolean;
    onSelectPerson?: (personId: number) => void;
}

export default function AssociationsTab({ data, canEdit, postCE, onSelectPerson }: Props) {
    const t = useTranslation('person');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.source_id));

    return (
        <div style={containerStyle}>
            <LegacyCreateButton tabKey="associations" canEdit={canEdit} />
            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label={t('relation')} value={formatBilingualLabel(item.assoc_desc_chn, item.assoc_desc)} />
                    <MetaRow label={t('relation_code')} value={item.assoc_code} />
                    <MetaRow
                        label={t('related_person')}
                        value={renderAssociationPerson(item, onSelectPerson)}
                    />
                    <MetaRow label={t('time_range')} value={formatYearRange(item.first_year, item.last_year, postCE)} />
                    <MetaRow label={t('source_label')} value={formatTextTitle(textRecords[item.source_id ?? 0], item.source_id)} />
                    <MetaRow label={t('pages_label')} value={item.pages} />
                    <MetaRow label={t('remarks')} value={item.notes} />
                    <CardActions>
                        <LegacyEditButton tabKey="associations" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="associations" pk={item.pk} canEdit={canEdit} />
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

function renderAssociationPerson(item: AssociationItem, onSelectPerson?: (personId: number) => void): React.ReactNode {
    if (!item.assoc_person_id) {
        return null;
    }

    const label = formatBilingualLabel(item.assoc_person_name_chn, item.assoc_person_name);

    return (
        <>
            <button
                type="button"
                onClick={() => onSelectPerson?.(item.assoc_person_id as number)}
                style={linkButtonStyle}
            >
                [{item.assoc_person_id}]
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
