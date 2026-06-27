import React, { useEffect, useMemo, useRef, useState } from 'react';
import ActionStatus, { BtnSpinner } from './PersonEditorShared/ActionStatus';
import { redirectAfterSubresourceCreate } from './PersonEditorShared/afterCreate';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';
import AiCodeLookupPanel, { AiCandidate } from './PersonEditorShared/AiCodeLookupPanel';
import {
    gridCardStyle, gGrid, gInputStyle, gReadonlyStyle, gOkStyle, gErrStyle,
    gSubmitRow, gBtnGroupRight, gPrimaryBtn, gInfoBtn, gDangerBtn, gCancelBtn,
    gAuditWrapStyle, gridSectionHeadStyle, GridLabel, gridCell, gridInput,
} from './PersonEditorShared/grid';

/**
 * 社會區分（statuses）編輯器（對齊 legacy biogmains/statuses/_form.blade.php，非 person-browser）。
 * 欄位：序號(c_sequence) / 社會區分(c_status_code，status 搜尋) / 補充說明(c_supplement) /
 * 起始年(EraTimeField 無農曆，year=c_firstyear) / 終止年(EraTimeField 無農曆，year=c_lastyear) /
 * 出處(c_source，text 搜尋) / 頁碼(c_pages) / 備註(c_notes) / textperson_pair /
 * AI 智能識別社會區分類別代碼（填入 c_status_code）。
 *
 * 複合主鍵 3 段（c_personid, c_sequence, c_status_code），皆 NOT NULL；除 c_personid 外
 * （c_sequence, c_status_code）可改鍵，空值正規化為 '0'（未詳），對齊 legacy emptyToSentinel。
 * 起/終年的 year 為非主鍵真實欄（c_firstyear / c_lastyear），與 entries 的 c_year 不同（statuses
 * 的年份不參與主鍵）。
 */
type Fields = Record<string, string>;

interface Props {
    personId: number;
    personLabel: string;
    dynastyCode?: number | null;
    mode: 'create' | 'edit';
    initialFields: Fields;
    initialLabels?: Fields;
    canEdit: boolean;
    canPropose: boolean;
    aiEnabled: boolean;
    aiModel?: string;
    aiSuggestEndpoint: string;
    createEndpoint: string;
    mutateEndpoint: string;
    deleteEndpoint?: string;
    indexUrl: string;
    routeName?: string;
    t?: (k: string) => string;
}

const PK = ['c_personid', 'c_sequence', 'c_status_code'];
// c_personid 固定；c_sequence、c_status_code 皆可改鍵。
const EDITABLE_PK = ['c_sequence', 'c_status_code'];
// 起始年群組：year 對映非主鍵真實欄 c_firstyear；nhCode/nhYear/range 為非主鍵真實欄。
const FY = { year: 'c_firstyear', nhCode: 'c_fy_nh_code', nhYear: 'c_fy_nh_year', range: 'c_fy_range' };
// 終止年群組：year 對映 c_lastyear。
const LY = { year: 'c_lastyear', nhCode: 'c_ly_nh_code', nhYear: 'c_ly_nh_year', range: 'c_ly_range' };
// 非主鍵可寫欄位（提交 changes 用）。對齊 StatusMutationHandler / StatusCreateHandler allowedFields。
const NON_PK = [
    'c_supplement',
    'c_firstyear', 'c_fy_nh_code', 'c_fy_nh_year', 'c_fy_range',
    'c_lastyear', 'c_ly_nh_code', 'c_ly_nh_year', 'c_ly_range',
    'c_source', 'c_pages', 'c_notes',
];

type EraGroup = typeof FY;

