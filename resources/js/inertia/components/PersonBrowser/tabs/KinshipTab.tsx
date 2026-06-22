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
import { formatTextTitle } from '../shared/textLookup';
import { useTextCodes } from '../shared/useTextCodes';
import { getCsrfToken } from '../shared/csrf';
import { APP_THEME } from '../../../theme';
import { useTranslation } from '../../../hooks/useTranslation';
import { Button } from '../../ui/Button';
import { ConfirmDialog } from '../../ui/ConfirmDialog';
import KinshipEditorModal, { KinshipEditorRow } from '../KinshipEditorModal';

interface KinshipItem {
    pk: {
        c_personid: number;
        c_kin_id: number | null;
        c_kin_code: number | null;
    };
    kin_code: number | null;
    relation_chn: string | null;
    relation: string | null;
    kin_person_id: number | null;
    kin_person_name_chn: string | null;
    kin_person_name: string | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: KinshipItem[] };
    canEdit: boolean;
    /** 可提案但不可直接寫入（眾包用戶）。 */
    canPropose?: boolean;
    /** 由 PersonBrowser 透過 props 注入的遷移開關（basicinformation.kinship）。 */
    kinshipEditorIsNew?: boolean;
    personId?: number | null;
    createEndpoint?: string;
    mutateEndpoint?: string;
    deleteEndpoint?: string;
    /** 編輯/刪除成功後刷新該分頁。 */
    onRefresh?: () => void;
    onSelectPerson?: (personId: number) => void;
}

/**
 * 親屬關係列表。
 * 僅顯示直接關係，不做親屬的親屬展開（kinship network expansion）。
 */
export default function KinshipTab({
    data,
    canEdit,
    canPropose = false,
    kinshipEditorIsNew = false,
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
    const [editorRow, setEditorRow] = useState<KinshipEditorRow | null>(null);

    const [deleteTarget, setDeleteTarget] = useState<KinshipItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // 新編輯器在 flag=new 且（可直接編輯 或 可提案）且必要端點齊全時啟用。
    const useReactEditor =
        kinshipEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 可直接寫入者走 direct；否則（僅可提案）走 proposal。
    const proposalMode = !canEdit && canPropose;

    const openCreate = () => {
        setEditorMode('create');
        setEditorRow(null);
        setEditorOpen(true);
    };

    const openEdit = (item: KinshipItem) => {
        setEditorMode('edit');
        setEditorRow(item as KinshipEditorRow);
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
                    resource: 'kinship',
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
                <LegacyCreateButton tabKey="kinship" canEdit={canEdit} />
            )}

            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label={t('relation')} value={formatBilingualLabel(item.relation_chn, item.relation)} />
                    <MetaRow label={t('relation_code')} value={item.kin_code} />
                    <MetaRow label={t('kin_person')} value={renderKinshipPerson(item, onSelectPerson)} />
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
                                <LegacyEditButton tabKey="kinship" pk={item.pk} canEdit={canEdit} />
                                <LegacyDeleteButton tabKey="kinship" pk={item.pk} canEdit={canEdit} />
                            </>
                        )}
                    </CardActions>
                </TabCard>
            ))}
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} showAll={showAll} onToggleShowAll={() => setShowAll(!showAll)} totalItems={totalItems} />

            {useReactEditor ? (
                <>
                    <KinshipEditorModal
                        open={editorOpen}
                        mode={editorMode}
                        proposalMode={proposalMode}
                        personId={personId!}
                        createEndpoint={createEndpoint}
                        mutateEndpoint={mutateEndpoint}
                        row={editorRow}
                        kinCodeInitialLabel={
                            editorRow ? formatBilingualLabel(editorRow.relation_chn, editorRow.relation) : null
                        }
                        kinPersonInitialLabel={
                            editorRow ? formatBilingualLabel(editorRow.kin_person_name_chn, editorRow.kin_person_name) : null
                        }
                        onClose={() => setEditorOpen(false)}
                        onSaved={() => onRefresh?.()}
                    />
                    <ConfirmDialog
                        open={deleteTarget != null}
                        onOpenChange={(o) => {
                            if (!o) setDeleteTarget(null);
                        }}
                        title={proposalMode ? t('proposal_delete_btn') : t('kinship_delete_title')}
                        description={deleteError ?? (proposalMode ? `${t('proposal_delete_prefix')}\n${t('kinship_delete_confirm')}` : t('kinship_delete_confirm'))}
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

function renderKinshipPerson(item: KinshipItem, onSelectPerson?: (personId: number) => void): React.ReactNode {
    if (!item.kin_person_id) {
        return null;
    }

    const label = formatBilingualLabel(item.kin_person_name_chn, item.kin_person_name);

    return (
        <>
            <button
                type="button"
                onClick={() => onSelectPerson?.(item.kin_person_id as number)}
                style={linkButtonStyle}
            >
                [{item.kin_person_id}]
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
