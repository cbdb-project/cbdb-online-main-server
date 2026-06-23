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

interface EntryItem {
    pk: {
        c_personid: number;
        c_entry_code: number | null;
        c_sequence: number | null;
        c_kin_code: number | null;
        c_assoc_code: number | null;
        c_kin_id: number | null;
        c_year: number | null;
        c_assoc_id: number | null;
        c_inst_code: number | null;
        c_inst_name_code: number | null;
    };
    sequence: number | null;
    entry_code: number | null;
    entry_desc_chn: string | null;
    entry_desc: string | null;
    year: number | null;
    kin_id: number | null;
    kin_summary: string | null;
    assoc_id: number | null;
    assoc_summary: string | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: EntryItem[] };
    canEdit: boolean;
    /** 可提案但不可直接寫入（眾包用戶）。 */
    canPropose?: boolean;
    /** 由 PersonBrowser 透過 props 注入的遷移開關（basicinformation.entries）。 */
    entriesEditorIsNew?: boolean;
    personId?: number | null;
    createEndpoint?: string;
    mutateEndpoint?: string;
    deleteEndpoint?: string;
    /** 編輯/刪除成功後刷新該分頁。 */
    onRefresh?: () => void;
    onSelectPerson?: (personId: number) => void;
}

export default function EntriesTab({
    data,
    canEdit,
    canPropose = false,
    entriesEditorIsNew = false,
    personId = null,
    createEndpoint = '',
    mutateEndpoint = '',
    deleteEndpoint = '',
    onRefresh,
}: Props) {
    const t = useTranslation('person');
    const tb = useTranslation('biogmains');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);

    const [deleteTarget, setDeleteTarget] = useState<EntryItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // 新編輯器在 flag=new 且（可直接編輯 或 可提案）且必要端點齊全時啟用。
    const useReactEditor =
        entriesEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 可直接寫入者走 direct；否則（僅可提案）走 proposal。
    const proposalMode = !canEdit && canPropose;

    const openCreate = () => {
        const url = buildEditV2CreateUrl('entries', personId);
        if (url) router.visit(url);
    };

    const openEdit = (item: EntryItem) => {
        const url = buildEditV2EditUrl('entries', item.pk, personId);
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
                    resource: 'entries',
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
                <LegacyCreateButton tabKey="entries" canEdit={canEdit} />
            )}

            <SubresourceTable
                items={pageItems}
                rowKey={(item) => stableKey(item.pk)}
                emptyText={t('no_records')}
                actionsHeader={tb('actions')}
                columns={[
                    { header: t('seq_no'), width: 56, render: (item) => data.items.indexOf(item) + 1 },
                    { header: tb('sequence'), render: (item) => item.sequence },
                    { header: tb('entry_method'), render: (item) => formatBilingualLabel(item.entry_desc_chn, item.entry_desc) },
                    { header: tb('entry_year_field'), render: (item) => item.year },
                ]}
                actions={(canEdit || canPropose) ? (item) => (useReactEditor ? (
                    <span style={actionCellStyle}>
                        <Button size="sm" variant="outline" onClick={() => openEdit(item)}>{t('edit_btn')}</Button>
                        <Button size="sm" variant="destructive" onClick={() => { setDeleteError(null); setDeleteTarget(item); }}>{t('delete_btn')}</Button>
                    </span>
                ) : (
                    <span style={actionCellStyle}>
                        <LegacyEditButton tabKey="entries" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="entries" pk={item.pk} canEdit={canEdit} />
                    </span>
                )) : undefined}
            />
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} showAll={showAll} onToggleShowAll={() => setShowAll(!showAll)} totalItems={totalItems} />

            {useReactEditor ? (
                <>
                    <ConfirmDialog
                        open={deleteTarget != null}
                        onOpenChange={(o) => {
                            if (!o) setDeleteTarget(null);
                        }}
                        title={proposalMode ? t('proposal_delete_btn') : t('entry_delete_title')}
                        description={deleteError ?? (proposalMode ? `${t('proposal_delete_prefix')}\n${t('entry_delete_confirm')}` : t('entry_delete_confirm'))}
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
