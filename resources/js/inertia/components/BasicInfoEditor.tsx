import React, { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import { getCsrfToken } from './PersonBrowser/shared/csrf';
import ActionStatus, { BtnSpinner } from './PersonEditorShared/ActionStatus';
import {
    gridCardStyle, gGrid, gFull, gLabelStyle, gCodeStyle, gInputStyle, gHintStyle,
    gReadonlyStyle, gOkStyle, gErrStyle, gWarnStyle, gAuditWrapStyle,
    gSubmitRow, gBtnGroupRight, gPrimaryBtn, gInfoBtn, gDangerBtn, gSuccessBtn,
    gridSectionHeadStyle, GridLabel, GridSection,
} from './PersonEditorShared/grid';

/**
 * 基本資料編輯器（對齊 legacy /basicinformation/{id}/edit，非 person-browser）。
 * 忠實復刻 legacy edit.blade：全 BIOG_MAIN 可編輯欄位 + 年號轉換（EraTimeField）+ 生成拼音 +
 * indexYear 聯動 + 三態授權提交。提交走既有 /api/v2/mutate（resource=basicinformation）。
 *
 * 註：本元件不依賴 app/person-browser 的編輯/分頁 UI；僅複用通用表單控件 EraTimeField / CodeAutocomplete。
 */

type Fields = Record<string, string>;

interface Props {
    personId: number;
    personLabel?: string;        // 已不再於本元件顯示（中樞 banner 已示人物）；保留以相容呼叫端
    initialFields: Fields;       // 所有 c_* 欄位的初始字串值
    initialLabels?: Fields;      // 代碼欄位的顯示標籤（年號等），key 同 c_* 名
    canEdit: boolean;            // 可直接寫入
    canPropose: boolean;         // 可提案
    mutateEndpoint: string;      // /api/v2/mutate
    deleteEndpoint?: string;     // /api/v2/delete
    pinyinEndpoint?: string;     // /api/select/search/pinyin
    indexUrl?: string;           // 刪除後導回的人物列表
    duplicateCollateralUrl?: string;
    saveasUrl?: string;
    t?: (k: string) => string;   // person/biogmains 翻譯
    onSaved?: () => void;        // 儲存成功後回呼（供上層刷新分頁快取，避免切分頁回來看到舊值）
    // 供上層（PersonEditor）在切分頁／離頁時偵測未存變更並跳窗提示（所見即所保存）。
    onEditorStateChange?: (state: { editing: boolean; dirty: boolean }) => void;
    onRegisterSaveHandler?: (handler: (() => Promise<boolean>) | null) => void;
}

const READONLY_DERIVED = ['c_name_chn', 'c_name', 'c_name_proper', 'c_name_rm'];
// direct 儲存後從 json.result.row 即時刷新的唯讀/後端重算欄：派生姓名（updateById 重算）＋ 建檔/更新稽核欄。
// （指數年/方法/來源/地址為週期性算法另計、且其顯示值為 label 非 row 原始碼，故不在此刷新範圍。）
const REFRESH_AFTER_SAVE = [...READONLY_DERIVED, 'c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date'];

// 姓名來源欄 → 派生全名（c_name*）的客戶端即時計算，與後端 auto_pinyin/合成及 PersonBrowser/BasicInfoView 一致：
// 編輯中文名/拼音/外文欄或按「生成姓名拼音」後，下方「自動生成」唯讀框即時更新，使資料流（中文→拼音→派生）連貫；
// 存檔仍以後端重算為準（存後由 REFRESH_AFTER_SAVE 從 result.row 覆寫）。
const NAME_SRC = ['c_surname_chn', 'c_mingzi_chn', 'c_surname', 'c_mingzi', 'c_surname_proper', 'c_mingzi_proper', 'c_surname_rm', 'c_mingzi_rm'];
const joinSp = (a?: string, b?: string): string => [a ?? '', b ?? ''].map((s) => s.trim()).filter((s) => s !== '').join(' ');
const deriveNames = (f: Fields): Partial<Fields> => ({
    c_name_chn: `${f.c_surname_chn ?? ''}${f.c_mingzi_chn ?? ''}`,
    c_name: joinSp(f.c_surname, f.c_mingzi),
    c_name_proper: joinSp(f.c_mingzi_proper, f.c_surname_proper),
    c_name_rm: joinSp(f.c_mingzi_rm, f.c_surname_rm),
});

// 一組日期欄位（生年/卒年/活動年）↔ EraTimeField 的子欄位映射。
interface DateGroup {
    year: string; nhCode: string; nhYear: string;
    range?: string; intercalary?: string; month?: string; day?: string; dayGz?: string; notes?: string;
}

export default function BasicInfoEditor({
    personId, initialFields, initialLabels = {},
    canEdit, canPropose, mutateEndpoint, deleteEndpoint, pinyinEndpoint = '/api/select/search/pinyin',
    indexUrl = '/basicinformation', duplicateCollateralUrl, saveasUrl, t, onSaved,
    onEditorStateChange, onRegisterSaveHandler,
}: Props) {
    // useTranslation 在缺 key 時回傳 key 本身；故須在 t(k)===k（未翻譯）時退回中文 fallback，
    // 否則按鈕/標籤會顯示原始 key（如 save_directly）而非中文。
    const tr = (k: string, fallback: string) => {
        const v = t ? t(k) : k;
        return v && v !== k ? v : fallback;
    };
    const [fields, setFields] = useState<Fields>(initialFields);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    // 已儲存基準快照：用 state（非 ref），更新後 dirty useMemo 才會重算——否則儲存後 fields 不變時 dirty 殘留 true，
    // 重整頁面仍誤觸「Changes you made may not be saved」離頁守衛。
    const [savedSnapshot, setSavedSnapshot] = useState<string>(JSON.stringify(initialFields));
    const msgTimer = useRef<number | null>(null);
    // 成功訊息 3 秒自動消失（頂部 flash 與近按鈕 ✓ 同步消失，避免分不清是這次還是上次的儲存）。
    const flashSaved = (msg: string) => {
        setMessage(msg);
        if (msgTimer.current) window.clearTimeout(msgTimer.current);
        msgTimer.current = window.setTimeout(() => setMessage(null), 3000);
    };
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [pinyinDone, setPinyinDone] = useState(false);
    // 非阻塞提示：生成拼音時偵測到關係稱謂，但括號內之人不在此人親屬名單中（後端已改用一般拼音轉換）。
    const [pinyinKinshipHint, setPinyinKinshipHint] = useState(false);
    // 非阻塞提示：儲存時姓名含異體字，後端已落地替換為參考字（char_variant_map 嚴格模式）。
    const [variantReplacedNotice, setVariantReplacedNotice] = useState<string | null>(null);
    const [comment, setComment] = useState('');
    const [deleting, setDeleting] = useState(false);

    const dirty = useMemo(() => JSON.stringify(fields) !== savedSnapshot, [fields, savedSnapshot]);
    const dynastyCode = useMemo(() => {
        const v = parseInt(fields.c_dy ?? '', 10);
        return Number.isFinite(v) && v > 0 ? v : null;
    }, [fields.c_dy]);

    const set = (key: string, value: string) => setFields((p) => {
        const next = { ...p, [key]: value };
        // 編輯姓名來源欄時，同步重算派生全名，讓「自動生成」唯讀框即時反映（與資料流敘事一致）。
        return NAME_SRC.includes(key) ? { ...next, ...deriveNames(next) } : next;
    });
    const setLabel = (key: string, value: string) => setLabels((p) => ({ ...p, [key]: value }));

    // 離頁守衛：有未存變更時警告（dirty guard）。TODO（基本資料收尾）：補 legacy 的
    // 「名中/拼音任一空」專屬提示（#check_info）。
    useEffect(() => {
        const handler = (e: BeforeUnloadEvent) => {
            if (!dirty) return;
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', handler);
        return () => window.removeEventListener('beforeunload', handler);
    }, [dirty]);

    // 向上層回報編輯狀態：本編輯器落地即進入編輯（editing 恆為 true），dirty 反映是否有未存變更。
    // PersonEditor 據此在切分頁／離頁時跳窗提示「未儲存」。
    useEffect(() => {
        onEditorStateChange?.({ editing: true, dirty });
    }, [dirty, onEditorStateChange]);

    // 卸載時（切離 basic_info 分頁）重置上層狀態並解除 save handler，避免殘留 dirty 誤觸其他分頁的提示。
    useEffect(() => () => {
        onRegisterSaveHandler?.(null);
        onEditorStateChange?.({ editing: false, dirty: false });
    }, [onEditorStateChange, onRegisterSaveHandler]);

    // 卸載時清掉成功訊息自動消失計時器。
    useEffect(() => () => { if (msgTimer.current) window.clearTimeout(msgTimer.current); }, []);

    // indexYear 聯動：享年 = 卒-生+1；index_year = 享年>60 ? 生+60 : 卒（對齊 legacy indexYear()）。
    const recomputeIndexYear = (next: Fields): Fields => {
        const by = parseInt(next.c_birthyear ?? '', 10);
        const dy = parseInt(next.c_deathyear ?? '', 10);
        const out = { ...next };
        if (Number.isFinite(by) && Number.isFinite(dy) && dy >= by) {
            const age = dy - by + 1;
            out.c_death_age = String(age);
            out.c_index_year = String(age > 60 ? by + 60 : dy);
        }
        return out;
    };

    const buildEra = (g: DateGroup): EraTimeFieldValues => ({
        year: fields[g.year] ?? '',
        nhCode: fields[g.nhCode] ?? '',
        nhCodeLabel: labels[g.nhCode] ?? '',
        nhYear: fields[g.nhYear] ?? '',
        range: g.range ? fields[g.range] ?? '' : '',
        rangeLabel: g.range ? labels[g.range] ?? '' : '',
        intercalary: g.intercalary ? fields[g.intercalary] ?? '0' : '0',
        month: g.month ? fields[g.month] ?? '' : '',
        day: g.day ? fields[g.day] ?? '' : '',
        dayGz: g.dayGz ? fields[g.dayGz] ?? '' : '',
        dayGzLabel: g.dayGz ? labels[g.dayGz] ?? '' : '',
        notes: g.notes ? fields[g.notes] ?? '' : '',
    });

    const applyEra = (g: DateGroup, patch: Partial<EraTimeFieldValues>) => {
        setFields((prev) => {
            let next = { ...prev };
            if (patch.year !== undefined) next[g.year] = patch.year;
            if (patch.nhCode !== undefined) next[g.nhCode] = patch.nhCode;
            if (patch.nhYear !== undefined) next[g.nhYear] = patch.nhYear;
            if (g.range && patch.range !== undefined) next[g.range] = patch.range;
            if (g.intercalary && patch.intercalary !== undefined) next[g.intercalary] = patch.intercalary;
            if (g.month && patch.month !== undefined) next[g.month] = patch.month;
            if (g.day && patch.day !== undefined) next[g.day] = patch.day;
            if (g.dayGz && patch.dayGz !== undefined) next[g.dayGz] = patch.dayGz;
            if (g.notes && patch.notes !== undefined) next[g.notes] = patch.notes;
            // 生/卒年變動時重算 indexYear。
            if ((g.year === 'c_birthyear' || g.year === 'c_deathyear') && patch.year !== undefined) {
                next = recomputeIndexYear(next);
            }
            return next;
        });
        if (patch.nhCodeLabel !== undefined) setLabel(g.nhCode, patch.nhCodeLabel);
        if (g.range && patch.rangeLabel !== undefined) setLabel(g.range, patch.rangeLabel);
        if (g.dayGz && patch.dayGzLabel !== undefined) setLabel(g.dayGz, patch.dayGzLabel);
    };

    // 生成拼音：用中文姓名查 /api/select/search/pinyin，回填拼音姓/名。
    // 注意：此端點回傳「純文字字串」（如 "An Shi"、"(Wife of Li Bai)"），不是 {data:[...]} 自動完成結構，
    // 故以 r.text() 讀取（舊版 Blade 編輯器亦直接取用字串）。誤用 r.json() 會在解析純文字時拋錯 → 一律「生成拼音失敗」。
    const generatePinyin = async () => {
        setError(null); setMessage(null); setPinyinKinshipHint(false); // 清掉前次錯誤/訊息/提示，避免殘留。
        try {
            // 不再吞掉 HTTP 失敗：!r.ok 即拋出，落到下方 catch 顯示 generate_pinyin_failed（否則 500/422 會被誤當成功）。
            // 帶 person_id 以啟用後端「親屬關係守衛」；回傳 X-Pinyin-Kinship-Unmatched 標頭時代表偵測到關係稱謂但查無此親屬。
            const fetchPinyin = async (q: string, split: boolean): Promise<{ text: string; kinshipUnmatched: boolean }> => {
                const pid = personId != null ? `&person_id=${personId}` : '';
                const r = await fetch(`${pinyinEndpoint}?q=${encodeURIComponent(q)}${split ? '' : '&split=0'}${pid}`, {
                    headers: { Accept: 'text/plain', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                });
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return { text: (await r.text()).trim(), kinshipUnmatched: r.headers.get('X-Pinyin-Kinship-Unmatched') === '1' };
            };
            // 姓氏走預設 split=1（已知姓氏後插空格）；名走 split=0（整體轉換、不再拆姓）。
            const sp = await fetchPinyin(fields.c_surname_chn ?? '', true);
            const mp = await fetchPinyin(fields.c_mingzi_chn ?? '', false);
            // 回填拼音姓/名後，同步重算派生「拼音全名」(c_name)，使下方自動生成框即時更新（資料流收尾）。
            setFields((p) => { const next = { ...p, c_surname: sp.text || p.c_surname, c_mingzi: mp.text || p.c_mingzi }; return { ...next, ...deriveNames(next) }; });
            setPinyinDone(true);
            window.setTimeout(() => setPinyinDone(false), 4000);
            // 任一欄位偵測到「關係稱謂但查無此親屬」→ 顯示非阻塞提示（不自動消失，供使用者留意並修正資料）。
            if (sp.kinshipUnmatched || mp.kinshipUnmatched) {
                setPinyinKinshipHint(true);
            }
        } catch {
            setError(tr('generate_pinyin_failed', '生成拼音失敗'));
        }
    };

    // 回傳 boolean：成功 true、驗證失敗／無變更／出錯 false。供切分頁「儲存並繼續」判斷是否可放行導航。
    const save = async (mode: 'direct' | 'proposal'): Promise<boolean> => {
        setSaving(true); setError(null); setMessage(null); setVariantReplacedNotice(null);
        const initial: Fields = JSON.parse(savedSnapshot);
        // 名（中）／拼音名「不可清空」（direct 與 proposal 一致，後端同規則）：
        // 原值非空、現值清空即阻擋；原本即為空的人物可維持空、照常編輯其他欄位。
        if ((initial.c_mingzi_chn ?? '').trim() && !(fields.c_mingzi_chn ?? '').trim()) {
            setSaving(false); setError(tr('mingzi_chn_no_clear', '「名（中）」不可清空')); return false;
        }
        if ((initial.c_mingzi ?? '').trim() && !(fields.c_mingzi ?? '').trim()) {
            setSaving(false); setError(tr('mingzi_no_clear', '「拼音名」不可清空')); return false;
        }
        // 朝代 c_dy 必填（僅此基本資料編輯頁；其他編輯器的朝代維持非必填）。空（''/'0'）即阻擋並提示。
        if (!fields.c_dy || fields.c_dy === '0') {
            setSaving(false); setError(tr('dynasty_required', '朝代為必填欄位，請先選擇朝代')); return false;
        }
        // 只送與初始不同、且非唯讀派生的欄位。
        const changes: Record<string, string | null> = {};
        for (const [k, v] of Object.entries(fields)) {
            if (READONLY_DERIVED.includes(k)) continue;
            if ((initial[k] ?? '') !== (v ?? '')) changes[k] = v === '' ? null : v;
        }
        if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return false; }
        try {
            const res = await fetch(mutateEndpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    resource: 'basicinformation', person_id: personId, mode, operation: 'update',
                    target: { pk: { c_personid: personId } }, changes,
                    ...(comment ? { meta: { comment } } : {}),
                }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            flashSaved(mode === 'proposal' ? tr('proposal_submitted', '已提交建議') : tr('save_success', '已儲存'));
            const notices = Array.isArray(json?.notices) ? json.notices as string[] : [];
            setVariantReplacedNotice(notices.length > 0 ? notices.join('；') : null);
            // direct 儲存後，從回傳列即時刷新「唯讀/後端重算」欄位，不必重整頁面：
            // 派生姓名（c_name*，後端 updateById 由姓/名重算）＋ 稽核欄（建檔/更新）。
            // 以函式式合併（保留請求期間使用者新輸入，修正 race：不可用已捕捉的舊 fields 覆寫）；
            // 並把刷新值併入 baseline，避免被誤判為「未存變更」。
            const row = (mode === 'direct' && json?.result?.row && typeof json.result.row === 'object') ? json.result.row as Record<string, unknown> : null;
            const patch: Fields = {};
            if (row) {
                for (const k of REFRESH_AFTER_SAVE) {
                    if (row[k] != null) patch[k] = String(row[k]);
                }
            }
            if (Object.keys(patch).length > 0) {
                setFields((prev) => ({ ...prev, ...patch }));
            }
            const baseline: Fields = { ...fields, ...patch };
            setSavedSnapshot(JSON.stringify(baseline));
            // 通知上層儲存成功：上層據此刷新分頁快取，使「切分頁再切回」時載入到已存的新值，
            // 而非最初載入時的舊快照（本元件仍保持掛載、不重載，成功提示不受影響）。
            onSaved?.();

            return true;
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));

            return false;
        } finally { setSaving(false); }
    };

    // 註冊「儲存並繼續」處理器供上層在切分頁時呼叫：canEdit → direct；否則可提案者 → proposal。
    // save 每次 render 重建，故此 effect 每次都以最新 closure（含最新 fields）重新註冊，避免舊值。
    useEffect(() => {
        onRegisterSaveHandler?.(
            (canEdit || canPropose) ? (() => save(canEdit ? 'direct' : 'proposal')) : null,
        );
    }, [onRegisterSaveHandler, save, canEdit, canPropose]);

    // 刪除（軟刪除）走 /api/v2/delete（對齊 legacy delete-form）。僅 canEdit 顯示。
    const doDelete = async () => {
        if (!deleteEndpoint) return;
        if (!window.confirm(tr('delete_confirm', '確定要刪除此人物嗎？此操作無法復原。'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json', 'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    resource: 'basicinformation', person_id: personId, mode: 'direct',
                    operation: 'delete', target: { pk: { c_personid: personId } },
                }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setSavedSnapshot(JSON.stringify(fields)); // 避免離頁守衛攔截
            router.visit(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    // 對齊 legacy #check_info：名（中）或拼音名任一空 → 提示。
    const nameWarning = (fields.c_mingzi_chn ?? '') === '' || (fields.c_mingzi ?? '') === '';

    // 統一網格欄位（上標籤 + 技術碼淡化），對齊使用者認可的設計草圖。
    // 所有 g* 與 block 皆為「回傳 JSX 的函式」而非巢狀元件，避免每次 render 重新掛載 → input 失焦。
    const fLabel = (label: string, code?: string, required = false) => (
        <GridLabel label={label} code={code} required={required} />
    );
    // 可編輯文字欄（readonly 時灰底）。
    const gText = (key: string, label: string, code?: string, opts: { readonly?: boolean; hint?: string; full?: boolean; required?: boolean } = {}) => (
        <div style={opts.full ? gFull : undefined}>
            {fLabel(label, code, opts.required)}
            <input type="text" name={key} id={key} value={fields[key] ?? ''} readOnly={opts.readonly} disabled={opts.readonly}
                onChange={(e) => set(key, e.target.value)}
                style={{ ...gInputStyle, ...(opts.readonly ? gReadonlyStyle : {}) }} />
            {opts.hint ? <span style={gHintStyle}>{opts.hint}</span> : null}
        </div>
    );
    // 唯讀展示欄（index 自動欄 / 建檔資訊 / 派生全名）：顯示 label（display_value）若有，否則原始值。
    const gRO = (key: string, label: string, code?: string, opts: { hint?: string; full?: boolean } = {}) => (
        <div style={opts.full ? gFull : undefined}>
            {fLabel(label, code)}
            <input className="cbdb-historical-text" type="text" value={labels[key] || fields[key] || ''} readOnly disabled style={{ ...gInputStyle, ...gReadonlyStyle }} />
            {opts.hint ? <span style={gHintStyle}>{opts.hint}</span> : null}
        </div>
    );
    // 代碼自動完成欄。
    const gCode = (key: string, label: string, code: string, model: string, idKey: string, labelKeys: string[], required = false) => (
        <div>
            {fLabel(label, code, required)}
            <CodeAutocomplete mode="list" model={model} idKey={idKey} labelKeys={labelKeys}
                value={fields[key] ?? ''} initialLabel={labels[key] ?? ''}
                onChange={(v, lbl) => { set(key, v); setLabel(key, lbl); }} disabled={!canEdit && !canPropose} />
        </div>
    );

    // 建檔/更新顯示：合併為「{使用者} 於 {日期}」（en: "{user} at {date}"），較「by / date」更具語義。
    const auditValue = (by?: string, date?: string) => {
        if (by && date) return `${by} ${tr('audit_at', '於')} ${date}`;
        return by || date || '';
    };

    // 區塊分組：留白 + 藍系標題列（不對齊 legacy，使用者認可之更合適設計）。內部欄位走一致響應式網格。
    const block = (title: string, children: React.ReactNode) => (
        <GridSection title={title}>{children}</GridSection>
    );

    const birth: DateGroup = { year: 'c_birthyear', nhCode: 'c_by_nh_code', nhYear: 'c_by_nh_year', range: 'c_by_range', intercalary: 'c_by_intercalary', month: 'c_by_month', day: 'c_by_day', dayGz: 'c_by_day_gz' };
    const death: DateGroup = { year: 'c_deathyear', nhCode: 'c_dy_nh_code', nhYear: 'c_dy_nh_year', range: 'c_dy_range', intercalary: 'c_dy_intercalary', month: 'c_dy_month', day: 'c_dy_day', dayGz: 'c_dy_day_gz' };
    const flEarly: DateGroup = { year: 'c_fl_earliest_year', nhCode: 'c_fl_ey_nh_code', nhYear: 'c_fl_ey_nh_year', notes: 'c_fl_ey_notes' };
    const flLate: DateGroup = { year: 'c_fl_latest_year', nhCode: 'c_fl_ly_nh_code', nhYear: 'c_fl_ly_nh_year', notes: 'c_fl_ly_notes' };

    // 回車保存（復刻舊版 Blade：表單內於單行輸入框按 Enter 觸發提交）。以原生 <form> 實現，
    // 因此 textarea 換行、輸入法（IME）選字用的 Enter 皆為瀏覽器原生行為、不會誤觸提交；
    // 表單內按鈕皆為 type="button" 不隱式提交。canEdit → 直接保存；否則可提案者 → 提交建議。
    const onFormSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (saving || deleting || !dirty) return;
        if (canEdit) {
            void save('direct');
        } else if (canPropose) {
            void save('proposal');
        }
    };

    return (
        <form style={gridCardStyle} onSubmit={onFormSubmit}>
            {/* 隱藏提交鈕：讓表單具備「預設提交按鈕」，使單行輸入框按 Enter 觸發原生隱式提交（→ onFormSubmit）。
                可見動作按鈕皆為 type="button"（點擊行為不變）；此鈕僅供 Enter，並以 tabIndex=-1／aria-hidden 排除於鍵盤焦點與無障礙。 */}
            <button type="submit" aria-hidden="true" tabIndex={-1} style={hiddenSubmitStyle} />
            {/* 不再重複「人物基本資料 — {人物}」標題：詳情中樞 banner 已顯示人物、分頁標籤已示「基本資料」。 */}
            {message ? <div style={gOkStyle}>{message}</div> : null}
            {error ? <div style={gErrStyle}>{error}</div> : null}
            {pinyinDone ? <div style={gOkStyle}>{tr('basicinfo_pinyin_alert', '「生成拼音」已完成')}</div> : null}
            {pinyinKinshipHint ? <div style={gWarnStyle}>{tr('pinyin_kinship_unmatched_hint', '偵測到親屬關係詞，但此人親屬名單中查無此人，已改用一般拼音轉換。')}</div> : null}
            {variantReplacedNotice ? <div style={gWarnStyle}>{variantReplacedNotice}</div> : null}
            {nameWarning ? <div style={gWarnStyle}>{tr('name_required_warning', '請確認「名（中）」與「拼音名」是否填寫。')}</div> : null}

            {/* 區塊一：姓名（資料流分組）。自上而下一條線、按鈕作橋：中文姓名 →〔生成姓名拼音〕→ 拼音 → 外文/羅馬字 → 自動生成框。 */}
            {block(tr('block_names', '姓名'), <>
                {/* 中文姓名（生成姓名拼音的來源）。 */}
                <div style={gGrid}>
                    {gText('c_surname_chn', tr('surname_chn', '姓（中）'), 'c_surname_chn')}
                    {gText('c_mingzi_chn', tr('mingzi_chn', '名（中）'), 'c_mingzi_chn', { required: true })}
                </div>
                {/* 按鈕作橋：取上方中文名、產出下方拼音；上下皆留白，使「作用範圍」一目了然。 */}
                {canEdit ? <div style={pinyinRowStyle}><button type="button" style={gInfoBtn} onClick={() => void generatePinyin()}>{tr('generate_pinyin_btn', '生成姓名拼音')}</button></div> : null}
                {/* 拼音（可手動修正）。 */}
                <div style={gGrid}>
                    {gText('c_surname', 'Xing', 'c_surname')}
                    {gText('c_mingzi', 'Ming', 'c_mingzi', { required: true })}
                </div>
                {/* 外文／羅馬字（獨立，不受生成姓名拼音影響）。 */}
                <div style={gGrid}>
                    {gText('c_surname_proper', tr('foreign_surname', '外文姓'), 'c_surname_proper')}
                    {gText('c_mingzi_proper', tr('foreign_mingzi', '外文名'), 'c_mingzi_proper')}
                    {gText('c_surname_rm', tr('foreign_rm_surname', '羅馬字姓'), 'c_surname_rm')}
                    {gText('c_mingzi_rm', tr('foreign_rm_mingzi', '羅馬字名'), 'c_mingzi_rm')}
                </div>
                <div style={derivedBoxStyle}>
                    <div style={derivedTagStyle}>{tr('derived_auto_tag', '自動生成（唯讀，由上方姓名合併）')}</div>
                    <div style={gGrid}>
                        {gRO('c_name_chn', tr('full_name_chn', '姓名（中）'), 'c_name_chn')}
                        {gRO('c_name', tr('pinyin_full', '拼音'), 'c_name')}
                        {gRO('c_name_proper', tr('foreign_full', '外文全名'), 'c_name_proper')}
                        {gRO('c_name_rm', tr('rm_full', '羅馬字全名'), 'c_name_rm')}
                    </div>
                </div>
            </>)}

            {/* 區塊二：基本屬性（性別/族群/朝代） */}
            {block(tr('block_attributes', '基本屬性'), (
                <div style={gGrid}>
                    <div>
                        {fLabel(tr('gender', '性別'), 'c_female')}
                        <select className="cbdb-historical-text" value={fields.c_female ?? ''} disabled={!canEdit && !canPropose}
                            onChange={(e) => set('c_female', e.target.value)} style={gInputStyle}>
                            <option value="">{tr('please_select', 'NULL')}</option>
                            <option value="0">0-{tr('male', '男')}</option>
                            <option value="1">1-{tr('female', '女')}</option>
                        </select>
                    </div>
                    {gCode('c_ethnicity_code', tr('tribe', '族群/部族'), 'c_ethnicity_code', 'ethnicity', 'c_ethnicity_code', ['c_ethnicity_code', 'c_name_chn', 'c_name'])}
                    {gCode('c_dy', tr('dynasty', '朝代'), 'c_dy', 'dynasty', 'c_dy', ['c_dy', 'c_dynasty_chn', 'c_dynasty', 'c_year_range'], true)}
                </div>
            ))}

            {/* 區塊三：生卒年與指數年（生/卒年號轉換 + 享年；指數年/地址為系統自動計算，收進唯讀子區塊去除擁擠） */}
            {block(tr('block_life_dates', '生卒年與指數年'), <>
                <div style={gGrid}>
                    <div style={gFull}>{fLabel(tr('birth_year', '生年'), 'c_birthyear')}
                        <EraTimeField values={buildEra(birth)} onChange={(p) => applyEra(birth, p)} dynastyCode={dynastyCode} showRange showLunar /></div>
                    <div style={gFull}>{fLabel(tr('death_year', '卒年'), 'c_deathyear')}
                        <EraTimeField values={buildEra(death)} onChange={(p) => applyEra(death, p)} dynastyCode={dynastyCode} showRange showLunar /></div>
                    {gText('c_death_age', tr('age_at_death', '享年'), 'c_death_age')}
                    {gCode('c_death_age_range', tr('range_label', '範圍'), 'c_death_age_range', 'range', 'c_range_code', ['c_range_code', 'c_range_chn', 'c_range'])}
                </div>
                <div style={derivedBoxStyle}>
                    <div style={derivedTagStyle}>{tr('index_auto_tag', '指數年與指數地址（系統依算法定期自動計算，唯讀，無需手動填寫）')}</div>
                    <div style={gGrid}>
                        {gRO('c_index_year', tr('index_year', '指數年'), 'c_index_year')}
                        {gRO('c_index_year_type_code', tr('index_year_method', '指數年方法'), 'c_index_year_type_code')}
                        {gRO('c_index_year_source_id', tr('index_year_source', '指數年來源'), 'c_index_year_source_id')}
                        {gRO('c_index_addr_id', tr('index_addr', '指數地址'), 'c_index_addr_id')}
                        {gRO('c_index_addr_type_code', tr('index_addr_type', '指數地址類型'), 'c_index_addr_type_code')}
                    </div>
                </div>
            </>)}

            {/* 區塊四：在世年（始/終） */}
            {block(tr('block_floruit', '在世年（活動年）'), (
                <div style={gGrid}>
                    <div style={gFull}>{fLabel(tr('active_from', '在世始年'), 'c_fl_earliest_year')}
                        <EraTimeField values={buildEra(flEarly)} onChange={(p) => applyEra(flEarly, p)} dynastyCode={dynastyCode} showNotes /></div>
                    <div style={gFull}>{fLabel(tr('active_until', '在世終年'), 'c_fl_latest_year')}
                        <EraTimeField values={buildEra(flLate)} onChange={(p) => applyEra(flLate, p)} dynastyCode={dynastyCode} showNotes /></div>
                </div>
            ))}

            {/* 區塊五：籍貫與戶籍（郡望/戶籍） */}
            {block(tr('block_origin', '籍貫與戶籍'), (
                <div style={gGrid}>
                    {gCode('c_choronym_code', tr('choronym', '郡望'), 'c_choronym_code', 'choronym', 'c_choronym_code', ['c_choronym_code', 'c_choronym_chn', 'c_choronym'])}
                    {gCode('c_household_status_code', tr('household_field', '戶籍'), 'c_household_status_code', 'household', 'c_household_status_code', ['c_household_status_code', 'c_household_status_desc_chn', 'c_household_status_desc'])}
                </div>
            ))}

            {/* 區塊六：備註 */}
            {block(tr('block_notes', '備註'), (
                <div style={gGrid}>
                    <div style={gFull}>{fLabel(tr('notes_field', '備註'), 'c_notes')}
                        <textarea name="c_notes" id="c_notes" value={fields.c_notes ?? ''} disabled={!canEdit && !canPropose} rows={5}
                            onChange={(e) => set('c_notes', e.target.value)} style={{ ...gInputStyle, height: 'auto' }} /></div>
                </div>
            ))}

            {/* 修改說明：提案（提交建議）時附帶；legacy 對任何 active 使用者皆顯示。 */}
            {(canEdit || canPropose) ? (
                <div style={{ marginBottom: 16 }}>
                    {fLabel(tr('modification_note_label', '修改說明'))}
                    <textarea name="modification_note" id="modification_note" value={comment} rows={3} onChange={(e) => setComment(e.target.value)}
                        placeholder={tr('modification_note_placeholder', '請說明修改原因')} style={{ ...gInputStyle, height: 'auto' }} />
                    <span style={gHintStyle}>{tr('modification_note_hint', '此說明將記錄於操作歷史中（提交建議時附帶）')}</span>
                </div>
            ) : null}

            {/* audit-fields 唯讀區：建檔者+日期、更新者+日期 各合併為一欄（兩列），對齊其餘 12 編輯器與 legacy。 */}
            {(fields.c_created_by || fields.c_created_date || fields.c_modified_by || fields.c_modified_date) ? (
                <div style={gAuditWrapStyle}>
                    <div style={gridSectionHeadStyle}>{tr('create_or_modify', '建檔 / 更新資訊')}</div>
                    <div style={gGrid}>
                        <div>{fLabel(tr('audit_created', '建檔'))}
                            <input className="cbdb-historical-text" type="text" readOnly disabled style={{ ...gInputStyle, ...gReadonlyStyle }}
                                value={auditValue(fields.c_created_by, fields.c_created_date)} /></div>
                        <div>{fLabel(tr('audit_updated', '更新'))}
                            <input className="cbdb-historical-text" type="text" readOnly disabled style={{ ...gInputStyle, ...gReadonlyStyle }}
                                value={auditValue(fields.c_modified_by, fields.c_modified_date)} /></div>
                    </div>
                </div>
            ) : null}

            <div style={gSubmitRow}>
                {/* 主要動作靠左 */}
                {canEdit ? <button type="button" disabled={saving || !dirty} style={gPrimaryBtn} onClick={() => void save('direct')}>{saving ? <><BtnSpinner />{tr('saving', '儲存中…')}</> : tr('save_directly', '直接保存')}</button> : null}
                {(canEdit || canPropose) ? <button type="button" disabled={saving || !dirty} style={gInfoBtn} onClick={() => void save('proposal')}>{saving ? <><BtnSpinner />{tr('saving', '儲存中…')}</> : tr('submit_proposal', '提交建議')}</button> : null}
                {/* 近按鈕即時回饋（Q3）：儲存中轉圈 / ✓ 已儲存 / ✗ 失敗，緊鄰按鈕，不必抬頭看頂部 flash。 */}
                <ActionStatus saving={saving} deleting={deleting} message={message} error={error} t={t} />
                {/* 危險/另存動作靠右（對齊 legacy 的 float-right 分組） */}
                {(canEdit && deleteEndpoint) || duplicateCollateralUrl || saveasUrl ? (
                    <div style={gBtnGroupRight}>
                        {canEdit && deleteEndpoint ? <button type="button" disabled={deleting} style={gDangerBtn} onClick={() => void doDelete()}>{tr('delete', '刪除')}</button> : null}
                        {duplicateCollateralUrl ? <a href={duplicateCollateralUrl} style={gSuccessBtn}>{tr('duplicate_collateral', 'Duplicate Collateral Info')}</a> : null}
                        {saveasUrl ? <a href={saveasUrl} style={gSuccessBtn}>{tr('duplicate_basic', 'Duplicate Basic Info')}</a> : null}
                    </div>
                ) : null}
            </div>
        </form>
    );
}

// 隱藏提交按鈕：畫面外但仍為可提交候選（不可用 display:none／hidden，否則部分瀏覽器不觸發隱式提交）。
const hiddenSubmitStyle: React.CSSProperties = { position: 'absolute', width: 1, height: 1, padding: 0, margin: -1, border: 0, overflow: 'hidden', clip: 'rect(0 0 0 0)' };

// BasicInfo 專屬（非版面）樣式：唯讀派生子區塊、生成拼音按鈕列。
// 唯讀派生子區塊：虛線框 + 淡背景，明確標示「自動生成」。
const derivedBoxStyle: React.CSSProperties = { background: 'var(--surface-sunken)', border: '1px dashed var(--border)', borderRadius: 10, padding: '12px 14px', marginTop: 4 };
const derivedTagStyle: React.CSSProperties = { fontSize: '0.78rem', color: 'var(--muted-foreground)', marginBottom: 10 };
// 生成拼音按鈕列：與上方姓名網格、下方派生區留足間距（修正先前過緊）。
const pinyinRowStyle: React.CSSProperties = { margin: '16px 0' };
