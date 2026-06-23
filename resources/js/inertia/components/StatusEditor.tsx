import React, { useMemo, useRef, useState } from 'react';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';

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

interface AiCandidate {
    code_id: number;
    desc_en: string;
    desc_chn: string;
    relevance: string;
    reason: string;
}

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
        c_sequence: '0', c_status_code: '0',
        c_source: '0',
        ...initialFields,
    };
    const [fields, setFields] = useState<Fields>(base);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const snapshot = useRef(JSON.stringify(base));
    const originalPk = useRef<Record<string, number>>(Object.fromEntries(PK.map((k) => [k, Number(initialFields[k] ?? (k === 'c_personid' ? personId : 0))])));
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [sourceHighlight, setSourceHighlight] = useState(false);
    const [statusHighlight, setStatusHighlight] = useState(false);
    const [comment, setComment] = useState('');

    // AI 智能識別狀態
    const [aiQuery, setAiQuery] = useState('');
    const [aiBusy, setAiBusy] = useState(false);
    const [aiError, setAiError] = useState<string | null>(null);
    const [aiCandidates, setAiCandidates] = useState<AiCandidate[] | null>(null);
    const [aiNotFound, setAiNotFound] = useState<string[]>([]);
    const [aiSummary, setAiSummary] = useState('');
    const [showAiNotice, setShowAiNotice] = useState(false);
    // 對齊 legacy ai_fill_log_id 隱藏欄（v2 mutation 後端目前不消費，但保留以利往後審計串接）。
    const aiFillLogId = useRef<number | null>(null);

    const dirty = useMemo(() => JSON.stringify(fields) !== snapshot.current, [fields]);
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
    const runAiLookup = async () => {
        const q = aiQuery.trim();
        if (!q) { setAiError(tr('ai_enter_description', '請輸入描述')); return; }
        setAiBusy(true); setAiError(null);
        setAiCandidates(null); setAiNotFound([]); setAiSummary('');
        try {
            const res = await fetch(aiSuggestEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    query: q,
                    table: 'STATUS_CODES',
                    person_id: personId,
                    route_name: routeName ?? '',
                    route_url: typeof window !== 'undefined' ? window.location.pathname : '',
                }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.success) {
                throw new Error(json?.error || tr('ai_recognition_failed', 'AI 識別失敗'));
            }
            if (typeof json.ai_fill_log_id === 'number') aiFillLogId.current = json.ai_fill_log_id;
            const data = json.data ?? {};
            setAiCandidates(Array.isArray(data.matched_codes) ? data.matched_codes : []);
            setAiNotFound(Array.isArray(data.not_found) ? data.not_found : []);
            setAiSummary(typeof data.summary === 'string' ? data.summary : '');
        } catch (e) {
            setAiError(e instanceof Error ? e.message : tr('ai_recognition_failed', 'AI 識別失敗'));
        } finally {
            setAiBusy(false);
        }
    };

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
            const initial: Fields = JSON.parse(snapshot.current);
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
            setMessage(sm === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            snapshot.current = JSON.stringify(fields);
            if (mode === 'create') { window.location.assign(indexUrl); }
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
        if (!deleteEndpoint || !window.confirm(tr('delete_confirm', '確定刪除此社會區分記錄？'))) return;
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
            snapshot.current = JSON.stringify(fields);
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const textRow = (key: string, label: string, highlight = false, placeholder?: string) => (
        <div style={rowStyle}><label style={labelStyle}>{label}</label><div style={fieldStyle}>
            <input type="text" value={fields[key] ?? ''} disabled={!editable} placeholder={placeholder}
                onChange={(e) => set(key, e.target.value)}
                style={{ ...inputStyle, ...(highlight ? { background: '#FFFFBB' } : {}), ...(!editable ? roStyle : {}) }} /></div></div>
    );

    const relevanceColor = (r: string) => (r === '高' ? '#16a34a' : r === '中' ? '#d97706' : '#64748b');

    return (
        <div style={cardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('status_create', '新增社會區分') : tr('status_edit', '編輯社會區分')} — {personLabel}</h3>
            {message ? <div style={okStyle}>{message}</div> : null}
            {error ? <div style={errStyle}>{error}</div> : null}

            {aiEnabled && editable ? (
                <div style={aiCardStyle}>
                    <div style={aiHeaderStyle}>
                        <strong><i className="fas fa-magic" /> {tr('ai_status_recognition', 'AI 智能識別社會區分類別代碼')}</strong>
                        <button type="button" style={aiNoticeToggle} onClick={() => setShowAiNotice((v) => !v)}>
                            <i className="fas fa-exclamation-triangle" /> {tr('ai_notice_title', '使用須知')}
                        </button>
                    </div>
                    {showAiNotice ? (
                        <div style={aiNoticeBox}>
                            <p style={{ margin: '0 0 6px' }}>{tr('ai_consent_intro', '使用本功能即表示您同意：')}</p>
                            <ul style={{ margin: 0, paddingLeft: 18 }}>
                                <li>{tr('ai_consent_record', '您的輸入將被記錄供品質改進。')}</li>
                                <li>{tr('ai_consent_third_party', '輸入內容將送至第三方 AI 服務。')}</li>
                                <li>{tr('ai_consent_verify', 'AI 結果僅供參考，請自行核實。')}</li>
                            </ul>
                            {aiModel ? <p style={{ margin: '6px 0 0' }}>{tr('ai_current_model', '目前模型：')}<code>{aiModel}</code></p> : null}
                        </div>
                    ) : null}
                    <div style={{ padding: 12 }}>
                        <label style={{ ...labelStyle, width: 'auto', display: 'block', paddingTop: 0 }}>{tr('ai_enter_description', '輸入文字描述')}</label>
                        <textarea value={aiQuery} disabled={aiBusy} rows={3} onChange={(e) => setAiQuery(e.target.value)}
                            placeholder={tr('ai_input_placeholder_status', '例如：通醫學')}
                            style={{ ...inputStyle, height: 'auto', width: '100%' }} />
                        <div style={{ fontSize: '0.78rem', color: '#64748b', marginTop: 4 }}>{tr('ai_description_hint_status', '描述社會區分，AI 會建議代碼。')}</div>
                        <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', gap: 12 }}>
                            <button type="button" style={infoBtn} disabled={aiBusy} onClick={() => void runAiLookup()}>
                                {aiBusy ? tr('ai_processing', '處理中…') : tr('ai_recognize_btn', 'AI 智能識別')}
                            </button>
                            {aiError ? <span style={{ color: '#b91c1c', fontSize: '0.82rem' }}>{aiError}</span> : null}
                        </div>
                        {aiCandidates ? (
                            <div style={{ marginTop: 12 }}>
                                <div style={{ fontWeight: 700, marginBottom: 6 }}>{tr('ai_candidate_codes', '候選代碼（點擊填入表單）')}</div>
                                {aiCandidates.length ? (
                                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                                        {aiCandidates.map((c) => (
                                            <button key={c.code_id} type="button" title={c.reason}
                                                style={{ ...aiCandidateBtn, borderColor: relevanceColor(c.relevance) }}
                                                onClick={() => applyAiCode(c)}>
                                                <strong>{c.code_id}</strong> {c.desc_chn} <small>({c.desc_en})</small>
                                            </button>
                                        ))}
                                    </div>
                                ) : <span style={{ color: '#64748b' }}>{tr('ai_no_match', '表中未找到對應的概念')}</span>}
                                {aiNotFound.length ? (
                                    <div style={{ marginTop: 8 }}>
                                        <div style={{ color: '#d97706', fontSize: '0.82rem' }}>{tr('ai_no_match', '表中未找到對應的概念')}</div>
                                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, marginTop: 4 }}>
                                            {aiNotFound.map((n, i) => <span key={`${n}-${i}`} style={aiBadge}>{n}</span>)}
                                        </div>
                                    </div>
                                ) : null}
                                {aiSummary ? <div style={aiSummaryBox}>{aiSummary}</div> : null}
                            </div>
                        ) : null}
                    </div>
                </div>
            ) : null}

            <div style={rowStyle}><label style={labelStyle}>{tr('sequence', '序號')} (c_sequence)</label><div style={fieldStyle}>
                <input type="number" value={fields.c_sequence ?? ''} disabled={!editable} required maxLength={4}
                    onChange={(e) => set('c_sequence', e.target.value)} style={{ ...inputStyle, ...(!editable ? roStyle : {}) }} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('status', '社會區分')} (c_status_code)</label><div style={fieldStyle}>
                <div style={statusHighlight ? { background: '#FFFFBB', borderRadius: 6 } : undefined}>
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/status"
                        value={fields.c_status_code ?? '0'} initialLabel={labels.c_status_code ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_status_code', v || '0'); setLabel('c_status_code', l); }} /></div></div></div>

            {textRow('c_supplement', tr('supplement_text', '補充說明') + ' (c_supplement)', false, tr('supplement_placeholder', ''))}

            <div style={rowStyle}><label style={labelStyle}>{tr('start_year', '起始年')} (c_firstyear)</label><div style={fieldStyle}>
                <EraTimeField values={buildEra(FY)} onChange={(p) => applyEra(FY, p)} dynastyCode={dynastyCode} showRange disabled={!editable} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('end_year', '終止年')} (c_lastyear)</label><div style={fieldStyle}>
                <EraTimeField values={buildEra(LY)} onChange={(p) => applyEra(LY, p)} dynastyCode={dynastyCode} showRange disabled={!editable} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('source_field', '出處')} (c_source)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                    value={fields.c_source ?? ''} initialLabel={labels.c_source ?? ''} disabled={!editable}
                    aria-invalid={sourceHighlight}
                    onChange={(v, l) => { set('c_source', v || '0'); setLabel('c_source', l); }} /></div></div>
            {textRow('c_pages', tr('pages_entries', '頁碼'), sourceHighlight)}
            <div style={rowStyle}><label style={labelStyle}>{tr('notes_field', '備註')} (c_notes)</label><div style={fieldStyle}>
                <textarea value={fields.c_notes ?? ''} disabled={!editable} onChange={(e) => set('c_notes', e.target.value)} rows={4} style={{ ...inputStyle, height: 'auto', ...(!editable ? roStyle : {}) }} /></div></div>

            <TextpersonPair personId={personId} label={tr('candidate_source_title', '候選出處')} onPick={onPickTextperson} disabled={!editable} />

            {mode === 'edit' && (fields.c_created_by || fields.c_modified_by) ? (
                <>
                    {fields.c_created_by ? (
                        <div style={rowStyle}><label style={labelStyle}>{tr('audit_created', '建檔')}</label><div style={fieldStyle}>
                            <input type="text" value={`${fields.c_created_by}${fields.c_created_date ? '/' + fields.c_created_date : ''}`} readOnly disabled style={{ ...inputStyle, ...roStyle }} /></div></div>
                    ) : null}
                    {fields.c_modified_by ? (
                        <div style={rowStyle}><label style={labelStyle}>{tr('audit_updated', '更新')}</label><div style={fieldStyle}>
                            <input type="text" value={`${fields.c_modified_by}${fields.c_modified_date ? '/' + fields.c_modified_date : ''}`} readOnly disabled style={{ ...inputStyle, ...roStyle }} /></div></div>
                    ) : null}
                </>
            ) : null}

            {(canEdit || canPropose) && (
                <div style={rowStyle}><label style={labelStyle}>{tr('modification_note_label', '修改說明')}</label><div style={fieldStyle}>
                    <textarea value={comment} onChange={(e) => setComment(e.target.value)} rows={3} style={{ ...inputStyle, height: 'auto' }}
                        placeholder={tr('modification_note_placeholder', '提案時請說明修改原因')} /></div></div>
            )}

            <div style={{ ...rowStyle, gap: 8 }}>
                <div style={{ width: 160, flexShrink: 0 }} />
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    {canEdit ? <button type="button" style={primaryBtn} disabled={saving} onClick={() => void save('direct')}>{tr('save_directly', '直接保存')}</button> : null}
                    {(canEdit || canPropose) ? <button type="button" style={infoBtn} disabled={saving} onClick={() => void save('proposal')}>{tr('submit_proposal', '提交建議')}</button> : null}
                    {mode === 'edit' && canEdit && deleteEndpoint ? <button type="button" style={dangerBtn} disabled={deleting} onClick={() => void doDelete()}>{tr('delete', '刪除')}</button> : null}
                    <a href={indexUrl} style={cancelBtn}>{tr('cancel', '取消')}</a>
                </div>
            </div>
            {dirty ? <div style={{ ...rowStyle, color: '#92400e', fontSize: '0.8rem' }}><div style={{ width: 160, flexShrink: 0 }} />{tr('unsaved_changes', '有未儲存的變更')}</div> : null}
        </div>
    );
}

