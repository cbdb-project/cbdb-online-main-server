import React, { useState } from 'react';
import TabPager from '../shared/TabPager';
import LegacyCreateButton from '../shared/LegacyCreateButton';
import LegacyEditButton from '../shared/LegacyEditButton';
import LegacyDeleteButton from '../shared/LegacyDeleteButton';
import { NavButton } from '../../ui/NavButton';
import { useTabPager } from '../shared/useTabPager';
import { stableKey } from '../shared/stableKey';
import { buildTextUrl, type TextCodeRecord } from '../shared/textLookup';
import { useTextCodes } from '../shared/useTextCodes';
import { getCsrfToken } from '../shared/csrf';
import { buildEditV2CreateUrl, buildEditV2EditUrl } from '../shared/legacyEditUrl';
import SubresourceTable from '../../PersonEditorShared/SubresourceTable';
import { APP_THEME } from '../../../theme';
import { useTranslation } from '../../../hooks/useTranslation';
import { Button } from '../../ui/Button';
import { ConfirmDialog } from '../../ui/ConfirmDialog';

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
    const tb = useTranslation('biogmains');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.text_id));

    const [deleteTarget, setDeleteTarget] = useState<SourceItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // 新編輯器在 flag=new 且（可直接編輯 或 可提案）且必要端點齊全時啟用。
    const useReactEditor = sourcesEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 可直接寫入者走 direct；否則（僅可提案）走 proposal。
    const proposalMode = !canEdit && canPropose;
    const createHref = buildEditV2CreateUrl('sources', personId);
    const editHref = (item: SourceItem) => buildEditV2EditUrl('sources', item.pk, personId);

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
                    <NavButton size="sm" href={createHref}>
                        {t('add_btn')}
                    </NavButton>
                </div>
            ) : (
                <LegacyCreateButton tabKey="sources" canEdit={canEdit} />
            )}

            <SubresourceTable
                items={pageItems}
                rowKey={(item) => stableKey(item.pk)}
                emptyText={t('no_records')}
                actionsHeader={tb('actions')}
                columns={[
                    { header: t('seq_no'), width: 56, render: (item) => data.items.indexOf(item) + 1 },
                    { header: tb('source_field'), render: (item) => renderSourceTitle(item, textRecords) },
                    { header: tb('page_no'), render: (item) => renderPageNo(item, textRecords) },
                ]}
                actions={(canEdit || canPropose) ? (item) => (useReactEditor ? (
                    <span style={actionCellStyle}>
                        <NavButton size="sm" variant="outline" href={editHref(item)}>{t('edit_btn')}</NavButton>
                        <Button size="sm" variant="destructive" onClick={() => { setDeleteError(null); setDeleteTarget(item); }}>{t('delete_btn')}</Button>
                    </span>
                ) : (
                    <span style={actionCellStyle}>
                        <LegacyEditButton tabKey="sources" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="sources" pk={item.pk} canEdit={canEdit} />
                    </span>
                )) : undefined}
            />
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} showAll={showAll} onToggleShowAll={() => setShowAll(!showAll)} totalItems={totalItems} />

            {useReactEditor ? (
                <>
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

const actionCellStyle: React.CSSProperties = { display: 'inline-flex', gap: 6 };

const linkStyle: React.CSSProperties = {
    color: APP_THEME.brandText,
    textDecoration: 'none',
};

/** 出處欄：對齊 legacy `{{ $value->c_title_chn }}`（中文書名），缺則回退 text 記錄／英文名／text_id。 */
function renderSourceTitle(item: SourceItem, textRecords: Record<number, TextCodeRecord>): React.ReactNode {
    const record = textRecords[item.text_id ?? 0];
    return record?.c_title_chn ?? item.title_chn ?? item.title ?? (item.text_id ? String(item.text_id) : null);
}

/**
 * 頁碼欄：對齊 legacy index——當 text 有 API URL（c_url_api）時，頁碼文字本身為連結（href = c_url_api + 頁碼 + coda），
 * 否則純文字顯示頁碼。
 */
function renderPageNo(item: SourceItem, textRecords: Record<number, TextCodeRecord>): React.ReactNode {
    const record = textRecords[item.text_id ?? 0];
    const url = record?.c_url_api ? buildTextUrl(record, item.pages) : null;
    if (url && item.pages) {
        return (
            <a href={url} target="_blank" rel="noreferrer" style={linkStyle}>
                {item.pages}
            </a>
        );
    }
    return item.pages;
}
