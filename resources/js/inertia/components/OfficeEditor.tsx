import React, { useMemo, useRef, useState } from 'react';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';

/**
 * 官名／任官（offices/postings）編輯器（對齊 legacy biogmains/offices/_form.blade.php，非 person-browser）。
 *
 * 欄位：序號 / 官名(c_office_id，office 搜尋，以 c_dy 過濾) / 社會機構(c_inst_code，socialinstcode 搜尋，
 * 值為「code-namecode」，送出前拆成 c_inst_code + c_inst_name_code) / 地名(c_addr 多選，寫 POSTED_TO_ADDR_DATA 副表) /
 * 出處(c_source，text 搜尋) / 頁碼 / 起年(EraTimeField 含農曆) / 訖年(EraTimeField 含農曆) /
 * 任命類型(c_appt_code，appttype) / 任官方式(c_assume_office_code，assumeoffice) /
 * 官職分類(c_office_category_id，officecate) / 備註 / 朝代(c_dy，dynasty) / textperson_pair 候選出處。
 *
 * 複合主鍵 (c_office_id, c_posting_id)。c_posting_id 由伺服器配發（create）。c_office_id 可改，
 * 改時後端 syncPostingAddresses 會把地址遷移到新官職（不流失）。編輯模式提供「另存新檔」(saveas)：
 * 以目前所有欄位＋地址走 create，配發新 c_posting_id（對齊 legacy officeCloneById 語義）。
 *
 * 地址：v2 update 會把 changes.c_addr（id 陣列）抽出走 syncPostingAddresses；create 走 officeStoreById。
 * 故本元件對 create 與 update 一律送出目前的 c_addr id 列表。
 */
type Fields = Record<string, string>;
export interface AddrItem { id: string; label: string }

interface Props {
    personId: number;
    personLabel: string;
    dynastyCode?: number | null;
    mode: 'create' | 'edit';
    initialFields: Fields;
    initialLabels?: Fields;
    initialAddr?: AddrItem[];
    canEdit: boolean;
    canPropose: boolean;
    createEndpoint: string;
    mutateEndpoint: string;
    deleteEndpoint?: string;
    indexUrl: string;
    t?: (k: string) => string;
}

const FY = { year: 'c_firstyear', nhCode: 'c_fy_nh_code', nhYear: 'c_fy_nh_year', range: 'c_fy_range', intercalary: 'c_fy_intercalary', month: 'c_fy_month', day: 'c_fy_day', dayGz: 'c_fy_day_gz' };
const LY = { year: 'c_lastyear', nhCode: 'c_ly_nh_code', nhYear: 'c_ly_nh_year', range: 'c_ly_range', intercalary: 'c_ly_intercalary', month: 'c_ly_month', day: 'c_ly_day', dayGz: 'c_ly_day_gz' };
type EraGroup = typeof FY;

// 非主鍵可寫欄位（送 changes）。c_office_id 為 PK，單獨處理（create 必送、update 改動才送）。
const NON_PK = [
    'c_sequence', 'c_inst_code', 'c_inst_name_code', 'c_source', 'c_pages', 'c_notes',
    'c_appt_code', 'c_assume_office_code', 'c_office_category_id', 'c_dy',
    'c_firstyear', 'c_fy_nh_code', 'c_fy_nh_year', 'c_fy_range', 'c_fy_intercalary', 'c_fy_month', 'c_fy_day', 'c_fy_day_gz',
    'c_lastyear', 'c_ly_nh_code', 'c_ly_nh_year', 'c_ly_range', 'c_ly_intercalary', 'c_ly_month', 'c_ly_day', 'c_ly_day_gz',
];

