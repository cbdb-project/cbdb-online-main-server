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
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.text_id));

    return (
        <div style={containerStyle}>
            <LegacyCreateButton tabKey="sources" canEdit={canEdit} />
            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => {
                const record = textRecords[item.text_id ?? 0];
                const title =
                    formatTextTitle(record) ?? formatBilingualLabel(item.title_chn, item.title) ?? (item.text_id ? String(item.text_id) : null);
                const titleUrl = record?.c_url_homepage ?? null;
                const url = buildTextUrl(record, item.pages);
                const tags = [
                    item.is_main_source ? '主出處' : null,
                    item.is_self_bio ? '本人傳記' : null,
                ].filter((value): value is string => Boolean(value));

                return (
                    <TabCard key={stableKey(item.pk)}>
                        <MetaRow
                            label="書名"
                            value={
                                titleUrl && title ? (
                                    <a href={titleUrl} target="_blank" rel="noreferrer" style={linkStyle}>
                                        {title}
                                    </a>
                                ) : (
                                    title
                                )
                            }
                        />
                        <MetaRow label="著作 ID" value={item.text_id} />
                        <MetaRow label="標記" value={tags.length > 0 ? (
                            <span style={badgesStyle}>
                                {tags.map((tag) => (
                                    <span key={tag} style={tag === '本人傳記' ? badgeBioStyle : badgeMainStyle}>
                                        {tag}
                                    </span>
                                ))}
                            </span>
                        ) : null}
                        />
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
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} showAll={showAll} onToggleShowAll={() => setShowAll(!showAll)} totalItems={totalItems} />
        </div>
    );
}

const containerStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 8,
};

const badgesStyle: React.CSSProperties = {
    display: 'inline-flex',
    flexWrap: 'wrap',
    gap: 4,
    alignItems: 'center',
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
