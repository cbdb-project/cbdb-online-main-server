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
import AddressDisplayWithMap from '../shared/AddressDisplayWithMap';
import { useTranslation } from '../../../hooks/useTranslation';
import { Button } from '../../ui/Button';
import { ConfirmDialog } from '../../ui/ConfirmDialog';

interface PostingItem {
    pk: {
        c_office_id: number | null;
        c_posting_id: number | null;
    };
    sequence: number | null;
    office_id: number | null;
    posting_id: number | null;
    office_chn: string | null;
    office: string | null;
    first_year: number | null;
    last_year: number | null;
    tenure_summary: string | null;
    addresses: Array<{
        addr_id: number | null;
        addr_chn: string | null;
        addr: string | null;
        admin_cat_code: number | null;
        admin_cat_label: string | null;
        longitude: number | null;
        latitude: number | null;
    }>;
    address_summary: string | null;
    source_id: number | null;
    pages: string | null;
    notes: string | null;
    appt_code?: number | null;
    appt_chn?: string | null;
    appt?: string | null;
}

interface Props {
    data: { tab: string; person_index_year?: number | null; items: PostingItem[] };
    canEdit: boolean;
    /** 可提案但不可直接寫入（眾包用戶）。 */
    canPropose?: boolean;
    postCE?: boolean;
    /** 由 PersonBrowser 透過 props 注入的遷移開關（basicinformation.offices）。 */
    officesEditorIsNew?: boolean;
    personId?: number | null;
    createEndpoint?: string;
    mutateEndpoint?: string;
    deleteEndpoint?: string;
    /** 編輯/刪除成功後刷新該分頁。 */
    onRefresh?: () => void;
}

export default function PostingsTab({
    data,
    canEdit,
    canPropose = false,
    postCE,
    officesEditorIsNew = false,
    personId = null,
    createEndpoint = '',
    mutateEndpoint = '',
    deleteEndpoint = '',
    onRefresh,
}: Props) {
    const t = useTranslation('person');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);
    const { records: textRecords } = useTextCodes(data.items.map((item) => item.source_id));

    const [deleteTarget, setDeleteTarget] = useState<PostingItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // 新編輯器在 flag=new 且（可直接編輯 或 可提案）且必要端點齊全時啟用。
    const useReactEditor = officesEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 可直接寫入者走 direct；否則（僅可提案）走 proposal。
    const proposalMode = !canEdit && canPropose;

    const openCreate = () => {
        const url = buildEditV2CreateUrl('postings', personId);
        if (url) router.visit(url);
    };

    const openEdit = (item: PostingItem) => {
        const url = buildEditV2EditUrl('postings', item.pk, personId);
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
                    resource: 'postings',
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
                <LegacyCreateButton tabKey="postings" canEdit={canEdit} />
            )}

            {data.items.length === 0 ? <EmptyState /> : null}
            {pageItems.map((item) => (
                <TabCard key={stableKey(item.pk)}>
                    <MetaRow label={t('seq_no')} value={item.sequence ?? '—'} />
                    <MetaRow label={t('office_id')} value={item.office_id} />
                    <MetaRow label={t('office_name')} value={formatBilingualLabel(item.office_chn, item.office)} />
                    <MetaRow label={t('tenure')} value={item.tenure_summary} />
                    <MetaRow label={t('time_range')} value={formatYearRange(item.first_year, item.last_year, postCE)} />
                    <MetaRow
                        label={t('address_label')}
                        value={item.addresses.length > 0 ? (
                            <span style={addressListStyle}>
                                {item.addresses.map((address, index) => (
                                    <React.Fragment key={`${address.addr_id ?? 'addr'}-${index}`}>
                                        {index > 0 ? <span>；</span> : null}
                                        <AddressDisplayWithMap
                                            labelChn={address.addr_chn}
                                            labelEng={address.addr}
                                            adminCatCode={address.admin_cat_code}
                                            adminCatLabel={address.admin_cat_label}
                                            latitude={address.latitude}
                                            longitude={address.longitude}
                                            year={inferDisplayYear(item.first_year, item.last_year, data.person_index_year ?? null)}
                                        />
                                    </React.Fragment>
                                ))}
                            </span>
                        ) : item.address_summary}
                    />
                    <MetaRow label={t('appt_type_label')} value={formatBilingualLabel(item.appt_chn ?? null, item.appt ?? null)} />
                    <MetaRow label={t('source_label')} value={formatTextTitle(textRecords[item.source_id ?? 0], item.source_id)} />
                    <MetaRow label={t('pages_label')} value={item.pages} />
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
                                <LegacyEditButton tabKey="postings" pk={item.pk} canEdit={canEdit} />
                                <LegacyDeleteButton tabKey="postings" pk={item.pk} canEdit={canEdit} />
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
                        title={proposalMode ? t('proposal_delete_btn') : t('posting_delete_title')}
                        description={deleteError ?? (proposalMode ? `${t('proposal_delete_prefix')}\n${t('posting_delete_confirm')}` : t('posting_delete_confirm'))}
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

const addressListStyle: React.CSSProperties = {
    display: 'inline-flex',
    flexWrap: 'wrap',
    alignItems: 'center',
    gap: 4,
};

function inferDisplayYear(firstYear: number | null, lastYear: number | null, fallbackYear: number | null): number | null {
    if (firstYear !== null && lastYear !== null) {
        return Math.round((firstYear + lastYear) / 2);
    }

    return firstYear ?? lastYear ?? fallbackYear;
}