const cardStyle: React.CSSProperties = { background: '#fff', border: '1px solid #e5e7eb', borderRadius: 10, padding: 20, maxWidth: '100%' };
const titleStyle: React.CSSProperties = { fontSize: '1.1rem', fontWeight: 700, marginBottom: 12 };
const rowStyle: React.CSSProperties = { display: 'flex', gap: 12, alignItems: 'flex-start', padding: '6px 0' };
const labelStyle: React.CSSProperties = { width: 160, flexShrink: 0, fontSize: '0.875rem', color: '#374151', paddingTop: 6 };
const fieldStyle: React.CSSProperties = { flex: 1, minWidth: 0 };
const inputStyle: React.CSSProperties = { width: '100%', height: 36, padding: '0 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: '0.875rem', boxSizing: 'border-box' };
const roStyle: React.CSSProperties = { background: '#f3f4f6', cursor: 'not-allowed' };
const okStyle: React.CSSProperties = { background: '#ecfdf5', border: '1px solid #a7f3d0', color: '#065f46', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const errStyle: React.CSSProperties = { background: '#fef2f2', border: '1px solid #fecaca', color: '#991b1b', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const primaryBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #255f93', background: '#255f93', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const infoBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #0e7490', background: '#0891b2', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const dangerBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #b91c1c', background: '#fff5f5', color: '#b91c1c', fontWeight: 700, cursor: 'pointer' };
const cancelBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #cbd5e1', background: '#fff', color: '#475569', fontWeight: 700, textDecoration: 'none', display: 'inline-flex', alignItems: 'center' };
const aiCardStyle: React.CSSProperties = { border: '1px solid #bae6fd', borderRadius: 10, marginBottom: 14, overflow: 'hidden' };
const aiHeaderStyle: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, background: '#0891b2', color: '#fff', padding: '8px 12px', fontSize: '0.92rem' };
const aiNoticeToggle: React.CSSProperties = { background: 'transparent', border: 'none', color: '#fff', opacity: 0.9, cursor: 'pointer', fontSize: '0.78rem' };
const aiNoticeBox: React.CSSProperties = { background: '#fffbeb', borderBottom: '1px solid #fde68a', color: '#92400e', padding: '8px 12px', fontSize: '0.82rem' };
const aiCandidateBtn: React.CSSProperties = { borderRadius: 8, padding: '6px 10px', border: '1px solid #cbd5e1', background: '#fff', cursor: 'pointer', fontSize: '0.82rem', textAlign: 'left' };
const aiBadge: React.CSSProperties = { background: '#f1f5f9', borderRadius: 6, padding: '2px 8px', fontSize: '0.78rem', color: '#475569' };
const aiSummaryBox: React.CSSProperties = { background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 6, padding: '8px 10px', marginTop: 8, fontSize: '0.82rem', color: '#334155' };
