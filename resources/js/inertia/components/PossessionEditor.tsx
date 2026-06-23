import React, { useMemo, useRef, useState } from 'react';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';

/**
 * 占有／財產（possession）編輯器（對齊 legacy biogmains/possession/_form.blade.php，非 person-browser）。
 * 欄位：序號 / 占有行為(possact) / 英文/中文描述 / 數量+單位(measure) / 年份(EraTimeField 無農曆) /
 * 地名(c_addr_id 多選，POSSESSION_ADDR 副表) / 出處 / 頁碼 / 備註 / textperson_pair；三態授權提交。
 * 主鍵 c_possession_record_id 為伺服器配發 surrogate：新增 target.pk 留空，由 PossessionCreateHandler 配發；
 * 更新以 c_possession_record_id 定位。
 *
 * 地址副表（POSSESSION_ADDR）：新增時 c_addr_id 陣列由 PossessionCreateHandler 寫入副表；
 * 更新（PossessionMutationHandler 為單表 update，白名單不含 c_addr_id）目前不支援改地址副表，
 * 故編輯模式地名欄位設為唯讀並標示 TODO（對齊「child 表處理有風險寧標 TODO」原則），避免「靜默不落庫」。
 */
type Fields = Record<string, string>;
interface AddrItem { id: string; label: string }

