import React, { useEffect, useMemo, useRef, useState } from 'react';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';
import ActionStatus, { BtnSpinner } from './PersonEditorShared/ActionStatus';
import MirrorConflictNotice, { MirrorConflict } from './PersonEditorShared/MirrorConflictNotice';
import MirrorSuspectedNotice, { MirrorSuspected } from './PersonEditorShared/MirrorSuspectedNotice';
import OppositeEdgeNotice from './PersonEditorShared/OppositeEdgeNotice';
import { useOppositeEdgeDetection } from './PersonEditorShared/useOppositeEdgeDetection';

/**
 * 親屬關係（kinship / KIN_DATA）編輯器（對齊 legacy biogmains/kinship/_form.blade.php，非 person-browser）。
 *
 * === 互逆配對碼（c_kinship_pair）：可選/可手選反向關係碼 ===
 * 反向關係常有歧義須人選（父→子或女、第幾子…）。本編輯器對齊 legacy：依正向碼查
 * /api/select/search/kinpair 取候選、預設選第一個，使用者可手動更正後送 c_kinship_pair。
 * v2 後端（KinshipCreate/MutationHandler）：未送→權威預設 c_kin_pair1；送→驗證為合法配對
 * 否則 422（fail-closed），再於同交易雙向同步鏡像列。
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
    const [savedSnapshot, setSavedSnapshot] = useState(JSON.stringify(base));
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
    const [conflict, setConflict] = useState<MirrorConflict | null>(null); // #66 對面鏡像衝突
    const [suspected, setSuspected] = useState<MirrorSuspected | null>(null); // #70 對面疑似漂移鏡像
    // 互逆配對碼（反向關係碼）：候選由 /api/select/search/kinpair 依正向碼取得（對齊 legacy）。
    // 預設選第一個候選（同 legacy）；反向關係常有歧義（父→子/女、第幾子…）故容許手選。
    type PairOpt = { code: string; label: string };
    const [pairCandidates, setPairCandidates] = useState<PairOpt[]>([]);
    const [reversePair, setReversePair] = useState<string>('');
    // edit 模式僅在使用者「主動更改」反向碼時才送出覆寫（避免改備註等非關係編輯誤改鏡像反向碼）。
    const [pairTouched, setPairTouched] = useState(false);
    const msgTimer = useRef<number | null>(null);
    const flashSaved = (m: string) => { setMessage(m); if (msgTimer.current) window.clearTimeout(msgTimer.current); msgTimer.current = window.setTimeout(() => setMessage(null), 3000); };
    useEffect(() => () => { if (msgTimer.current) window.clearTimeout(msgTimer.current); }, []);
    // #80（§5-B）：對面多筆對應時，direct 存檔須二次確認（先停下提示，再次點擊才一併同步）。偵測結果變動即重置。
    const multiAckRef = useRef(false);

    const dirty = useMemo(() => JSON.stringify(fields) !== savedSnapshot, [fields, savedSnapshot]);

    // #79：偵測對面缺邊/多條（僅 edit 模式、依「已存檔」的列定位；存檔後 savedSnapshot 變→重抓）。
    const savedRow = useMemo<Fields>(() => { try { return JSON.parse(savedSnapshot) as Fields; } catch { return base; } }, [savedSnapshot]);
    const { result: oppositeEdge } = useOppositeEdgeDetection({
        // 僅「可直接寫入」者偵測（後端亦 detection:false 把關，前端先過濾省一次請求；對齊提示只對直接寫入者有意義）。
        enabled: mode === 'edit' && canEdit,
        resource: 'kinship',
        personId,
        forward: { opposite_id: savedRow.c_kin_id ?? '0', forward_code: savedRow.c_kin_code ?? '0', autogen_notes: savedRow.c_autogen_notes ?? null },
        reloadKey: savedSnapshot,
    });
    // 偵測結果物件每次重抓即換參考（含同筆數但對面列集合改變的情形）→ 重置武裝旗標。
    useEffect(() => { multiAckRef.current = false; }, [oppositeEdge]);
    const reverseCodeLabel = useMemo(() => pairCandidates.find((o) => o.code === reversePair)?.label, [pairCandidates, reversePair]);

    const set = (k: string, v: string) => setFields((p) => ({ ...p, [k]: v }));
    const setLabel = (k: string, v: string) => setLabels((p) => ({ ...p, [k]: v }));
    const editable = canEdit || canPropose;

    // 正向碼變更時重抓反向配對候選，並預設選第一個（對齊 legacy JS）；正向碼改變即重置 touched。
    useEffect(() => {
        const code = fields.c_kin_code;
        if (!code || Number(code) === 0) { setPairCandidates([]); setReversePair(''); setPairTouched(false); return; }
        let aborted = false;
        (async () => {
            try {
                const res = await fetch(`/api/select/search/kinpair?kin_code=${encodeURIComponent(code)}&person_id=${encodeURIComponent(fields.c_kin_id ?? '0')}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                });
                const rows = await res.json().catch(() => []);
                if (aborted) return;
                const opts: PairOpt[] = Array.isArray(rows)
                    ? rows.map((r: Record<string, unknown>) => ({
                        code: String(r.c_kincode),
                        label: `${r.c_kincode} ${r.c_kinrel_chn ?? ''} ${r.c_kinrel ?? ''}`.trim(),
                    }))
                    : [];
                setPairCandidates(opts);
                // create：預設選第一候選（同 legacy）；edit：預設「保持目前反向碼」（空），
                // 不冒充目前值（編輯器未載入既有鏡像列的實際反向碼），未觸碰即不送、後端保留原值。
                setReversePair(mode === 'create' && opts.length ? opts[0].code : '');
                setPairTouched(false);
            } catch { if (!aborted) { setPairCandidates([]); setReversePair(''); } }
        })();
        return () => { aborted = true; };
    // 僅依正向碼變動觸發（c_kin_id 變動不需重抓配對候選）。
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [fields.c_kin_code]);

    const onPickTextperson = (p: { source: string; pages: string; sourceLabel: string }) => {
        setFields((prev) => ({ ...prev, c_source: p.source, c_pages: p.pages }));
        setLabel('c_source', p.sourceLabel);
        setSourceHighlight(true);
        window.setTimeout(() => setSourceHighlight(false), 4000);
        setMessage(tr('update_source_success', '已自動回填出處與頁碼'));
    };

    const save = async (sm: 'direct' | 'proposal', force = false) => {
        // #80（§5-B）：direct 存檔且偵測到對面多筆對應時，第一次點擊只提示不送出（武裝確認），第二次才一併同步。
        // force（#66/#70 衝突強制覆寫/收斂）已是使用者的明確決定，不再二次攔截。
        if (sm === 'direct' && !force && oppositeEdge?.status === 'multiple' && !multiAckRef.current) {
            multiAckRef.current = true;
            setError(tr('opposite_edge_multiple_confirm', '對面有多筆對應的反向關係。直接保存會一併同步這些反向列。請先確認上方列出的記錄無誤，再次點擊「直接保存」以繼續。'));
            return;
        }
        setSaving(true); setError(null); setMessage(null); setConflict(null); setSuspected(null);
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
            // 反向配對碼：create 送目前選取（預設第一候選，同 legacy）；後端驗證為合法配對後寫入鏡像。
            if (reversePair) changes.c_kinship_pair = reversePair;
        } else {
            endpoint = mutateEndpoint; operation = 'update'; target = originalPk.current;
            const initial: Fields = JSON.parse(savedSnapshot);
            changes = {};
            for (const k of NON_PK) { const v = fields[k] ?? ''; if ((initial[k] ?? '') !== v) changes[k] = v === '' ? null : v; }
            // 可改主鍵段：與原值（正規化後）不同才送（PK NOT NULL，送哨兵而非空）。
            for (const k of EDITABLE_PK) {
                const cur = String(pkVal(k));
                const init = String(Number(initial[k]?.trim() ? initial[k] : '0'));
                if (cur !== init) changes[k] = cur;
            }
            // 反向配對碼：edit 僅在使用者主動更改時送出覆寫（避免改備註等誤改鏡像反向碼）。
            if (reversePair && pairTouched) changes.c_kinship_pair = reversePair;
            if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        }
        try {
            // #66：meta 可帶 comment（proposal）與 force（衝突警告中選「強制覆寫」時）。
            const meta: Record<string, unknown> = {};
            if (sm === 'proposal' && comment) meta.comment = comment;
            if (force) meta.force = true;
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'kinship', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(Object.keys(meta).length ? { meta } : {}) }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) {
                // #66：對面鏡像衝突 → 顯示警告 + 連結 + 強制覆寫，不丟一般錯誤。
                const mc = json?.errors?.mirror_conflict;
                if (res.status === 409 && mc) { setSaving(false); setConflict(mc as MirrorConflict); return; }
                // #70：對面疑似漂移鏡像 → 顯示疑似警告 + 逐一連結 + 強制收斂。
                const ms = json?.errors?.mirror_suspected;
                if (res.status === 409 && ms) { setSaving(false); setSuspected(ms as MirrorSuspected); return; }
                throw new Error(json?.message || `HTTP ${res.status}`);
            }
            flashSaved(sm === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            // direct 儲存後從回傳列即時刷新唯讀稽核欄（建檔/更新），免重整；函式式合併避免 race，並併入 baseline 免誤判未存變更。
            const auditRow = (sm === 'direct' && json?.result?.row && typeof json.result.row === 'object') ? json.result.row as Record<string, unknown> : null;
            const auditPatch: Fields = {};
            if (auditRow) { for (const k of ['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date']) { if (auditRow[k] != null) auditPatch[k] = String(auditRow[k]); } }
            if (Object.keys(auditPatch).length > 0) setFields((prev) => ({ ...prev, ...auditPatch }));
            setSavedSnapshot(JSON.stringify({ ...fields, ...auditPatch }));
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
            setSavedSnapshot(JSON.stringify(fields));
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
            <OppositeEdgeNotice result={oppositeEdge} reverseCodeLabel={reverseCodeLabel} tr={tr} />

            {searchRow('c_kin_code', `${tr('kinship_relation', '親屬關係')} (c_kin_code)`, '/api/select/search/kincode')}
            {searchRow('c_kin_id', `${tr('relative_name', '親屬姓名')} (c_kin_id)`, '/api/select/search/biog')}

            {/* 互逆配對碼：依正向碼取候選，預設第一個（同 legacy），反向關係有歧義（父→子/女、第幾子…）故可手選。 */}
            <div style={rowStyle}><label style={labelStyle}>{tr('reverse_pair_label', '互逆配對碼')} (c_kinship_pair)</label><div style={fieldStyle}>
                {pairCandidates.length ? (
                    <select value={reversePair} disabled={!editable}
                        onChange={(e) => { setReversePair(e.target.value); setPairTouched(true); }}
                        style={{ ...inputStyle }}>
                        {mode === 'edit' ? <option value="">{tr('keep_current_pair', '（保持目前反向碼）')}</option> : null}
                        {pairCandidates.map((o) => <option key={o.code} value={o.code}>{o.label}</option>)}
                    </select>
                ) : (
                    <input type="text" value={tr('no_paired_kinship', '（此關係無對應反向配對碼，系統自動處理）')} readOnly disabled style={{ ...inputStyle, ...roStyle }} />
                )}
                <span style={pairFieldHintStyle}>{tr('reverse_pair_hint', '系統依關係碼自動雙向同步；預設取建議的反向碼，可手動更正（例：父→子或女、第幾子）。')}</span>
            </div></div>

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

            {conflict ? (
                <MirrorConflictNotice
                    conflict={conflict}
                    mirrorUrl={`/app/basicinformation/${conflict.pk.c_personid}/kinship/edit-v2?${new URLSearchParams(Object.fromEntries(Object.entries(conflict.pk).map(([k, v]) => [k, String(v)]))).toString()}`}
                    onForce={() => void save('direct', true)}
                    onDismiss={() => setConflict(null)}
                    forcing={saving}
                    tr={tr}
                />
            ) : null}

            {suspected ? (
                <MirrorSuspectedNotice
                    suspected={suspected}
                    urlFor={(pk) => `/app/basicinformation/${pk.c_personid}/kinship/edit-v2?${new URLSearchParams(Object.fromEntries(Object.entries(pk).map(([k, v]) => [k, String(v)]))).toString()}`}
                    onForce={() => void save('direct', true)}
                    onDismiss={() => setSuspected(null)}
                    forcing={saving}
                    tr={tr}
                />
            ) : null}

            <div style={{ ...rowStyle, gap: 8 }}>
                <div style={{ width: 160, flexShrink: 0 }} />
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    {canEdit ? <button type="button" style={primaryBtn} disabled={saving || (mode === 'edit' && !dirty)} onClick={() => void save('direct')}>{saving ? <><BtnSpinner />{tr('saving', '儲存中…')}</> : tr('save_directly', '直接保存')}</button> : null}
                    {(canEdit || canPropose) ? <button type="button" style={infoBtn} disabled={saving || (mode === 'edit' && !dirty)} onClick={() => void save('proposal')}>{saving ? <><BtnSpinner />{tr('saving', '儲存中…')}</> : tr('submit_proposal', '提交建議')}</button> : null}
                    <ActionStatus saving={saving} deleting={deleting} message={message} error={error} t={t} />
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
const labelStyle: React.CSSProperties = { width: 160, flexShrink: 0, fontSize: '1rem', color: '#374151', paddingTop: 6 };
const fieldStyle: React.CSSProperties = { flex: 1, minWidth: 0 };
const inputStyle: React.CSSProperties = { width: '100%', height: 36, padding: '0 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: '1rem', boxSizing: 'border-box' };
const roStyle: React.CSSProperties = { background: '#f3f4f6', cursor: 'not-allowed' };
const pairFieldHintStyle: React.CSSProperties = { display: 'block', marginTop: 2, fontSize: '0.78rem', color: '#6b7280' };
const okStyle: React.CSSProperties = { background: '#ecfdf5', border: '1px solid #a7f3d0', color: '#065f46', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const errStyle: React.CSSProperties = { background: '#fef2f2', border: '1px solid #fecaca', color: '#991b1b', borderRadius: 6, padding: '8px 12px', marginBottom: 8, fontSize: '0.85rem' };
const primaryBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #255f93', background: '#255f93', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const infoBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #0e7490', background: '#0891b2', color: '#fff', fontWeight: 700, cursor: 'pointer' };
const dangerBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #b91c1c', background: '#fff5f5', color: '#b91c1c', fontWeight: 700, cursor: 'pointer' };
const cancelBtn: React.CSSProperties = { borderRadius: 8, padding: '8px 14px', border: '1px solid #cbd5e1', background: '#fff', color: '#475569', fontWeight: 700, textDecoration: 'none', display: 'inline-flex', alignItems: 'center' };
