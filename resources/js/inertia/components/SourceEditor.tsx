import React, { useEffect, useMemo, useRef, useState } from 'react';
import ActionStatus, { BtnSpinner } from './PersonEditorShared/ActionStatus';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import { getCsrfToken } from './PersonBrowser/shared/csrf';
import {
    gridCardStyle, gGrid, gInputStyle, gReadonlyStyle, gOkStyle, gErrStyle, gWarnStyle,
    gSubmitRow, gBtnGroupRight, gPrimaryBtn, gInfoBtn, gDangerBtn, gCancelBtn,
    gAuditWrapStyle, gridSectionHeadStyle, GridLabel, gridCell, gridInput,
} from './PersonEditorShared/grid';

/**
 * 著述出處（sources）編輯器（對齊 legacy biogmains/sources/_form.blade.php，非 person-browser）。
 * 欄位：出處（c_textid，text 搜尋；legacy label 標示 c_source）/ 頁數·條目（c_pages）/
 * 選項（c_main_source 主要出處、c_self_bio 本人傳記，皆 checkbox 0/1）/ 備註（c_notes）。
 *
 * === 與 entries/statuses 的關鍵差異（divergent handler shape）===
 * 1. 後端為單一 SourceMutationHandler（AbstractMutationHandler + BiogSourceRepository），
 *    無獨立 Create handler；create/update 同走 /api/v2/create 與 /api/v2/mutate。
 * 2. 複合主鍵 3 段：c_personid, c_textid, c_pages。c_pages 為 varchar 主鍵，
 *    「未知」哨兵為 ''（空字串，非 '0'）；現行 DB 已有大量列以 '' 為慣例。
 * 3. **PK 在 update 模式可改鍵（#116）**：c_textid / c_pages 雖為主鍵，但編輯時可直接改鍵
 *    （對齊 altname/address、legacy）；後端以 changes 取新值重寫主鍵、碰撞回 409。存後以
 *    回傳 result.pk 重同步 originalPk（陷阱#5）。注意：清空 c_pages（改為空字串）會被 Laravel
 *    ConvertEmptyStringsToNull 轉為 null，後端 `changes['c_pages'] ?? targetPk` 維持原頁碼，
 *    故無法把頁碼「改空」，只能改為非空頁碼（此為刻意限制，非 bug）。
 * 4. 真實可寫欄位（MUTABLE_COLUMNS）= c_notes, c_main_source, c_self_bio。
 *    create 另接受 c_textid / c_pages（PK），其餘真實欄位皆在此清單內，無 phantom 欄位。
 */
type Fields = Record<string, string>;

interface Props {
    personId: number;
    personLabel: string;
    mode: 'create' | 'edit';
    initialFields: Fields;
    initialLabels?: Fields;
    canEdit: boolean;
    canPropose: boolean;
    createEndpoint: string;
    mutateEndpoint: string;
    deleteEndpoint?: string;
    indexUrl: string;
    isWikiSource?: boolean;
    t?: (k: string) => string;
}

// 複合主鍵 3 段。c_personid 固定；c_textid / c_pages 僅在 create 模式可設定（update immutable）。
const PK = ['c_personid', 'c_textid', 'c_pages'];
// update 模式可寫的非主鍵欄位（對齊 BiogSourceRepository::MUTABLE_COLUMNS）。
const MUTABLE = ['c_notes', 'c_main_source', 'c_self_bio'];

