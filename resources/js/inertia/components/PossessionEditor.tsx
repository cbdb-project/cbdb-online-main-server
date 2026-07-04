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
 * 占有／財產（possession）編輯器（對齊 legacy biogmains/possession/_form.blade.php，非 person-browser）。
 * 欄位：序號 / 占有行為(possact) / 英文/中文描述 / 數量+單位(measure) / 年份(EraTimeField 無農曆) /
 * 地名(c_addr_id 多選，POSSESSION_ADDR 副表) / 出處 / 頁碼 / 備註 / textperson_pair；三態授權提交。
 * 主鍵 c_possession_record_id 為伺服器配發 surrogate：新增 target.pk 留空，由 PossessionCreateHandler 配發；
 * 更新以 c_possession_record_id 定位。
 *
 * 地址副表（POSSESSION_ADDR）：新增與編輯皆可增刪。送 c_addr_id 陣列，由 PossessionMutationHandler
 * 於同交易 afterDirectUpdate（及 create 的 PossessionCreateHandler）同步副表（record_id 固定、刪重插整組）；
 * proposal 更新經 applyPossessionUpdateProposal 套用。不寫 POSSESSION_DATA 純量欄。
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
    const base: Fields = { c_personid: String(personId), c_possession_act_code: '0', c_source: '0', c_measure_code: '0', ...initialFields };
    const [fields, setFields] = useState<Fields>(base);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const [addrItems, setAddrItems] = useState<AddrItem[]>(initialAddr);
    const [addKey, setAddKey] = useState(0);
    const initialAddrIds = useRef<string[]>(initialAddr.map((a) => String(a.id)));
    const [savedSnapshot, setSavedSnapshot] = useState(JSON.stringify(base));
    const originalPk = useRef<Record<string, number>>({ c_possession_record_id: Number(initialFields.c_possession_record_id ?? 0) });
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [sourceHighlight, setSourceHighlight] = useState(false);
    const [comment, setComment] = useState('');
    const msgTimer = useRef<number | null>(null);
    const flashSaved = (m: string) => { setMessage(m); if (msgTimer.current) window.clearTimeout(msgTimer.current); msgTimer.current = window.setTimeout(() => setMessage(null), 3000); };
    useEffect(() => () => { if (msgTimer.current) window.clearTimeout(msgTimer.current); }, []);

    const addrDirty = useMemo(() => {
        const a = [...new Set(addrItems.map((x) => String(x.id)))].sort();
        const b = [...new Set(initialAddrIds.current)].sort();
        return JSON.stringify(a) !== JSON.stringify(b);
    }, [addrItems]);
    const dirty = useMemo(() => JSON.stringify(fields) !== savedSnapshot || addrDirty, [fields, addrDirty, savedSnapshot]);
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
            const initial: Fields = JSON.parse(savedSnapshot);
            changes = {};
            for (const k of NON_PK) { const v = fields[k] ?? ''; if ((initial[k] ?? '') !== v) changes[k] = v === '' ? null : v; }
            // 地址有變動才送 c_addr_id（清空則送空陣列）；後端 afterDirectUpdate 同步 POSSESSION_ADDR。
            if (addrDirty) changes.c_addr_id = addrItems.map((it) => it.id);
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
            flashSaved(sm === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            // direct 儲存後從回傳列即時刷新唯讀稽核欄（建檔/更新），免重整；函式式合併避免 race，並併入 baseline 免誤判未存變更。
            const auditRow = (sm === 'direct' && json?.result?.row && typeof json.result.row === 'object') ? json.result.row as Record<string, unknown> : null;
            const auditPatch: Fields = {};
            if (auditRow) { for (const k of ['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date']) { if (auditRow[k] != null) auditPatch[k] = String(auditRow[k]); } }
            if (Object.keys(auditPatch).length > 0) setFields((prev) => ({ ...prev, ...auditPatch }));
            setSavedSnapshot(JSON.stringify({ ...fields, ...auditPatch }));
            initialAddrIds.current = addrItems.map((it) => String(it.id));
            if (mode === 'create') { redirectAfterSubresourceCreate(indexUrl, json, sm === 'direct'); }
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    const doDelete = async () => {
        if (!deleteEndpoint || !window.confirm(tr('possession_delete_confirm', '確定刪除此財產記錄？'))) return;
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
            setSavedSnapshot(JSON.stringify(fields));
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const textRow = (key: string, label: string, code?: string) => (
        gridCell(label, { code }, gridInput({ value: fields[key] ?? '', onChange: (v) => set(key, v), disabled: !editable, name: key }))
    );

    return (
        <div style={gridCardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('possession_create', '新增財產記錄') : tr('possession_edit', '編輯財產記錄')}</h3>
            {message ? <div style={gOkStyle}>{message}</div> : null}
            {error ? <div style={gErrStyle}>{error}</div> : null}

            <div style={gGrid}>
                {gridCell(tr('possession_action_field', '占有行為'), { code: 'c_possession_act_code' },
                    <CodeAutocomplete mode="list" model="possact" idKey="c_possession_act_code" labelKeys={['c_possession_act_desc_chn', 'c_possession_act_desc']}
                        value={fields.c_possession_act_code ?? '0'} initialLabel={labels.c_possession_act_code ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_possession_act_code', v); setLabel('c_possession_act_code', l); }} />)}

                {/* 次序移到該行最右（與 address/status/entry 一致，#123） */}
                {textRow('c_sequence', tr('sequence', '序號'), 'entry_sequence')}

                {textRow('c_possession_desc', tr('possession_english', '英文描述'), 'possession_desc')}
                {textRow('c_possession_desc_chn', tr('possession_chinese', '中文描述'), 'possession_desc_chn')}

                {gridCell(tr('quantity', '數量'), { code: 'c_quantity', full: true },
                    <div style={{ display: 'flex', gap: 8 }}>
                        <input type="text" name="c_quantity" id="c_quantity" value={fields.c_quantity ?? ''} disabled={!editable} onChange={(e) => set('c_quantity', e.target.value)} style={{ ...gInputStyle, width: 100, ...(!editable ? gReadonlyStyle : {}) }} />
                        <div style={{ flex: 1, minWidth: 0 }}>
                            <CodeAutocomplete mode="list" model="measure" idKey="c_measure_code" labelKeys={['c_measure_desc_chn', 'c_measure_desc']}
                                value={fields.c_measure_code ?? ''} initialLabel={labels.c_measure_code ?? ''} disabled={!editable} placeholder={tr('unit', '單位')}
                                onChange={(v, l) => { set('c_measure_code', v); setLabel('c_measure_code', l); }} /></div></div>)}

                {gridCell(tr('year_field', '年份'), { code: 'c_possession_yr', full: true },
                    <EraTimeField values={buildEra(YR)} onChange={(p) => applyEra(YR, p)} dynastyCode={dynastyCode} showRange disabled={!editable} />)}

                {gridCell(tr('place_name', '地名'), { code: 'c_addr_id', full: true },
                    <>
                        {editable ? (
                            <CodeAutocomplete key={addKey} mode="search" endpoint="/api/select/search/addr"
                                extraQuery={{ dy_start: dynastyStart ?? '', dy_end: dynastyEnd ?? '' }}
                                value="" initialLabel="" placeholder={tr('add_place', '搜尋並加入地名…')}
                                onChange={(v, l) => addAddr(v, l)} />
                        ) : null}
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: editable ? 6 : 0 }}>
                            {addrItems.map((it) => (
                                <span key={it.id} style={chipStyle}>{it.label}
                                    {editable ? (
                                        <button type="button" onClick={() => removeAddr(it.id)} style={chipRemoveStyle} aria-label="remove">×</button>
                                    ) : null}
                                </span>
                            ))}
                            {addrItems.length === 0 ? <span style={{ fontSize: '0.8rem', color: 'var(--muted-foreground)' }}>{tr('no_place', '（未設定地名）')}</span> : null}
                        </div>
                    </>)}

                {gridCell(tr('source_field', '出處'), { code: 'c_source' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                        value={fields.c_source ?? ''} initialLabel={labels.c_source ?? ''} disabled={!editable}
                        aria-invalid={sourceHighlight}
                        onChange={(v, l) => { set('c_source', v); setLabel('c_source', l); }} />)}

                {gridCell(tr('pages_entries', '頁碼'), {},
                    gridInput({ value: fields.c_pages ?? '', onChange: (v) => set('c_pages', v), disabled: !editable, highlight: sourceHighlight, name: 'c_pages' }))}

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
        </div>
    );
}

const titleStyle: React.CSSProperties = { fontSize: '1.1rem', fontWeight: 700, marginBottom: 12 };
const chipStyle: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 4, background: 'var(--info-subtle)', border: '1px solid var(--info-border)', borderRadius: 14, padding: '2px 10px', fontSize: '0.8rem', color: 'var(--info-subtle-foreground)' };
const chipRemoveStyle: React.CSSProperties = { border: 'none', background: 'transparent', color: 'var(--muted-foreground)', cursor: 'pointer', fontSize: 14, lineHeight: '14px', padding: 0 };
