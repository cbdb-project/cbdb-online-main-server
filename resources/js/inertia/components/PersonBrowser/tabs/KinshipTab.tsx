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
import SubresourceTable from '../../PersonEditorShared/SubresourceTable';
import MirrorDeleteMultipleNotice, { MirrorDeleteMultiple } from '../../PersonEditorShared/MirrorDeleteMultipleNotice';
import { APP_THEME } from '../../../theme';
import { useTranslation } from '../../../hooks/useTranslation';
import { Button } from '../../ui/Button';
import { ConfirmDialog } from '../../ui/ConfirmDialog';

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
    const tb = useTranslation('biogmains');
    const { pageItems, currentPage, totalPages, setCurrentPage, showAll, setShowAll, totalItems } = useTabPager(data.items);

    const [deleteTarget, setDeleteTarget] = useState<KinshipItem | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);
    const [deleteMulti, setDeleteMulti] = useState<MirrorDeleteMultiple | null>(null); // #81 §6 刪除命中對面多筆反向列
    // 409 當下「快照」要刪的列；force 重送一律用此快照，避免 notice 顯示期間 deleteTarget 被列表其他刪除按鈕覆寫而誤刪他列。
    const [multiTarget, setMultiTarget] = useState<KinshipItem | null>(null);
    const resetDeleteFlow = () => {
        setDeleteMulti(null);
        setMultiTarget(null);
        setDeleteTarget(null);
    };
    const deleteFlowOpen = deleteTarget != null || deleteMulti != null || multiTarget != null;
    // biogmains 翻譯 + fallback（對齊編輯器 tr 形態）：查無鍵時用 fallback。
    const tr = (k: string, fb: string): string => { const v = tb(k); return v && v !== k ? v : fb; };

    // 新編輯器在 flag=new 且（可直接編輯 或 可提案）且必要端點齊全時啟用。
    // #34：新增/編輯導向獨立 edit-v2 編輯器頁（非 person-browser 內聯 modal）；刪除仍於列表內聯確認。
    const useReactEditor =
        kinshipEditorIsNew && (canEdit || canPropose) && personId != null && !!createEndpoint && !!mutateEndpoint && !!deleteEndpoint;
    // 可直接寫入者走 direct；否則（僅可提案）走 proposal。
    const proposalMode = !canEdit && canPropose;
    const createHref = buildEditV2CreateUrl('kinship', personId);
    const editHref = (item: KinshipItem) => buildEditV2EditUrl('kinship', item.pk, personId);

    // force=true 為「對面多筆反向列」確認後重送（帶 meta.force，後端一併刪除全部候選）。
    // force 時用 409 快照 multiTarget（非可能已漂移的 deleteTarget）以鎖定刪除對象。
    const handleDelete = async (force = false) => {
        const target = force ? multiTarget : deleteTarget;
        if (!target || !personId) {
            return;
        }
        setDeleting(true);
        setDeleteError(null);
        try {
            const body: Record<string, unknown> = {
                resource: 'kinship',
                person_id: personId,
                mode: proposalMode ? 'proposal' : 'direct',
                target: { pk: target.pk },
            };
            if (force) body.meta = { force: true };
            const response = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok || !json?.ok) {
                // #81 §6：對面命中多筆反向列 → 快照當前目標、收起確認框、列出候選供確認，再帶 meta.force 重送。
                const dm = json?.errors?.mirror_delete_multiple;
                if (response.status === 409 && dm) {
                    setDeleting(false);
                    setDeleteTarget(null);
                    setMultiTarget(target);
                    setDeleteMulti(dm as MirrorDeleteMultiple);
                    return;
                }
                setDeleteError(json?.message || `${t('delete_failed')}（HTTP ${response.status}）`);
                return;
            }
            resetDeleteFlow();
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
                <LegacyCreateButton tabKey="kinship" canEdit={canEdit} />
            )}

            <SubresourceTable
                items={pageItems}
                rowKey={(item) => stableKey(item.pk)}
                emptyText={t('no_records')}
                actionsHeader={tb('actions')}
                columns={[
                    { header: t('seq_no'), width: 56, render: (item) => data.items.indexOf(item) + 1 },
                    { header: t('relation'), render: (item) => formatBilingualLabel(item.relation_chn, item.relation) },
                    { header: t('kin_person'), render: (item) => renderKinshipPerson(item, onSelectPerson) },
                ]}
                actions={(canEdit || canPropose) ? (item) => (useReactEditor ? (
                    <span style={actionCellStyle}>
                        <NavButton size="sm" variant="outline" href={editHref(item)}>{t('edit_btn')}</NavButton>
                        <Button size="sm" variant="destructive" disabled={deleting || deleteFlowOpen} onClick={() => { setDeleteError(null); setDeleteTarget(item); }}>{t('delete_btn')}</Button>
                    </span>
                ) : (
                    <span style={actionCellStyle}>
                        <LegacyEditButton tabKey="kinship" pk={item.pk} canEdit={canEdit} />
                        <LegacyDeleteButton tabKey="kinship" pk={item.pk} canEdit={canEdit} />
                    </span>
                )) : undefined}
            />
            <TabPager currentPage={currentPage} totalPages={totalPages} onPageChange={setCurrentPage} showAll={showAll} onToggleShowAll={() => setShowAll(!showAll)} totalItems={totalItems} />

            {useReactEditor ? (
                <>
                    <ConfirmDialog
                        open={deleteTarget != null && deleteMulti == null}
                        onOpenChange={(o) => {
                            if (!o) resetDeleteFlow();
                        }}
                        title={proposalMode ? t('proposal_delete_btn') : t('kinship_delete_title')}
                        description={deleteError ?? (proposalMode ? `${t('proposal_delete_prefix')}\n${t('kinship_delete_confirm')}` : t('kinship_delete_confirm'))}
                        confirmLabel={deleting ? (proposalMode ? t('submitting_proposal') : t('saving')) : (proposalMode ? t('proposal_delete_btn') : t('delete_btn'))}
                        cancelLabel={t('cancel_btn')}
                        destructive
                        loading={deleting}
                        onConfirm={() => void handleDelete()}
                    />
                    {deleteMulti ? (
                        <MirrorDeleteMultipleNotice
                            info={deleteMulti}
                            urlFor={(row) => `/app/basicinformation/${row.c_personid}/kinship/edit-v2?${new URLSearchParams({ c_personid: String(row.c_personid), c_kin_id: String(row.c_kin_id), c_kin_code: String(row.c_kin_code) }).toString()}`}
                            onConfirm={() => void handleDelete(true)}
                            onDismiss={resetDeleteFlow}
                            deleting={deleting}
                            tr={tr}
                        />
                    ) : null}
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

function renderKinshipPerson(item: KinshipItem, onSelectPerson?: (personId: number) => void): React.ReactNode {
    if (!item.kin_person_id) {
        return null;
    }

    const label = formatBilingualLabel(item.kin_person_name_chn, item.kin_person_name);
    const pid = item.kin_person_id as number;

    return (
        <>
            {onSelectPerson ? (
                // person-browser 主從檢視：原地切換選取的人物。
                <button type="button" onClick={() => onSelectPerson(pid)} style={linkButtonStyle}>
                    [{item.kin_person_id}]
                </button>
            ) : (
                // 編輯器情境：另開新分頁（對齊 legacy target="_blank"），避免離開當前人物編輯頁。
                <a href={`/app/basicinformation/${pid}`} target="_blank" rel="noopener noreferrer" style={linkButtonStyle}>
                    [{item.kin_person_id}]
                </a>
            )}
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