export default function StatusEditor({
    personId, personLabel, dynastyCode = null, mode, initialFields, initialLabels = {},
    canEdit, canPropose, aiEnabled, aiModel, aiSuggestEndpoint,
    createEndpoint, mutateEndpoint, deleteEndpoint, indexUrl, routeName, t,
}: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    // 新增預設：主鍵 NOT NULL 皆 '0'（c_sequence legacy 預設 '0' 且 required；c_status_code 預設 0=未詳）；
    // c_source 雖可空，legacy 仍 emptyToSentinel→0，故 create 預設 '0'（編輯模式由 initialFields 覆蓋）。
    const base: Fields = {
        c_personid: String(personId),
        c_sequence: '0', c_status_code: '',
        c_source: '0',
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
    const [statusHighlight, setStatusHighlight] = useState(false);
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

    const onPickTextperson = (p: { source: string; pages: string; sourceLabel: string }) => {
        setFields((prev) => ({ ...prev, c_source: p.source, c_pages: p.pages }));
        setLabel('c_source', p.sourceLabel);
        setSourceHighlight(true);
        window.setTimeout(() => setSourceHighlight(false), 4000);
        setMessage(tr('update_source_success', '已自動回填出處與頁碼'));
    };

    // === AI 智能識別社會區分類別代碼（對齊 legacy btn-ai-code-lookup） ===
    const applyAiCode = (c: AiCandidate) => {
        set('c_status_code', String(c.code_id));
        setLabel('c_status_code', `${c.code_id} ${c.desc_chn} ${c.desc_en}`.trim());
        setStatusHighlight(true);
        window.setTimeout(() => setStatusHighlight(false), 4000);
    };

    const save = async (sm: 'direct' | 'proposal') => {
        // 序號為新增必填（legacy required）。
        if (mode === 'create' && !(fields.c_sequence ?? '').trim()) {
            setError(tr('please_fill_sequence', '請填寫序號')); return;
        }
        // 社會區分 c_status_code 為主碼，必填（拒絕 0/未詳）：僅新增時擋；編輯既有列不卡。
        if (mode === 'create' && (!fields.c_status_code || fields.c_status_code === '0')) {
            setError(tr('please_select_status', '請選擇社會區分')); return;
        }
        // 編輯模式：可改鍵欄位不可被清空（清空 + skip-changes 會造成 client/DB PK 失準）。
        if (mode === 'edit') {
            for (const k of EDITABLE_PK) {
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
            // 可改鍵：c_sequence、c_status_code。PK NOT NULL，空值送 '0'；與原值（正規化後）不同才送。
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
                body: JSON.stringify({ resource: 'statuses', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(sm === 'proposal' && comment ? { meta: { comment } } : {}) }),
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
        if (!deleteEndpoint || !window.confirm(tr('status_delete_confirm', '確定刪除此社會區分記錄？'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'statuses', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setSavedSnapshot(JSON.stringify(fields));
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const textRow = (key: string, label: string, code?: string, highlight = false, placeholder?: string) => (
        gridCell(label, { code }, gridInput({ value: fields[key] ?? '', onChange: (v) => set(key, v), disabled: !editable, highlight, placeholder }))
    );

    return (
        <div style={gridCardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('status_create', '新增社會區分') : tr('status_edit', '編輯社會區分')}</h3>
            {message ? <div style={gOkStyle}>{message}</div> : null}
            {error ? <div style={gErrStyle}>{error}</div> : null}

            {aiEnabled && editable ? (
                <AiCodeLookupPanel
                    table="STATUS_CODES"
                    personId={personId}
                    aiSuggestEndpoint={aiSuggestEndpoint}
                    aiModel={aiModel}
                    routeName={routeName}
                    title={tr('ai_status_recognition', 'AI 智能識別社會區分類別代碼')}
                    placeholder={tr('ai_input_placeholder_status', '例如：通醫學')}
                    hint={tr('ai_description_hint_status', '描述社會區分，AI 會建議代碼。')}
                    onApply={applyAiCode}
                />
            ) : null}

            <div style={gGrid}>
                {gridCell(tr('status', '社會區分'), { code: 'c_status_code', required: true },
                    <div style={statusHighlight ? { background: 'var(--highlight)', color: 'var(--highlight-foreground)', borderRadius: 6 } : undefined}>
                        <CodeAutocomplete mode="search" endpoint="/api/select/search/status"
                            value={fields.c_status_code ?? '0'} initialLabel={labels.c_status_code ?? ''} disabled={!editable}
                            onChange={(v, l) => { set('c_status_code', v || '0'); setLabel('c_status_code', l); }} /></div>)}

                {/* 次序非重點，置於社會區分右側（#103） */}
                {gridCell(tr('sequence', '次序'), { code: 'c_sequence', required: true },
                    <input type="number" value={fields.c_sequence ?? ''} disabled={!editable} required maxLength={4}
                        onChange={(e) => set('c_sequence', e.target.value)} style={{ ...gInputStyle, ...(!editable ? gReadonlyStyle : {}) }} />)}

                {textRow('c_supplement', tr('supplement_text', '補充說明'), 'c_supplement', false, tr('supplement_placeholder', ''))}

                {gridCell(tr('start_year', '起始年'), { code: 'c_firstyear', full: true },
                    <EraTimeField values={buildEra(FY)} onChange={(p) => applyEra(FY, p)} dynastyCode={dynastyCode} showRange disabled={!editable} />)}

                {gridCell(tr('end_year', '終止年'), { code: 'c_lastyear', full: true },
                    <EraTimeField values={buildEra(LY)} onChange={(p) => applyEra(LY, p)} dynastyCode={dynastyCode} showRange disabled={!editable} />)}

                {gridCell(tr('source_field', '出處'), { code: 'c_source' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                        value={fields.c_source ?? ''} initialLabel={labels.c_source ?? ''} disabled={!editable}
                        aria-invalid={sourceHighlight}
                        onChange={(v, l) => { set('c_source', v || '0'); setLabel('c_source', l); }} />)}
                {textRow('c_pages', tr('pages_entries', '頁碼'), 'c_pages', sourceHighlight)}
                {gridCell(tr('notes_field', '備註'), { code: 'c_notes', full: true },
                    <textarea value={fields.c_notes ?? ''} disabled={!editable} onChange={(e) => set('c_notes', e.target.value)} rows={4} style={{ ...gInputStyle, height: 'auto', ...(!editable ? gReadonlyStyle : {}) }} />)}
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
            {dirty ? <div style={{ marginTop: 8, color: 'var(--warning-subtle-foreground)', fontSize: '0.8rem' }}>{tr('unsaved_changes', '有未儲存的變更')}</div> : null}
        </div>
    );
}

const titleStyle: React.CSSProperties = { fontSize: '1.1rem', fontWeight: 700, marginBottom: 12 };
