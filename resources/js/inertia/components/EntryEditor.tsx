import React, { useMemo, useRef, useState } from 'react';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';

/**
 * 入仕（entries）編輯器（對齊 legacy biogmains/entries/_form.blade.php，非 person-browser）。
 * 欄位：序號 / 入仕途徑(entry 搜尋) / 入仕年(EraTimeField 無農曆，year 即主鍵 c_year) /
 * 等第 / 應試次數 / 應試領域 / 父祖狀態(parentstatus 清單) / 入仕地(addr 搜尋，朝代範圍過濾) /
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
        c_entry_code: '0', c_sequence: '0', c_kin_code: '0', c_assoc_code: '0', c_kin_id: '0',
        c_year: '0', c_assoc_id: '0', c_inst_code: '0', c_inst_name_code: '0',
        c_source: '0', c_entry_addr_id: '0',
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
                body: JSON.stringify({ resource: 'entries', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(sm === 'proposal' && comment ? { meta: { comment } } : {}) }),
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
        if (!deleteEndpoint || !window.confirm(tr('delete_confirm', '確定刪除此入仕記錄？'))) return;
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
            <h3 style={titleStyle}>{mode === 'create' ? tr('entry_create', '新增入仕') : tr('entry_edit', '編輯入仕')} — {personLabel}</h3>
            {message ? <div style={okStyle}>{message}</div> : null}
            {error ? <div style={errStyle}>{error}</div> : null}

            <div style={rowStyle}><label style={labelStyle}>{tr('migration_sequence', '序號')} (c_sequence)</label><div style={fieldStyle}>
                <input type="number" value={fields.c_sequence ?? ''} disabled={!editable} required maxLength={4}
                    onChange={(e) => set('c_sequence', e.target.value)} style={{ ...inputStyle, ...(!editable ? roStyle : {}) }} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('entry_field', '入仕途徑')} (c_entry_code)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/entry"
                    value={fields.c_entry_code ?? '0'} initialLabel={labels.c_entry_code ?? ''} disabled={!editable}
                    onChange={(v, l) => { set('c_entry_code', v || '0'); setLabel('c_entry_code', l); }} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('entry_year_field', '入仕年')} (c_year)</label><div style={fieldStyle}>
                <EraTimeField values={buildEra(EY)} onChange={(p) => applyEra(EY, p)} dynastyCode={dynastyCode} showRange disabled={!editable} /></div></div>

            {textRow('c_exam_rank', tr('exam_ranking', '等第') + ' (c_exam_rank)')}
            {textRow('c_attempt_count', tr('entry_attempt_count', '應試次數') + ' (c_attempt_count)')}
            {textRow('c_exam_field', tr('entry_exam_field', '應試領域') + ' (c_exam_field)')}

            <div style={rowStyle}><label style={labelStyle}>{tr('entry_parental_status', '父祖狀態')} (c_parental_status_code)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="list" model="parentstatus" idKey="c_parental_status_code"
                    labelKeys={['c_parental_status_code', 'c_parental_status_desc_chn', 'c_parental_status_desc']}
                    value={fields.c_parental_status_code ?? ''} initialLabel={labels.c_parental_status_code ?? ''} disabled={!editable}
                    onChange={(v, l) => { set('c_parental_status_code', v); setLabel('c_parental_status_code', l); }} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('entry_addr', '入仕地')} (c_entry_addr_id)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/addr"
                    extraQuery={{ dy_start: dynastyStart ?? '', dy_end: dynastyEnd ?? '' }}
                    value={fields.c_entry_addr_id ?? '0'} initialLabel={labels.c_entry_addr_id ?? ''} disabled={!editable}
                    onChange={(v, l) => { set('c_entry_addr_id', v || '0'); setLabel('c_entry_addr_id', l); }} /></div></div>

            {textRow('c_age', tr('age_field', '年齡') + ' (c_age)')}
            {textRow('c_posting_notes', tr('entry_posting_notes', '任官註記') + ' (c_posting_notes)')}

            <div style={rowStyle}><label style={labelStyle}>{tr('kinship_code', '親屬關係碼')} (c_kin_code)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/kincode"
                    value={fields.c_kin_code ?? '0'} initialLabel={labels.c_kin_code ?? ''} disabled={!editable}
                    onChange={(v, l) => { set('c_kin_code', v || '0'); setLabel('c_kin_code', l); }} /></div></div>
            <div style={rowStyle}><label style={labelStyle}>{tr('kinship_person', '親屬人')} (c_kin_id)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/biog"
                    value={fields.c_kin_id ?? '0'} initialLabel={labels.c_kin_id ?? ''} disabled={!editable}
                    onChange={(v, l) => { set('c_kin_id', v || '0'); setLabel('c_kin_id', l); }} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('assoc_code', '社會關係碼')} (c_assoc_code)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/assoccode"
                    value={fields.c_assoc_code ?? '0'} initialLabel={labels.c_assoc_code ?? ''} disabled={!editable}
                    onChange={(v, l) => { set('c_assoc_code', v || '0'); setLabel('c_assoc_code', l); }} /></div></div>
            <div style={rowStyle}><label style={labelStyle}>{tr('assoc_person', '關係人')} (c_assoc_id)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/biog"
                    value={fields.c_assoc_id ?? '0'} initialLabel={labels.c_assoc_id ?? ''} disabled={!editable}
                    onChange={(v, l) => { set('c_assoc_id', v || '0'); setLabel('c_assoc_id', l); }} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('socialinst_field', '社會機構')} (social_institution)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/socialinstcode"
                    value={instCombined} initialLabel={labels.c_inst_code ?? ''} disabled={!editable}
                    onChange={onPickInst} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('source_field', '出處')} (c_source)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                    value={fields.c_source ?? ''} initialLabel={labels.c_source ?? ''} disabled={!editable}
                    aria-invalid={sourceHighlight}
                    onChange={(v, l) => { set('c_source', v || '0'); setLabel('c_source', l); }} /></div></div>
            {textRow('c_pages', tr('pages_entries', '頁碼') + ' (c_pages)', sourceHighlight)}
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
