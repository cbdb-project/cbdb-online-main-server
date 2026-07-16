import React, { useEffect, useMemo, useRef, useState } from 'react';
import ActionStatus, { BtnSpinner } from './PersonEditorShared/ActionStatus';
import { redirectAfterSubresourceCreate } from './PersonEditorShared/afterCreate';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';
import {
    gridCardStyle, gGrid, gPairRow, gInputStyle, gReadonlyStyle, gOkStyle, gErrStyle,
    gSubmitRow, gBtnGroupRight, gPrimaryBtn, gInfoBtn, gDangerBtn, gCancelBtn,
    gAuditWrapStyle, gHiddenSubmitStyle, gridSectionHeadStyle, GridLabel, gridCell, gridInput,
} from './PersonEditorShared/grid';

/**
 * 入仕（entries）編輯器（對齊 legacy biogmains/entries/_form.blade.php，非 person-browser）。
 * 欄位：序號 / 入仕途徑(entry 搜尋) / 入仕年(EraTimeField 無農曆，year 即主鍵 c_year) /
 * 等第 / 應試次數 / 入仕科目 / 父祖狀態(parentstatus 清單) / 入仕地(addr 搜尋，朝代範圍過濾) /
 * 年齡 / 任官註記 / 親屬碼·親屬人(kincode/biog 搜尋) / 社會關係碼·關係人(assoccode/biog 搜尋) /
 * 社會機構(socialinstcode 合併搜尋→拆 c_inst_code + c_inst_name_code) / 出處·頁碼·備註 / textperson_pair。
 *
 * 複合主鍵 10 段（c_personid + c_entry_code, c_sequence, c_kin_code, c_assoc_code, c_kin_id,
 * c_year, c_assoc_id, c_inst_code, c_inst_name_code），皆 NOT NULL；除 c_personid 外皆可改鍵，
 * 空值正規化為 '0'（未詳），對齊 legacy emptyToSentinel。c_year 由 EraTimeField 的 year 欄驅動，亦為主鍵。
 */
type Fields = Record<string, string>;

