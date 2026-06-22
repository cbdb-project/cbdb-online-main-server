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
import { formatBilingualLabel, formatYearRange } from '../shared/formatters';
import { stableKey } from '../shared/stableKey';
import { formatTextTitle } from '../shared/textLookup';
import { useTextCodes } from '../shared/useTextCodes';
import { getCsrfToken } from '../shared/csrf';
import { APP_THEME } from '../../../theme';
import { useTranslation } from '../../../hooks/useTranslation';
import { Button } from '../../ui/Button';
import { ConfirmDialog } from '../../ui/ConfirmDialog';
import AssociationEditorModal, { AssociationEditorRow } from '../AssociationEditorModal';

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
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.source_id));

    const [editorOpen, setEditorOpen] = useState(false);
    const [editorMode, setEditorMode] = useState<'create' | 'edit'>('create');
    const [editorRow, setEditorRow] = useState<AssociationEditorRow | null>(null);

    const [deleteTarget, setDeleteTarget] = useState<AssociationItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // 新編輯器在 flag=new 且（可直接編輯 或 可提案）且必要端點齊全時啟用。
    const useReactEditor =
        assocEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 可直接寫入者走 direct；否則（僅可提案）走 proposal。
    const proposalMode = !canEdit && canPropose;

    const openCreate = () => {
        setEditorMode('create');
        setEditorRow(null);
        setEditorOpen(true);
    };

    const openEdit = (item: AssociationItem) => {
        setEditorMode('edit');
        setEditorRow(item as AssociationEditorRow);
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

            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label={t('relation')} value={formatBilingualLabel(item.assoc_desc_chn, item.assoc_desc)} />
                    <MetaRow label={t('relation_code')} value={item.assoc_code} />
                    <MetaRow label={t('related_person')} value={renderAssociationPerson(item, onSelectPerson)} />
                    <MetaRow label={t('time_range')} value={formatYearRange(item.first_year, item.last_year, postCE)} />
                    <MetaRow label={t('source_label')} value={formatTextTitle(textRecords[item.source_id ?? 0], item.source_id)} />
                    <MetaRow label={t('pages_label')} value={item.pages} />
                    <MetaRow label={t('remarks')} value={item.notes} />
                    <CardActions>
                        {useReactEditor ? (
                            <>
                                <Button size="sm" variant="outline" onClick={() => openEdit(item)}>
                                    {t('edit_btn')}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    onClick={() => {
                                        setDeleteError(null);
                                        setDeleteTarget(item);
                                    }}
                                >
                                    {t('delete_btn')}
                                </Button>
                            </>
                        ) : (
                            <>
                                <LegacyEditButton tabKey="associations" pk={item.pk} canEdit={canEdit} />
                                <LegacyDeleteButton tabKey="associations" pk={item.pk} canEdit={canEdit} />
                            </>
                        )}
                    </CardActions>
                </TabCard>
            ))}
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} showAll={showAll} onToggleShowAll={() => setShowAll(!showAll)} totalItems={totalItems} />

            {useReactEditor ? (
                <>
                    <AssociationEditorModal
                        open={editorOpen}
                        mode={editorMode}
                        proposalMode={proposalMode}
                        personId={personId!}
                        createEndpoint={createEndpoint}
                        mutateEndpoint={mutateEndpoint}
                        row={editorRow}
                        assocCodeInitialLabel={
                            editorRow ? formatBilingualLabel(editorRow.assoc_desc_chn, editorRow.assoc_desc) : null
                        }
                        assocPersonInitialLabel={
                            editorRow ? formatBilingualLabel(editorRow.assoc_person_name_chn, editorRow.assoc_person_name) : null
                        }
                        onClose={() => setEditorOpen(false)}
                        onSaved={() => onRefresh?.()}
                    />
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

function renderAssociationPerson(item: AssociationItem, onSelectPerson?: (personId: number) => void): React.ReactNode {
    if (!item.assoc_person_id) {
        return null;
    }

    const label = formatBilingualLabel(item.assoc_person_name_chn, item.assoc_person_name);

    return (
        <>
            <button
                type="button"
                onClick={() => onSelectPerson?.(item.assoc_person_id as number)}
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
