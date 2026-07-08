import React, { useEffect, useMemo, useRef, useState } from 'react';
import ActionStatus, { BtnSpinner } from './PersonEditorShared/ActionStatus';
import { redirectAfterSubresourceCreate } from './PersonEditorShared/afterCreate';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import PostingAiAutofill, { AiAutofillData, AiFieldEntry } from './PersonEditorShared/PostingAiAutofill';
import { getCsrfToken } from './PersonBrowser/shared/csrf';
import {
    gridCardStyle, gGrid, gInputStyle, gReadonlyStyle, gOkStyle, gErrStyle,
    gSubmitRow, gBtnGroupRight, gPrimaryBtn, gInfoBtn, gDangerBtn, gSuccessBtn, gCancelBtn,
    gAuditWrapStyle, gHiddenSubmitStyle, gridSectionHeadStyle, GridLabel, gridCell, gridInput,
} from './PersonEditorShared/grid';

/**
 * 官名／任官（offices/postings）編輯器（對齊 legacy biogmains/offices/_form.blade.php，非 person-browser）。
 *
 * 欄位：序號 / 官名(c_office_id，office 搜尋，以 c_dy 過濾) / 社會機構(c_inst_code，socialinstcode 搜尋，
 * 值為「code-namecode」，送出前拆成 c_inst_code + c_inst_name_code) / 地名(c_addr 多選，寫 POSTED_TO_ADDR_DATA 副表) /
 * 出處(c_source，text 搜尋) / 頁碼 / 起年(EraTimeField 含農曆) / 訖年(EraTimeField 含農曆) /
 * 任命類型(c_appt_code，appttype) / 任官方式(c_assume_office_code，assumeoffice) /
 * 官職分類(c_office_category_id，officecate) / 備註 / 朝代(c_dy，dynasty) / textperson_pair 候選出處。
 *
 * 複合主鍵 (c_office_id, c_posting_id)。c_posting_id 由伺服器配發（create）。c_office_id 可改，
 * 改時後端 syncPostingAddresses 會把地址遷移到新官職（不流失）。編輯模式提供「另存新檔」(saveas)：
 * 以目前所有欄位＋地址走 create，配發新 c_posting_id（對齊 legacy officeCloneById 語義）。
 *
 * 地址：v2 update 會把 changes.c_addr（id 陣列）抽出走 syncPostingAddresses；create 走 officeStoreById。
 * 故本元件對 create 與 update 一律送出目前的 c_addr id 列表。
 */
type Fields = Record<string, string>;
export interface AddrItem { id: string; label: string }

interface Props {
    personId: number;
    personLabel: string;
    dynastyCode?: number | null;
    dynastyStart?: string;   // 人物朝代起年（addr 搜尋過濾 dy_start，非朝代代碼）
    dynastyEnd?: string;     // 人物朝代末年（dy_end）
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
    aiEnabled?: boolean;
    aiModel?: string;
    aiExtractEndpoint?: string;
    t?: (k: string) => string;
}

const FY = { year: 'c_firstyear', nhCode: 'c_fy_nh_code', nhYear: 'c_fy_nh_year', range: 'c_fy_range', intercalary: 'c_fy_intercalary', month: 'c_fy_month', day: 'c_fy_day', dayGz: 'c_fy_day_gz' };
const LY = { year: 'c_lastyear', nhCode: 'c_ly_nh_code', nhYear: 'c_ly_nh_year', range: 'c_ly_range', intercalary: 'c_ly_intercalary', month: 'c_ly_month', day: 'c_ly_day', dayGz: 'c_ly_day_gz' };
type EraGroup = typeof FY;

// 非主鍵可寫欄位（送 changes）。c_office_id 為 PK，單獨處理（create 必送、update 改動才送）。
const NON_PK = [
    'c_sequence', 'c_inst_code', 'c_inst_name_code', 'c_source', 'c_pages', 'c_notes',
    'c_appt_code', 'c_assume_office_code', 'c_office_category_id', 'c_dy',
    'c_firstyear', 'c_fy_nh_code', 'c_fy_nh_year', 'c_fy_range', 'c_fy_intercalary', 'c_fy_month', 'c_fy_day', 'c_fy_day_gz',
    'c_lastyear', 'c_ly_nh_code', 'c_ly_nh_year', 'c_ly_range', 'c_ly_intercalary', 'c_ly_month', 'c_ly_day', 'c_ly_day_gz',
];

