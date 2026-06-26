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
import AddressDisplayWithMap from '../shared/AddressDisplayWithMap';
import SubresourceTable from '../../PersonEditorShared/SubresourceTable';
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
    const tb = useTranslation('biogmains');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);

    const [deleteTarget, setDeleteTarget] = useState<PostingItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    // 新編輯器在 flag=new 且（可直接編輯 或 可提案）且必要端點齊全時啟用。
    const useReactEditor = officesEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 可直接寫入者走 direct；否則（僅可提案）走 proposal。
    const proposalMode = !canEdit && canPropose;
    const createHref = buildEditV2CreateUrl('postings', personId);
    const editHref = (item: PostingItem) => buildEditV2EditUrl('postings', item.pk, personId);

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
                    <NavButton size="sm" href={createHref}>
                        {t('add_btn')}
                    </NavButton>
                </div>
            ) : (
                <LegacyCreateButton tabKey="postings" canEdit={canEdit} />
            )}

            <SubresourceTable
                items={pageItems}
                rowKey={(item) => stableKey(item.pk)}
                emptyText={t('no_records')}
                actionsHeader={tb('actions')}
                columns={[
                    { header: t('seq_no'), width: 56, render: (item) => data.items.indexOf(item) + 1 },
                    { header: tb('sequence'), render: (item) => item.sequence ?? '—' },
                    { header: t('posting_id'), render: (item) => item.posting_id },
                    { header: tb('office_name_field'), width: '40%', render: (item) => formatBilingualLabel(item.office_chn, item.office) },
                    { header: tb('place_name'), render: (item) => renderAddresses(item, personId) },
                    { header: tb('start_year'), render: (item) => formatYear(item.first_year, postCE) },
                    { header: tb('end_year'), render: (item) => formatYear(item.last_year, postCE) },
                ]}
                actions={(canEdit || canPropose) ? (item) => (useReactEditor ? (
                    <span style={actionCellStyle}>
                        <NavButton size="sm" variant="outline" href={editHref(item)}>{t('edit_btn')}</NavButton>
                        <Button size="sm" variant="destructive" onClick={() => { setDeleteError(null); setDeleteTarget(item); }}>{t('delete_btn')}</Button>
                    </span>
                ) : (
                    <span style={actionCellStyle}>
                        <LegacyEditButton tabKey="postings" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="postings" pk={item.pk} canEdit={canEdit} />
                    </span>
                )) : undefined}
            />
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

const actionCellStyle: React.CSSProperties = { display: 'inline-flex', gap: 6 };

const addressListStyle: React.CSSProperties = {
    display: 'inline-flex',
    flexWrap: 'wrap',
    alignItems: 'center',
    gap: 4,
};

function renderAddresses(item: PostingItem, personId: number | null): React.ReactNode {
    if (item.addresses.length === 0) {
        return item.address_summary;
    }

    return (
        <span style={addressListStyle}>
            {item.addresses.map((address, index) => (
                <React.Fragment key={`${address.addr_id ?? 'addr'}-${index}`}>
                    {index > 0 ? <span>；</span> : null}
                    <AddressDisplayWithMap
                        labelChn={address.addr_chn}
                        labelEng={address.addr}
                        latitude={address.latitude}
                        longitude={address.longitude}
                        personId={personId}
                        mapKey={`office:${item.pk.c_office_id}:${item.pk.c_posting_id}:${address.addr_id}`}
                    />
                </React.Fragment>
            ))}
        </span>
    );
}

/**
 * 格式化單一年份（始年／終年），過濾 CBDB 哨兵值 0 與 -9999；
 * postCE 為 true 時額外過濾負數年份。對齊 legacy index 的 c_firstyear/c_lastyear 純值欄。
 */
function formatYear(year: number | null, postCE: boolean = false): number | null {
    if (year == null || year === 0 || year === -9999) return null;
    if (postCE && year < 0) return null;
    return year;
}
