import React, { useMemo, useRef, useState } from 'react';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';

/**
 * 親屬關係（kinship / KIN_DATA）編輯器（對齊 legacy biogmains/kinship/_form.blade.php，非 person-browser）。
 *
 * === 互逆配對碼（c_kinship_pair）由後端自動補齊 ===
 * legacy 用前端 JS 查 /api/select/search/kinpair 填 c_kinship_pair（hidden）送出。
 * v2 後端 KinshipCreate/MutationHandler 一律以 KINSHIP_CODES.c_kin_pair1 權威推導反向親屬碼
 * （不採信前端值），並於同交易雙向同步鏡像列，故本編輯器**不送 pair**。
 *
 * === 3 段複合主鍵 ===
 * c_personid（路由帶入，不可改）, c_kin_id（親屬人物）, c_kin_code（親屬關係碼）。
 * 編輯模式可改 c_kin_id / c_kin_code（後端鏡像隨之遷移）；空值正規化哨兵 '0'；改鍵後重同步 originalPk。
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

// 3 段複合主鍵。
const PK = ['c_personid', 'c_kin_id', 'c_kin_code'];
// 可改主鍵段（除 c_personid）。
const EDITABLE_PK = ['c_kin_id', 'c_kin_code'];
// 非主鍵可寫欄位（對齊 legacy _form 與 v2 handler 白名單）。
const NON_PK = ['c_source', 'c_pages', 'c_notes', 'c_autogen_notes'];

export default function KinEditor({
    personId, personLabel, mode, initialFields, initialLabels = {},
    canEdit, canPropose, createEndpoint, mutateEndpoint, deleteEndpoint, indexUrl, t,
}: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    const base: Fields = {
        c_personid: String(personId),
        c_kin_id: '0', c_kin_code: '0', c_source: '0',
        ...initialFields,
    };
    const [fields, setFields] = useState<Fields>(base);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const snapshot = useRef(JSON.stringify(base));
    const originalPk = useRef<Record<string, number | string>>(Object.fromEntries(PK.map((k) => {
        if (k === 'c_personid') return [k, personId];
        return [k, Number(initialFields[k] ?? 0)];
    })));
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

    const onPickTextperson = (p: { source: string; pages: string; sourceLabel: string }) => {
        setFields((prev) => ({ ...prev, c_source: p.source, c_pages: p.pages }));
        setLabel('c_source', p.sourceLabel);
        setSourceHighlight(true);
        window.setTimeout(() => setSourceHighlight(false), 4000);
        setMessage(tr('update_source_success', '已自動回填出處與頁碼'));
    };

    const save = async (sm: 'direct' | 'proposal') => {
        setSaving(true); setError(null); setMessage(null);
        // PK 段空值正規化為哨兵 '0'。
        const pkVal = (k: string): number | string => {
            if (k === 'c_personid') return personId;
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
                const init = String(Number(initial[k]?.trim() ? initial[k] : '0'));
                if (cur !== init) changes[k] = cur;
            }
            if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        }
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'kinship', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(sm === 'proposal' && comment ? { meta: { comment } } : {}) }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setMessage(sm === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            snapshot.current = JSON.stringify(fields);
            if (mode === 'create') { window.location.assign(indexUrl); } else if (sm === 'direct') {
                // 改鍵後以實際送出的 PK 變更覆寫 originalPk（不可用 fields 重建，避免 Number('')=0 失準）。
                const nextPk = { ...originalPk.current };
                for (const k of EDITABLE_PK) { if (Object.prototype.hasOwnProperty.call(changes, k)) nextPk[k] = Number(changes[k]); }
                originalPk.current = nextPk;
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    const doDelete = async () => {
        if (!deleteEndpoint || !window.confirm(tr('delete_confirm', '確定刪除此親屬關係？'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'kinship', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            snapshot.current = JSON.stringify(fields);
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const searchRow = (key: string, label: string, endpoint: string, highlight = false, sentinel = '0') => (
        <div style={rowStyle}><label style={labelStyle}>{label}</label><div style={fieldStyle}>
            <CodeAutocomplete mode="search" endpoint={endpoint} value={fields[key] ?? sentinel} initialLabel={labels[key] ?? ''}
                disabled={!editable} aria-invalid={highlight}
                onChange={(v, l) => { set(key, v || sentinel); setLabel(key, l); }} /></div></div>
    );

    return (
        <div style={cardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('kinship_create', '新增親屬關係') : tr('kinship_edit', '編輯親屬關係')} — {personLabel}</h3>
            {message ? <div style={okStyle}>{message}</div> : null}
            {error ? <div style={errStyle}>{error}</div> : null}

            {searchRow('c_kin_code', `${tr('kinship_relation', '親屬關係')} (c_kin_code)`, '/api/select/search/kincode')}
            {searchRow('c_kin_id', `${tr('relative_name', '親屬姓名')} (c_kin_id)`, '/api/select/search/biog')}

            <div style={rowStyle}><label style={labelStyle}>{tr('source_field', '出處')} (c_source)</label><div style={fieldStyle}>
                <CodeAutocomplete mode="search" endpoint="/api/select/search/text" value={fields.c_source ?? '0'} initialLabel={labels.c_source ?? ''} disabled={!editable} onChange={(v, l) => { set('c_source', v || '0'); setLabel('c_source', l); }} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('pages_entries', '頁碼')} (c_pages)</label><div style={fieldStyle}>
                <input type="text" value={fields.c_pages ?? ''} disabled={!editable} onChange={(e) => set('c_pages', e.target.value)}
                    style={{ ...inputStyle, ...(sourceHighlight ? { background: '#FFFFBB' } : {}), ...(!editable ? roStyle : {}) }} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>{tr('notes_field', '備註')} (c_notes)</label><div style={fieldStyle}>
                <textarea value={fields.c_notes ?? ''} disabled={!editable} onChange={(e) => set('c_notes', e.target.value)} rows={4} style={{ ...inputStyle, height: 'auto', ...(!editable ? roStyle : {}) }} /></div></div>

            <div style={rowStyle}><label style={labelStyle}>c_autogen_notes</label><div style={fieldStyle}>
                <textarea value={fields.c_autogen_notes ?? ''} disabled={!editable} onChange={(e) => set('c_autogen_notes', e.target.value)} rows={4} style={{ ...inputStyle, height: 'auto', ...(!editable ? roStyle : {}) }} /></div></div>

            <TextpersonPair personId={personId} label={tr('candidate_source_title', '候選出處')} onPick={onPickTextperson} disabled={!editable} />

            <div style={pairHintStyle}>
                ℹ️ {tr('kinship_pair_auto_hint', '互逆親屬關係（配對）由系統依關係碼自動雙向同步，無需手動填寫。')}
            </div>

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
const inputStyle: React.CSSProperties = { width: '100%', height: 36, padding: '0 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: '0.875rem', boxSizing: 'border-box' };
const roStyle: React.CSSProperties = { background: '#f3f4f6', cursor: 'not-allowed' };
const pairHintStyle: React.CSSProperties = { background: '#f0f9ff', border: '1px solid #bae6fd', borderRadius: 8, padding: '8px 12px', margin: '6px 0', fontSize: '0.82rem', color: '#334155' };
const okStyle: React.CSSProperties = { background: '#ecfdf5', border: '1px solid #a7f3d0', color: '#065f46', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const errStyle: React.CSSProperties = { background: '#fef2f2', border: '1px solid #fecaca', color: '#991b1b', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const primaryBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #255f93', background: '#255f93', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const infoBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #0e7490', background: '#0891b2', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const dangerBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #b91c1c', background: '#fff5f5', color: '#b91c1c', fontWeight: 700, cursor: 'pointer' };
const cancelBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #cbd5e1', background: '#fff', color: '#475569', fontWeight: 700, textDecoration: 'none', display: 'inline-flex', alignItems: 'center' };