export default function OfficeEditor({
    personId, personLabel, dynastyCode = null, mode, initialFields, initialLabels = {}, initialAddr = [],
    canEdit, canPropose, createEndpoint, mutateEndpoint, deleteEndpoint, indexUrl, t,
}: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    // 新增預設對齊 legacy：c_office_id 預設 option 0、c_source 預設 0、旗標 0；編輯由 initialFields 覆蓋。
    const base: Fields = {
        c_personid: String(personId),
        c_office_id: '0', c_source: '0',
        c_inst_code: '0', c_inst_name_code: '0',
        c_fy_intercalary: '0', c_ly_intercalary: '0',
        ...initialFields,
    };
    const [fields, setFields] = useState<Fields>(base);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const [addr, setAddr] = useState<AddrItem[]>(initialAddr);
    const [addrKey, setAddrKey] = useState(0); // 用於重置地址新增框
    const snapshot = useRef(JSON.stringify({ f: base, a: initialAddr }));
    const originalPk = useRef<Record<string, number>>({
        c_office_id: Number(initialFields.c_office_id ?? 0),
        c_posting_id: Number(initialFields.c_posting_id ?? 0),
    });
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [sourceHighlight, setSourceHighlight] = useState(false);
    const [comment, setComment] = useState('');

    const dirty = useMemo(() => JSON.stringify({ f: fields, a: addr }) !== snapshot.current, [fields, addr]);
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

    // 社會機構：CodeAutocomplete 值為「code-namecode」，拆成兩欄存於 fields。
    const instValue = (fields.c_inst_code && fields.c_inst_code !== '0')
        ? `${fields.c_inst_code}-${fields.c_inst_name_code ?? '0'}`
        : '';
    const onInstChange = (v: string, l: string) => {
        if (!v || v === '0' || v === '-999') {
            setFields((p) => ({ ...p, c_inst_code: '0', c_inst_name_code: '0' }));
            setLabel('c_inst_code', '');
            return;
        }
        // 僅以第一個 '-' 拆分（防社會機構代碼含多個 '-' 時誤拆，雖現行皆為數字代碼）。
        const dash = v.indexOf('-');
        const code = dash >= 0 ? v.slice(0, dash) : v;
        const nameCode = dash >= 0 ? v.slice(dash + 1) : '';
        setFields((p) => ({ ...p, c_inst_code: code || '0', c_inst_name_code: nameCode || '0' }));
        setLabel('c_inst_code', l);
    };

    const addAddr = (v: string, l: string) => {
        if (!v || v === '0' || v === '-999') return;
        setAddr((prev) => (prev.some((a) => a.id === v) ? prev : [...prev, { id: v, label: l || `ADDR ${v}` }]));
        setAddrKey((k) => k + 1); // 重置新增框
    };
    const removeAddr = (id: string) => setAddr((prev) => prev.filter((a) => a.id !== id));

    const onPickTextperson = (p: { source: string; pages: string; sourceLabel: string }) => {
        setFields((prev) => ({ ...prev, c_source: p.source, c_pages: p.pages }));
        setLabel('c_source', p.sourceLabel);
        setSourceHighlight(true);
        window.setTimeout(() => setSourceHighlight(false), 4000);
        setMessage(tr('update_source_success', '已自動回填出處與頁碼'));
    };

    const addrIds = () => addr.map((a) => Number(a.id)).filter((n) => Number.isFinite(n));

    // sm: direct/proposal；asNew: 編輯模式的「另存新檔」（走 create 配發新 c_posting_id）。
    const save = async (sm: 'direct' | 'proposal', asNew = false) => {
        setSaving(true); setError(null); setMessage(null);
        const isCreate = mode === 'create' || asNew;
        let changes: Record<string, string | null | number[] | number>;
        let target: Record<string, number>;
        let endpoint: string;
        let operation: string;

        if (isCreate) {
            endpoint = createEndpoint; operation = 'create'; target = {};
            changes = { c_office_id: Number(fields.c_office_id ?? 0) || 0 };
            for (const k of NON_PK) { const v = fields[k] ?? ''; if (v !== '') changes[k] = v; }
            changes.c_addr = addrIds();
        } else {
            // 清空的可空 FK 代碼欄（c_dy/c_assume_office_code/c_office_category_id 與 era 代碼
            // c_*_nh_code/c_*_range/c_*_day_gz）一律送 null：這些欄皆為 nullable FK，null 為「未詳」
            // 的 FK 安全值（不會觸發外鍵違規而擋下存檔）；legacy 僅靠 MySQL ''→0 隱性轉型，非刻意寫 0，
            // 故 v2 改以語義正確、永不擋存檔的 null（c_office_id/c_source/c_inst_code 仍用 0，因其有明確 0=未詳 列）。
            endpoint = mutateEndpoint; operation = 'update'; target = originalPk.current;
            const snap = JSON.parse(snapshot.current) as { f: Fields; a: AddrItem[] };
            changes = {};
            // c_office_id 可改（PK），改動才送。
            const curOffice = Number(fields.c_office_id ?? 0) || 0;
            if (curOffice !== Number(snap.f.c_office_id ?? 0)) changes.c_office_id = curOffice;
            for (const k of NON_PK) { const v = fields[k] ?? ''; if ((snap.f[k] ?? '') !== v) changes[k] = v === '' ? null : v; }
            // 地址：與快照比較，有變更才送 c_addr（後端會 diff/遷移/清空）。
            const beforeIds = (snap.a ?? []).map((a) => a.id).sort().join(',');
            const afterIds = addr.map((a) => a.id).sort().join(',');
            if (beforeIds !== afterIds) changes.c_addr = addrIds();
            if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        }

        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'postings', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(sm === 'proposal' && comment ? { meta: { comment } } : {}) }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setMessage(sm === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            snapshot.current = JSON.stringify({ f: fields, a: addr });
            if (isCreate) { window.location.assign(indexUrl); } else if (sm === 'direct') {
                // c_office_id 可改 → 重同步 originalPk（c_posting_id 不變）。
                originalPk.current = { c_office_id: Number(fields.c_office_id ?? 0) || 0, c_posting_id: originalPk.current.c_posting_id };
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    const doDelete = async () => {
        if (!deleteEndpoint || !window.confirm(tr('delete_confirm', '確定刪除此任官記錄？'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'postings', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            snapshot.current = JSON.stringify({ f: fields, a: addr });
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const textRow = (key: string, label: string, highlight = false) => (
        <div style={rowStyle}><label style={labelStyle}>{label}</label><div style={fieldStyle}>
            <input type="text" value={fields[key] ?? ''} disabled={!editable} onChange={(e) => set(key, e.target.value)}
                style={{ ...inputStyle, ...(highlight ? { background: '#FFFFBB' } : {}), ...(!editable ? roStyle : {}) }} /></div></div>
    );
    const listRow = (key: string, label: string, model: string, idKey: string, labelKeys: string[]) => (
        <div style={rowStyle}><label style={labelStyle}>{label}</label><div style={fieldStyle}>
            <CodeAutocomplete mode="list" model={model} idKey={idKey} labelKeys={labelKeys}
                value={fields[key] ?? ''} initialLabel={labels[key] ?? ''} disabled={!editable}
                onChange={(v, l) => { set(key, v); setLabel(key, l); }} /></div></div>
    );

    return (
        <div style={cardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('office_create', '新增任官') : tr('office_edit', '編輯任官')} — {personLabel}</h3>
            {message ? <div style={okStyle}>{message}</div> : null}
            {error ? <div style={errStyle}>{error}</div> : null}

            {mode === 'edit' ? (
                <div style={rowStyle}><label style={labelStyle}>posting_id</label><div style={fieldStyle}>
                    <input type="text" value={fields.c_posting_id ?? ''} readOnly disabled style={{ ...inputStyle, ...roStyle }} /></div></div>
            ) : null}

            {textRow('c_sequence', `${tr('sequence', '序號')} (c_sequence)`)}

            <div style={rowStyle}><label style={labelStyle}>{tr('office_name_field', '官名')} (c_office_id)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/office"
                    value={fields.c_office_id ?? '0'} initialLabel={labels.c_office_id ?? ''} disabled={!editable}
                    extraQuery={dynastyCode != null ? { c_dy: String(dynastyCode) } : undefined}
                    onChange={(v, l) => { set('c_office_id', v || '0'); setLabel('c_office_id', l); }} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('socialinst_field', '社會機構')} (social_institution)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/socialinstcode"
                    value={instValue} initialLabel={labels.c_inst_code ?? ''} disabled={!editable}
                    onChange={onInstChange} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('place_name', '地名')}</label><div style={fieldStyle}>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: addr.length ? 6 : 0 }}>
                    {addr.map((it) => (
                        <span key={it.id} style={chipStyle}>{it.label} #{it.id}
                            {editable ? <button type="button" onClick={() => removeAddr(it.id)} style={chipRemoveBtn} aria-label={`${tr('remove', '移除')} ${it.id}`}>×</button> : null}
                        </span>
                    ))}
                    {addr.length === 0 ? <span style={{ fontSize: '0.8rem', color: '#94a3b8' }}>{tr('no_place', '（未設定地名）')}</span> : null}
                </div>
                {editable ? (
                    <CodeAutocomplete key={addrKey} mode="search" endpoint="/api/select/search/addr"
                        value="" initialLabel="" placeholder={tr('add_place', '搜尋並新增地名…')}
                        extraQuery={dynastyCode != null ? { dy_start: String(dynastyCode), dy_end: String(dynastyCode) } : undefined}
                        onChange={addAddr} />
                ) : null}
            </div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('source_field', '出處')} (c_source)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                    value={fields.c_source ?? '0'} initialLabel={labels.c_source ?? ''} disabled={!editable}
                    onChange={(v, l) => { set('c_source', v || '0'); setLabel('c_source', l); }} /></div></div>

            {textRow('c_pages', `${tr('pages_entries', '頁碼')} (c_pages)`, sourceHighlight)}

            <div style={rowStyle}><label style={labelStyle}>{tr('start_year', '起年')} (firstyear)</label><div style={fieldStyle}>
                <EraTimeField values={buildEra(FY)} onChange={(p) => applyEra(FY, p)} dynastyCode={dynastyCode} showRange showLunar disabled={!editable} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('end_year', '訖年')} (lastyear)</label><div style={fieldStyle}>
                <EraTimeField values={buildEra(LY)} onChange={(p) => applyEra(LY, p)} dynastyCode={dynastyCode} showRange showLunar disabled={!editable} /></div></div>

            {listRow('c_appt_code', `${tr('appt_type', '任命類型')} (c_appt_code)`, 'appttype', 'c_appt_code', ['c_appt_desc_chn', 'c_appt_desc'])}
            {listRow('c_assume_office_code', `${tr('assume_office', '任官方式')} (c_assume_office_code)`, 'assumeoffice', 'c_assume_office_code', ['c_assume_office_desc_chn', 'c_assume_office_desc'])}
            {listRow('c_office_category_id', `${tr('office_category', '官職分類')} (c_office_category_id)`, 'officecate', 'c_office_category_id', ['c_category_desc_chn', 'c_category_desc'])}

            <div style={rowStyle}><label style={labelStyle}>{tr('notes_field', '備註')} (c_notes)</label><div style={fieldStyle}>
                <textarea value={fields.c_notes ?? ''} disabled={!editable} onChange={(e) => set('c_notes', e.target.value)} rows={4} style={{ ...inputStyle, height: 'auto', ...(!editable ? roStyle : {}) }} /></div></div>

            {listRow('c_dy', `${tr('dynasty', '朝代')} (dy)`, 'dynasty', 'c_dy', ['c_dynasty_chn', 'c_dynasty'])}

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
                    {mode === 'edit' && canEdit ? <button type="button" style={successBtn} disabled={saving} onClick={() => void save('direct', true)}>{tr('save_as', '另存新檔')}</button> : null}
                    {(canEdit || canPropose) ? <button type="button" style={infoBtn} disabled={saving} onClick={() => void save('proposal')}>{tr('submit_proposal', '提交建議')}</button> : null}
                    {mode === 'edit' && canEdit && deleteEndpoint ? <button type="button" style={dangerBtn} disabled={deleting} onClick={() => void doDelete()}>{tr('delete', '刪除')}</button> : null}
                    <a href={indexUrl} style={cancelBtn}>{tr('cancel', '取消')}</a>
                </div>
            </div>
            {dirty ? <div style={{ ...rowStyle, color: '#92400e', fontSize: '0.8rem' }}><div style={{ width: 160, flexShrink: 0 }} />{tr('unsaved_changes', '有未儲存的變更')}</div> : null}
        </div>
    );
}