interface Props {
    personId: number;
    personLabel: string;
    dynastyCode?: number | null;
    dynastyStart?: string;
    dynastyEnd?: string;
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

const YR = { year: 'c_possession_yr', nhCode: 'c_possession_nh_code', nhYear: 'c_possession_nh_yr', range: 'c_possession_yr_range' };
// 全為 nullable 欄位；空值送 null（update）或省略（create）。
const NON_PK = [
    'c_sequence', 'c_possession_act_code', 'c_possession_desc', 'c_possession_desc_chn',
    'c_quantity', 'c_measure_code',
    'c_possession_yr', 'c_possession_nh_code', 'c_possession_nh_yr', 'c_possession_yr_range',
    'c_source', 'c_pages', 'c_notes',
];

type EraGroup = { year: string; nhCode: string; nhYear: string; range: string };

export default function PossessionEditor({
    personId, personLabel, dynastyCode = null, dynastyStart, dynastyEnd, mode, initialFields, initialLabels = {}, initialAddr = [],
    canEdit, canPropose, createEndpoint, mutateEndpoint, deleteEndpoint, indexUrl, t,
}: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    // 對齊 legacy possession/_form 的新增預設（select-vue selected="0" / c_source 預設 option 0）：
    // 新增時若使用者未動，仍須送出 '0'（未詳碼）而非省略致 DB 落 NULL。編輯模式 initialFields 會覆蓋這些預設。
    const base: Fields = { c_personid: String(personId), c_possession_act_code: '0', c_source: '0', ...initialFields };
    const [fields, setFields] = useState<Fields>(base);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const [addrItems, setAddrItems] = useState<AddrItem[]>(initialAddr);
    const [addKey, setAddKey] = useState(0);
    const snapshot = useRef(JSON.stringify(base));
    const originalPk = useRef<Record<string, number>>({ c_possession_record_id: Number(initialFields.c_possession_record_id ?? 0) });
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [sourceHighlight, setSourceHighlight] = useState(false);
    const [comment, setComment] = useState('');

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

    const addAddr = (v: string, l: string) => {
        if (!v) return;
        setAddrItems((prev) => (prev.some((it) => it.id === v) ? prev : [...prev, { id: v, label: l || v }]));
        setAddKey((k) => k + 1);
    };
    const removeAddr = (id: string) => setAddrItems((prev) => prev.filter((it) => it.id !== id));

    const onPickTextperson = (p: { source: string; pages: string; sourceLabel: string }) => {
        setFields((prev) => ({ ...prev, c_source: p.source, c_pages: p.pages }));
        setLabel('c_source', p.sourceLabel);
        setSourceHighlight(true);
        window.setTimeout(() => setSourceHighlight(false), 4000);
        setMessage(tr('update_source_success', '已自動回填出處與頁碼'));
    };

    const save = async (sm: 'direct' | 'proposal') => {
        setSaving(true); setError(null); setMessage(null);
        let changes: Record<string, string | string[] | null>;
        let target: Record<string, number>;
        let endpoint: string;
        let operation: string;
        if (mode === 'create') {
            endpoint = createEndpoint; operation = 'create'; target = {};
            changes = {};
            for (const k of NON_PK) { const v = fields[k] ?? ''; if (v !== '') changes[k] = v; }
            // 地址副表：新增時送 c_addr_id 陣列，由 PossessionCreateHandler 寫入 POSSESSION_ADDR。
            if (addrItems.length) changes.c_addr_id = addrItems.map((it) => it.id);
        } else {
            endpoint = mutateEndpoint; operation = 'update'; target = originalPk.current;
            const initial: Fields = JSON.parse(snapshot.current);
            changes = {};
            for (const k of NON_PK) { const v = fields[k] ?? ''; if ((initial[k] ?? '') !== v) changes[k] = v === '' ? null : v; }
            // 編輯模式不送 c_addr_id（副表更新尚未支援，TODO）。
            if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        }
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'possessions', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(sm === 'proposal' && comment ? { meta: { comment } } : {}) }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setMessage(sm === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            snapshot.current = JSON.stringify(fields);
            if (mode === 'create') { window.location.assign(indexUrl); }
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    const doDelete = async () => {
        if (!deleteEndpoint || !window.confirm(tr('delete_confirm', '確定刪除此財產記錄？'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'possessions', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            snapshot.current = JSON.stringify(fields);
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const textRow = (key: string, label: string, hint = '') => (
        <div style={rowStyle}><label style={labelStyle}>{label}{hint ? ` ${hint}` : ''}</label><div style={fieldStyle}>
            <input type="text" value={fields[key] ?? ''} disabled={!editable} onChange={(e) => set(key, e.target.value)}
                style={{ ...inputStyle, ...(!editable ? roStyle : {}) }} /></div></div>
    );

    return (
        <div style={cardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('possession_create', '新增財產記錄') : tr('possession_edit', '編輯財產記錄')} — {personLabel}</h3>
            {message ? <div style={okStyle}>{message}</div> : null}
            {error ? <div style={errStyle}>{error}</div> : null}

            {textRow('c_sequence', tr('sequence', '序號'), '(entry_sequence)')}

            <div style={rowStyle}><label style={labelStyle}>{tr('possession_action_field', '占有行為')} (c_possession_act_code)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="list" model="possact" idKey="c_possession_act_code" labelKeys={['c_possession_act_desc_chn', 'c_possession_act_desc']}
                    value={fields.c_possession_act_code ?? '0'} initialLabel={labels.c_possession_act_code ?? ''} disabled={!editable}
                    onChange={(v, l) => { set('c_possession_act_code', v); setLabel('c_possession_act_code', l); }} /></div></div>

            {textRow('c_possession_desc', tr('possession_english', '英文描述'), '(possession_desc)')}
            {textRow('c_possession_desc_chn', tr('possession_chinese', '中文描述'), '(possession_desc_chn)')}

            <div style={rowStyle}><label style={labelStyle}>{tr('quantity', '數量')} (c_quantity)</label><div style={{ ...fieldStyle, display: 'flex', gap: 8 }}>
                <input type="text" value={fields.c_quantity ?? ''} disabled={!editable} onChange={(e) => set('c_quantity', e.target.value)} style={{ ...inputStyle, width: 100, ...(!editable ? roStyle : {}) }} />
                <div style={{ flex: 1, minWidth: 0 }}>
                    <CodeAutocomplete mode="list" model="measure" idKey="c_measure_code" labelKeys={['c_measure_desc_chn', 'c_measure_desc']}
                        value={fields.c_measure_code ?? ''} initialLabel={labels.c_measure_code ?? ''} disabled={!editable} placeholder={tr('unit', '單位')}
                        onChange={(v, l) => { set('c_measure_code', v); setLabel('c_measure_code', l); }} /></div></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('year_field', '年份')} (c_possession_yr)</label><div style={fieldStyle}>
                <EraTimeField values={buildEra(YR)} onChange={(p) => applyEra(YR, p)} dynastyCode={dynastyCode} showRange disabled={!editable} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('place_name', '地名')} (c_addr_id)</label><div style={fieldStyle}>
                {mode === 'create' && editable ? (
                    <CodeAutocomplete key={addKey} mode="search" endpoint="/api/select/search/addr"
                        extraQuery={{ dy_start: dynastyStart ?? '', dy_end: dynastyEnd ?? '' }}
                        value="" initialLabel="" placeholder={tr('add_place', '搜尋並加入地名…')}
                        onChange={(v, l) => addAddr(v, l)} />
                ) : (
                    <div style={{ fontSize: '0.8rem', color: '#92400e', marginBottom: 4 }}>
                        {tr('possession_addr_edit_todo', '地址副表更新尚未支援（TODO），如需修改地名請暫用舊版編輯頁。')}
                    </div>
                )}
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 6 }}>
                    {addrItems.map((it) => (
                        <span key={it.id} style={chipStyle}>{it.label}
                            {mode === 'create' && editable ? (
                                <button type="button" onClick={() => removeAddr(it.id)} style={chipRemoveStyle} aria-label="remove">×</button>
                            ) : null}
                        </span>
                    ))}
                    {addrItems.length === 0 ? <span style={{ fontSize: '0.8rem', color: '#94a3b8' }}>{tr('no_place', '（未設定地名）')}</span> : null}
                </div></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('source_field', '出處')} (c_source)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                    value={fields.c_source ?? ''} initialLabel={labels.c_source ?? ''} disabled={!editable}
                    aria-invalid={sourceHighlight}
                    onChange={(v, l) => { set('c_source', v); setLabel('c_source', l); }} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('pages_entries', '頁碼')}</label><div style={fieldStyle}>
                <input type="text" value={fields.c_pages ?? ''} disabled={!editable} onChange={(e) => set('c_pages', e.target.value)}
                    style={{ ...inputStyle, ...(sourceHighlight ? { background: '#FFFFBB' } : {}), ...(!editable ? roStyle : {}) }} /></div></div>

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
const chipStyle: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 4, background: '#eef4fb', border: '1px solid #c7d7ea', borderRadius: 14, padding: '2px 10px', fontSize: '0.8rem', color: '#1f3a5f' };
const chipRemoveStyle: React.CSSProperties = { border: 'none', background: 'transparent', color: '#64748b', cursor: 'pointer', fontSize: 14, lineHeight: '14px', padding: 0 };
const okStyle: React.CSSProperties = { background: '#ecfdf5', border: '1px solid #a7f3d0', color: '#065f46', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const errStyle: React.CSSProperties = { background: '#fef2f2', border: '1px solid #fecaca', color: '#991b1b', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const primaryBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #255f93', background: '#255f93', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const infoBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #0e7490', background: '#0891b2', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const dangerBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #b91c1c', background: '#fff5f5', color: '#b91c1c', fontWeight: 700, cursor: 'pointer' };
const cancelBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #cbd5e1', background: '#fff', color: '#475569', fontWeight: 700, textDecoration: 'none', display: 'inline-flex', alignItems: 'center' };
