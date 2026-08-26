import React, { useState } from 'react';
import TabPager from '../shared/TabPager';
import LegacyCreateButton from '../shared/LegacyCreateButton';
import LegacyEditButton from '../shared/LegacyEditButton';
import LegacyDeleteButton from '../shared/LegacyDeleteButton';
import { NavButton } from '../../ui/NavButton';
import { useTabPager } from '../shared/useTabPager';
import { formatBilingualLabel } from '../shared/formatters';
import { stableKey } from '../shared/stableKey';
import { getCsrfToken } from '../shared/csrf';
import { buildEditV2CreateUrl, buildEditV2EditUrl } from '../shared/legacyEditUrl';
import SubresourceTable from '../../PersonEditorShared/SubresourceTable';
import { useTranslation } from '../../../hooks/useTranslation';
import { Button } from '../../ui/Button';
import { ConfirmDialog } from '../../ui/ConfirmDialog';

/**
 * 出處顯示：優先書名（中／英），沒有對應 TEXT_CODES 列時退回 `#id`，
 * 哨兵 0 與 null 一律視為「未填」。
 */
function formatSourceLabel(item: AltNameItem): string | null {
    const label = formatBilingualLabel(item.source_title_chn, item.source_title);
    if (label) return label;
    return item.source_id ? `#${item.source_id}` : null;
}

interface AltNameItem {
    pk: {
        c_personid: number;
        c_alt_name_chn: string | null;
        c_alt_name_type_code: number | null;
    };
    sequence: number | null;
    name_chn: string | null;
    name: string | null;
    type_code: number | null;
    type_label_chn: string | null;
    type_label: string | null;
    source_id: number | null;
    source_title_chn: string | null;
    source_title: string | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: AltNameItem[] };
    canEdit: boolean;
    /** 可提案但不可直接寫入（眾包用戶）。 */
    canPropose?: boolean;
    /** 由 PersonBrowser 透過 props 注入的遷移開關（basicinformation.altname）。 */
    altnameEditorIsNew?: boolean;
    personId?: number | null;
    createEndpoint?: string;
    mutateEndpoint?: string;
    deleteEndpoint?: string;
    /** 編輯/刪除成功後刷新該分頁。 */
    onRefresh?: () => void;
}

export default function AltNamesTab({
    data,
    canEdit,
    canPropose = false,
    altnameEditorIsNew = false,
    personId = null,
    createEndpoint = '',
    mutateEndpoint = '',
    deleteEndpoint = '',
    onRefresh,
}: Props) {
    const t = useTranslation('person');
    const tb = useTranslation('biogmains');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);

    const [deleteTarget, setDeleteTarget] = useState<AltNameItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // 新編輯器在 flag=new 且（可直接編輯 或 可提案）且必要端點齊全時啟用。
    const useReactEditor = altnameEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 可直接寫入者走 direct；否則（僅可提案）走 proposal。
    const proposalMode = !canEdit && canPropose;
    const createHref = buildEditV2CreateUrl('alt_names', personId);
    const editHref = (item: AltNameItem) => buildEditV2EditUrl('alt_names', item.pk, personId);

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
                    resource: 'altnames',
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
                <LegacyCreateButton tabKey="alt_names" canEdit={canEdit} />
            )}

            <SubresourceTable
                items={pageItems}
                rowKey={(item) => stableKey(item.pk)}
                emptyText={t('no_records')}
                actionsHeader={tb('actions')}
                columns={[
                    { header: t('seq_no'), width: 56, render: (item) => item.sequence ?? (data.items.indexOf(item) + 1) },
                    { header: tb('altname_pinyin_label'), render: (item) => item.name },
                    { header: tb('altname_chinese'), render: (item) => item.name_chn },
                    { header: t('alt_name_type'), render: (item) => formatBilingualLabel(item.type_label_chn, item.type_label) },
                    // 出處／頁碼／備註：AltnameMutationHandler 的 allowedFields 允許提案修改，
                    // 卻只在編輯器裡看得到——列表不顯示會讓使用者以為沒存進去。
                    { header: tb('source_field'), render: (item) => formatSourceLabel(item) },
                    { header: tb('pages_entries'), render: (item) => item.pages },
                    { header: tb('notes_field'), render: (item) => (item.notes ? <div style={notesCellStyle}>{item.notes}</div> : null) },
                ]}
                actions={(canEdit || canPropose) ? (item) => (useReactEditor ? (
                    <span style={actionCellStyle}>
                        <NavButton size="sm" variant="outline" href={editHref(item)}>{t('edit_btn')}</NavButton>
                        <Button size="sm" variant="destructive" onClick={() => { setDeleteError(null); setDeleteTarget(item); }}>{t('delete_btn')}</Button>
                    </span>
                ) : (
                    <span style={actionCellStyle}>
                        <LegacyEditButton tabKey="alt_names" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="alt_names" pk={item.pk} canEdit={canEdit} />
                    </span>
                )) : undefined}
            />
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} showAll={showAll} onToggleShowAll={() => setShowAll(!showAll)} totalItems={totalItems} />

            {useReactEditor ? (
                <>
                    <ConfirmDialog
                        open={deleteTarget != null}
                        onOpenChange={(o) => { if (!o) setDeleteTarget(null); }}
                        title={proposalMode ? t('proposal_delete_btn') : t('altname_delete_title')}
                        description={deleteError ?? (proposalMode ? `${t('proposal_delete_prefix')}\n${t('altname_delete_confirm')}` : t('altname_delete_confirm'))}
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

// 備註可能很長：限寬並保留換行，避免把整張表撐爆（外層 SubresourceTable 已有橫向捲動）。
const notesCellStyle: React.CSSProperties = {
    maxWidth: 360,
    whiteSpace: 'pre-wrap',
    wordBreak: 'break-word',
};

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
