import React, { useMemo, useRef, useState } from 'react';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';

/**
 * 社會關係（associations / ASSOC_DATA）編輯器（對齊 legacy biogmains/assoc/_form.blade.php，非 person-browser）。
 * 欄位最多、9 段複合主鍵的編輯器。
 *
 * === 互逆配對碼（pair codes）由後端自動補齊 ===
 * legacy 用前端 JS 查 assocpair/kinpair 填 c_assocship_pair/c_kinship_pair/c_assoc_kinship_pair（hidden）送出。
 * v2 後端 AssociationCreate/MutationHandler 已對「未送的配對碼」以代碼表權威值補齊
 * （ASSOC_CODES.c_assoc_pair / KINSHIP_CODES.c_kin_pair1），故本編輯器**不送 pair**，後端保證鏡像關係碼正確。
 *
 * === 9 段複合主鍵 ===
 * c_personid, c_assoc_code, c_assoc_id, c_kin_code, c_kin_id, c_assoc_kin_code, c_assoc_kin_id,
 * c_text_title(varchar,'[n/a]'哨兵), c_assoc_first_year(start year,'-9999'哨兵)。
 * 編輯模式 PK 段可改（改 c_assoc_code 等→後端鏡像遷移）；空值正規化哨兵；改鍵後重同步 originalPk。
 */
type Fields = Record<string, string>;
interface AiCandidate { code_id: number | string; desc_chn?: string; desc_en?: string; relevance?: string }

interface Props {
    personId: number;
    personLabel: string;
    dynastyCode?: number | null;
    mode: 'create' | 'edit';
    initialFields: Fields;
    initialLabels?: Fields;
    canEdit: boolean;
    canPropose: boolean;
    createEndpoint: string;
    mutateEndpoint: string;
    deleteEndpoint?: string;
    indexUrl: string;
    aiEnabled?: boolean;
    aiSuggestEndpoint?: string;
    routeName?: string;
    t?: (k: string) => string;
}

// fy era 的 year 即 PK 段 c_assoc_first_year；ly year 為 c_assoc_last_year（非 PK）。
const FY = { year: 'c_assoc_first_year', nhCode: 'c_assoc_fy_nh_code', nhYear: 'c_assoc_fy_nh_year', range: 'c_assoc_fy_range', intercalary: 'c_assoc_fy_intercalary', month: 'c_assoc_fy_month', day: 'c_assoc_fy_day', dayGz: 'c_assoc_fy_day_gz' };
const LY = { year: 'c_assoc_last_year', nhCode: 'c_assoc_ly_nh_code', nhYear: 'c_assoc_ly_nh_year', range: 'c_assoc_ly_range', intercalary: 'c_assoc_ly_intercalary', month: 'c_assoc_ly_month', day: 'c_assoc_ly_day', dayGz: 'c_assoc_ly_day_gz' };
type EraGroup = typeof FY;

// 9 段複合主鍵。
const PK = ['c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year'];
// 可改主鍵段（除 c_personid；c_assoc_first_year 經 era fy year 改）。
const EDITABLE_PK = ['c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year'];
// varchar PK c_text_title 哨兵 '[n/a]'；c_assoc_first_year 哨兵 '-9999'；其餘 code PK 段哨兵 '0'。
const TEXT_PK_SENTINEL = '[n/a]';
const YEAR_PK_SENTINEL = '-9999';
// 非主鍵可寫欄位。
const NON_PK = [
    'c_assoc_last_year', 'c_assoc_ly_nh_code', 'c_assoc_ly_nh_year', 'c_assoc_ly_range',
    'c_assoc_ly_intercalary', 'c_assoc_ly_month', 'c_assoc_ly_day', 'c_assoc_ly_day_gz',
    'c_assoc_fy_nh_code', 'c_assoc_fy_nh_year', 'c_assoc_fy_range',
    'c_assoc_fy_intercalary', 'c_assoc_fy_month', 'c_assoc_fy_day', 'c_assoc_fy_day_gz',
    'c_sequence', 'c_notes', 'c_topic_code', 'c_occasion_code', 'c_assoc_count',
    'c_tertiary_personid', 'c_tertiary_type_notes', 'c_assoc_claimer_id',
    'c_addr_id', 'c_inst_code', 'c_inst_name_code', 'c_source', 'c_pages',
];

