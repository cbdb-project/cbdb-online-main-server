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
import { getCsrfToken } from '../shared/csrf';
import { buildEditV2CreateUrl, buildEditV2EditUrl } from '../shared/legacyEditUrl';
import AddressDisplayWithMap from '../shared/AddressDisplayWithMap';
import { useTranslation } from '../../../hooks/useTranslation';
import { Button } from '../../ui/Button';
import { ConfirmDialog } from '../../ui/ConfirmDialog';

interface AddressItem {
    pk: {
        c_personid: number;
        c_addr_id: number | null;
        c_addr_type: number | null;
        c_sequence: number | null;
    };
    sequence: number | null;
    addr_id: number | null;
    addr_chn: string | null;
    addr: string | null;
    admin_cat_code: number | null;
    admin_cat_label: string | null;
    type_code: number | null;
    type_label_chn: string | null;
    type_label: string | null;
    first_year: number | null;
    last_year: number | null;
    longitude: number | null;
    latitude: number | null;
    source_id?: number | null;
    pages?: string | null;
    notes: string | null;
}

interface Props {
    data: { tab: string; person_index_year?: number | null; items: AddressItem[] };
    canEdit: boolean;
    /** 可提案但不可直接寫入（眾包用戶）。 */
    canPropose?: boolean;
    postCE?: boolean;
    /** 由 PersonBrowser 透過 props 注入的遷移開關（basicinformation.addresses）。 */
    addressesEditorIsNew?: boolean;
    personId?: number | null;
    createEndpoint?: string;
    mutateEndpoint?: string;
    deleteEndpoint?: string;
    /** 編輯/刪除成功後刷新該分頁。 */
    onRefresh?: () => void;
}

export default function AddressesTab({
    data,
    canEdit,
    canPropose = false,
    postCE,
    addressesEditorIsNew = false,
    personId = null,
    createEndpoint = '',
    mutateEndpoint = '',
    deleteEndpoint = '',
    onRefresh,
}: Props) {
    const t = useTranslation('person');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);

    const [deleteTarget, setDeleteTarget] = useState<AddressItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // 新編輯器在 flag=new 且（可直接編輯 或 可提案）且必要端點齊全時啟用。
    const useReactEditor = addressesEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 可直接寫入者走 direct；否則（僅可提案）走 proposal。
    const proposalMode = !canEdit && canPropose;

    const openCreate = () => {
        const url = buildEditV2CreateUrl('addresses', personId);
        if (url) router.visit(url);
    };

    const openEdit = (item: AddressItem) => {
        const url = buildEditV2EditUrl('addresses', item.pk, personId);
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
                    resource: 'addresses',
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
                <LegacyCreateButton tabKey="addresses" canEdit={canEdit} />
            )}

            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label={t('seq_no')} value={item.sequence ?? '—'} />
                    <MetaRow label={t('addr_id')} value={item.addr_id} />
                    <MetaRow
                        label={t('address_label')}
                        value={(
                            <AddressDisplayWithMap
                                labelChn={item.addr_chn}
                                labelEng={item.addr}
                                adminCatCode={item.admin_cat_code}
                                adminCatLabel={item.admin_cat_label}
                                latitude={item.latitude}
                                longitude={item.longitude}
                                year={inferDisplayYear(item.first_year, item.last_year, data.person_index_year ?? null)}
                            />
                        )}
                    />
                    <MetaRow label={t('type_label')} value={formatBilingualLabel(item.type_label_chn, item.type_label)} />
                    <MetaRow label={t('time_range')} value={formatYearRange(item.first_year, item.last_year, postCE)} />
                    <MetaRow
                        label={t('coordinates')}
                        value={item.latitude !== null && item.longitude !== null ? `${item.longitude}, ${item.latitude}` : null}
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
                                <LegacyEditButton tabKey="addresses" pk={item.pk} canEdit={canEdit} />
                                <LegacyDeleteButton tabKey="addresses" pk={item.pk} canEdit={canEdit} />
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
                        onOpenChange={(o) => { if (!o) setDeleteTarget(null); }}
                        title={proposalMode ? t('proposal_delete_btn') : t('address_delete_title')}
                        description={deleteError ?? (proposalMode ? `${t('proposal_delete_prefix')}\n${t('address_delete_confirm')}` : t('address_delete_confirm'))}
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

function inferDisplayYear(firstYear: number | null, lastYear: number | null, fallbackYear: number | null): number | null {
    if (firstYear !== null && lastYear !== null) {
        return Math.round((firstYear + lastYear) / 2);
    }

    return firstYear ?? lastYear ?? fallbackYear;
}