interface Props {
    personId: number;
    personLabel: string;
    dynastyCode?: number | null;
    dynastyStart?: string;
    dynastyEnd?: string;
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

const PK = ['c_personid', 'c_entry_code', 'c_sequence', 'c_kin_code', 'c_assoc_code', 'c_kin_id', 'c_year', 'c_assoc_id', 'c_inst_code', 'c_inst_name_code'];
// c_personid 固定，其餘 9 段皆可改鍵。
const EDITABLE_PK = ['c_entry_code', 'c_sequence', 'c_kin_code', 'c_assoc_code', 'c_kin_id', 'c_year', 'c_assoc_id', 'c_inst_code', 'c_inst_name_code'];
// 年號（時間）群組：year 對映主鍵 c_year，nhCode/nhYear/range 為非主鍵真實欄。
const EY = { year: 'c_year', nhCode: 'c_entry_nh_id', nhYear: 'c_entry_nh_year', range: 'c_entry_range' };
// 非主鍵可寫欄位（提交 changes 用）。c_entry_nh_id/nh_year/range 為年號子欄。
const NON_PK = [
    'c_entry_nh_id', 'c_entry_nh_year', 'c_entry_range',
    'c_exam_rank', 'c_attempt_count', 'c_exam_field', 'c_parental_status_code',
    'c_entry_addr_id', 'c_age', 'c_posting_notes',
    'c_source', 'c_pages', 'c_notes',
];

type EraGroup = typeof EY;

export default function EntryEditor({
    personId, personLabel, dynastyCode = null, dynastyStart, dynastyEnd, mode, initialFields, initialLabels = {},
    canEdit, canPropose, createEndpoint, mutateEndpoint, deleteEndpoint, indexUrl, t,
}: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    // 新增預設：主鍵 NOT NULL 皆 '0'；c_sequence legacy 預設 '0' 且 required；
    // c_source / c_entry_addr_id 雖可空，legacy 仍 emptyToSentinel→0，故 create 預設 '0'（編輯模式由 initialFields 覆蓋）。
    const base: Fields = {
        c_personid: String(personId),
        c_entry_code: '', c_sequence: '0', c_kin_code: '0', c_assoc_code: '0', c_kin_id: '0',
        c_year: '0', c_assoc_id: '0', c_inst_code: '0', c_inst_name_code: '0',
        c_source: '0', c_entry_addr_id: '0',
        ...initialFields,
    };
    const [fields, setFields] = useState<Fields>(base);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const [savedSnapshot, setSavedSnapshot] = useState(JSON.stringify(base));
    const msgTimer = useRef<number | null>(null);
    const originalPk = useRef<Record<string, number>>(Object.fromEntries(PK.map((k) => [k, Number(initialFields[k] ?? (k === 'c_personid' ? personId : 0))])));
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const flashSaved = (m: string) => { setMessage(m); if (msgTimer.current) window.clearTimeout(msgTimer.current); msgTimer.current = window.setTimeout(() => setMessage(null), 3000); };
    useEffect(() => () => { if (msgTimer.current) window.clearTimeout(msgTimer.current); }, []);
    const [error, setError] = useState<string | null>(null);
    const [sourceHighlight, setSourceHighlight] = useState(false);
    const [comment, setComment] = useState('');

    const dirty = useMemo(() => JSON.stringify(fields) !== savedSnapshot, [fields, savedSnapshot]);
    const set = (k: string, v: string) => setFields((p) => ({ ...p, [k]: v }));
    const setLabel = (k: string, v: string) => setLabels((p) => ({ ...p, [k]: v }));
    const editable = canEdit || canPropose;

    const buildEra = (g: EraGroup): EraTimeFieldValues => ({
        year: fields[g.year] ?? '', nhCode: fields[g.nhCode] ?? '', nhCodeLabel: labels[g.nhCode] ?? '',
        nhYear: fields[g.nhYear] ?? '', range: fields[g.range] ?? '', rangeLabel: labels[g.range] ?? '',
        intercalary: '0', month: '', day: '', dayGz: '', dayGzLabel: '',
    });
    const applyEra = (g: EraGroup, patch: Partial<EraTimeFieldValues>) => {
        setFields((prev) => {
            const next = { ...prev };
            (['year', 'nhCode', 'nhYear', 'range'] as const).forEach((kk) => {
                if (patch[kk] !== undefined) next[g[kk]] = patch[kk] as string;
            });
            return next;
        });
        if (patch.nhCodeLabel !== undefined) setLabel(g.nhCode, patch.nhCodeLabel);
        if (patch.rangeLabel !== undefined) setLabel(g.range, patch.rangeLabel);
    };

    // 社會機構合併欄位：搜尋回傳 id = `inst_code-inst_name_code`，拆分填入兩欄；清空視為未詳 0-0。
    const instCombined = (Number(fields.c_inst_code ?? 0) || Number(fields.c_inst_name_code ?? 0))
        ? `${fields.c_inst_code ?? '0'}-${fields.c_inst_name_code ?? '0'}` : '';
    const onPickInst = (v: string, l: string) => {
        if (!v) { set('c_inst_code', '0'); set('c_inst_name_code', '0'); setLabel('c_inst_code', ''); return; }
        const dash = v.indexOf('-');
        const code = dash >= 0 ? v.slice(0, dash) : v;
        const nameCode = dash >= 0 ? v.slice(dash + 1) : '0';
        set('c_inst_code', code || '0');
        set('c_inst_name_code', nameCode || '0');
        setLabel('c_inst_code', l);
    };

    const onPickTextperson = (p: { source: string; pages: string; sourceLabel: string }) => {
        setFields((prev) => ({ ...prev, c_source: p.source, c_pages: p.pages }));
        setLabel('c_source', p.sourceLabel);
        setSourceHighlight(true);
        window.setTimeout(() => setSourceHighlight(false), 4000);
        setMessage(tr('update_source_success', '已自動回填出處與頁碼'));
    };

    const save = async (sm: 'direct' | 'proposal') => {
        // 序號為新增必填（legacy required）。
        if (mode === 'create' && !(fields.c_sequence ?? '').trim()) {
            setError(tr('please_fill_sequence', '請填寫序號')); return;
        }
        // 入仕途徑 c_entry_code 為主碼，必填（拒絕 0/未詳）：僅新增時擋；編輯既有列不卡。
        if (mode === 'create' && (!fields.c_entry_code || fields.c_entry_code === '0')) {
            setError(tr('please_select_entry', '請選擇入仕途徑')); return;
        }
        // 編輯模式：可改主鍵不可被清空（清空會靜默正規化為 0 或 client/DB PK 失準）。僅擋空、允許既有 0。
        // c_year（入仕年）可未詳（空→0 合法），故排除；其餘可改 PK 段皆擋空。
        if (mode === 'edit') {
            for (const k of EDITABLE_PK) {
                if (k === 'c_year') continue;
                if (!(fields[k] ?? '').trim()) { setError(tr('pk_field_required', '主鍵欄位不可為空')); return; }
            }
        }
        setSaving(true); setError(null); setMessage(null);
        // 主鍵 NOT NULL：空值正規化為 '0'（未詳），對齊 legacy。
        const pkVal = (k: string) => (k === 'c_personid' ? String(personId) : (fields[k]?.trim() ? fields[k] : '0'));
        let changes: Record<string, string | null>;
        let target: Record<string, number>;
        let endpoint: string;
        let operation: string;
        if (mode === 'create') {
            endpoint = createEndpoint; operation = 'create';
            target = Object.fromEntries(PK.map((k) => [k, Number(pkVal(k))]));
            changes = {};
            for (const k of NON_PK) { const v = fields[k] ?? ''; if (v !== '') changes[k] = v; }
        } else {
            endpoint = mutateEndpoint; operation = 'update'; target = originalPk.current;
            const initial: Fields = JSON.parse(savedSnapshot);
            changes = {};
            for (const k of NON_PK) { const v = fields[k] ?? ''; if ((initial[k] ?? '') !== v) changes[k] = v === '' ? null : v; }
            // 可改鍵：除 c_personid 外 9 段。PK NOT NULL，空值送 '0'；與原值（正規化後）不同才送。
            for (const k of EDITABLE_PK) {
                const cur = fields[k]?.trim() ? fields[k] : '0';
                const init = (initial[k]?.trim() ? initial[k] : '0');
                if (cur !== init) changes[k] = cur;
            }
            if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        }
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'entries', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(comment ? { meta: { comment } } : {}) }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            flashSaved(sm === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            // direct 儲存後從回傳列即時刷新唯讀稽核欄（建檔/更新），免重整；函式式合併避免 race，並併入 baseline 免誤判未存變更。
            const auditRow = (sm === 'direct' && json?.result?.row && typeof json.result.row === 'object') ? json.result.row as Record<string, unknown> : null;
            const auditPatch: Fields = {};
            if (auditRow) { for (const k of ['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date']) { if (auditRow[k] != null) auditPatch[k] = String(auditRow[k]); } }
            if (Object.keys(auditPatch).length > 0) setFields((prev) => ({ ...prev, ...auditPatch }));
            setSavedSnapshot(JSON.stringify({ ...fields, ...auditPatch }));
            if (mode === 'create') { redirectAfterSubresourceCreate(indexUrl, json, sm === 'direct'); }
            // 直接儲存若改了鍵：以「實際送出的 PK 變更」覆寫 originalPk（不可用 fields 重建，避免清空 Number('')=0 失準）。
            else if (sm === 'direct') {
                const nextPk = { ...originalPk.current };
                for (const k of EDITABLE_PK) { if (Object.prototype.hasOwnProperty.call(changes, k)) nextPk[k] = Number(changes[k]); }
                originalPk.current = nextPk;
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    const doDelete = async () => {
        if (!deleteEndpoint || !window.confirm(tr('entry_delete_confirm', '確定刪除此入仕記錄？'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'entries', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setSavedSnapshot(JSON.stringify(fields));
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const textRow = (key: string, label: string, code?: string, highlight = false, hint?: string) => (
        gridCell(label, { code, hint }, gridInput({ value: fields[key] ?? '', onChange: (v) => set(key, v), disabled: !editable, highlight, name: key }))
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
            <h3 style={titleStyle}>{mode === 'create' ? tr('entry_create', '新增入仕') : tr('entry_edit', '編輯入仕')}</h3>
            {message ? <div style={gOkStyle}>{message}</div> : null}
            {error ? <div style={gErrStyle}>{error}</div> : null}

            <div style={gGrid}>
                {gridCell(tr('entry_field', '入仕途徑'), { code: 'c_entry_code', required: true },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/entry"
                        value={fields.c_entry_code ?? '0'} initialLabel={labels.c_entry_code ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_entry_code', v || '0'); setLabel('c_entry_code', l); }} />)}

                {/* 次序非重點，置於入仕途徑右側（#102）；label 用「次序」而非地址用語「遷徙次序」 */}
                {gridCell(tr('sequence', '次序'), { code: 'c_sequence', required: mode === 'create' },
                    <input type="number" name="c_sequence" id="c_sequence" value={fields.c_sequence ?? ''} disabled={!editable} required maxLength={4}
                        onChange={(e) => set('c_sequence', e.target.value)} style={{ ...gInputStyle, ...(!editable ? gReadonlyStyle : {}) }} />)}

                {gridCell(tr('entry_year_field', '入仕年'), { code: 'c_year', full: true },
                    <EraTimeField values={buildEra(EY)} onChange={(p) => applyEra(EY, p)} dynastyCode={dynastyCode} showRange disabled={!editable} />)}

                {textRow('c_exam_rank', tr('exam_ranking', '等第'), 'c_exam_rank')}
                {textRow('c_attempt_count', tr('entry_attempt_count', '應試次數'), 'c_attempt_count', false, tr('arabic_numerals_hint', '請填阿拉伯數字(半形/半角)'))}
                {textRow('c_exam_field', tr('entry_exam_field', '入仕科目'), 'c_exam_field')}

                {gridCell(tr('entry_parental_status', '父祖狀態'), { code: 'c_parental_status_code' },
                    <CodeAutocomplete mode="list" model="parentstatus" idKey="c_parental_status_code"
                        labelKeys={['c_parental_status_code', 'c_parental_status_desc_chn', 'c_parental_status_desc']}
                        value={fields.c_parental_status_code ?? ''} initialLabel={labels.c_parental_status_code ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_parental_status_code', v); setLabel('c_parental_status_code', l); }} />)}

                {gridCell(tr('entry_addr', '入仕地'), { code: 'c_entry_addr_id' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/addr"
                        extraQuery={{ dy_start: dynastyStart ?? '', dy_end: dynastyEnd ?? '' }}
                        value={fields.c_entry_addr_id ?? '0'} initialLabel={labels.c_entry_addr_id ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_entry_addr_id', v || '0'); setLabel('c_entry_addr_id', l); }} />)}

                {textRow('c_age', tr('age_field', '年齡'), 'c_age')}
                {textRow('c_posting_notes', tr('entry_posting_notes', '任官註記'), 'c_posting_notes')}

                {gridCell(tr('kinship_code', '親屬關係碼'), { code: 'c_kin_code' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/kincode"
                        value={fields.c_kin_code ?? '0'} initialLabel={labels.c_kin_code ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_kin_code', v || '0'); setLabel('c_kin_code', l); }} />)}
                {gridCell(tr('kinship_person', '親屬人'), { code: 'c_kin_id' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/biog"
                        value={fields.c_kin_id ?? '0'} initialLabel={labels.c_kin_id ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_kin_id', v || '0'); setLabel('c_kin_id', l); }} />)}

                {gridCell(tr('assoc_code', '社會關係碼'), { code: 'c_assoc_code' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/assoccode"
                        value={fields.c_assoc_code ?? '0'} initialLabel={labels.c_assoc_code ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_assoc_code', v || '0'); setLabel('c_assoc_code', l); }} />)}
                {gridCell(tr('assoc_person', '關係人'), { code: 'c_assoc_id' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/biog"
                        value={fields.c_assoc_id ?? '0'} initialLabel={labels.c_assoc_id ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_assoc_id', v || '0'); setLabel('c_assoc_id', l); }} />)}

                {gridCell(tr('socialinst_field', '社會機構'), { code: 'social_institution' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/socialinstcode"
                        value={instCombined} initialLabel={labels.c_inst_code ?? ''} disabled={!editable}
                        onChange={onPickInst} />)}

                {/* 出處 + 頁碼 共佔一行（gPairRow：寬螢幕也並排、不被外層拆散） */}
                <div style={gPairRow}>
                    {gridCell(tr('source_field', '出處'), { code: 'c_source' },
                        <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                            value={fields.c_source ?? ''} initialLabel={labels.c_source ?? ''} disabled={!editable}
                            aria-invalid={sourceHighlight}
                            onChange={(v, l) => { set('c_source', v || '0'); setLabel('c_source', l); }} />)}
                    {textRow('c_pages', tr('pages_entries', '頁碼'), 'c_pages', sourceHighlight)}
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
                {(canEdit || canPropose) ? <button type="button" style={gInfoBtn} disabled={saving || (mode === 'edit' && !dirty)} onClick={() => void save('proposal')}>{saving ? <><BtnSpinner />{tr('saving', '儲存中…')}</> : tr('submit_proposal', '提交建議')}</button> : null}
                <ActionStatus saving={saving} deleting={deleting} message={message} error={error} t={t} />
                <div style={gBtnGroupRight}>
                    {mode === 'edit' && canEdit && deleteEndpoint ? <button type="button" style={gDangerBtn} disabled={deleting} onClick={() => void doDelete()}>{tr('delete', '刪除')}</button> : null}
                    <a href={indexUrl} style={gCancelBtn}>{tr('cancel', '取消')}</a>
                </div>
            </div>
            {dirty ? <div style={{ marginTop: 8, color: 'var(--warning-subtle-foreground)', fontSize: '0.8rem' }}>{tr('unsaved_changes', '有未儲存的變更')}</div> : null}
        </form>
    );
}

const titleStyle: React.CSSProperties = { fontSize: '1.1rem', fontWeight: 700, marginBottom: 12 };
