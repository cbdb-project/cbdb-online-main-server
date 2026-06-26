import React, { useEffect, useMemo, useRef, useState } from 'react';
import ActionStatus, { BtnSpinner } from './PersonEditorShared/ActionStatus';
import { redirectAfterSubresourceCreate } from './PersonEditorShared/afterCreate';
import { router } from '@inertiajs/react';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';
import {
    gridCardStyle, gGrid, gInputStyle, gReadonlyStyle, gOkStyle, gErrStyle,
    gSubmitRow, gBtnGroupRight, gPrimaryBtn, gInfoBtn, gDangerBtn, gCancelBtn,
    gAuditWrapStyle, gridSectionHeadStyle, GridLabel, gridCell, gridInput,
} from './PersonEditorShared/grid';

/**
 * 地址編輯器（對齊 legacy biogmains/addresses/_form.blade.php，非 person-browser）。
 * 欄位：序號 / 地址類型 / 地名(addr 搜尋, 朝代範圍過濾) / 起年·末年(EraTimeField 含農曆) /
 * 出處 / 頁碼 / 備註 / 祖籍 / textperson_pair；三態授權提交；create→/api/v2/create、update→/api/v2/mutate。
 * 複合主鍵 (c_personid, c_addr_id, c_addr_type, c_sequence)；對齊 legacy，序號／類型／地名於新增與編輯皆可改，
 * 後端 performUpdate 支援主鍵改鍵（含衝突檢查）。
 */
type Fields = Record<string, string>;

interface Props {
    personId: number;
    personLabel: string;
    dynastyCode?: number | null;  // 人物朝代代碼（EraTimeField 年號轉換用）
    dynastyStart?: string;   // 人物朝代起年（addr 搜尋過濾 dy_start）
    dynastyEnd?: string;     // 人物朝代末年（dy_end）
    mode: 'create' | 'edit';
    initialFields: Fields;
    initialLabels?: Fields;
    otherBelongs?: string;
    canEdit: boolean;
    canPropose: boolean;
    createEndpoint: string;
    mutateEndpoint: string;
    deleteEndpoint?: string;
    indexUrl: string;
    t?: (k: string) => string;
}

const PK = ['c_personid', 'c_addr_id', 'c_addr_type', 'c_sequence'];
const FY = { year: 'c_firstyear', nhCode: 'c_fy_nh_code', nhYear: 'c_fy_nh_year', range: 'c_fy_range', intercalary: 'c_fy_intercalary', month: 'c_fy_month', day: 'c_fy_day', dayGz: 'c_fy_day_gz' };
const LY = { year: 'c_lastyear', nhCode: 'c_ly_nh_code', nhYear: 'c_ly_nh_year', range: 'c_ly_range', intercalary: 'c_ly_intercalary', month: 'c_ly_month', day: 'c_ly_day', dayGz: 'c_ly_day_gz' };
// 非主鍵可寫欄位（提交 changes 用）。
const NON_PK = [
    'c_firstyear', ...['c_fy_nh_code', 'c_fy_nh_year', 'c_fy_range', 'c_fy_intercalary', 'c_fy_month', 'c_fy_day', 'c_fy_day_gz'],
    'c_lastyear', ...['c_ly_nh_code', 'c_ly_nh_year', 'c_ly_range', 'c_ly_intercalary', 'c_ly_month', 'c_ly_day', 'c_ly_day_gz'],
    'c_source', 'c_pages', 'c_notes', 'c_natal',
];