export default function AssocEditor({
    personId, personLabel, dynastyCode = null, mode, initialFields, initialLabels = {},
    canEdit, canPropose, createEndpoint, mutateEndpoint, deleteEndpoint, indexUrl,
    aiEnabled = false, aiSuggestEndpoint, routeName, t,
}: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    const base: Fields = {
        c_personid: String(personId),
        c_assoc_code: '0', c_assoc_id: '0', c_kin_code: '0', c_kin_id: '0',
        c_assoc_kin_code: '0', c_assoc_kin_id: '0',
        c_text_title: TEXT_PK_SENTINEL, c_assoc_first_year: '',
        c_inst_code: '0', c_inst_name_code: '0', c_source: '0',
        c_assoc_count: '1',
        ...initialFields,
    };
    const [fields, setFields] = useState<Fields>(base);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const snapshot = useRef(JSON.stringify(base));
    const originalPk = useRef<Record<string, number | string>>(Object.fromEntries(PK.map((k) => {
        if (k === 'c_personid') return [k, personId];
        if (k === 'c_text_title') return [k, String(initialFields.c_text_title ?? TEXT_PK_SENTINEL)];
        if (k === 'c_assoc_first_year') return [k, Number(initialFields.c_assoc_first_year ?? -9999)];
        return [k, Number(initialFields[k] ?? 0)];
    })));
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [sourceHighlight, setSourceHighlight] = useState(false);
    const [assocHighlight, setAssocHighlight] = useState(false);
    const [comment, setComment] = useState('');

    // AI 代碼識別狀態。
    const [aiQuery, setAiQuery] = useState('');
    const [aiBusy, setAiBusy] = useState(false);
    const [aiError, setAiError] = useState<string | null>(null);
    const [aiCandidates, setAiCandidates] = useState<AiCandidate[] | null>(null);
    const [aiSummary, setAiSummary] = useState('');

    const dirty = useMemo(() => JSON.stringify(fields) !== snapshot.current, [fields]);
    const set = (k: string, v: string) => setFields((p) => ({ ...p, [k]: v }));
    const setLabel = (k: string, v: string) => setLabels((p) => ({ ...p, [k]: v }));
    const editable = canEdit || canPropose;

    const buildEra = (g: EraGroup): EraTimeFieldValues => ({
        year: fields[g.year] ?? '', nhCode: fields[g.nhCode] ?? '', nhCodeLabel: labels[g.nhCode] ?? '',
        nhYear: fields[g.nhYear] ?? '', range: fields[g.range] ?? '', rangeLabel: labels[g.range] ?? '',
        intercalary: fields[g.intercalary] ?? '0', month: fields[g.month] ?? '', day: fields[g.day] ?? '',
        dayGz: fields[g.dayGz] ?? '', dayGzLabel: labels[g.dayGz] ?? '',
    });
    const applyEra = (g: EraGroup, patch: Partial<EraTimeFieldValues>) => {
        setFields((prev) => {
            const next = { ...prev };
            (['year', 'nhCode', 'nhYear', 'range', 'intercalary', 'month', 'day', 'dayGz'] as const).forEach((kk) => {
                if (patch[kk] !== undefined) next[g[kk]] = patch[kk] as string;
            });
            return next;
        });
        if (patch.nhCodeLabel !== undefined) setLabel(g.nhCode, patch.nhCodeLabel);
        if (patch.rangeLabel !== undefined) setLabel(g.range, patch.rangeLabel);
        if (patch.dayGzLabel !== undefined) setLabel(g.dayGz, patch.dayGzLabel);
    };

    // 社會機構 c_inst_code 值為「code-namecode」，拆成兩欄（同 offices）。
    const instValue = (fields.c_inst_code && fields.c_inst_code !== '0')
        ? `${fields.c_inst_code}-${fields.c_inst_name_code ?? '0'}` : '';
    const onInstChange = (v: string, l: string) => {
        if (!v || v === '0' || v === '-999') {
            setFields((p) => ({ ...p, c_inst_code: '0', c_inst_name_code: '0' }));
            setLabel('c_inst_code', '');
            return;
        }
        const dash = v.indexOf('-');
        const code = dash >= 0 ? v.slice(0, dash) : v;
        const nameCode = dash >= 0 ? v.slice(dash + 1) : '';
        setFields((p) => ({ ...p, c_inst_code: code || '0', c_inst_name_code: nameCode || '0' }));
        setLabel('c_inst_code', l);
    };

    const onPickTextperson = (p: { source: string; pages: string; sourceLabel: string }) => {
        setFields((prev) => ({ ...prev, c_source: p.source, c_pages: p.pages }));
        setLabel('c_source', p.sourceLabel);
        setSourceHighlight(true);
        window.setTimeout(() => setSourceHighlight(false), 4000);
        setMessage(tr('update_source_success', '已自動回填出處與頁碼'));
    };

    const runAiLookup = async () => {
        const q = aiQuery.trim();
        if (!q) { setAiError(tr('ai_enter_description', '請輸入描述')); return; }
        if (!aiSuggestEndpoint) return;
        setAiBusy(true); setAiError(null); setAiCandidates(null); setAiSummary('');
        try {
            const res = await fetch(aiSuggestEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ query: q, table: 'ASSOC_CODES', person_id: personId, route_name: routeName ?? '', route_url: typeof window !== 'undefined' ? window.location.pathname : '' }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.success) throw new Error(json?.error || tr('ai_recognition_failed', 'AI 識別失敗'));
            const data = json.data ?? {};
            setAiCandidates(Array.isArray(data.matched_codes) ? data.matched_codes : []);
            setAiSummary(typeof data.summary === 'string' ? data.summary : '');
        } catch (e) {
            setAiError(e instanceof Error ? e.message : tr('ai_recognition_failed', 'AI 識別失敗'));
        } finally { setAiBusy(false); }
    };
    const applyAiCode = (c: AiCandidate) => {
        set('c_assoc_code', String(c.code_id));
        setLabel('c_assoc_code', `${c.code_id} ${c.desc_chn ?? ''} ${c.desc_en ?? ''}`.trim());
        setAssocHighlight(true);
        window.setTimeout(() => setAssocHighlight(false), 4000);
    };

    const save = async (sm: 'direct' | 'proposal') => {
        setSaving(true); setError(null); setMessage(null);
        // PK 段空值正規化：c_text_title→'[n/a]'、c_assoc_first_year→'-9999'、其餘 code 段→'0'。
        const pkVal = (k: string): number | string => {
            if (k === 'c_personid') return personId;
            if (k === 'c_text_title') return (fields[k]?.trim() ? fields[k] : TEXT_PK_SENTINEL);
            if (k === 'c_assoc_first_year') return (fields[k]?.trim() ? Number(fields[k]) : Number(YEAR_PK_SENTINEL));
            return Number(fields[k]?.trim() ? fields[k] : '0');
        };
        let changes: Record<string, string | null>;
        let target: Record<string, number | string>;
        let endpoint: string;
        let operation: string;
        if (mode === 'create') {
            endpoint = createEndpoint; operation = 'create';
            target = Object.fromEntries(PK.map((k) => [k, pkVal(k)]));
            changes = {};
            for (const k of NON_PK) { const v = fields[k] ?? ''; if (v !== '') changes[k] = v; }
        } else {
            endpoint = mutateEndpoint; operation = 'update'; target = originalPk.current;
            const initial: Fields = JSON.parse(snapshot.current);
            changes = {};
            for (const k of NON_PK) { const v = fields[k] ?? ''; if ((initial[k] ?? '') !== v) changes[k] = v === '' ? null : v; }
            // 可改主鍵段：與原值（正規化後）不同才送（PK NOT NULL，送哨兵而非空）。
            for (const k of EDITABLE_PK) {
                const cur = String(pkVal(k));
                const init = String(k === 'c_text_title'
                    ? (initial[k]?.trim() ? initial[k] : TEXT_PK_SENTINEL)
                    : k === 'c_assoc_first_year'
                        ? (initial[k]?.trim() ? Number(initial[k]) : Number(YEAR_PK_SENTINEL))
                        : Number(initial[k]?.trim() ? initial[k] : '0'));
                if (cur !== init) changes[k] = cur;
            }
            if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        }
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'associations', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(sm === 'proposal' && comment ? { meta: { comment } } : {}) }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setMessage(sm === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            snapshot.current = JSON.stringify(fields);
            if (mode === 'create') { window.location.assign(indexUrl); } else if (sm === 'direct') {
                // 改鍵後以實際送出的 PK 變更覆寫 originalPk（不可用 fields 重建，避免清空 Number('')=0 失準）。
                const nextPk = { ...originalPk.current };
                for (const k of EDITABLE_PK) { if (Object.prototype.hasOwnProperty.call(changes, k)) nextPk[k] = (k === 'c_text_title' ? String(changes[k]) : Number(changes[k])); }
                originalPk.current = nextPk;
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    const doDelete = async () => {
        if (!deleteEndpoint || !window.confirm(tr('delete_confirm', '確定刪除此社會關係？'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'associations', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            snapshot.current = JSON.stringify(fields);
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const textRow = (key: string, label: string, highlight = false, hint?: string) => (
        <div style={rowStyle}><label style={labelStyle}>{label}</label><div style={fieldStyle}>
            <input type="text" value={fields[key] ?? ''} disabled={!editable} onChange={(e) => set(key, e.target.value)}
                style={{ ...inputStyle, ...(highlight ? { background: '#FFFFBB' } : {}), ...(!editable ? roStyle : {}) }} />
            {hint ? <span style={fieldHintStyle}>{hint}</span> : null}</div></div>
    );
    const searchRow = (key: string, label: string, endpoint: string, highlight = false, sentinel = '0') => (
        <div style={rowStyle}><label style={labelStyle}>{label}</label><div style={fieldStyle}>
            <CodeAutocomplete mode="search" endpoint={endpoint} value={fields[key] ?? sentinel} initialLabel={labels[key] ?? ''}
                disabled={!editable} aria-invalid={highlight}
                onChange={(v, l) => { set(key, v || sentinel); setLabel(key, l); }} /></div></div>
    );
    const listRow = (key: string, label: string, model: string, idKey: string, labelKeys: string[]) => (
        <div style={rowStyle}><label style={labelStyle}>{label}</label><div style={fieldStyle}>
            <CodeAutocomplete mode="list" model={model} idKey={idKey} labelKeys={labelKeys}
                value={fields[key] ?? ''} initialLabel={labels[key] ?? ''} disabled={!editable}
                onChange={(v, l) => { set(key, v); setLabel(key, l); }} /></div></div>
    );
    // 緊湊搜尋格（label 置頂）：供「關係碼 | 關係人」雙欄並排（對齊 legacy 一行兩欄 col-sm-6）。
    const searchCell = (key: string, label: string, endpoint: string, highlight = false, sentinel = '0') => (
        <div style={cellColStyle}>
            <label style={cellLabelStyle}>{label}</label>
            <CodeAutocomplete mode="search" endpoint={endpoint} value={fields[key] ?? sentinel} initialLabel={labels[key] ?? ''}
                disabled={!editable} aria-invalid={highlight}
                onChange={(v, l) => { set(key, v || sentinel); setLabel(key, l); }} />
        </div>
    );
    const pairRow = (left: React.ReactNode, right: React.ReactNode) => (
        <div style={twoColStyle}><div style={colStyle}>{left}</div><div style={colStyle}>{right}</div></div>
    );

    return (
        <div style={cardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('assoc_create', '新增社會關係') : tr('assoc_edit', '編輯社會關係')} — {personLabel}</h3>
            {message ? <div style={okStyle}>{message}</div> : null}
            {error ? <div style={errStyle}>{error}</div> : null}

            {aiEnabled && aiSuggestEndpoint && editable ? (
                <div style={aiCard}>
                    <div style={{ fontWeight: 700, marginBottom: 6 }}>🔎 {tr('ai_assoc_lookup', 'AI 智能識別社會關係代碼')}</div>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <input type="text" value={aiQuery} disabled={aiBusy} onChange={(e) => setAiQuery(e.target.value)}
                            placeholder={tr('ai_assoc_placeholder', '描述關係，例如「同年進士」「妻舅」')} style={{ ...inputStyle, flex: 1, minWidth: 200 }} />
                        <button type="button" style={aiBtn} disabled={aiBusy} onClick={() => void runAiLookup()}>⚡ {tr('ai_lookup_btn', '識別')}</button>
                    </div>
                    {aiError ? <div style={{ color: '#991b1b', fontSize: '0.82rem', marginTop: 6 }}>{aiError}</div> : null}
                    {aiSummary ? <div style={{ fontSize: '0.82rem', color: '#334155', marginTop: 6 }}>{aiSummary}</div> : null}
                    {aiCandidates && aiCandidates.length > 0 ? (
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
                            {aiCandidates.map((c) => (
                                <button type="button" key={String(c.code_id)} style={candBtn} onClick={() => applyAiCode(c)}>
                                    {c.code_id} {c.desc_chn ?? ''} {c.desc_en ?? ''}{c.relevance ? `（${c.relevance}）` : ''}
                                </button>
                            ))}
                        </div>
                    ) : aiCandidates && aiCandidates.length === 0 ? (
                        <div style={{ fontSize: '0.82rem', color: '#94a3b8', marginTop: 6 }}>{tr('ai_no_candidate', '無候選代碼，請手動選擇')}</div>
                    ) : null}
                </div>
            ) : null}

            {textRow('c_sequence', `${tr('sequence', '序號')} (c_sequence)`)}

            {/* 關係碼 | 關係人 三組雙欄並排（對齊 legacy assoc/_form 一行兩欄）。 */}
            {pairRow(
                searchCell('c_kin_code', `${tr('kinship_field', '親屬關係')} (c_kin_code)`, '/api/select/search/kincode'),
                searchCell('c_kin_id', `${tr('kin_person', '親屬人物')} (c_kin_id)`, '/api/select/search/biog'),
            )}
            {pairRow(
                searchCell('c_assoc_code', `${tr('assoc_field', '社會關係')} (c_assoc_code)`, '/api/select/search/assoccode', assocHighlight),
                searchCell('c_assoc_id', `${tr('assoc_person', '關聯人物')} (c_assoc_id)`, '/api/select/search/biog'),
            )}
            {pairRow(
                searchCell('c_assoc_kin_code', `${tr('assoc_kin_field', '關聯親屬關係')} (c_assoc_kin_code)`, '/api/select/search/kincode'),
                searchCell('c_assoc_kin_id', `${tr('assoc_kin_person', '關聯親屬人物')} (c_assoc_kin_id)`, '/api/select/search/biog'),
            )}

            <div style={rowStyle}><label style={labelStyle}>{tr('assoc_start_year', '關係始年')} (first_year)</label><div style={fieldStyle}>
                <EraTimeField values={buildEra(FY)} onChange={(p) => applyEra(FY, p)} dynastyCode={dynastyCode} showRange showLunar disabled={!editable} /></div></div>
            <div style={rowStyle}><label style={labelStyle}>{tr('assoc_end_year', '關係末年')} (last_year)</label><div style={fieldStyle}>
                <EraTimeField values={buildEra(LY)} onChange={(p) => applyEra(LY, p)} dynastyCode={dynastyCode} showRange showLunar disabled={!editable} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('notes_field', '備註')} (c_notes)</label><div style={fieldStyle}>
                <textarea value={fields.c_notes ?? ''} disabled={!editable} onChange={(e) => set('c_notes', e.target.value)} rows={4} style={{ ...inputStyle, height: 'auto', ...(!editable ? roStyle : {}) }} /></div></div>

            {listRow('c_topic_code', `${tr('topic_field', '主題')} (c_topic_code)`, 'topic', 'c_topic_code', ['c_topic_desc_chn', 'c_topic_desc'])}
            {listRow('c_occasion_code', `${tr('occasion_field', '場合')} (c_occasion_code)`, 'occasion', 'c_occasion_code', ['c_occasion_desc_chn', 'c_occasion_desc'])}

            {textRow('c_text_title', `${tr('text_title_field', '作品/出處標題')} (c_text_title)`)}
            {textRow('c_assoc_count', `${tr('assoc_count_field', '數量')} (c_assoc_count)`, false, tr('assoc_count_hint', '此欄位僅適用於書信：當無法以標題及日期區分多次信件時，則僅建「一筆」社會關係，並將信件總數填於此欄。請填阿拉伯數字'))}

            {searchRow('c_tertiary_personid', `${tr('tertiary_person', '中介人物')} (c_tertiary_personid)`, '/api/select/search/biog')}
            {textRow('c_tertiary_type_notes', `${tr('tertiary_notes', '中介說明')} (c_tertiary_type_notes)`)}
            {searchRow('c_assoc_claimer_id', `${tr('claimer_person', '見證人物')} (c_assoc_claimer_id)`, '/api/select/search/biog')}
            {searchRow('c_addr_id', `${tr('place_name', '地點')} (c_addr_id)`, '/api/select/search/addr')}

            <div style={rowStyle}><label style={labelStyle}>{tr('socialinst_field', '社會機構')} (social_institution)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/socialinstcode" value={instValue} initialLabel={labels.c_inst_code ?? ''} disabled={!editable} onChange={onInstChange} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('source_field', '出處')} (c_source)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/text" value={fields.c_source ?? '0'} initialLabel={labels.c_source ?? ''} disabled={!editable} onChange={(v, l) => { set('c_source', v || '0'); setLabel('c_source', l); }} /></div></div>
            {textRow('c_pages', `${tr('pages_entries', '頁碼')} (c_pages)`, sourceHighlight)}

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
                    <textarea value={comment} onChange={(e) => setComment(e.target.value)} rows={3} style={{ ...inputStyle, height: 'auto' }} placeholder={tr('modification_note_placeholder', '提案時請說明修改原因')} /></div></div>
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
const fieldHintStyle: React.CSSProperties = { display: 'block', marginTop: 2, fontSize: '0.78rem', color: '#6b7280' };
// 雙欄並排（對齊 legacy col-sm-6 × col-sm-6）：窄屏自動換行。
const twoColStyle: React.CSSProperties = { display: 'flex', gap: 24, flexWrap: 'wrap' };
const colStyle: React.CSSProperties = { flex: '1 1 320px', minWidth: 0 };
const cellColStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 2, padding: '6px 0' };
const cellLabelStyle: React.CSSProperties = { fontSize: '0.8rem', color: '#374151' };
const inputStyle: React.CSSProperties = { width: '100%', height: 36, padding: '0 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: '0.875rem', boxSizing: 'border-box' };
const roStyle: React.CSSProperties = { background: '#f3f4f6', cursor: 'not-allowed' };
const aiCard: React.CSSProperties = { background: '#f0f9ff', border: '1px solid #bae6fd', borderRadius: 8, padding: 12, marginBottom: 14 };
const aiBtn: React.CSSProperties = { borderRadius: 8, padding: '7px 14px', border: '1px solid #0369a1', background: '#0ea5e9', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const candBtn: React.CSSProperties = { borderRadius: 14, padding: '4px 12px', border: '1px solid #c7d7ea', background: '#eef4fb', color: '#1f3a5f', fontSize: '0.82rem', cursor: 'pointer' };
const okStyle: React.CSSProperties = { background: '#ecfdf5', border: '1px solid #a7f3d0', color: '#065f46', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const errStyle: React.CSSProperties = { background: '#fef2f2', border: '1px solid #fecaca', color: '#991b1b', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const primaryBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #255f93', background: '#255f93', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const infoBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #0e7490', background: '#0891b2', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const dangerBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #b91c1c', background: '#fff5f5', color: '#b91c1c', fontWeight: 700, cursor: 'pointer' };
const cancelBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #cbd5e1', background: '#fff', color: '#475569', fontWeight: 700, textDecoration: 'none', display: 'inline-flex', alignItems: 'center' };
