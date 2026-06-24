import React, { useMemo, useRef, useState } from 'react';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';

/**
 * 事件（events）編輯器（對齊 legacy biogmains/events/_form.blade.php，非 person-browser）。
 * 欄位：序號 / 事件名(event 搜尋) / 角色 / 年份(EraTimeField 含農曆，干支日=c_day_ganzhi) /
 * 地名(c_addr_id 多選) / 出處 / 頁碼 / 重大事件 / 備註 / textperson_pair；三態授權提交。
 * 邏輯主鍵 (c_personid, c_sequence, c_event_code)；序號／事件可改鍵，空值正規化為 '0'。
 *
 * 地址（c_addr_id）寫入 EVENTS_ADDR 副表（多筆）：新增/編輯皆可增刪；送 c_addr_id 陣列，
 * 由 v2 EventCreate/MutationHandler 於同交易內同步副表（afterDirectInsert/afterDirectUpdate，
 * 改邏輯主鍵時遷移舊→新 tuple），不寫 EVENTS_DATA.c_addr_id 純量欄。
 */
type Fields = Record<string, string>;
interface AddrItem { id: string; label: string }

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

const PK = ['c_personid', 'c_sequence', 'c_event_code'];
const EDITABLE_PK = ['c_sequence', 'c_event_code'];
const YR = { year: 'c_year', nhCode: 'c_nh_code', nhYear: 'c_nh_year', range: 'c_yr_range', intercalary: 'c_intercalary', month: 'c_month', day: 'c_day', dayGz: 'c_day_ganzhi' };
// 非主鍵可寫欄位（均 nullable，空值送 null）。c_addr_id 不在此（副表，TODO）。
const NON_PK = [
    'c_role',
    'c_year', 'c_nh_code', 'c_nh_year', 'c_yr_range', 'c_intercalary', 'c_month', 'c_day', 'c_day_ganzhi',
    'c_source', 'c_pages', 'c_event', 'c_notes',
];

type EraGroup = typeof YR;