export default function AddressEditor({
    personId, personLabel, dynastyCode = null, dynastyStart, dynastyEnd, mode, initialFields, initialLabels = {}, otherBelongs = '',
    canEdit, canPropose, createEndpoint, mutateEndpoint, deleteEndpoint, indexUrl, t,
}: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    const base: Fields = { c_personid: String(personId), c_sequence: '0', c_addr_type: '', c_addr_id: '', c_natal: '', c_source: '0', ...initialFields };
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
    const [comment, setComment] = useState('');

    const dirty = useMemo(() => JSON.stringify(fields) !== savedSnapshot, [fields, savedSnapshot]);
    const set = (k: string, v: string) => setFields((p) => ({ ...p, [k]: v }));
    const setLabel = (k: string, v: string) => setLabels((p) => ({ ...p, [k]: v }));
    const editable = canEdit || canPropose;

    const buildEra = (g: typeof FY): EraTimeFieldValues => ({
        year: fields[g.year] ?? '', nhCode: fields[g.nhCode] ?? '', nhCodeLabel: labels[g.nhCode] ?? '',
        nhYear: fields[g.nhYear] ?? '', range: fields[g.range] ?? '', rangeLabel: labels[g.range] ?? '',
        intercalary: fields[g.intercalary] ?? '0', month: fields[g.month] ?? '', day: fields[g.day] ?? '',
        dayGz: fields[g.dayGz] ?? '', dayGzLabel: labels[g.dayGz] ?? '',
    });
    const applyEra = (g: typeof FY, patch: Partial<EraTimeFieldValues>) => {
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
        // 地名 c_addr_id 為主碼，必填（拒絕 0/未詳）：僅新增時擋；編輯既有列不卡。
        if (mode === 'create' && (!fields.c_addr_id || fields.c_addr_id === '0')) {
            setSaving(false); setError(tr('please_select_place', '請選擇地名')); return;
        }
        // 地址類別 c_addr_type 為主碼，必填（拒絕 0/未詳）：僅新增時擋（#104）。
        if (mode === 'create' && (!fields.c_addr_type || fields.c_addr_type === '0')) {
            setSaving(false); setError(tr('please_select_addr_type', '請選擇地址類別')); return;
        }
        // 編輯模式：可改主鍵欄位不可被清空（清空 + skip-changes 會造成 client/DB PK 失準）。
        // 僅擋「空字串」，0=未詳 等既有值仍允許（對齊 StatusEditor）。
        if (mode === 'edit') {
            for (const k of ['c_addr_id', 'c_addr_type', 'c_sequence']) {
                if (!(fields[k] ?? '').trim()) { setSaving(false); setError(tr('pk_field_required', '主鍵欄位不可為空')); return; }
            }
        }
        const pk = Object.fromEntries(PK.map((k) => [k, Number(fields[k] ?? 0)]));
        let changes: Record<string, string | null>;
        let target: Record<string, number>;
        let endpoint: string;
        let operation: string;
        if (mode === 'create') {
            endpoint = createEndpoint; operation = 'create'; target = pk;
            changes = {};
            for (const k of NON_PK) { const v = fields[k] ?? ''; if (v !== '') changes[k] = v; }
        } else {
            endpoint = mutateEndpoint; operation = 'update'; target = originalPk.current;
            const initial: Fields = JSON.parse(savedSnapshot);
            changes = {};
            for (const k of NON_PK) { const v = fields[k] ?? ''; if ((initial[k] ?? '') !== v) changes[k] = v === '' ? null : v; }
            // 主鍵欄位（序號／類型／地名）可改：對齊 legacy，後端據此改鍵。PK 不可為空，故只送非空值。
            for (const k of ['c_addr_id', 'c_addr_type', 'c_sequence']) { const v = fields[k] ?? ''; if ((initial[k] ?? '') !== v && v !== '') changes[k] = v; }
            if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        }
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'addresses', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(sm === 'proposal' && comment ? { meta: { comment } } : {}) }),
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
            // 直接儲存若改了主鍵（序號／類型／地名），列已改鍵；以「實際送出的 PK 變更」覆寫 originalPk，
            // 後續操作才指向新列。不可用 fields 重建（清空欄位 Number('')=0 會讓 client 與 DB 失準），
            // 只套用 changes 內真正送出的 PK 欄位（清空未送出者保留原值）。
            else if (sm === 'direct') {
                const nextPk = { ...originalPk.current };
                for (const k of ['c_addr_id', 'c_addr_type', 'c_sequence']) { if (Object.prototype.hasOwnProperty.call(changes, k)) nextPk[k] = Number(changes[k]); }
                originalPk.current = nextPk;
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    const doDelete = async () => {
        if (!deleteEndpoint || !window.confirm(tr('address_delete_confirm', '確定刪除此地址？'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'addresses', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setSavedSnapshot(JSON.stringify(fields));
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const numRow = (key: string, label: string, code?: string, required = false, maxLength?: number) => (
        gridCell(label, { code, required }, (
            <input type="number" value={fields[key] ?? ''} disabled={!editable} required={required} maxLength={maxLength}
                onChange={(e) => set(key, e.target.value)} style={{ ...gInputStyle, ...(!editable ? gReadonlyStyle : {}) }} />
        ))
    );
    const textRow = (key: string, label: string, code?: string, highlight = false) => (
        gridCell(label, { code }, gridInput({ value: fields[key] ?? '', onChange: (v) => set(key, v), disabled: !editable, highlight }))
    );

    return (
        <div style={gridCardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('address_create', '新增地址') : tr('address_edit', '編輯地址')}</h3>
            {message ? <div style={gOkStyle}>{message}</div> : null}
            {error ? <div style={gErrStyle}>{error}</div> : null}

            <div style={gGrid}>
                {gridCell(tr('address_type', '地址類型'), { code: 'c_addr_type', required: true },
                    <CodeAutocomplete mode="list" model="biogaddr" idKey="c_addr_type" labelKeys={['c_addr_desc_chn', 'c_addr_desc']}
                        value={fields.c_addr_type ?? '0'} initialLabel={labels.c_addr_type ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_addr_type', v); setLabel('c_addr_type', l); }} />)}

                {gridCell(tr('place_name', '地名'), { code: 'c_addr_id', required: true, hint: otherBelongs ? `${tr('other_upper_info', '其他上層資訊')}: ${otherBelongs}` : undefined },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/addr"
                        extraQuery={{ dy_start: dynastyStart ?? '', dy_end: dynastyEnd ?? '' }}
                        value={fields.c_addr_id ?? '0'} initialLabel={labels.c_addr_id ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_addr_id', v); setLabel('c_addr_id', l); }} />)}

                {/* 遷移序號非重點，置於核心欄（地址類別/地名）之後（#99） */}
                {numRow('c_sequence', tr('migration_sequence', '遷移序號'), 'c_sequence', false, 4)}

                {gridCell(tr('start_year', '起年'), { code: 'c_firstyear', full: true },
                    <EraTimeField values={buildEra(FY)} onChange={(p) => applyEra(FY, p)} dynastyCode={dynastyCode} showRange showLunar disabled={!editable} />)}
                {gridCell(tr('end_year', '末年'), { code: 'c_lastyear', full: true },
                    <EraTimeField values={buildEra(LY)} onChange={(p) => applyEra(LY, p)} dynastyCode={dynastyCode} showRange showLunar disabled={!editable} />)}

                {gridCell(tr('source_field', '出處'), { code: 'c_source' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                        value={fields.c_source ?? ''} initialLabel={labels.c_source ?? ''} disabled={!editable}
                        aria-invalid={sourceHighlight}
                        onChange={(v, l) => { set('c_source', v); setLabel('c_source', l); }} />)}
                {textRow('c_pages', tr('pages_entries', '頁碼'), 'c_pages', sourceHighlight)}
                {gridCell(tr('notes_field', '備註'), { code: 'c_notes', full: true },
                    <textarea value={fields.c_notes ?? ''} disabled={!editable} onChange={(e) => set('c_notes', e.target.value)} rows={4} style={{ ...gInputStyle, height: 'auto', ...(!editable ? gReadonlyStyle : {}) }} />)}

                {gridCell(tr('maiden_addr', '祖籍'), { code: 'c_natal' },
                    <select value={fields.c_natal ?? ''} onChange={(e) => set('c_natal', e.target.value)} disabled={!editable} style={gInputStyle}>
                        <option value="">{tr('please_select', '請選擇')}</option>
                        <option value="0">0-{tr('no', '否')}</option>
                        <option value="1">1-{tr('yes', '是')}</option>
                    </select>)}
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
        </div>
    );
}

const titleStyle: React.CSSProperties = { fontSize: '1.1rem', fontWeight: 700, marginBottom: 12 };
