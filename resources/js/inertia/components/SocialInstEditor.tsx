import React, { useEffect, useMemo, useRef, useState } from 'react';
import ActionStatus, { BtnSpinner } from './PersonEditorShared/ActionStatus';
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
 * 社會機構（socialinst）編輯器（對齊 legacy biogmains/socialinst/_form.blade.php，非 person-browser）。
 * 欄位：社交機構(socialinstcode 搜尋，合併值 inst_code-inst_name_code) / 角色(birole 清單) /
 * 起年·末年(EraTimeField 無農曆) / 出處 / 頁碼 / 備註 / textperson_pair；三態授權提交。
 * 複合主鍵 (c_personid, c_inst_code, c_inst_name_code, c_bi_role_code)，四鍵皆 NOT NULL；
 * 對齊 legacy：機構／角色於編輯模式可改（後端 performUpdate 改鍵 + 衝突檢查），空值正規化為 '0'（未詳）。
 */
type Fields = Record<string, string>;

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
    t?: (k: string) => string;
}

const PK = ['c_personid', 'c_inst_code', 'c_inst_name_code', 'c_bi_role_code'];
// 編輯模式可改鍵（c_personid 固定）。空值一律正規化為 '0'（未詳），對齊 legacy explode 後的 `?: '0'`。
const EDITABLE_PK = ['c_inst_code', 'c_inst_name_code', 'c_bi_role_code'];
const BY = { year: 'c_bi_begin_year', nhCode: 'c_bi_by_nh_code', nhYear: 'c_bi_by_nh_year', range: 'c_bi_by_range' };
const EY = { year: 'c_bi_end_year', nhCode: 'c_bi_ey_nh_code', nhYear: 'c_bi_ey_nh_year', range: 'c_bi_ey_range' };
// 非主鍵可寫欄位（均 nullable，空值送 null）。c_source 為 FK(TEXT_CODES) nullable，空值送 null。
const NON_PK = [
    'c_source', 'c_pages', 'c_notes',
    'c_bi_begin_year', 'c_bi_by_nh_code', 'c_bi_by_nh_year', 'c_bi_by_range',
    'c_bi_end_year', 'c_bi_ey_nh_code', 'c_bi_ey_nh_year', 'c_bi_ey_range',
];

type EraGroup = { year: string; nhCode: string; nhYear: string; range: string };

export default function SocialInstEditor({
    personId, personLabel, dynastyCode = null, mode, initialFields, initialLabels = {},
    canEdit, canPropose, createEndpoint, mutateEndpoint, deleteEndpoint, indexUrl, t,
}: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    // 對齊 legacy socialinst/_form 的新增預設（c_source 預設 option 0、機構/角色 0-0/0）：
    // 新增時若使用者未動 c_source，仍須送出 '0'（未詳碼）而非省略致 DB 落 NULL。編輯模式 initialFields 會覆蓋這些預設。
    const base: Fields = { c_personid: String(personId), c_inst_code: '0', c_inst_name_code: '0', c_bi_role_code: '0', c_source: '0', ...initialFields };
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

    // 機構合併欄位：搜尋回傳 id = `inst_code-inst_name_code`，拆分填入兩欄；清空視為未詳 0-0。
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
        setSaving(true); setError(null); setMessage(null);
        // PK 欄位 NOT NULL：空值正規化為 '0'（未詳），對齊 legacy。
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
            const initial: Fields = JSON.parse(savedSnapshot);
            changes = {};
            for (const k of NON_PK) { const v = fields[k] ?? ''; if ((initial[k] ?? '') !== v) changes[k] = v === '' ? null : v; }
            // 可改鍵：機構／角色。PK NOT NULL，空值送 '0'；與原值（正規化後）不同才送。
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
                body: JSON.stringify({ resource: 'social_institutions', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(sm === 'proposal' && comment ? { meta: { comment } } : {}) }),
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
        if (!deleteEndpoint || !window.confirm(tr('delete_confirm', '確定刪除此社會機構記錄？'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'social_institutions', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setSavedSnapshot(JSON.stringify(fields));
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    return (
        <div style={gridCardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('socialinst_create', '新增社會機構') : tr('socialinst_edit', '編輯社會機構')} — {personLabel}</h3>
            {message ? <div style={gOkStyle}>{message}</div> : null}
            {error ? <div style={gErrStyle}>{error}</div> : null}

            <div style={gGrid}>
                {gridCell(tr('socialinst_field', '社交機構'), { code: 'social_institution' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/socialinstcode"
                        value={instCombined} initialLabel={labels.c_inst_code ?? ''} disabled={!editable}
                        onChange={onPickInst} />)}

                {gridCell(tr('socialinst_role', '社交機構角色'), { code: 'c_bi_role_code' },
                    <CodeAutocomplete mode="list" model="birole" idKey="c_bi_role_code" labelKeys={['c_bi_role_chn', 'c_bi_role_desc']}
                        value={fields.c_bi_role_code ?? '0'} initialLabel={labels.c_bi_role_code ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_bi_role_code', v); setLabel('c_bi_role_code', l); }} />)}

                {gridCell(tr('start_year', '起年'), { code: 'c_bi_begin_year', full: true },
                    <EraTimeField values={buildEra(BY)} onChange={(p) => applyEra(BY, p)} dynastyCode={dynastyCode} showRange disabled={!editable} />)}
                {gridCell(tr('end_year', '末年'), { code: 'c_bi_end_year', full: true },
                    <EraTimeField values={buildEra(EY)} onChange={(p) => applyEra(EY, p)} dynastyCode={dynastyCode} showRange disabled={!editable} />)}

                {gridCell(tr('source_field', '出處'), { code: 'c_source' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                        value={fields.c_source ?? ''} initialLabel={labels.c_source ?? ''} disabled={!editable}
                        aria-invalid={sourceHighlight}
                        onChange={(v, l) => { set('c_source', v); setLabel('c_source', l); }} />)}

                {gridCell(tr('pages_entries', '頁碼'), {},
                    gridInput({ value: fields.c_pages ?? '', onChange: (v) => set('c_pages', v), disabled: !editable, highlight: sourceHighlight }))}

                {gridCell(tr('notes_field', '備註'), { code: 'c_notes', full: true },
                    <textarea value={fields.c_notes ?? ''} disabled={!editable} onChange={(e) => set('c_notes', e.target.value)} rows={4} style={{ ...gInputStyle, height: 'auto', ...(!editable ? gReadonlyStyle : {}) }} />)}
            </div>

            <TextpersonPair personId={personId} label={tr('candidate_source_title', '候選出處')} onPick={onPickTextperson} disabled={!editable} />

            {mode === 'edit' && (fields.c_created_by || fields.c_modified_by) ? (
                <div style={gAuditWrapStyle}>
                    <div style={gridSectionHeadStyle}>{tr('create_or_modify', '建檔 / 更新資訊')}</div>
                    <div style={gGrid}>
                        {fields.c_created_by ? gridCell(tr('audit_created', '建檔'), {},
                            <input type="text" value={`${fields.c_created_by}${fields.c_created_date ? '/' + fields.c_created_date : ''}`} readOnly disabled style={{ ...gInputStyle, ...gReadonlyStyle }} />) : null}
                        {fields.c_modified_by ? gridCell(tr('audit_updated', '更新'), {},
                            <input type="text" value={`${fields.c_modified_by}${fields.c_modified_date ? '/' + fields.c_modified_date : ''}`} readOnly disabled style={{ ...gInputStyle, ...gReadonlyStyle }} />) : null}
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
