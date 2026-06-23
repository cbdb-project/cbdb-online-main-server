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

interface PossessionItem {
    pk: {
        c_possession_record_id: number | null;
    };
    sequence: number | null;
    act_code: number | null;
    act_chn: string | null;
    act: string | null;
    desc_chn: string | null;
    desc: string | null;
    quantity: string | null;
    year: number | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: PossessionItem[] };
    canEdit: boolean;
    /** 可提案但不可直接寫入（眾包用戶）。 */
    canPropose?: boolean;
    /** 由 PersonBrowser 透過 props 注入的遷移開關（basicinformation.possession）。 */
    possessionEditorIsNew?: boolean;
    personId?: number | null;
    createEndpoint?: string;
    mutateEndpoint?: string;
    deleteEndpoint?: string;
    /** 編輯/刪除成功後刷新該分頁。 */
    onRefresh?: () => void;
}

export default function PossessionsTab({
    data,
    canEdit,
    canPropose = false,
    possessionEditorIsNew = false,
    personId = null,
    createEndpoint = '',
    mutateEndpoint = '',
    deleteEndpoint = '',
    onRefresh,
}: Props) {
    const t = useTranslation('person');
    const tb = useTranslation('biogmains');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);

    const [deleteTarget, setDeleteTarget] = useState<PossessionItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // 新編輯器在 flag=new 且（可直接編輯 或 可提案）且必要端點齊全時啟用。
    const useReactEditor = possessionEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 可直接寫入者走 direct；否則（僅可提案）走 proposal。
    const proposalMode = !canEdit && canPropose;

    const openCreate = () => {
        const url = buildEditV2CreateUrl('possessions', personId);
        if (url) router.visit(url);
    };

    const openEdit = (item: PossessionItem) => {
        const url = buildEditV2EditUrl('possessions', item.pk, personId);
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
                    resource: 'possessions',
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
                <LegacyCreateButton tabKey="possessions" canEdit={canEdit} />
            )}

            <SubresourceTable
                items={pageItems}
                rowKey={(item) => stableKey(item.pk)}
                emptyText={t('no_records')}
                actionsHeader={tb('actions')}
                columns={[
                    { header: t('seq_no'), width: 56, render: (item) => item.sequence ?? (data.items.indexOf(item) + 1) },
                    { header: tb('action_col'), render: (item) => formatBilingualLabel(item.act_chn, item.act) },
                    { header: tb('possession_col'), render: (item) => formatBilingualLabel(item.desc_chn, item.desc) },
                ]}
                actions={(canEdit || canPropose) ? (item) => (useReactEditor ? (
                    <span style={actionCellStyle}>
                        <Button size="sm" variant="outline" onClick={() => openEdit(item)}>{t('edit_btn')}</Button>
                        <Button size="sm" variant="destructive" onClick={() => { setDeleteError(null); setDeleteTarget(item); }}>{t('delete_btn')}</Button>
                    </span>
                ) : (
                    <span style={actionCellStyle}>
                        <LegacyEditButton tabKey="possessions" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="possessions" pk={item.pk} canEdit={canEdit} />
                    </span>
                )) : undefined}
            />
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} showAll={showAll} onToggleShowAll={() => setShowAll(!showAll)} totalItems={totalItems} />

            {useReactEditor ? (
                <>
                    <ConfirmDialog
                        open={deleteTarget != null}
                        onOpenChange={(o) => { if (!o) setDeleteTarget(null); }}
                        title={proposalMode ? t('proposal_delete_btn') : t('possession_delete_title')}
                        description={deleteError ?? (proposalMode ? `${t('proposal_delete_prefix')}\n${t('possession_delete_confirm')}` : t('possession_delete_confirm'))}
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
