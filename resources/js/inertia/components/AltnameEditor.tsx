import React, { useEffect, useMemo, useRef, useState } from 'react';
import ActionStatus, { BtnSpinner } from './PersonEditorShared/ActionStatus';
import { ProposalModeDialog } from './ui/ProposalModeDialog';
import { redirectAfterSubresourceCreate } from './PersonEditorShared/afterCreate';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';
import { detectUmlautConversions, applyUmlaut } from '../utils/pinyinUmlaut';
import type { UmlautConversion } from '../utils/pinyinUmlaut';
import {
    gridCardStyle, gGrid, gPairRow, gInputStyle, gReadonlyStyle, gOkStyle, gErrStyle,
    gSubmitRow, gBtnGroupRight, gPrimaryBtn, gInfoBtn, gDangerBtn, gCancelBtn,
    gAuditWrapStyle, gHiddenSubmitStyle, gridSectionHeadStyle, GridLabel, gridCell, gridInput,
} from './PersonEditorShared/grid';

/**
 * 別名（altname）編輯器（對齊 legacy biogmains/altname/_form，非 person-browser）。
 * 欄位：序號(c_sequence) / 別名(中) c_alt_name_chn(PK,字串) / 別名 c_alt_name /
 * 類型 c_alt_name_type_code(altcode 碼表) / 出處 c_source(text 搜尋) / 頁碼 / 備註 / textperson_pair。
 * 複合主鍵 (c_personid, c_alt_name_chn, c_alt_name_type_code)；c_alt_name_chn 為字串主鍵——
 * 構建 PK 時必須保留字串（不可 Number()）。對齊 legacy，別名(中)/類型於編輯時可改，後端 performUpdate 改鍵。
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
    t?: (k: string) => string;
}

const PK = ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'];
const EDITABLE_PK = ['c_alt_name_chn', 'c_alt_name_type_code'];
const NON_PK = ['c_alt_name', 'c_source', 'c_pages', 'c_notes', 'c_sequence'];
// 字串型主鍵欄（構建 PK 時保留字串，不可 Number()）。
const STRING_PK = new Set(['c_alt_name_chn']);

// 依欄位型別構建單一 PK 值（字串 PK 保留字串、其餘轉數字）。
function pkVal(key: string, raw: string, personId: number): string | number {
    if (key === 'c_personid') return Number(raw || personId);
    if (STRING_PK.has(key)) return raw ?? '';
    return Number(raw || 0);
}

export default function AltnameEditor({
    personId, personLabel, mode, initialFields, initialLabels = {},
    canEdit, canPropose, createEndpoint, mutateEndpoint, deleteEndpoint, indexUrl, t,
}: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    // 對齊 legacy：c_sequence 不預設值（建立時必填、由使用者決定序號），避免捏造序號造成排序錯誤。
    const base: Fields = { c_personid: String(personId), c_alt_name_chn: '', c_alt_name_type_code: '0', c_source: '0', ...initialFields };
    const [fields, setFields] = useState<Fields>(base);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const [savedSnapshot, setSavedSnapshot] = useState(JSON.stringify(base));
    const msgTimer = useRef<number | null>(null);
    const originalPk = useRef<Record<string, string | number>>(
        Object.fromEntries(PK.map((k) => [k, pkVal(k, initialFields[k] ?? (k === 'c_personid' ? String(personId) : ''), personId)])),
    );
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [confirmProposalMode, setConfirmProposalMode] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const flashSaved = (m: string) => { setMessage(m); if (msgTimer.current) window.clearTimeout(msgTimer.current); msgTimer.current = window.setTimeout(() => setMessage(null), 3000); };
    useEffect(() => () => { if (msgTimer.current) window.clearTimeout(msgTimer.current); }, []);
    const dirty = useMemo(() => JSON.stringify(fields) !== savedSnapshot, [fields, savedSnapshot]);
    const [error, setError] = useState<string | null>(null);
    const [sourceHighlight, setSourceHighlight] = useState(false);
    const [comment, setComment] = useState('');

    const set = (k: string, v: string) => setFields((p) => ({ ...p, [k]: v }));
    const setLabel = (k: string, v: string) => setLabels((p) => ({ ...p, [k]: v }));
    const editable = canEdit || canPropose;

    const onPickTextperson = (p: { source: string; pages: string; sourceLabel: string }) => {
        setFields((prev) => ({ ...prev, c_source: p.source, c_pages: p.pages }));
        setLabel('c_source', p.sourceLabel);
        setSourceHighlight(true);
        window.setTimeout(() => setSourceHighlight(false), 4000);
        setMessage(tr('update_source_success', '已自動回填出處與頁碼'));
    };

    // Tier 2：c_alt_name 可能含西文別名，保存時偵測是否命中 v→ü 規則；命中才彈窗由使用者決定。
    // 待確認的保存意圖（sm 模式、命中清單、依規則轉換後的值）；null 表示無待確認。
    const [umlautPrompt, setUmlautPrompt] = useState<{ sm: 'direct' | 'proposal'; conversions: UmlautConversion[]; converted: string } | null>(null);

    const save = async (sm: 'direct' | 'proposal') => {
        // 別名（拼音）c_alt_name 必填：建立與編輯皆不可為空（使用者指定，較 legacy 嚴格）。
        if ((fields.c_alt_name ?? '') === '') { setError(tr('altname_pinyin_required', '請輸入別名（拼音）')); return; }
        // Tier 2 閘：唯有依「我們的規則」（l/n+v 且後非 a/i/o/u）命中時，才彈窗；否則照舊直接提交。
        // 有命中即代表字串會改變（如 Lv→Lü），故直接開窗由使用者決定轉換／保留。
        const conversions = detectUmlautConversions(fields.c_alt_name);
        if (conversions.length > 0) {
            setError(null); setMessage(null);
            setUmlautPrompt({ sm, conversions, converted: applyUmlaut(fields.c_alt_name) });
            return;
        }
        await doSubmit(sm, fields.c_alt_name ?? '');
    };

    // 實際送出（altName 為 Tier 2 確認後採用的別名拼音值：轉換版或保留原值）。
    const doSubmit = async (sm: 'direct' | 'proposal', altName: string) => {
        setSaving(true); setError(null); setMessage(null);
        const effFields: Fields = { ...fields, c_alt_name: altName };
        let changes: Record<string, string | null>;
        let target: Record<string, string | number>;
        let endpoint: string;
        let operation: string;
        if (mode === 'create') {
            // 別名(中) 為主鍵，必填；c_sequence 對齊 legacy create 為必填。
            if ((effFields.c_alt_name_chn ?? '') === '') { setSaving(false); setError(tr('altname_required', '請輸入別名（中文）')); return; }
            if ((effFields.c_sequence ?? '') === '') { setSaving(false); setError(tr('sequence_required', '請輸入序號')); return; }
            endpoint = createEndpoint; operation = 'create';
            target = Object.fromEntries(PK.map((k) => [k, pkVal(k, effFields[k] ?? '', personId)]));
            changes = {};
            for (const k of NON_PK) { const v = effFields[k] ?? ''; if (v !== '') changes[k] = v; }
        } else {
            endpoint = mutateEndpoint; operation = 'update'; target = originalPk.current;
            // 主鍵欄（別名(中)/類型）不可清空：清空會讓送出的 changes 略過該欄，導致 DB 仍為舊鍵、
            // 但 client snapshot/PK 已失準（後續 save/delete 命中錯誤記錄）。對齊 legacy 直接擋下。
            for (const k of EDITABLE_PK) {
                if ((effFields[k] ?? '') === '') { setSaving(false); setError(tr('pk_required', '主鍵欄位不可為空')); return; }
            }
            const initial: Fields = JSON.parse(savedSnapshot);
            changes = {};
            for (const k of NON_PK) { const v = effFields[k] ?? ''; if ((initial[k] ?? '') !== v) changes[k] = v === '' ? null : v; }
            // 可改主鍵（別名(中)/類型）：對齊 legacy，後端據此改鍵（上方已保證非空）。
            for (const k of EDITABLE_PK) { const v = effFields[k] ?? ''; if ((initial[k] ?? '') !== v) changes[k] = v; }
            if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        }
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'altnames', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(comment ? { meta: { comment } } : {}) }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            flashSaved(sm === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            // direct 儲存後從回傳列即時刷新唯讀稽核欄（建檔/更新），免重整；函式式合併避免 race，並併入 baseline 免誤判未存變更。
            const auditRow = (sm === 'direct' && json?.result?.row && typeof json.result.row === 'object') ? json.result.row as Record<string, unknown> : null;
            const auditPatch: Fields = {};
            if (auditRow) { for (const k of ['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date']) { if (auditRow[k] != null) auditPatch[k] = String(auditRow[k]); } }
            // 同步 c_alt_name（若 Tier 2 採用轉換值）與稽核欄，使 UI 與 baseline 皆等於實際入庫值。
            setFields((prev) => ({ ...prev, c_alt_name: altName, ...auditPatch }));
            setSavedSnapshot(JSON.stringify({ ...effFields, ...auditPatch }));
            if (mode === 'create') { redirectAfterSubresourceCreate(indexUrl, json, sm === 'direct'); }
            // 直接儲存若改了主鍵：以「實際送出的 PK 變更」覆寫 originalPk（字串 PK 保留字串）。
            // 不可用 fields 重建（清空欄位會讓 client/DB PK 失準）。
            else if (sm === 'direct') {
                const nextPk = { ...originalPk.current };
                for (const k of EDITABLE_PK) {
                    if (Object.prototype.hasOwnProperty.call(changes, k)) {
                        nextPk[k] = STRING_PK.has(k) ? String(changes[k]) : Number(changes[k]);
                    }
                }
                originalPk.current = nextPk;
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    const doDelete = async () => {
        if (!deleteEndpoint || !window.confirm(tr('altname_delete_confirm', '確定刪除此別名？'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'altnames', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setSavedSnapshot(JSON.stringify(fields));
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const textRow = (key: string, label: string, code?: string, type = 'text', highlight = false, required = false) => (
        gridCell(label, { code, required }, gridInput({ value: fields[key] ?? '', onChange: (v) => set(key, v), type, disabled: !editable, highlight, name: key }))
    );

    // 回車保存（復刻舊版 Blade：表單內於單行輸入框按 Enter 觸發提交）。以原生 <form> 實現，
    // 因此 textarea 換行、輸入法（IME）選字用的 Enter 皆為瀏覽器原生行為、不會誤觸提交；
    // 表單內按鈕皆為 type="button" 不隱式提交。canEdit → 直接保存；否則可提案者 → 提交建議。
    const onFormSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (saving || deleting || (mode === 'edit' && !dirty)) return;
        if (canEdit) {
            void save('direct');
        } else if (canPropose) {
            void save('proposal');
        }
    };

    return (
        <form style={gridCardStyle} onSubmit={onFormSubmit}>
            <button type="submit" aria-hidden="true" tabIndex={-1} style={gHiddenSubmitStyle} />
            <h3 style={titleStyle}>{mode === 'create' ? tr('altname_create', '新增別名') : tr('altname_edit', '編輯別名')}</h3>
            {message ? <div style={gOkStyle}>{message}</div> : null}
            {error ? <div style={gErrStyle}>{error}</div> : null}

            {umlautPrompt ? (
                <div style={umlautBackdropStyle} role="dialog" aria-modal="true" aria-label={tr('pinyin_umlaut_title', '偵測到拼音 v → ü')}>
                    <div style={umlautCardStyle}>
                        <div style={{ fontWeight: 700, marginBottom: 8 }}>{tr('pinyin_umlaut_title', '偵測到拼音 v → ü')}</div>
                        <div style={{ marginBottom: 8, fontSize: '0.9rem', color: '#444' }}>
                            {tr('pinyin_umlaut_hint', '「別名（拼音）」可能含以 v 代寫的 ü。是否依漢語拼音規則轉換？若為西文別名（如 Denver）請選「保留原樣」。')}
                        </div>
                        <ul style={{ margin: '0 0 10px', paddingLeft: 18 }}>
                            {umlautPrompt.conversions.map((c, i) => (
                                <li key={`${c.index}-${i}`}><code>{c.from}</code> → <code>{c.to}</code></li>
                            ))}
                        </ul>
                        <div style={{ fontSize: '0.85rem', color: '#666', marginBottom: 14, wordBreak: 'break-all' }}>
                            {tr('pinyin_umlaut_preview', '轉換後：')}<code>{umlautPrompt.converted}</code>
                        </div>
                        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
                            <button type="button" style={gCancelBtn} onClick={() => setUmlautPrompt(null)}>{tr('cancel', '取消')}</button>
                            <button type="button" style={gInfoBtn} onClick={() => { const p = umlautPrompt; setUmlautPrompt(null); void doSubmit(p.sm, fields.c_alt_name ?? ''); }}>{tr('pinyin_umlaut_keep', '保留原樣並儲存')}</button>
                            <button type="button" style={gPrimaryBtn} onClick={() => { const p = umlautPrompt; setUmlautPrompt(null); void doSubmit(p.sm, p.converted); }}>{tr('pinyin_umlaut_convert', '轉換並儲存')}</button>
                        </div>
                    </div>
                </div>
            ) : null}

            <div style={gGrid}>
                {/* 類型置最左（首位，使用者指定）；其後別名(中)+拼音相鄰，次序去強調置後 */}
                {gridCell(tr('altname_type', '類型'), { code: 'c_alt_name_type_code' },
                    <CodeAutocomplete mode="list" model="altcode" idKey="c_name_type_code" labelKeys={['c_name_type_code', 'c_name_type_desc_chn', 'c_name_type_desc']}
                        value={fields.c_alt_name_type_code ?? '0'} initialLabel={labels.c_alt_name_type_code ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_alt_name_type_code', v); setLabel('c_alt_name_type_code', l); }} />)}
                {textRow('c_alt_name_chn', tr('altname_chn', '別名（中）'), 'c_alt_name_chn', 'text', false, true)}
                {textRow('c_alt_name', tr('altname_pinyin_label', '別名（拼音）'), 'c_alt_name', 'text', false, true)}
                {textRow('c_sequence', tr('sequence', '次序'), 'c_sequence', 'number', false, mode === 'create')}

                {/* 出處 + 頁碼 共佔一行（gPairRow：寬螢幕也並排、不被外層拆散） */}
                <div style={gPairRow}>
                    {gridCell(tr('source_field', '出處'), { code: 'c_source' },
                        <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                            value={fields.c_source ?? ''} initialLabel={labels.c_source ?? ''} disabled={!editable}
                            onChange={(v, l) => { set('c_source', v); setLabel('c_source', l); }} />)}
                    {textRow('c_pages', tr('pages_entries', '頁碼'), 'c_pages', 'text', sourceHighlight)}
                </div>
                {gridCell(tr('notes_field', '備註'), { code: 'c_notes', full: true },
                    <textarea name="c_notes" id="c_notes" value={fields.c_notes ?? ''} disabled={!editable} onChange={(e) => set('c_notes', e.target.value)} rows={4} style={{ ...gInputStyle, height: 'auto', ...(!editable ? gReadonlyStyle : {}) }} />)}
            </div>

            <TextpersonPair personId={personId} label={tr('candidate_source_title', '候選出處')} onPick={onPickTextperson} disabled={!editable} />

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
                    <textarea name="modification_note" id="modification_note" value={comment} onChange={(e) => setComment(e.target.value)} rows={3} style={{ ...gInputStyle, height: 'auto' }}
                        placeholder={tr('modification_note_placeholder', '提案時請說明修改原因')} />
                </div>
            )}

            <div style={gSubmitRow}>
                {canEdit ? <button type="button" style={gPrimaryBtn} disabled={saving || (mode === 'edit' && !dirty)} onClick={() => void save('direct')}>{saving ? <><BtnSpinner />{tr('saving', '儲存中…')}</> : tr('save_directly', '直接保存')}</button> : null}
                {(canEdit || canPropose) ? <button type="button" style={gInfoBtn} disabled={saving || (mode === 'edit' && !dirty)} onClick={() => (canEdit ? setConfirmProposalMode(true) : void save('proposal'))}>{saving ? <><BtnSpinner />{tr('saving', '儲存中…')}</> : tr('submit_proposal', '提交建議')}</button> : null}
                <ActionStatus saving={saving} deleting={deleting} message={message} error={error} t={t} />
                <div style={gBtnGroupRight}>
                    {mode === 'edit' && canEdit && deleteEndpoint ? <button type="button" style={gDangerBtn} disabled={deleting} onClick={() => void doDelete()}>{tr('delete', '刪除')}</button> : null}
                    <a href={indexUrl} style={gCancelBtn}>{tr('cancel', '取消')}</a>
                </div>
            </div>
            <ProposalModeDialog
                open={confirmProposalMode}
                onOpenChange={setConfirmProposalMode}
                title={tr('direct_save_prompt_title', '直接保存還是提交提案？')}
                description={tr('direct_save_prompt_desc', '您具有直接保存的權限。直接保存會立即套用變更；提交提案則需等待其他同事審核後才會套用。請選擇您想要的方式。')}
                saveDirectLabel={tr('save_directly', '直接保存')}
                submitProposalLabel={tr('submit_proposal', '提交建議')}
                cancelLabel={tr('cancel', '取消')}
                loading={saving}
                onSaveDirect={() => { setConfirmProposalMode(false); void save('direct'); }}
                onSubmitProposal={() => { setConfirmProposalMode(false); void save('proposal'); }}
            />
        </form>
    );
}

const titleStyle: React.CSSProperties = { fontSize: '1.1rem', fontWeight: 700, marginBottom: 12 };

const umlautBackdropStyle: React.CSSProperties = {
    position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.35)', zIndex: 1050,
    display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16,
};
const umlautCardStyle: React.CSSProperties = {
    background: '#fff', borderRadius: 6, padding: 20, maxWidth: 480, width: '100%',
    boxShadow: '0 8px 32px rgba(0,0,0,0.25)',
};
