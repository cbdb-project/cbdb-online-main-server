import React, { useEffect, useMemo, useRef, useState } from 'react';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';
import ActionStatus, { BtnSpinner } from './PersonEditorShared/ActionStatus';
import { redirectAfterSubresourceCreate } from './PersonEditorShared/afterCreate';
import {
    gridCardStyle, gGrid, gInputStyle, gReadonlyStyle, gOkStyle, gErrStyle,
    gSubmitRow, gBtnGroupRight, gPrimaryBtn, gInfoBtn, gDangerBtn, gCancelBtn,
    gAuditWrapStyle, gridSectionHeadStyle, GridLabel, gridCell, gridInput,
} from './PersonEditorShared/grid';

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
    const base: Fields = { c_personid: String(personId), c_sequence: '0', c_event_code: '', c_source: '0', ...initialFields };
    const [fields, setFields] = useState<Fields>(base);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const [addrItems, setAddrItems] = useState<AddrItem[]>(initialAddr);
    const [addrKey, setAddrKey] = useState(0); // 重置地址新增框
    const initialAddrIds = useRef<string[]>(initialAddr.map((a) => String(a.id)));
    const [savedSnapshot, setSavedSnapshot] = useState(JSON.stringify(base));
    const originalPk = useRef<Record<string, number>>(Object.fromEntries(PK.map((k) => [k, Number(initialFields[k] ?? (k === 'c_personid' ? personId : 0))])));
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [sourceHighlight, setSourceHighlight] = useState(false);
    const [comment, setComment] = useState('');
    const msgTimer = useRef<number | null>(null);
    const flashSaved = (m: string) => { setMessage(m); if (msgTimer.current) window.clearTimeout(msgTimer.current); msgTimer.current = window.setTimeout(() => setMessage(null), 3000); };
    useEffect(() => () => { if (msgTimer.current) window.clearTimeout(msgTimer.current); }, []);

    // 地址（EVENTS_ADDR 多筆）是否相對初始有變動（去重比對 id 集合）。
    const addrDirty = useMemo(() => {
        const a = [...new Set(addrItems.map((x) => String(x.id)))].sort();
        const b = [...new Set(initialAddrIds.current)].sort();
        return JSON.stringify(a) !== JSON.stringify(b);
    }, [addrItems]);
    const dirty = useMemo(() => JSON.stringify(fields) !== savedSnapshot || addrDirty, [fields, addrDirty, savedSnapshot]);
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
        // 事件名 c_event_code 為主碼，必填（拒絕 0/未詳）：僅新增時擋；編輯既有列不卡。
        if (mode === 'create' && (!fields.c_event_code || fields.c_event_code === '0')) {
            setSaving(false); setError(tr('please_select_event', '請選擇事件名')); return;
        }
        // 編輯模式：可改主鍵（序號/事件名）不可被清空（清空會靜默正規化為 0）。僅擋空、允許既有 0。
        if (mode === 'edit') {
            for (const k of EDITABLE_PK) {
                if (!(fields[k] ?? '').trim()) { setSaving(false); setError(tr('pk_field_required', '主鍵欄位不可為空')); return; }
            }
        }
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
            const initial: Fields = JSON.parse(savedSnapshot);
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
            flashSaved(sm === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            // direct 儲存後從回傳列即時刷新唯讀稽核欄（建檔/更新），免重整；函式式合併避免 race，並併入 baseline 免誤判未存變更。
            const auditRow = (sm === 'direct' && json?.result?.row && typeof json.result.row === 'object') ? json.result.row as Record<string, unknown> : null;
            const auditPatch: Fields = {};
            if (auditRow) { for (const k of ['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date']) { if (auditRow[k] != null) auditPatch[k] = String(auditRow[k]); } }
            if (Object.keys(auditPatch).length > 0) setFields((prev) => ({ ...prev, ...auditPatch }));
            setSavedSnapshot(JSON.stringify({ ...fields, ...auditPatch }));
            initialAddrIds.current = addrItems.map((a) => String(a.id));
            if (mode === 'create') { redirectAfterSubresourceCreate(indexUrl, json, sm === 'direct'); }
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
        if (!deleteEndpoint || !window.confirm(tr('event_delete_confirm', '確定刪除此事件？'))) return;
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
            setSavedSnapshot(JSON.stringify(fields));
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const textRow = (key: string, label: string, code: string, highlight = false) => (
        gridCell(label, { code }, gridInput({ value: fields[key] ?? '', onChange: (v) => set(key, v), disabled: !editable, highlight }))
    );

    return (
        <div style={gridCardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('event_create', '新增事件') : tr('event_edit', '編輯事件')}</h3>
            {message ? <div style={gOkStyle}>{message}</div> : null}
            {error ? <div style={gErrStyle}>{error}</div> : null}

            <div style={gGrid}>
                {gridCell(tr('event_name', '事件名'), { code: 'c_event_code', required: true },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/event"
                        value={fields.c_event_code ?? '0'} initialLabel={labels.c_event_code ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_event_code', v); setLabel('c_event_code', l); }} />)}

                {textRow('c_role', tr('event_role', '事件角色'), 'c_role')}

                {/* 次序移到該行最右（與 address/status/entry 一致，#123） */}
                {textRow('c_sequence', tr('sequence', '序號'), 'c_sequence')}

                {gridCell(tr('event_year_field', '事件年份'), { code: 'c_year', full: true },
                    <EraTimeField values={buildEra(YR)} onChange={(p) => applyEra(YR, p)} dynastyCode={dynastyCode} showRange showLunar disabled={!editable} />)}

                {gridCell(tr('place_name', '地名'), { code: 'c_addr_id', full: true }, <>
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
                    </div>
                </>)}

                {gridCell(tr('source_field', '出處'), { code: 'c_source' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                        value={fields.c_source ?? ''} initialLabel={labels.c_source ?? ''} disabled={!editable}
                        aria-invalid={sourceHighlight}
                        onChange={(v, l) => { set('c_source', v); setLabel('c_source', l); }} />)}

                {textRow('c_pages', tr('pages_entries', '頁碼'), 'c_pages', sourceHighlight)}

                {gridCell(tr('major_event', '重大事件'), { code: 'c_event', full: true },
                    <textarea value={fields.c_event ?? ''} disabled={!editable} onChange={(e) => set('c_event', e.target.value)} rows={4} style={{ ...gInputStyle, height: 'auto', ...(!editable ? gReadonlyStyle : {}) }} />)}

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
            {dirty ? <div style={{ marginTop: 8, color: '#92400e', fontSize: '0.8rem' }}>{tr('unsaved_changes', '有未儲存的變更')}</div> : null}
        </div>
    );
}

const titleStyle: React.CSSProperties = { fontSize: '1.1rem', fontWeight: 700, marginBottom: 12 };
const chipStyle: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 4, background: '#eef4fb', border: '1px solid #c7d7ea', borderRadius: 14, padding: '2px 6px 2px 10px', fontSize: '0.8rem', color: '#1f3a5f' };
const chipRemoveStyle: React.CSSProperties = { border: 'none', background: 'transparent', color: '#1f3a5f', cursor: 'pointer', fontSize: '1rem', lineHeight: 1, padding: '0 2px' };