export default function EventEditor({
    personId, personLabel, dynastyCode = null, mode, initialFields, initialLabels = {}, initialAddr = [],
    canEdit, canPropose, createEndpoint, mutateEndpoint, deleteEndpoint, indexUrl, t,
}: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    // 對齊 legacy 新增預設（c_sequence '0'、c_event_code 預設 option 0、c_source 預設 option 0）。
    // 編輯模式 initialFields 會覆蓋這些預設。
    const base: Fields = { c_personid: String(personId), c_sequence: '0', c_event_code: '0', c_source: '0', ...initialFields };
    const [fields, setFields] = useState<Fields>(base);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const [addrItems, setAddrItems] = useState<AddrItem[]>(initialAddr);
    const [addrKey, setAddrKey] = useState(0); // 重置地址新增框
    const initialAddrIds = useRef<string[]>(initialAddr.map((a) => String(a.id)));
    const snapshot = useRef(JSON.stringify(base));
    const originalPk = useRef<Record<string, number>>(Object.fromEntries(PK.map((k) => [k, Number(initialFields[k] ?? (k === 'c_personid' ? personId : 0))])));
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [sourceHighlight, setSourceHighlight] = useState(false);
    const [comment, setComment] = useState('');

    // 地址（EVENTS_ADDR 多筆）是否相對初始有變動（去重比對 id 集合）。
    const addrDirty = useMemo(() => {
        const a = [...new Set(addrItems.map((x) => String(x.id)))].sort();
        const b = [...new Set(initialAddrIds.current)].sort();
        return JSON.stringify(a) !== JSON.stringify(b);
    }, [addrItems]);
    const dirty = useMemo(() => JSON.stringify(fields) !== snapshot.current || addrDirty, [fields, addrDirty]);
    const set = (k: string, v: string) => setFields((p) => ({ ...p, [k]: v }));
    const setLabel = (k: string, v: string) => setLabels((p) => ({ ...p, [k]: v }));
    const editable = canEdit || canPropose;
    const addAddr = (id: string, label: string) => {
        setAddrKey((k) => k + 1); // 重置新增框
        if (!id || id === '0') return;
        setAddrItems((prev) => (prev.some((x) => String(x.id) === String(id)) ? prev : [...prev, { id: String(id), label }]));
    };
    const removeAddr = (id: string) => setAddrItems((prev) => prev.filter((x) => String(x.id) !== String(id)));

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

    const onPickTextperson = (p: { source: string; pages: string; sourceLabel: string }) => {
        setFields((prev) => ({ ...prev, c_source: p.source, c_pages: p.pages }));
        setLabel('c_source', p.sourceLabel);
        setSourceHighlight(true);
        window.setTimeout(() => setSourceHighlight(false), 4000);
        setMessage(tr('update_source_success', '已自動回填出處與頁碼'));
    };

    const save = async (sm: 'direct' | 'proposal') => {
        setSaving(true); setError(null); setMessage(null);
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
            // 事件地址（EVENTS_ADDR 多筆）：有設定才送 c_addr_id 陣列，由後端寫副表。
            if (addrItems.length) (changes as Record<string, unknown>).c_addr_id = addrItems.map((a) => Number(a.id));
        } else {
            endpoint = mutateEndpoint; operation = 'update'; target = originalPk.current;
            const initial: Fields = JSON.parse(snapshot.current);
            changes = {};
            for (const k of NON_PK) { const v = fields[k] ?? ''; if ((initial[k] ?? '') !== v) changes[k] = v === '' ? null : v; }
            // 可改鍵：序號／事件。邏輯 PK 空值送 '0'；與原值（正規化後）不同才送。
            for (const k of EDITABLE_PK) {
                const cur = fields[k]?.trim() ? fields[k] : '0';
                const init = (initial[k]?.trim() ? initial[k] : '0');
                if (cur !== init) changes[k] = cur;
            }
            // 地址有變動才送 c_addr_id（清空則送空陣列）；交由後端 afterDirectUpdate 同步 EVENTS_ADDR。
            if (addrDirty) (changes as Record<string, unknown>).c_addr_id = addrItems.map((a) => Number(a.id));
            if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        }
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'events', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(sm === 'proposal' && comment ? { meta: { comment } } : {}) }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setMessage(sm === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            snapshot.current = JSON.stringify(fields);
            initialAddrIds.current = addrItems.map((a) => String(a.id));
            if (mode === 'create') { window.location.assign(indexUrl); }
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
        if (!deleteEndpoint || !window.confirm(tr('delete_confirm', '確定刪除此事件？'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'events', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            snapshot.current = JSON.stringify(fields);
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

    return (
        <div style={cardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('event_create', '新增事件') : tr('event_edit', '編輯事件')} — {personLabel}</h3>
            {message ? <div style={okStyle}>{message}</div> : null}
            {error ? <div style={errStyle}>{error}</div> : null}

            {textRow('c_sequence', `${tr('sequence', '序號')} (c_sequence)`)}

            <div style={rowStyle}><label style={labelStyle}>{tr('event_name', '事件名')} (c_event_code)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/event"
                    value={fields.c_event_code ?? '0'} initialLabel={labels.c_event_code ?? ''} disabled={!editable}
                    onChange={(v, l) => { set('c_event_code', v); setLabel('c_event_code', l); }} /></div></div>

            {textRow('c_role', `${tr('event_role', '事件角色')} (c_role)`)}

            <div style={rowStyle}><label style={labelStyle}>{tr('event_year_field', '事件年份')} (c_year)</label><div style={fieldStyle}>
                <EraTimeField values={buildEra(YR)} onChange={(p) => applyEra(YR, p)} dynastyCode={dynastyCode} showRange showLunar disabled={!editable} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('place_name', '地名')} (c_addr_id)</label><div style={fieldStyle}>
                {editable ? (
                    <CodeAutocomplete key={addrKey} mode="search" endpoint="/api/select/search/addr" value="" initialLabel=""
                        placeholder={tr('add_place', '搜尋並加入地名…')}
                        onChange={(v, l) => addAddr(v, l)} />
                ) : null}
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: editable ? 6 : 0 }}>
                    {addrItems.map((it) => (
                        <span key={it.id} style={chipStyle}>
                            {it.label}
                            {editable ? <button type="button" onClick={() => removeAddr(it.id)} style={chipRemoveStyle} aria-label="remove">×</button> : null}
                        </span>
                    ))}
                    {addrItems.length === 0 ? <span style={{ fontSize: '0.8rem', color: '#94a3b8' }}>{tr('no_place', '（未設定地名）')}</span> : null}
                </div></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('source_field', '出處')} (c_source)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                    value={fields.c_source ?? ''} initialLabel={labels.c_source ?? ''} disabled={!editable}
                    aria-invalid={sourceHighlight}
                    onChange={(v, l) => { set('c_source', v); setLabel('c_source', l); }} /></div></div>

            {textRow('c_pages', tr('pages_entries', '頁碼'), sourceHighlight)}

            <div style={rowStyle}><label style={labelStyle}>{tr('major_event', '重大事件')} (c_event)</label><div style={fieldStyle}>
                <textarea value={fields.c_event ?? ''} disabled={!editable} onChange={(e) => set('c_event', e.target.value)} rows={4} style={{ ...inputStyle, height: 'auto', ...(!editable ? roStyle : {}) }} /></div></div>

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
const chipStyle: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 4, background: '#eef4fb', border: '1px solid #c7d7ea', borderRadius: 14, padding: '2px 6px 2px 10px', fontSize: '0.8rem', color: '#1f3a5f' };
const chipRemoveStyle: React.CSSProperties = { border: 'none', background: 'transparent', color: '#1f3a5f', cursor: 'pointer', fontSize: '1rem', lineHeight: 1, padding: '0 2px' };
const okStyle: React.CSSProperties = { background: '#ecfdf5', border: '1px solid #a7f3d0', color: '#065f46', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const errStyle: React.CSSProperties = { background: '#fef2f2', border: '1px solid #fecaca', color: '#991b1b', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const primaryBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #255f93', background: '#255f93', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const infoBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #0e7490', background: '#0891b2', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const dangerBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #b91c1c', background: '#fff5f5', color: '#b91c1c', fontWeight: 700, cursor: 'pointer' };
const cancelBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #cbd5e1', background: '#fff', color: '#475569', fontWeight: 700, textDecoration: 'none', display: 'inline-flex', alignItems: 'center' };