export default function SourceEditor({
    personId, personLabel, mode, initialFields, initialLabels = {},
    canEdit, canPropose, createEndpoint, mutateEndpoint, deleteEndpoint, indexUrl, isWikiSource = false, t,
}: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    // 新增預設：c_textid '' = 尚未選擇（與合法代碼 0=未詳 區分；legacy required 僅要求「有選」，
    // 但伺服器允許 0=未詳，故不可把 0 當作「未選」拒絕）；c_pages legacy 預設 '0'（亦為合法字串頁碼）；旗標預設 '0'。
    // 編輯模式由 initialFields 覆蓋（含 NULL→'' 的旗標，由 ?? 不觸發、checkbox 視為未勾）。
    const base: Fields = {
        c_personid: String(personId),
        c_textid: '', c_pages: '0',
        c_main_source: '0', c_self_bio: '0',
        ...initialFields,
    };
    const [fields, setFields] = useState<Fields>(base);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const [savedSnapshot, setSavedSnapshot] = useState(JSON.stringify(base));
    const msgTimer = useRef<number | null>(null);
    // 編輯目標主鍵：c_pages 為字串（哨兵 ''），c_personid / c_textid 為整數。
    const originalPk = useRef<Record<string, number | string>>({
        c_personid: Number(initialFields.c_personid ?? personId),
        c_textid: Number(initialFields.c_textid ?? 0),
        c_pages: String(initialFields.c_pages ?? ''),
    });
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const flashSaved = (m: string) => { setMessage(m); if (msgTimer.current) window.clearTimeout(msgTimer.current); msgTimer.current = window.setTimeout(() => setMessage(null), 3000); };
    useEffect(() => () => { if (msgTimer.current) window.clearTimeout(msgTimer.current); }, []);
    const [error, setError] = useState<string | null>(null);
    const [comment, setComment] = useState('');

    const dirty = useMemo(() => JSON.stringify(fields) !== savedSnapshot, [fields, savedSnapshot]);
    const set = (k: string, v: string) => setFields((p) => ({ ...p, [k]: v }));
    const setLabel = (k: string, v: string) => setLabels((p) => ({ ...p, [k]: v }));
    const editable = canEdit || canPropose;
    const checked = (k: string) => (fields[k] ?? '0') === '1';

    const save = async (sm: 'direct' | 'proposal') => {
        // 出處（c_textid）為新增必填（legacy required = 必須「有選」）。'' = 尚未選擇 → 擋；
        // 但 0=未詳 為合法代碼（legacy 伺服器允許），使用者明確選 0 不阻擋。
        if (mode === 'create') {
            const txt = (fields.c_textid ?? '').trim();
            if (txt === '') { setError(tr('please_select_source', '請選擇出處')); return; }
        }
        setSaving(true); setError(null); setMessage(null);
        let changes: Record<string, string | null>;
        let target: Record<string, number | string>;
        let endpoint: string;
        let operation: string;
        if (mode === 'create') {
            endpoint = createEndpoint; operation = 'create';
            // c_pages 為字串主鍵段（哨兵 ''）；c_textid / c_personid 為整數。
            target = {
                c_personid: Number(personId),
                c_textid: Number(fields.c_textid ?? 0) || 0,
                c_pages: (fields.c_pages ?? '').trim(),
            };
            // 真實可寫欄位：旗標恆送（0/1，對齊 legacy hidden+checkbox），備註非空才送。
            changes = {
                c_main_source: checked('c_main_source') ? '1' : '0',
                c_self_bio: checked('c_self_bio') ? '1' : '0',
            };
            const notes = fields.c_notes ?? '';
            if (notes !== '') changes.c_notes = notes;
        } else {
            endpoint = mutateEndpoint; operation = 'update'; target = originalPk.current;
            const initial: Fields = JSON.parse(savedSnapshot);
            changes = {};
            // 改鍵（#116）：c_textid / c_pages 為主鍵但編輯時可改鍵。c_textid 不可清空（須有效出處）。
            const curTextid = (fields.c_textid ?? '').trim();
            if (curTextid === '') { setSaving(false); setError(tr('please_select_source', '請選擇出處')); return; }
            if (String(initial.c_textid ?? '') !== curTextid) changes.c_textid = curTextid;
            const curPages = (fields.c_pages ?? '').trim();
            if (String(initial.c_pages ?? '') !== curPages) changes.c_pages = curPages;
            // 非主鍵欄位。備註空字串送 null；旗標送 0/1。
            const notesCur = fields.c_notes ?? '';
            if ((initial.c_notes ?? '') !== notesCur) changes.c_notes = notesCur === '' ? null : notesCur;
            for (const k of ['c_main_source', 'c_self_bio']) {
                const cur = checked(k) ? '1' : '0';
                const init = (initial[k] ?? '0') === '1' ? '1' : '0';
                if (cur !== init) changes[k] = cur;
            }
            if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        }
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'sources', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(sm === 'proposal' && comment ? { meta: { comment } } : {}) }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            flashSaved(sm === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            // direct 儲存後從回傳列即時刷新唯讀稽核欄（建檔/更新），免重整；函式式合併避免 race，並併入 baseline 免誤判未存變更。
            const auditRow = (sm === 'direct' && json?.result?.row && typeof json.result.row === 'object') ? json.result.row as Record<string, unknown> : null;
            const auditPatch: Fields = {};
            if (auditRow) { for (const k of ['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date']) { if (auditRow[k] != null) auditPatch[k] = String(auditRow[k]); } }
            // 改鍵（#116）：以後端權威 result.pk 重同步 originalPk 與表單 PK 欄（含空 c_pages 被剝除而維持原值的情形），
            // 避免 client/DB 主鍵失準（陷阱#5）；NEVER 從表單 fields 重建。
            const pkPatch: Fields = {};
            const rpk = (sm === 'direct' && json?.result?.pk && typeof json.result.pk === 'object') ? json.result.pk as Record<string, unknown> : null;
            if (rpk && mode === 'edit') {
                originalPk.current = {
                    c_personid: Number(rpk.c_personid ?? originalPk.current.c_personid),
                    c_textid: Number(rpk.c_textid ?? originalPk.current.c_textid),
                    c_pages: String(rpk.c_pages ?? originalPk.current.c_pages),
                };
                if (rpk.c_textid != null) pkPatch.c_textid = String(rpk.c_textid);
                if (rpk.c_pages != null) pkPatch.c_pages = String(rpk.c_pages);
            }
            const mergedPatch: Fields = { ...auditPatch, ...pkPatch };
            if (Object.keys(mergedPatch).length > 0) setFields((prev) => ({ ...prev, ...mergedPatch }));
            setSavedSnapshot(JSON.stringify({ ...fields, ...mergedPatch }));
            if (mode === 'create') { window.location.assign(indexUrl); }
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    const doDelete = async () => {
        if (!deleteEndpoint || !window.confirm(tr('source_delete_confirm', '確定刪除此出處記錄？'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'sources', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setSavedSnapshot(JSON.stringify(fields));
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    return (
        <div style={gridCardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('source_create', '新增出處') : tr('source_edit', '編輯出處')}</h3>
            {message ? <div style={gOkStyle}>{message}</div> : null}
            {error ? <div style={gErrStyle}>{error}</div> : null}
            {mode === 'edit' && isWikiSource ? (
                <div style={gWarnStyle}>
                    <strong>{tr('wiki_warning', '警告')}：</strong>{tr('wiki_warning_text', '此為維基資料來源，請謹慎修改。')}
                </div>
            ) : null}

            <div style={gGrid}>
                {gridCell(tr('source_field', '出處'), { code: 'c_source', required: true, hint: mode === 'edit' ? tr('source_pk_rekey_hint', '出處與頁碼為主鍵，可直接修改（改鍵）；若與現有出處＋頁碼重複將被擋下。') : undefined },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                        value={fields.c_textid ?? ''} initialLabel={labels.c_textid ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_textid', v); setLabel('c_textid', l); }} />)}

                {gridCell(tr('pages_entries', '頁數/條目'), { code: 'c_pages' },
                    gridInput({ value: fields.c_pages ?? '', onChange: (v) => set('c_pages', v), disabled: !editable }))}

                {gridCell(tr('options', '選項'), { full: true },
                    <>
                        <label style={checkRow}>
                            <input type="checkbox" checked={checked('c_main_source')} disabled={!editable}
                                onChange={(e) => set('c_main_source', e.target.checked ? '1' : '0')} />
                            <span>{tr('primary_source', '主要出處')}</span>
                        </label>
                        <label style={checkRow}>
                            <input type="checkbox" checked={checked('c_self_bio')} disabled={!editable}
                                onChange={(e) => set('c_self_bio', e.target.checked ? '1' : '0')} />
                            <span>{tr('self_biography', '本人傳記')}</span>
                        </label>
                    </>)}

                {gridCell(tr('notes_field', '備註'), { code: 'c_notes', full: true },
                    <textarea value={fields.c_notes ?? ''} disabled={!editable} onChange={(e) => set('c_notes', e.target.value)} rows={5}
                        style={{ ...gInputStyle, height: 'auto', ...(!editable ? gReadonlyStyle : {}) }} />)}
            </div>

            {mode === 'edit' && (fields.c_created_by || fields.c_modified_by) ? (
                <div style={gAuditWrapStyle}>
                    <div style={gridSectionHeadStyle}>{tr('create_or_modify', '建檔 / 更新資訊')}</div>
                    <div style={gGrid}>
                        {fields.c_created_by ? gridCell(tr('audit_created', '建檔'), {},
                            <input type="text" value={`${fields.c_created_by}${fields.c_created_date ? ' ' + tr('audit_at', '於') + ' ' + fields.c_created_date : ''}`} readOnly disabled style={{ ...gInputStyle, ...gReadonlyStyle }} />) : null}
                        {fields.c_modified_by ? gridCell(tr('audit_updated', '更新'), {},
                            <input type="text" value={`${fields.c_modified_by}${fields.c_modified_date ? ' ' + tr('audit_at', '於') + ' ' + fields.c_modified_date : ''}`} readOnly disabled style={{ ...gInputStyle, ...gReadonlyStyle }} />) : null}
                    </div>
                </div>
            ) : null}

            {(canEdit || canPropose) && (
                <div style={{ marginBottom: 16 }}>
                    <GridLabel label={tr('modification_note_label', '修改說明')} />
                    <textarea value={comment} onChange={(e) => setComment(e.target.value)} rows={3} style={{ ...gInputStyle, height: 'auto' }}
                        placeholder={tr('modification_note_placeholder', '提案時請說明修改原因')} />
                </div>
            )}

            <div style={gSubmitRow}>
                {canEdit ? <button type="button" style={gPrimaryBtn} disabled={saving || (mode === 'edit' && !dirty)} onClick={() => void save('direct')}>{saving ? <><BtnSpinner />{tr('saving', '儲存中…')}</> : tr('save_directly', '直接保存')}</button> : null}
                {(canEdit || canPropose) ? <button type="button" style={gInfoBtn} disabled={saving || (mode === 'edit' && !dirty)} onClick={() => void save('proposal')}>{saving ? <><BtnSpinner />{tr('saving', '儲存中…')}</> : tr('submit_proposal', '提交建議')}</button> : null}
                <ActionStatus saving={saving} deleting={deleting} message={message} error={error} t={t} />
                <div style={gBtnGroupRight}>
                    {mode === 'edit' && canEdit && deleteEndpoint ? <button type="button" style={gDangerBtn} disabled={deleting} onClick={() => void doDelete()}>{tr('delete', '刪除')}</button> : null}
                    <a href={indexUrl} style={gCancelBtn}>{tr('cancel', '取消')}</a>
                </div>
            </div>
            {dirty ? <div style={{ marginTop: 8, color: '#92400e', fontSize: '0.8rem' }}>{tr('unsaved_changes', '有未儲存的變更')}</div> : null}
        </div>
    );
}

const titleStyle: React.CSSProperties = { fontSize: '1.1rem', fontWeight: 700, marginBottom: 12 };
const checkRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, padding: '4px 0', fontSize: '1rem', color: '#374151' };