export default function OfficeEditor({
    personId, personLabel, dynastyCode = null, dynastyStart, dynastyEnd, mode, initialFields, initialLabels = {}, initialAddr = [],
    canEdit, canPropose, createEndpoint, mutateEndpoint, deleteEndpoint, indexUrl, aiEnabled = false, aiModel, aiExtractEndpoint, t,
}: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    const isCreate = mode === 'create';
    // 新增預設對齊 legacy：c_office_id 預設 option 0、c_source 預設 0、旗標 0；編輯由 initialFields 覆蓋。
    const base: Fields = {
        c_personid: String(personId),
        c_office_id: '', c_source: '0',
        c_inst_code: '0', c_inst_name_code: '0',
        c_fy_intercalary: '0', c_ly_intercalary: '0',
        // 朝代預設為人物朝代（對齊 legacy：舊 offices/_form 於頁面載入時即從 person dynasty_code
        // 預填 c_dy，AI 僅在依任官時間判定不同朝代時覆寫）。list 模式 CodeAutocomplete 會依此值
        // 自載朝代名稱標籤，故不需另傳 label。編輯模式由 initialFields 的 c_dy 覆寫。
        ...(isCreate && dynastyCode != null ? { c_dy: String(dynastyCode) } : {}),
        ...initialFields,
    };
    const [fields, setFields] = useState<Fields>(base);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const [addr, setAddr] = useState<AddrItem[]>(initialAddr);
    const [addrKey, setAddrKey] = useState(0); // 用於重置地址新增框
    const [savedSnapshot, setSavedSnapshot] = useState(JSON.stringify({ f: base, a: initialAddr }));
    const msgTimer = useRef<number | null>(null);
    const originalPk = useRef<Record<string, number>>({
        c_office_id: Number(initialFields.c_office_id ?? 0),
        c_posting_id: Number(initialFields.c_posting_id ?? 0),
    });
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const flashSaved = (m: string) => { setMessage(m); if (msgTimer.current) window.clearTimeout(msgTimer.current); msgTimer.current = window.setTimeout(() => setMessage(null), 3000); };
    useEffect(() => () => { if (msgTimer.current) window.clearTimeout(msgTimer.current); }, []);
    const [error, setError] = useState<string | null>(null);
    const [sourceHighlight, setSourceHighlight] = useState(false);
    const [comment, setComment] = useState('');

    const dirty = useMemo(() => JSON.stringify({ f: fields, a: addr }) !== savedSnapshot, [fields, addr, savedSnapshot]);
    const set = (k: string, v: string) => setFields((p) => ({ ...p, [k]: v }));
    const setLabel = (k: string, v: string) => setLabels((p) => ({ ...p, [k]: v }));
    const editable = canEdit || canPropose;

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

    // 社會機構：CodeAutocomplete 值為「code-namecode」，拆成兩欄存於 fields。
    const instValue = (fields.c_inst_code && fields.c_inst_code !== '0')
        ? `${fields.c_inst_code}-${fields.c_inst_name_code ?? '0'}`
        : '';
    const onInstChange = (v: string, l: string) => {
        if (!v || v === '0' || v === '-999') {
            setFields((p) => ({ ...p, c_inst_code: '0', c_inst_name_code: '0' }));
            setLabel('c_inst_code', '');
            return;
        }
        // 僅以第一個 '-' 拆分（防社會機構代碼含多個 '-' 時誤拆，雖現行皆為數字代碼）。
        const dash = v.indexOf('-');
        const code = dash >= 0 ? v.slice(0, dash) : v;
        const nameCode = dash >= 0 ? v.slice(dash + 1) : '';
        setFields((p) => ({ ...p, c_inst_code: code || '0', c_inst_name_code: nameCode || '0' }));
        setLabel('c_inst_code', l);
    };

    const addAddr = (v: string, l: string) => {
        if (!v || v === '0' || v === '-999') return;
        setAddr((prev) => (prev.some((a) => a.id === v) ? prev : [...prev, { id: v, label: l || `ADDR ${v}` }]));
        setAddrKey((k) => k + 1); // 重置新增框
    };
    const removeAddr = (id: string) => setAddr((prev) => prev.filter((a) => a.id !== id));

    // AI 自動填套用前的快照（供「清除 AI 填入」還原）。
    const preAi = useRef<{ f: Fields; l: Fields; a: AddrItem[] } | null>(null);
    // AI extract 回傳的 ai_fill_logs log id：儲存（create）時經 meta 回傳後端，回寫 user_submitted +
    // submitted_at，使 /admin/ai-fill-logs 正確顯示「已提交」。清除 AI 填入時一併還原。
    const aiFillLogId = useRef<number | null>(null);
    const applyAiData = (data: AiAutofillData, logId?: number | null) => {
        aiFillLogId.current = logId ?? null;
        if (!preAi.current) preAi.current = { f: { ...fields }, l: { ...labels }, a: [...addr] };
        const entries: Record<string, AiFieldEntry> = { ...(data.matched_fields ?? {}), ...(data.suggested_fields ?? {}) };
        setFields((prev) => {
            const next = { ...prev };
            for (const [name, entry] of Object.entries(entries)) {
                if (name === 'c_addr' || name === 'c_inst_name_code') continue; // 另行處理
                if (name === 'c_inst_code') {
                    const raw = entry.value;
                    const s = Array.isArray(raw) ? String(raw[0] ?? '') : String(raw ?? '');
                    const dash = s.indexOf('-');
                    if (dash >= 0) { next.c_inst_code = s.slice(0, dash) || '0'; next.c_inst_name_code = s.slice(dash + 1) || '0'; } else if (s && s !== '0') {
                        next.c_inst_code = s;
                        const nm = entries.c_inst_name_code?.value;
                        if (nm != null) next.c_inst_name_code = String(nm);
                    }
                    continue;
                }
                const v = entry.value;
                if (v === null || v === undefined) continue;
                next[name] = String(v);
            }
            return next;
        });
        setLabels((prev) => {
            const next = { ...prev };
            for (const [name, entry] of Object.entries(entries)) {
                if (entry.text != null && !Array.isArray(entry.text)) next[name] = String(entry.text);
            }
            return next;
        });
        const addrEntry = entries.c_addr;
        if (addrEntry && Array.isArray(addrEntry.value)) {
            const texts = Array.isArray(addrEntry.text) ? addrEntry.text : [];
            setAddr(addrEntry.value.map((v, i) => ({ id: String(v), label: texts[i] != null ? String(texts[i]) : `ADDR ${v}` })));
        }
        setMessage(tr('ai_fill_done_status', 'AI 已填入，請人工核對後再儲存'));
    };
    const clearAi = () => {
        if (!preAi.current) return;
        setFields(preAi.current.f);
        setLabels(preAi.current.l);
        setAddr(preAi.current.a);
        preAi.current = null;
        aiFillLogId.current = null;
    };

    const onPickTextperson = (p: { source: string; pages: string; sourceLabel: string }) => {
        setFields((prev) => ({ ...prev, c_source: p.source, c_pages: p.pages }));
        setLabel('c_source', p.sourceLabel);
        setSourceHighlight(true);
        window.setTimeout(() => setSourceHighlight(false), 4000);
        setMessage(tr('update_source_success', '已自動回填出處與頁碼'));
    };

    const addrIds = () => addr.map((a) => Number(a.id)).filter((n) => Number.isFinite(n));

    // sm: direct/proposal；asNew: 編輯模式的「另存新檔」（走 create 配發新 c_posting_id）。
    const save = async (sm: 'direct' | 'proposal', asNew = false) => {
        setSaving(true); setError(null); setMessage(null);
        const creating = isCreate || asNew;
        // 官名 c_office_id 必填（拒絕 0/未詳）：僅新建/另存新檔時擋；編輯既有列不卡
        // （避免舊「未詳」資料只想改備註卻被迫補官名，亦對齊 legacy 僅 create 強制）。
        if (creating && (!fields.c_office_id || fields.c_office_id === '0')) {
            setSaving(false); setError(tr('please_select_office', '請選擇官名')); return;
        }
        let changes: Record<string, string | null | number[] | number>;
        let target: Record<string, number>;
        let endpoint: string;
        let operation: string;

        if (creating) {
            endpoint = createEndpoint; operation = 'create'; target = {};
            changes = { c_office_id: Number(fields.c_office_id ?? 0) || 0 };
            for (const k of NON_PK) { const v = fields[k] ?? ''; if (v !== '') changes[k] = v; }
            changes.c_addr = addrIds();
        } else {
            // 清空的可空 FK 代碼欄（c_dy/c_assume_office_code/c_office_category_id 與 era 代碼
            // c_*_nh_code/c_*_range/c_*_day_gz）一律送 null：這些欄皆為 nullable FK，null 為「未詳」
            // 的 FK 安全值（不會觸發外鍵違規而擋下存檔）；legacy 僅靠 MySQL ''→0 隱性轉型，非刻意寫 0，
            // 故 v2 改以語義正確、永不擋存檔的 null（c_office_id/c_source/c_inst_code 仍用 0，因其有明確 0=未詳 列）。
            endpoint = mutateEndpoint; operation = 'update'; target = originalPk.current;
            const snap = JSON.parse(savedSnapshot) as { f: Fields; a: AddrItem[] };
            changes = {};
            // c_office_id 可改（PK），改動才送。
            const curOffice = Number(fields.c_office_id ?? 0) || 0;
            if (curOffice !== Number(snap.f.c_office_id ?? 0)) changes.c_office_id = curOffice;
            for (const k of NON_PK) { const v = fields[k] ?? ''; if ((snap.f[k] ?? '') !== v) changes[k] = v === '' ? null : v; }
            // 地址：與快照比較，有變更才送 c_addr（後端會 diff/遷移/清空）。
            const beforeIds = (snap.a ?? []).map((a) => a.id).sort().join(',');
            const afterIds = addr.map((a) => a.id).sort().join(',');
            if (beforeIds !== afterIds) changes.c_addr = addrIds();
            if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        }

        // meta：proposal 附帶審核備註；create 且曾用 AI 自動填時附帶 ai_fill_log_id，供後端回寫
        // ai_fill_logs 的 user_submitted + submitted_at（不論是否人工修改過 AI 建議皆以 log id 連結）。
        const meta: Record<string, unknown> = {};
        if (sm === 'proposal' && comment) meta.comment = comment;
        if (creating && aiFillLogId.current) meta.ai_fill_log_id = aiFillLogId.current;

        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'postings', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(Object.keys(meta).length ? { meta } : {}) }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            flashSaved(sm === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            // direct 儲存後從回傳列即時刷新唯讀稽核欄（建檔/更新），免重整；函式式合併避免 race，並併入 baseline 免誤判未存變更。
            const auditRow = (sm === 'direct' && json?.result?.row && typeof json.result.row === 'object') ? json.result.row as Record<string, unknown> : null;
            const auditPatch: Fields = {};
            if (auditRow) { for (const k of ['c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date']) { if (auditRow[k] != null) auditPatch[k] = String(auditRow[k]); } }
            if (Object.keys(auditPatch).length > 0) setFields((prev) => ({ ...prev, ...auditPatch }));
            setSavedSnapshot(JSON.stringify({ f: { ...fields, ...auditPatch }, a: addr }));
            if (creating) { redirectAfterSubresourceCreate(indexUrl, json, sm === 'direct'); } else if (sm === 'direct') {
                // c_office_id 可改 → 重同步 originalPk（c_posting_id 不變）。
                originalPk.current = { c_office_id: Number(fields.c_office_id ?? 0) || 0, c_posting_id: originalPk.current.c_posting_id };
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    const doDelete = async () => {
        if (!deleteEndpoint || !window.confirm(tr('posting_delete_confirm', '確定刪除此任官記錄？'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'postings', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setSavedSnapshot(JSON.stringify({ f: fields, a: addr }));
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const textRow = (key: string, label: string, code: string, highlight = false, hint?: string) => (
        gridCell(label, { code, hint }, gridInput({ value: fields[key] ?? '', onChange: (v) => set(key, v), disabled: !editable, highlight, name: key }))
    );
    const listRow = (key: string, label: string, code: string, model: string, idKey: string, labelKeys: string[]) => (
        gridCell(label, { code },
            <CodeAutocomplete mode="list" model={model} idKey={idKey} labelKeys={labelKeys}
                value={fields[key] ?? ''} initialLabel={labels[key] ?? ''} disabled={!editable}
                onChange={(v, l) => { set(key, v); setLabel(key, l); }} />)
    );

    // 回車保存（復刻舊版 Blade：表單內於單行輸入框按 Enter 觸發提交）。以原生 <form> 實現，
    // 因此 textarea 換行、輸入法（IME）選字用的 Enter 皆為瀏覽器原生行為、不會誤觸提交；
    // 表單內按鈕皆為 type="button" 不隱式提交。canEdit → 直接保存；否則可提案者 → 提交建議。
    const onFormSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (saving || deleting || (mode === 'edit' && !dirty)) return;
        if (canEdit) {
            void save('direct');
        } else if (canPropose) {
            void save('proposal');
        }
    };

    return (
        <form style={gridCardStyle} onSubmit={onFormSubmit}>
            <button type="submit" aria-hidden="true" tabIndex={-1} style={gHiddenSubmitStyle} />
            <h3 style={titleStyle}>{mode === 'create' ? tr('office_create', '新增任官') : tr('office_edit', '編輯任官')}</h3>
            {message ? <div style={gOkStyle}>{message}</div> : null}
            {error ? <div style={gErrStyle}>{error}</div> : null}

            {mode === 'create' && aiEnabled && aiExtractEndpoint && editable ? (
                <PostingAiAutofill personId={personId} extractEndpoint={aiExtractEndpoint} aiModel={aiModel} disabled={!editable}
                    t={t} onApply={applyAiData} onClear={clearAi} />
            ) : null}

            <div style={gGrid}>
                {/* 官名 + 地名 同行（#101 使用者建議排版） */}
                {gridCell(tr('office_name_field', '官名'), { code: 'c_office_id', required: true },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/office"
                        value={fields.c_office_id ?? '0'} initialLabel={labels.c_office_id ?? ''} disabled={!editable}
                        extraQuery={dynastyCode != null ? { c_dy: String(dynastyCode) } : undefined}
                        onChange={(v, l) => { set('c_office_id', v || '0'); setLabel('c_office_id', l); }} />)}

                {/* 地名：搜尋框置頂（與官名輸入框對齊），已選地名以 chips 列於下方（#110 對齊修正） */}
                {gridCell(tr('place_name', '地名'), { code: 'c_addr' }, <>
                    {editable ? (
                        <CodeAutocomplete key={addrKey} mode="search" endpoint="/api/select/search/addr"
                            value="" initialLabel="" placeholder={tr('add_place', '搜尋並新增地名…')}
                            extraQuery={{ dy_start: dynastyStart ?? '', dy_end: dynastyEnd ?? '' }}
                            onChange={addAddr} />
                    ) : null}
                    {addr.length ? (
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: editable ? 6 : 0 }}>
                            {addr.map((it) => (
                                <span key={it.id} style={chipStyle}>{it.label} #{it.id}
                                    {editable ? <button type="button" onClick={() => removeAddr(it.id)} style={chipRemoveBtn} aria-label={`${tr('remove', '移除')} ${it.id}`}>×</button> : null}
                                </span>
                            ))}
                        </div>
                    ) : (!editable ? <span style={{ fontSize: '0.8rem', color: 'var(--muted-foreground)' }}>{tr('no_place', '（未設定地名）')}</span> : null)}
                </>)}

                {/* 次序 + posting_id 同行（#101）：次序非重點，去強調並下移 */}
                {textRow('c_sequence', tr('sequence', '次序'), 'c_sequence', false, tr('sequence_same_note', '註：若有同時任命的官職，請手動填上相同的 sequence'))}
                {mode === 'edit' ? gridCell('posting_id', {},
                    <input type="text" value={fields.c_posting_id ?? ''} readOnly disabled style={{ ...gInputStyle, ...gReadonlyStyle }} />) : null}

                {gridCell(tr('start_year', '起年'), { code: 'firstyear', full: true },
                    <EraTimeField values={buildEra(FY)} onChange={(p) => applyEra(FY, p)} dynastyCode={dynastyCode} showRange showLunar disabled={!editable} />)}

                {gridCell(tr('end_year', '訖年'), { code: 'lastyear', full: true },
                    <EraTimeField values={buildEra(LY)} onChange={(p) => applyEra(LY, p)} dynastyCode={dynastyCode} showRange showLunar disabled={!editable} />)}

                {/* 除授類別 / 是否赴任 / 職官類別（寬螢幕自動排成一列）（#101） */}
                {listRow('c_appt_code', tr('appt_type', '任命類型'), 'c_appt_code', 'appttype', 'c_appt_code', ['c_appt_desc_chn', 'c_appt_desc'])}
                {listRow('c_assume_office_code', tr('assume_office', '任官方式'), 'c_assume_office_code', 'assumeoffice', 'c_assume_office_code', ['c_assume_office_desc_chn', 'c_assume_office_desc'])}
                {listRow('c_office_category_id', tr('office_category', '官職分類'), 'c_office_category_id', 'officecate', 'c_office_category_id', ['c_category_desc_chn', 'c_category_desc'])}

                {gridCell(tr('socialinst_field', '社會機構'), { code: 'social_institution' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/socialinstcode"
                        value={instValue} initialLabel={labels.c_inst_code ?? ''} disabled={!editable}
                        onChange={onInstChange} />)}

                {listRow('c_dy', tr('dynasty', '朝代'), 'dy', 'dynasty', 'c_dy', ['c_dynasty_chn', 'c_dynasty'])}

                {gridCell(tr('notes_field', '備註'), { code: 'c_notes', full: true },
                    <textarea name="c_notes" id="c_notes" value={fields.c_notes ?? ''} disabled={!editable} onChange={(e) => set('c_notes', e.target.value)} rows={4} style={{ ...gInputStyle, height: 'auto', ...(!editable ? gReadonlyStyle : {}) }} />)}

                {/* 出處 / 頁碼（與下方「候選出處與頁數」對應）（#101） */}
                {gridCell(tr('source_field', '出處'), { code: 'c_source' },
                    <CodeAutocomplete mode="search" endpoint="/api/select/search/text"
                        value={fields.c_source ?? '0'} initialLabel={labels.c_source ?? ''} disabled={!editable}
                        onChange={(v, l) => { set('c_source', v || '0'); setLabel('c_source', l); }} />)}

                {textRow('c_pages', tr('pages_entries', '頁碼'), 'c_pages', sourceHighlight)}
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
                {mode === 'edit' && canEdit ? <button type="button" style={gSuccessBtn} disabled={saving} onClick={() => void save('direct', true)}>{tr('save_as', '另存新檔')}</button> : null}
                {(canEdit || canPropose) ? <button type="button" style={gInfoBtn} disabled={saving || (mode === 'edit' && !dirty)} onClick={() => void save('proposal')}>{saving ? <><BtnSpinner />{tr('saving', '儲存中…')}</> : tr('submit_proposal', '提交建議')}</button> : null}
                <ActionStatus saving={saving} deleting={deleting} message={message} error={error} t={t} />
                <div style={gBtnGroupRight}>
                    {mode === 'edit' && canEdit && deleteEndpoint ? <button type="button" style={gDangerBtn} disabled={deleting} onClick={() => void doDelete()}>{tr('delete', '刪除')}</button> : null}
                    <a href={indexUrl} style={gCancelBtn}>{tr('cancel', '取消')}</a>
                </div>
            </div>
            {dirty ? <div style={{ marginTop: 8, color: 'var(--warning-subtle-foreground)', fontSize: '0.8rem' }}>{tr('unsaved_changes', '有未儲存的變更')}</div> : null}
        </form>
    );
}

const titleStyle: React.CSSProperties = { fontSize: '1.1rem', fontWeight: 700, marginBottom: 12 };
const chipStyle: React.CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 4, background: 'var(--info-subtle)', border: '1px solid var(--info-border)', borderRadius: 14, padding: '2px 6px 2px 10px', fontSize: '0.8rem', color: 'var(--info-subtle-foreground)' };
const chipRemoveBtn: React.CSSProperties = { border: 'none', background: 'transparent', color: 'var(--info-subtle-foreground)', cursor: 'pointer', fontSize: '1rem', lineHeight: 1, padding: '0 2px' };
