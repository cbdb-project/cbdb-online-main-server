import React, { useEffect, useMemo, useRef, useState } from 'react';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';
import ActionStatus, { BtnSpinner } from './PersonEditorShared/ActionStatus';
import { redirectAfterSubresourceCreate } from './PersonEditorShared/afterCreate';
import MirrorConflictNotice, { MirrorConflict } from './PersonEditorShared/MirrorConflictNotice';
import MirrorSuspectedNotice, { MirrorSuspected } from './PersonEditorShared/MirrorSuspectedNotice';
import MirrorDeleteMultipleNotice, { MirrorDeleteMultiple } from './PersonEditorShared/MirrorDeleteMultipleNotice';
import OppositeEdgeNotice from './PersonEditorShared/OppositeEdgeNotice';
import PersonJumpLink from './PersonEditorShared/PersonJumpLink';
import { useOppositeEdgeDetection } from './PersonEditorShared/useOppositeEdgeDetection';
import {
    gridCardStyle, gGrid, gInputStyle, gReadonlyStyle, gHintStyle, gOkStyle, gErrStyle,
    gSubmitRow, gBtnGroupRight, gPrimaryBtn, gInfoBtn, gDangerBtn, gCancelBtn,
    gAuditWrapStyle, gridSectionHeadStyle, GridLabel, gridCell, gridInput,
} from './PersonEditorShared/grid';

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
        c_kin_id: '', c_kin_code: '', c_source: '0',
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
    const [deleteMulti, setDeleteMulti] = useState<MirrorDeleteMultiple | null>(null); // #81 §6 刪除命中對面多筆反向列
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

    // #88：互逆配對碼（reversePair）是獨立 state、不在 fields 內。只改它時須仍視為 dirty（啟用存檔），
    // 否則存檔鈕停用、改動存不進去（使用者回報「設反向為弟、存檔卻沒成功」）。後端有 pair-only 同步路徑承接。
    const dirty = useMemo(() => JSON.stringify(fields) !== savedSnapshot || (mode === 'edit' && pairTouched), [fields, savedSnapshot, mode, pairTouched]);

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
        // 親屬關係 c_kin_code / 親屬姓名 c_kin_id 為主碼，必填（拒絕 0/未詳）：僅新增時擋；編輯既有列不卡。
        if (mode === 'create') {
            if (!fields.c_kin_code || fields.c_kin_code === '0') {
                setError(tr('please_select_kin_relation', '請選擇親屬關係')); return;
            }
            if (!fields.c_kin_id || fields.c_kin_id === '0') {
                setError(tr('please_select_kin_person', '請選擇親屬姓名')); return;
            }
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
        // #88：pair-only 修復（僅互逆配對碼變更、無任何正向欄）屬「直接修復鏡像」維護動作，後端僅 direct 支援；
        // 以 proposal 送出會被父類「changes 不可為空」擋成 422，故前端先攔截並引導改用「直接保存」（對齊 AssocEditor）。
        if (mode === 'edit' && sm === 'proposal') {
            const realChanges = Object.keys(changes).filter((k) => k !== 'c_kinship_pair');
            if (realChanges.length === 0) {
                setSaving(false);
                setError(tr('pair_only_proposal_hint', '互逆配對碼修復請使用「直接保存」；提交建議請至少修改一個關係欄位。'));
                return;
            }
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
            setPairTouched(false); // #88：存檔成功後重置，避免反向配對碼改動持續被視為 dirty。
            if (mode === 'create') { redirectAfterSubresourceCreate(indexUrl, json, sm === 'direct'); } else if (sm === 'direct') {
                // 改鍵後以實際送出的 PK 變更覆寫 originalPk（不可用 fields 重建，避免 Number('')=0 失準）。
                const nextPk = { ...originalPk.current };
                for (const k of EDITABLE_PK) { if (Object.prototype.hasOwnProperty.call(changes, k)) nextPk[k] = Number(changes[k]); }
                originalPk.current = nextPk;
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    // force=true 為「對面多筆反向列」確認後重送（帶 meta.force，後端一併刪除全部候選）。
    const doDelete = async (force = false) => {
        if (!deleteEndpoint) return;
        if (!force && !window.confirm(tr('kinship_delete_confirm', '確定刪除此親屬關係？'))) return;
        setDeleting(true); setError(null);
        try {
            const body: Record<string, unknown> = { resource: 'kinship', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } };
            if (force) body.meta = { force: true };
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) {
                // #81 §6：對面命中多筆反向列 → 列出候選供確認，再帶 meta.force 重送。
                const dm = json?.errors?.mirror_delete_multiple;
                if (res.status === 409 && dm) { setDeleting(false); setDeleteMulti(dm as MirrorDeleteMultiple); return; }
                throw new Error(json?.message || `HTTP ${res.status}`);
            }
            setDeleteMulti(null);
            setSavedSnapshot(JSON.stringify(fields));
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const searchRow = (key: string, label: string, code: string, endpoint: string, highlight = false, sentinel = '0', required = false) => (
        gridCell(label, { code, required },
            <CodeAutocomplete mode="search" endpoint={endpoint} value={fields[key] ?? sentinel} initialLabel={labels[key] ?? ''}
                disabled={!editable} aria-invalid={highlight}
                onChange={(v, l) => { set(key, v || sentinel); setLabel(key, l); }} />)
    );

    return (
        <div style={gridCardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('kinship_create', '新增親屬關係') : tr('kinship_edit', '編輯親屬關係')}</h3>
            {message ? <div style={gOkStyle}>{message}</div> : null}
            {error ? <div style={gErrStyle}>{error}</div> : null}
            <OppositeEdgeNotice result={oppositeEdge} reverseCodeLabel={reverseCodeLabel} tr={tr} />

            <div style={gGrid}>
                {searchRow('c_kin_code', tr('kinship_relation', '親屬關係'), 'c_kin_code', '/api/select/search/kincode', false, '0', true)}
                {/* 親屬姓名：選定後於下方提供「前往此人物頁」連結（新分頁，不影響本頁編輯）。 */}
                {gridCell(tr('relative_name', '親屬姓名'), { code: 'c_kin_id', required: true }, <>
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/biog"
                        value={fields.c_kin_id ?? '0'} initialLabel={labels.c_kin_id ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_kin_id', v || '0'); setLabel('c_kin_id', l); }} />
                    <PersonJumpLink personId={fields.c_kin_id} name={labels.c_kin_id} tr={tr} />
                </>)}

                {/* 互逆配對碼：依正向碼取候選，預設第一個（同 legacy），反向關係有歧義（父→子/女、第幾子…）故可手選。 */}
                {gridCell(tr('reverse_pair_label', '互逆配對碼'), { code: 'c_kinship_pair', full: true }, <>
                    {pairCandidates.length ? (
                        <select value={reversePair} disabled={!editable}
                            onChange={(e) => { setReversePair(e.target.value); setPairTouched(true); }}
                            style={{ ...gInputStyle }}>
                            {mode === 'edit' ? <option value="">{tr('keep_current_pair', '（保持目前反向碼）')}</option> : null}
                            {pairCandidates.map((o) => <option key={o.code} value={o.code}>{o.label}</option>)}
                        </select>
                    ) : (
                        <input type="text" value={tr('no_paired_kinship', '（此關係無對應反向配對碼，系統自動處理）')} readOnly disabled style={{ ...gInputStyle, ...gReadonlyStyle }} />
                    )}
                    <span style={gHintStyle}>{tr('reverse_pair_hint', '系統依關係碼自動雙向同步；預設取建議的反向碼，可手動更正（例：父→子或女、第幾子）。')}</span>
                </>)}

                {gridCell(tr('source_field', '出處'), { code: 'c_source' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/text" value={fields.c_source ?? '0'} initialLabel={labels.c_source ?? ''} disabled={!editable} onChange={(v, l) => { set('c_source', v || '0'); setLabel('c_source', l); }} />)}

                {gridCell(tr('pages_entries', '頁碼'), { code: 'c_pages' },
                    gridInput({ value: fields.c_pages ?? '', onChange: (v) => set('c_pages', v), disabled: !editable, highlight: sourceHighlight, name: 'c_pages' }))}

                {gridCell(tr('notes_field', '備註'), { code: 'c_notes', full: true },
                    <textarea name="c_notes" id="c_notes" value={fields.c_notes ?? ''} disabled={!editable} onChange={(e) => set('c_notes', e.target.value)} rows={4} style={{ ...gInputStyle, height: 'auto', ...(!editable ? gReadonlyStyle : {}) }} />)}

                {gridCell('c_autogen_notes', { full: true },
                    <textarea name="c_autogen_notes" id="c_autogen_notes" value={fields.c_autogen_notes ?? ''} disabled={!editable} onChange={(e) => set('c_autogen_notes', e.target.value)} rows={4} style={{ ...gInputStyle, height: 'auto', ...(!editable ? gReadonlyStyle : {}) }} />)}
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
                    <textarea name="modification_note" id="modification_note" value={comment} onChange={(e) => setComment(e.target.value)} rows={3} style={{ ...gInputStyle, height: 'auto' }} placeholder={tr('modification_note_placeholder', '提案時請說明修改原因')} />
                </div>
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

            {deleteMulti ? (
                <MirrorDeleteMultipleNotice
                    info={deleteMulti}
                    urlFor={(row) => `/app/basicinformation/${row.c_personid}/kinship/edit-v2?${new URLSearchParams({ c_personid: String(row.c_personid), c_kin_id: String(row.c_kin_id), c_kin_code: String(row.c_kin_code) }).toString()}`}
                    onConfirm={() => void doDelete(true)}
                    onDismiss={() => setDeleteMulti(null)}
                    deleting={deleting}
                    tr={tr}
                />
            ) : null}

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
