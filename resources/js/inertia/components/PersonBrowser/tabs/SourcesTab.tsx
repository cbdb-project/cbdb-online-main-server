import React, { useState } from 'react';
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
import { buildTextUrl, formatTextTitle } from '../shared/textLookup';
import { useTextCodes } from '../shared/useTextCodes';
import { getCsrfToken } from '../shared/csrf';
import { APP_THEME } from '../../../theme';
import { useTranslation } from '../../../hooks/useTranslation';
import { Button } from '../../ui/Button';
import { ConfirmDialog } from '../../ui/ConfirmDialog';
import SourceEditorModal, { SourceEditorRow } from '../SourceEditorModal';

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
    /** 可提案但不可直接寫入（眾包用戶）。 */
    canPropose?: boolean;
    /** 由 PersonBrowser 透過 props 注入的遷移開關（basicinformation.sources）。 */
    sourcesEditorIsNew?: boolean;
    personId?: number | null;
    createEndpoint?: string;
    mutateEndpoint?: string;
    deleteEndpoint?: string;
    /** 編輯/刪除成功後刷新該分頁。 */
    onRefresh?: () => void;
}

export default function SourcesTab({
    data,
    canEdit,
    canPropose = false,
    sourcesEditorIsNew = false,
    personId = null,
    createEndpoint = '',
    mutateEndpoint = '',
    deleteEndpoint = '',
    onRefresh,
}: Props) {
    const t = useTranslation('person');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.text_id));

    const [editorOpen, setEditorOpen] = useState(false);
    const [editorMode, setEditorMode] = useState<'create' | 'edit'>('create');
    const [editorRow, setEditorRow] = useState<SourceEditorRow | null>(null);

    const [deleteTarget, setDeleteTarget] = useState<SourceItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // 新編輯器在 flag=new 且（可直接編輯 或 可提案）且必要端點齊全時啟用。
    const useReactEditor = sourcesEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 可直接寫入者走 direct；否則（僅可提案）走 proposal。
    const proposalMode = !canEdit && canPropose;

    const openCreate = () => {
        setEditorMode('create');
        setEditorRow(null);
        setEditorOpen(true);
    };

    const openEdit = (item: SourceItem) => {
        setEditorMode('edit');
        setEditorRow(item as SourceEditorRow);
        setEditorOpen(true);
    };

    const handleDelete = async () => {
        if (!deleteTarget || !personId) {
            return;
        }
        setDeleting(true);
        setDeleteError(null);
        try {
            const response = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    resource: 'sources',
                    person_id: personId,
                    mode: proposalMode ? 'proposal' : 'direct',
                    target: { pk: deleteTarget.pk },
                }),
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok || !json?.ok) {
                setDeleteError(json?.message || `${t('delete_failed')}（HTTP ${response.status}）`);
                return;
            }
            setDeleteTarget(null);
            onRefresh?.();
        } catch (err) {
            setDeleteError(err instanceof Error ? err.message : t('delete_failed'));
        } finally {
            setDeleting(false);
        }
    };

    return (
        <div style={containerStyle}>
            {useReactEditor ? (
                <div style={createBarStyle}>
                    <Button size="sm" onClick={openCreate}>
                        {t('add_btn')}
                    </Button>
                </div>
            ) : (
                <LegacyCreateButton tabKey="sources" canEdit={canEdit} />
            )}

            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => {
                const record = textRecords[item.text_id ?? 0];
                const title =
                    formatTextTitle(record) ?? formatBilingualLabel(item.title_chn, item.title) ?? (item.text_id ? String(item.text_id) : null);
                const titleUrl = record?.c_url_homepage ?? null;
                const url = buildTextUrl(record, item.pages);
                const tags = [
                    item.is_main_source ? t('main_source') : null,
                    item.is_self_bio ? t('self_bio') : null,
                ].filter((value): value is string => Boolean(value));

                return (
                    <TabCard key={stableKey(item.pk)}>
                        <MetaRow
                            label={t('book_title')}
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
                        <MetaRow label={t('text_id')} value={item.text_id} />
                        <MetaRow label={t('tag_label')} value={tags.length > 0 ? (
                            <span style={badgesStyle}>
                                {tags.map((tag) => (
                                    <span key={tag} style={tag === t('self_bio') ? badgeBioStyle : badgeMainStyle}>
                                        {tag}
                                    </span>
                                ))}
                            </span>
                        ) : null}
                        />
                        <MetaRow label={t('pages_label')} value={item.pages} />
                        <MetaRow
                            label={t('link_label')}
                            value={
                                url ? (
                                    <a href={url} target="_blank" rel="noreferrer" style={linkStyle}>
                                        {url}
                                    </a>
                                ) : null
                            }
                        />
                        <MetaRow label={t('remarks')} value={item.notes} />
                        <CardActions>
                            {useReactEditor ? (
                                <>
                                    <Button size="sm" variant="outline" onClick={() => openEdit(item)}>
                                        {t('edit_btn')}
                                    </Button>
                                    <Button size="sm" variant="destructive" onClick={() => { setDeleteError(null); setDeleteTarget(item); }}>
                                        {t('delete_btn')}
                                    </Button>
                                </>
                            ) : (
                                <>
                                    <LegacyEditButton tabKey="sources" pk={item.pk} canEdit={canEdit} />
                                    <LegacyDeleteButton tabKey="sources" pk={item.pk} canEdit={canEdit} />
                                </>
                            )}
                        </CardActions>
                    </TabCard>
                );
            })}
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} showAll={showAll} onToggleShowAll={() => setShowAll(!showAll)} totalItems={totalItems} />

            {useReactEditor ? (
                <>
                    <SourceEditorModal
                        open={editorOpen}
                        mode={editorMode}
                        proposalMode={proposalMode}
                        personId={personId!}
                        createEndpoint={createEndpoint}
                        mutateEndpoint={mutateEndpoint}
                        row={editorRow}
                        textInitialLabel={editorRow ? formatBilingualLabel(editorRow.title_chn, editorRow.title) : null}
                        onClose={() => setEditorOpen(false)}
                        onSaved={() => onRefresh?.()}
                    />
                    <ConfirmDialog
                        open={deleteTarget != null}
                        onOpenChange={(o) => { if (!o) setDeleteTarget(null); }}
                        title={proposalMode ? t('proposal_delete_btn') : t('source_delete_title')}
                        description={deleteError ?? (proposalMode ? `${t('proposal_delete_prefix')}\n${t('source_delete_confirm')}` : t('source_delete_confirm'))}
                        confirmLabel={deleting ? (proposalMode ? t('submitting_proposal') : t('saving')) : (proposalMode ? t('proposal_delete_btn') : t('delete_btn'))}
                        cancelLabel={t('cancel_btn')}
                        destructive
                        loading={deleting}
                        onConfirm={() => void handleDelete()}
                    />
                </>
            ) : null}
        </div>
    );
}

const containerStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    gap: 8,
};

const createBarStyle: React.CSSProperties = {
    display: 'flex',
    justifyContent: 'flex-end',
    marginBottom: 8,
};

const badgesStyle: React.CSSProperties = {
    display: 'inline-flex',
    flexWrap: 'wrap',
    gap: 4,
    alignItems: 'center',
};

const linkStyle: React.CSSProperties = {
    color: APP_THEME.brandText,
    textDecoration: 'none',
};

const badgeMainStyle: React.CSSProperties = {
    fontSize: '0.6875rem',
    padding: '1px 6px',
    borderRadius: 3,
    backgroundColor: APP_THEME.brand,
    color: '#fff',
};

const badgeBioStyle: React.CSSProperties = {
    fontSize: '0.6875rem',
    padding: '1px 6px',
    borderRadius: 3,
    backgroundColor: '#fff',
    color: APP_THEME.brandText,
    border: `1px solid ${APP_THEME.brandBorder}`,
};
