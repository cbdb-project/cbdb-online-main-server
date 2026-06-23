import React, { useState } from 'react';
import { router } from '@inertiajs/react';
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
import { buildEditV2CreateUrl, buildEditV2EditUrl } from '../shared/legacyEditUrl';
import { useTranslation } from '../../../hooks/useTranslation';
import { Button } from '../../ui/Button';
import { ConfirmDialog } from '../../ui/ConfirmDialog';

interface InstitutionItem {
    pk: {
        c_personid: number;
        c_inst_code: number | null;
        c_inst_name_code: number | null;
        c_bi_role_code: number | null;
    };
    role_code: number | null;
    role_chn: string | null;
    role: string | null;
    inst_code: number | null;
    inst_name_code: number | null;
    inst_name_chn: string | null;
    inst_name: string | null;
    first_year: number | null;
    last_year: number | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; items: InstitutionItem[] };
    canEdit: boolean;
    /** 可提案但不可直接寫入（眾包用戶）。 */
    canPropose?: boolean;
    postCE?: boolean;
    /** 由 PersonBrowser 透過 props 注入的遷移開關（basicinformation.socialinst）。 */
    socialInstEditorIsNew?: boolean;
    personId?: number | null;
    createEndpoint?: string;
    mutateEndpoint?: string;
    deleteEndpoint?: string;
    /** 編輯/刪除成功後刷新該分頁。 */
    onRefresh?: () => void;
}

export default function InstitutionsTab({
    data,
    canEdit,
    canPropose = false,
    postCE,
    socialInstEditorIsNew = false,
    personId = null,
    createEndpoint = '',
    mutateEndpoint = '',
    deleteEndpoint = '',
    onRefresh,
}: Props) {
    const t = useTranslation('person');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.source_id));

    const [deleteTarget, setDeleteTarget] = useState<InstitutionItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // 新編輯器在 flag=new 且（可直接編輯 或 可提案）且必要端點齊全時啟用。
    const useReactEditor =
        socialInstEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 可直接寫入者走 direct；否則（僅可提案）走 proposal。
    const proposalMode = !canEdit && canPropose;

    const openCreate = () => {
        const url = buildEditV2CreateUrl('social_institutions', personId);
        if (url) router.visit(url);
    };

    const openEdit = (item: InstitutionItem) => {
        const url = buildEditV2EditUrl('social_institutions', item.pk, personId);
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
                    resource: 'social_institutions',
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
                <LegacyCreateButton tabKey="social_institutions" canEdit={canEdit} />
            )}

            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label={t('institution_id')} value={item.inst_code} />
                    <MetaRow label={t('institution_label')} value={formatBilingualLabel(item.inst_name_chn, item.inst_name)} />
                    <MetaRow label={t('role_label')} value={formatBilingualLabel(item.role_chn, item.role)} />
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
                                <LegacyEditButton tabKey="social_institutions" pk={item.pk} canEdit={canEdit} />
                                <LegacyDeleteButton tabKey="social_institutions" pk={item.pk} canEdit={canEdit} />
                            </>
                        )}
                    </CardActions>
                </TabCard>
            ))}
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} showAll={showAll} onToggleShowAll={() => setShowAll(!showAll)} totalItems={totalItems} />

            {useReactEditor ? (
                <>
                    <ConfirmDialog
                        open={deleteTarget != null}
                        onOpenChange={(o) => {
                            if (!o) setDeleteTarget(null);
                        }}
                        title={proposalMode ? t('proposal_delete_btn') : t('social_institution_delete_title')}
                        description={deleteError ?? (proposalMode ? `${t('proposal_delete_prefix')}\n${t('social_institution_delete_confirm')}` : t('social_institution_delete_confirm'))}
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