const cardStyle: React.CSSProperties = { background: '#fff', border: '1px solid #e5e7eb', borderRadius: 10, padding: 20, maxWidth: 880 };
const titleStyle: React.CSSProperties = { fontSize: '1.1rem', fontWeight: 700, marginBottom: 12 };
const rowStyle: React.CSSProperties = { display: 'flex', gap: 12, alignItems: 'flex-start', padding: '6px 0' };
const labelStyle: React.CSSProperties = { width: 160, flexShrink: 0, fontSize: '0.875rem', color: '#374151', paddingTop: 6 };
const fieldStyle: React.CSSProperties = { flex: 1, minWidth: 0 };
const inputStyle: React.CSSProperties = { width: '100%', height: 36, padding: '0 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: '0.875rem', boxSizing: 'border-box' };
const roStyle: React.CSSProperties = { background: '#f3f4f6', cursor: 'not-allowed' };
const chipStyle: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 4, background: '#eef4fb', border: '1px solid #c7d7ea', borderRadius: 14, padding: '2px 6px 2px 10px', fontSize: '0.8rem', color: '#1f3a5f' };
const chipRemoveBtn: React.CSSProperties = { border: 'none', background: 'transparent', color: '#1f3a5f', cursor: 'pointer', fontSize: '1rem', lineHeight: 1, padding: '0 2px' };
const okStyle: React.CSSProperties = { background: '#ecfdf5', border: '1px solid #a7f3d0', color: '#065f46', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const errStyle: React.CSSProperties = { background: '#fef2f2', border: '1px solid #fecaca', color: '#991b1b', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const primaryBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #255f93', background: '#255f93', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const successBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #15803d', background: '#16a34a', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const infoBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #0e7490', background: '#0891b2', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const dangerBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #b91c1c', background: '#fff5f5', color: '#b91c1c', fontWeight: 700, cursor: 'pointer' };
const cancelBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #cbd5e1', background: '#fff', color: '#475569', fontWeight: 700, textDecoration: 'none', display: 'inline-flex', alignItems: 'center' };
