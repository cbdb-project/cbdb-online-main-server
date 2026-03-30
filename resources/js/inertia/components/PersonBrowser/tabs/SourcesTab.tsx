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
import { buildTextUrl, formatTextTitle } from '../shared/textLookup';
import { useTextCodes } from '../shared/useTextCodes';

interface SourceItem {
    pk: {
        c_personid: number;
        c_textid: number | null;
        c_pages: string | null;
    };
    text_id: number | null;
    title_chn: string | null;
    title: string | null;
    pages: string | null;
    notes: string | null;
    is_main_source: boolean;
    is_self_bio: boolean;
}

interface Props {
    data: { tab: string; items: SourceItem[] };
    canEdit: boolean;
}

export default function SourcesTab({ data, canEdit }: Props) {
    const { pageItems, currentPage, totalPages, setCurrentPage } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.text_id));

    return (
        <div style={containerStyle}>
            <LegacyCreateButton tabKey="sources" canEdit={canEdit} />
            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => {
                const record = textRecords[item.text_id ?? 0];
                const title =
                    formatTextTitle(record) ?? formatBilingualLabel(item.title_chn, item.title) ?? (item.text_id ? String(item.text_id) : null);
                const url = buildTextUrl(record, item.pages);

                return (
                    <TabCard key={stableKey(item.pk)}>
                        <div style={headerStyle}>
                            <MetaRow
                                label="書名"
                                value={
                                    url && title ? (
                                        <a href={url} target="_blank" rel="noreferrer" style={linkStyle}>
                                            {title}
                                        </a>
                                    ) : (
                                        title
                                    )
                                }
                            />
                            <div style={badgesStyle}>
                                {item.is_main_source && <span style={badgeMainStyle}>主出處</span>}
                                {item.is_self_bio && <span style={badgeBioStyle}>自傳</span>}
                            </div>
                        </div>
                        <MetaRow label="頁碼" value={item.pages} />
                        <MetaRow
                            label="連結"
                            value={
                                url ? (
                                    <a href={url} target="_blank" rel="noreferrer" style={linkStyle}>
                                        {url}
                                    </a>
                                ) : null
                            }
                        />
                        <MetaRow label="備註" value={item.notes} />
                        <LegacyEditButton tabKey="sources" pk={item.pk} canEdit={canEdit} />
                    </TabCard>
                );
            })}
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} />
        </div>
    );
}

const containerStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 8,
};

const headerStyle: React.CSSProperties = {
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
};

const badgesStyle: React.CSSProperties = {
    display: 'flex',
    gap: 4,
    flexShrink: 0,
};

const linkStyle: React.CSSProperties = {
    color: '#007bff',
    textDecoration: 'none',
};

const badgeMainStyle: React.CSSProperties = {
    fontSize: '0.6875rem',
    padding: '1px 6px',
    borderRadius: 3,
    backgroundColor: '#007bff',
    color: '#fff',
};

const badgeBioStyle: React.CSSProperties = {
    fontSize: '0.6875rem',
    padding: '1px 6px',
    borderRadius: 3,
    backgroundColor: '#28a745',
    color: '#fff',
};
