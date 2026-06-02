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
import { useTranslation } from '../../../hooks/useTranslation';

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
    canEdit: boolean;
}

export default function TextsTab({ data, canEdit }: Props) {
    const t = useTranslation('person');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);

    return (
        <div style={containerStyle}>
            <LegacyCreateButton tabKey="texts" canEdit={canEdit} />
            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label={t('text_id')} value={item.text_id} />
                    <MetaRow label={t('text_title')} value={formatBilingualLabel(item.title_chn, item.title)} />
                    <MetaRow label={t('year_label')} value={item.year} />
                    <MetaRow label={t('role_label')} value={formatBilingualLabel(item.role_chn, item.role)} />
                    <CardActions>
                        <LegacyEditButton tabKey="texts" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="texts" pk={item.pk} canEdit={canEdit} />
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
