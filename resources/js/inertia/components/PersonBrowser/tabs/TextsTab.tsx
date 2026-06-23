import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import TabPager from '../shared/TabPager';
import LegacyCreateButton from '../shared/LegacyCreateButton';
import LegacyEditButton from '../shared/LegacyEditButton';
import LegacyDeleteButton from '../shared/LegacyDeleteButton';
import { useTabPager } from '../shared/useTabPager';
import { formatBilingualLabel } from '../shared/formatters';
import { stableKey } from '../shared/stableKey';
import { getCsrfToken } from '../shared/csrf';
import { buildEditV2CreateUrl, buildEditV2EditUrl } from '../shared/legacyEditUrl';
import SubresourceTable from '../../PersonEditorShared/SubresourceTable';
import { useTranslation } from '../../../hooks/useTranslation';
import { Button } from '../../ui/Button';
import { ConfirmDialog } from '../../ui/ConfirmDialog';

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
    source_id?: number | null;
    pages?: string | null;
    notes?: string | null;
}

interface Props {
    data: { tab: string; items: TextItem[] };
    canEdit: boolean;
    /** 可提案但無法直接編輯時為 true（送出提案而非直接寫入）。 */
    canPropose?: boolean;
    /** 由 PersonBrowser 透過 props 注入的遷移開關（basicinformation.texts）。 */
    textsEditorIsNew?: boolean;
    personId?: number | null;
    createEndpoint?: string;
    mutateEndpoint?: string;
    deleteEndpoint?: string;
    /** 編輯/刪除成功後刷新該分頁。 */
    onRefresh?: () => void;
}

export default function TextsTab({
    data,
    canEdit,
    canPropose = false,
    textsEditorIsNew = false,
    personId = null,
    createEndpoint = '',
    mutateEndpoint = '',
    deleteEndpoint = '',
    onRefresh,
}: Props) {
    const t = useTranslation('person');
    const tb = useTranslation('biogmains');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);

    const [deleteTarget, setDeleteTarget] = useState<TextItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // 新編輯器只有在 flag=new 且（可編輯或可提案）且必要端點齊全時啟用。
    const useReactEditor = textsEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 無法直接編輯但可提案時，走提案模式（送出待審核提案而非直接寫入）。
    const proposalMode = !canEdit && canPropose;

    const openCreate = () => {
        const url = buildEditV2CreateUrl('texts', personId);
        if (url) router.visit(url);
    };

    const openEdit = (item: TextItem) => {
        const url = buildEditV2EditUrl('texts', item.pk, personId);
        if (url) router.visit(url);
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
                    resource: 'texts',
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
                <LegacyCreateButton tabKey="texts" canEdit={canEdit} />
            )}

            <SubresourceTable
                items={pageItems}
                rowKey={(item) => stableKey(item.pk)}
                emptyText={t('no_records')}
                actionsHeader={tb('actions')}
                columns={[
                    { header: t('seq_no'), width: 56, render: (item) => data.items.indexOf(item) + 1 },
                    { header: tb('book_title_field'), render: (item) => formatBilingualLabel(item.title_chn, item.title) },
                    { header: tb('text_role'), render: (item) => formatBilingualLabel(item.role_chn, item.role) },
                ]}
                actions={(canEdit || canPropose) ? (item) => (useReactEditor ? (
                    <span style={actionCellStyle}>
                        <Button size="sm" variant="outline" onClick={() => openEdit(item)}>{t('edit_btn')}</Button>
                        <Button size="sm" variant="destructive" onClick={() => { setDeleteError(null); setDeleteTarget(item); }}>{t('delete_btn')}</Button>
                    </span>
                ) : (
                    <span style={actionCellStyle}>
                        <LegacyEditButton tabKey="texts" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="texts" pk={item.pk} canEdit={canEdit} />
                    </span>
                )) : undefined}
            />
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} showAll={showAll} onToggleShowAll={() => setShowAll(!showAll)} totalItems={totalItems} />

            {useReactEditor ? (
                <>
                    <ConfirmDialog
                        open={deleteTarget != null}
                        onOpenChange={(o) => { if (!o) setDeleteTarget(null); }}
                        title={proposalMode ? t('proposal_delete_btn') : t('text_delete_title')}
                        description={deleteError ?? (proposalMode ? `${t('proposal_delete_prefix')}\n${t('text_delete_confirm')}` : t('text_delete_confirm'))}
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
