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
import { APP_THEME } from '../../../theme';
import { useTranslation } from '../../../hooks/useTranslation';
import { Button } from '../../ui/Button';
import { ConfirmDialog } from '../../ui/ConfirmDialog';

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
    sequence: number | null;
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
    /** 可提案但不可直接寫入（眾包用戶）。 */
    canPropose?: boolean;
    postCE?: boolean;
    /** 由 PersonBrowser 透過 props 注入的遷移開關（basicinformation.assoc）。 */
    assocEditorIsNew?: boolean;
    personId?: number | null;
    createEndpoint?: string;
    mutateEndpoint?: string;
    deleteEndpoint?: string;
    /** 編輯/刪除成功後刷新該分頁。 */
    onRefresh?: () => void;
    onSelectPerson?: (personId: number) => void;
}

export default function AssociationsTab({
    data,
    canEdit,
    canPropose = false,
    postCE,
    assocEditorIsNew = false,
    personId = null,
    createEndpoint = '',
    mutateEndpoint = '',
    deleteEndpoint = '',
    onRefresh,
    onSelectPerson,
}: Props) {
    const t = useTranslation('person');
    const tb = useTranslation('biogmains');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);

    const [deleteTarget, setDeleteTarget] = useState<AssociationItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // 新編輯器在 flag=new 且（可直接編輯 或 可提案）且必要端點齊全時啟用。
    // #34：新增/編輯導向獨立 edit-v2 編輯器頁（非 person-browser 內聯 modal）；刪除仍於列表內聯確認。
    const useReactEditor =
        assocEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 可直接寫入者走 direct；否則（僅可提案）走 proposal。
    const proposalMode = !canEdit && canPropose;

    const openCreate = () => {
        const url = buildEditV2CreateUrl('associations', personId);
        if (url) router.visit(url);
    };

    const openEdit = (item: AssociationItem) => {
        const url = buildEditV2EditUrl('associations', item.pk, personId);
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
                    resource: 'associations',
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
                <LegacyCreateButton tabKey="associations" canEdit={canEdit} />
            )}

            <SubresourceTable
                items={pageItems}
                rowKey={(item) => stableKey(item.pk)}
                emptyText={t('no_records')}
                actionsHeader={tb('actions')}
                columns={[
                    { header: t('seq_no'), width: 56, render: (item) => data.items.indexOf(item) + 1 },
                    { header: tb('sequence'), width: 64, render: (item) => item.sequence ?? '—' },
                    { header: tb('assoc_category_col'), render: (item) => formatBilingualLabel(item.assoc_desc_chn, item.assoc_desc) },
                    { header: tb('assoc_person_col'), render: (item) => renderAssociationPerson(item, onSelectPerson) },
                    { header: tb('work_title'), render: (item) => item.pk.c_text_title },
                ]}
                actions={(canEdit || canPropose) ? (item) => (useReactEditor ? (
                    <span style={actionCellStyle}>
                        <Button size="sm" variant="outline" onClick={() => openEdit(item)}>{t('edit_btn')}</Button>
                        <Button size="sm" variant="destructive" onClick={() => { setDeleteError(null); setDeleteTarget(item); }}>{t('delete_btn')}</Button>
                    </span>
                ) : (
                    <span style={actionCellStyle}>
                        <LegacyEditButton tabKey="associations" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="associations" pk={item.pk} canEdit={canEdit} />
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
                        title={proposalMode ? t('proposal_delete_btn') : t('assoc_delete_title')}
                        description={deleteError ?? (proposalMode ? `${t('proposal_delete_prefix')}\n${t('assoc_delete_confirm')}` : t('assoc_delete_confirm'))}
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

function renderAssociationPerson(item: AssociationItem, onSelectPerson?: (personId: number) => void): React.ReactNode {
    if (!item.assoc_person_id) {
        return null;
    }

    const label = formatBilingualLabel(item.assoc_person_name_chn, item.assoc_person_name);

    return (
        <>
            <button
                type="button"
                onClick={() => {
                    const pid = item.assoc_person_id as number;
                    if (onSelectPerson) { onSelectPerson(pid); } else { router.visit(`/app/basicinformation/${pid}`); }
                }}
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
