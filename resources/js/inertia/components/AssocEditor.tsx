import React, { useEffect, useMemo, useRef, useState } from 'react';
import EraTimeField, { EraTimeFieldValues } from './EraTimeField';
import CodeAutocomplete from './PersonBrowser/shared/CodeAutocomplete';
import TextpersonPair from './PersonEditorShared/TextpersonPair';
import { getCsrfToken } from './PersonBrowser/shared/csrf';
import AiCodeLookupPanel, { AiCandidate } from './PersonEditorShared/AiCodeLookupPanel';
import ActionStatus, { BtnSpinner } from './PersonEditorShared/ActionStatus';
import { redirectAfterSubresourceCreate } from './PersonEditorShared/afterCreate';
import MirrorConflictNotice, { MirrorConflict } from './PersonEditorShared/MirrorConflictNotice';
import MirrorSuspectedNotice, { MirrorSuspected } from './PersonEditorShared/MirrorSuspectedNotice';
import OppositeEdgeNotice from './PersonEditorShared/OppositeEdgeNotice';
import { useOppositeEdgeDetection } from './PersonEditorShared/useOppositeEdgeDetection';
import {
    gridCardStyle, gGrid, gInputStyle, gReadonlyStyle, gHintStyle, gOkStyle, gErrStyle,
    gSubmitRow, gBtnGroupRight, gPrimaryBtn, gInfoBtn, gDangerBtn, gCancelBtn,
    gAuditWrapStyle, gridSectionHeadStyle, GridLabel, GridSection, gridCell, gridInput,
} from './PersonEditorShared/grid';

/**
 * 社會關係（associations / ASSOC_DATA）編輯器（對齊 legacy biogmains/assoc/_form.blade.php，非 person-browser）。
 * 欄位最多、9 段複合主鍵的編輯器。
 *
 * === 互逆配對碼（c_assocship_pair）：可選/可手選反向關係碼 ===
 * 反向社會關係常有歧義須人選（一個正向碼在 ASSOC_CODES 可能有 c_assoc_pair / c_assoc_pair2 兩個合法反向）。
 * 本編輯器對齊 legacy + KinEditor：依正向碼查 /api/select/search/assocpair 取候選、create 預設選第一個，
 * 使用者可手動更正後送 c_assocship_pair；後端據此寫對方鏡像列的 c_assoc_code。
 * 後端 AssociationCreate/MutationHandler 對「未送的配對碼」以代碼表權威值補齊（ASSOC_CODES.c_assoc_pair），
 * 故縱使編輯器未送也不會落成「未详」(0)；但反向有歧義時務必由本選擇器手選正確配對。
 * （kin/assoc_kin 配對碼仍由後端依 KINSHIP_CODES.c_kin_pair1 補齊，非關係主軸不在此手選。）
 *
 * === 9 段複合主鍵 ===
 * c_personid, c_assoc_code, c_assoc_id, c_kin_code, c_kin_id, c_assoc_kin_code, c_assoc_kin_id,
 * c_text_title(varchar,'[n/a]'哨兵), c_assoc_first_year(start year,'-9999'哨兵)。
 * 編輯模式 PK 段可改（改 c_assoc_code 等→後端鏡像遷移）；空值正規化哨兵；改鍵後重同步 originalPk。
 */
type Fields = Record<string, string>;

interface Props {
    personId: number;
    personLabel: string;
    dynastyCode?: number | null;
    dynastyStart?: string;   // 人物朝代起年（addr 搜尋過濾 dy_start，非朝代代碼）
    dynastyEnd?: string;     // 人物朝代末年（dy_end）
    mode: 'create' | 'edit';
    initialFields: Fields;
    initialLabels?: Fields;
    canEdit: boolean;
    canPropose: boolean;
    createEndpoint: string;
    mutateEndpoint: string;
    deleteEndpoint?: string;
    indexUrl: string;
    aiEnabled?: boolean;
    aiSuggestEndpoint?: string;
    aiModel?: string;
    routeName?: string;
    t?: (k: string) => string;
}

// fy era 的 year 即 PK 段 c_assoc_first_year；ly year 為 c_assoc_last_year（非 PK）。
const FY = { year: 'c_assoc_first_year', nhCode: 'c_assoc_fy_nh_code', nhYear: 'c_assoc_fy_nh_year', range: 'c_assoc_fy_range', intercalary: 'c_assoc_fy_intercalary', month: 'c_assoc_fy_month', day: 'c_assoc_fy_day', dayGz: 'c_assoc_fy_day_gz' };
const LY = { year: 'c_assoc_last_year', nhCode: 'c_assoc_ly_nh_code', nhYear: 'c_assoc_ly_nh_year', range: 'c_assoc_ly_range', intercalary: 'c_assoc_ly_intercalary', month: 'c_assoc_ly_month', day: 'c_assoc_ly_day', dayGz: 'c_assoc_ly_day_gz' };
type EraGroup = typeof FY;

// 9 段複合主鍵。
const PK = ['c_personid', 'c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year'];
// 可改主鍵段（除 c_personid；c_assoc_first_year 經 era fy year 改）。
const EDITABLE_PK = ['c_assoc_code', 'c_assoc_id', 'c_kin_code', 'c_kin_id', 'c_assoc_kin_code', 'c_assoc_kin_id', 'c_text_title', 'c_assoc_first_year'];
// varchar PK c_text_title 哨兵 '[n/a]'；c_assoc_first_year 哨兵 '-9999'；其餘 code PK 段哨兵 '0'。
const TEXT_PK_SENTINEL = '[n/a]';
const YEAR_PK_SENTINEL = '-9999';
// 非主鍵可寫欄位。
const NON_PK = [
    'c_assoc_last_year', 'c_assoc_ly_nh_code', 'c_assoc_ly_nh_year', 'c_assoc_ly_range',
    'c_assoc_ly_intercalary', 'c_assoc_ly_month', 'c_assoc_ly_day', 'c_assoc_ly_day_gz',
    'c_assoc_fy_nh_code', 'c_assoc_fy_nh_year', 'c_assoc_fy_range',
    'c_assoc_fy_intercalary', 'c_assoc_fy_month', 'c_assoc_fy_day', 'c_assoc_fy_day_gz',
    'c_sequence', 'c_notes', 'c_topic_code', 'c_occasion_code', 'c_assoc_count',
    'c_tertiary_personid', 'c_tertiary_type_notes', 'c_assoc_claimer_id',
    'c_addr_id', 'c_inst_code', 'c_inst_name_code', 'c_source', 'c_pages',
];

export default function AssocEditor({
    personId, personLabel, dynastyCode = null, dynastyStart, dynastyEnd, mode, initialFields, initialLabels = {},
    canEdit, canPropose, createEndpoint, mutateEndpoint, deleteEndpoint, indexUrl,
    aiEnabled = false, aiSuggestEndpoint, aiModel, routeName, t,
}: Props) {
    const tr = (k: string, fb: string) => { const v = t ? t(k) : k; return v && v !== k ? v : fb; };
    const base: Fields = {
        c_personid: String(personId),
        c_assoc_code: '', c_assoc_id: '', c_kin_code: '0', c_kin_id: '0',
        c_assoc_kin_code: '0', c_assoc_kin_id: '0',
        c_text_title: TEXT_PK_SENTINEL, c_assoc_first_year: '',
        c_inst_code: '0', c_inst_name_code: '0', c_source: '0',
        c_assoc_count: '1',
        ...initialFields,
    };
    const [fields, setFields] = useState<Fields>(base);
    const [labels, setLabels] = useState<Fields>(initialLabels);
    const [savedSnapshot, setSavedSnapshot] = useState(JSON.stringify(base));
    const originalPk = useRef<Record<string, number | string>>(Object.fromEntries(PK.map((k) => {
        if (k === 'c_personid') return [k, personId];
        if (k === 'c_text_title') return [k, String(initialFields.c_text_title ?? TEXT_PK_SENTINEL)];
        if (k === 'c_assoc_first_year') return [k, Number(initialFields.c_assoc_first_year ?? -9999)];
        return [k, Number(initialFields[k] ?? 0)];
    })));
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [sourceHighlight, setSourceHighlight] = useState(false);
    const [assocHighlight, setAssocHighlight] = useState(false);
    const [comment, setComment] = useState('');
    const [conflict, setConflict] = useState<MirrorConflict | null>(null); // #66 對面鏡像衝突
    const [suspected, setSuspected] = useState<MirrorSuspected | null>(null); // #70 對面疑似漂移鏡像
    // 互逆配對碼（反向社會關係碼）：候選由 /api/select/search/assocpair 依正向碼取得（對齊 legacy / KinEditor）。
    // create 預設選第一個候選；反向有歧義（一碼可能有 c_assoc_pair / c_assoc_pair2 兩個合法反向）故容許手選。
    type PairOpt = { code: string; label: string };
    const [pairCandidates, setPairCandidates] = useState<PairOpt[]>([]);
    const [reversePair, setReversePair] = useState<string>('');
    // edit 模式僅在使用者「主動更改」反向碼時才送出覆寫（避免改備註等非關係編輯誤改鏡像反向碼）。
    const [pairTouched, setPairTouched] = useState(false);
    // 親屬互逆配對（c_kin_code→c_kinship_pair、c_assoc_kin_code→c_assoc_kinship_pair）：候選來自 KINSHIP pair
    // 端點（同 KinEditor），create 預設第一、edit 僅主動更改才送。對齊 legacy assoc/edit 三組配對。
    const [kinPairCandidates, setKinPairCandidates] = useState<PairOpt[]>([]);
    const [kinReversePair, setKinReversePair] = useState<string>('');
    const [kinPairTouched, setKinPairTouched] = useState(false);
    const [assocKinPairCandidates, setAssocKinPairCandidates] = useState<PairOpt[]>([]);
    const [assocKinReversePair, setAssocKinReversePair] = useState<string>('');
    const [assocKinPairTouched, setAssocKinPairTouched] = useState(false);
    const msgTimer = useRef<number | null>(null);
    const flashSaved = (m: string) => { setMessage(m); if (msgTimer.current) window.clearTimeout(msgTimer.current); msgTimer.current = window.setTimeout(() => setMessage(null), 3000); };
    useEffect(() => () => { if (msgTimer.current) window.clearTimeout(msgTimer.current); }, []);
    // #80（§5-B）：對面多筆對應時，direct 存檔須二次確認（先停下提示，再次點擊才一併同步）。偵測結果變動即重置。
    const multiAckRef = useRef(false);

    // #88：三組互逆配對碼（社會關係/親屬/關聯親屬）皆為獨立 state、不在 fields。只改任一須仍視為 dirty（啟用存檔），
    // 否則存檔鈕停用、改動存不進去。後端 handlePairOnlyMirrorSync 承接「僅配對碼變更」。
    const dirty = useMemo(
        () => JSON.stringify(fields) !== savedSnapshot || (mode === 'edit' && (pairTouched || kinPairTouched || assocKinPairTouched)),
        [fields, savedSnapshot, mode, pairTouched, kinPairTouched, assocKinPairTouched],
    );

    // #79：偵測對面缺邊/多條（僅 edit；依「已存檔」列定位；存檔後重抓）。
    const savedRow = useMemo<Fields>(() => { try { return JSON.parse(savedSnapshot) as Fields; } catch { return base; } }, [savedSnapshot]);
    const { result: oppositeEdge } = useOppositeEdgeDetection({
        // 僅「可直接寫入」者偵測（後端亦 detection:false 把關；前端先過濾省一次請求）。
        enabled: mode === 'edit' && canEdit,
        resource: 'associations',
        personId,
        forward: {
            opposite_id: savedRow.c_assoc_id ?? '0',
            forward_code: savedRow.c_assoc_code ?? '0',
            text_title: savedRow.c_text_title ?? TEXT_PK_SENTINEL,
            first_year: savedRow.c_assoc_first_year ?? YEAR_PK_SENTINEL,
        },
        reloadKey: savedSnapshot,
    });
    // 偵測結果物件每次重抓即換參考（含同筆數但對面列集合改變的情形）→ 重置武裝旗標。
    useEffect(() => { multiAckRef.current = false; }, [oppositeEdge]);
    const reverseCodeLabel = useMemo(() => pairCandidates.find((o) => o.code === reversePair)?.label, [pairCandidates, reversePair]);

    const set = (k: string, v: string) => setFields((p) => ({ ...p, [k]: v }));
    const setLabel = (k: string, v: string) => setLabels((p) => ({ ...p, [k]: v }));
    const editable = canEdit || canPropose;

    // 正向關係碼變更時重抓反向配對候選，create 預設選第一個（對齊 legacy JS）；正向碼改變即重置 touched。
    useEffect(() => {
        const code = fields.c_assoc_code;
        if (!code || Number(code) === 0) { setPairCandidates([]); setReversePair(''); setPairTouched(false); return; }
        let aborted = false;
        (async () => {
            try {
                const res = await fetch(`/api/select/search/assocpair?assoc_code=${encodeURIComponent(code)}&person_id=${encodeURIComponent(fields.c_assoc_id ?? '0')}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                });
                const rows = await res.json().catch(() => []);
                if (aborted) return;
                const opts: PairOpt[] = Array.isArray(rows)
                    ? rows.map((r: Record<string, unknown>) => ({
                        code: String(r.c_assoc_code),
                        label: `${r.c_assoc_code} ${r.c_assoc_desc_chn ?? ''} ${r.c_assoc_desc ?? ''}`.trim(),
                    }))
                    : [];
                setPairCandidates(opts);
                // create：預設選第一候選（同 legacy）；edit：預設「保持目前反向碼」（空），未觸碰即不送、後端保留原值。
                setReversePair(mode === 'create' && opts.length ? opts[0].code : '');
                setPairTouched(false);
            } catch { if (!aborted) { setPairCandidates([]); setReversePair(''); } }
        })();
        return () => { aborted = true; };
    // 僅依正向關係碼變動觸發（c_assoc_id 變動不需重抓配對候選）。
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [fields.c_assoc_code]);

    // 親屬關係碼（c_kin_code）變更時抓互逆配對候選（→ c_kinship_pair），沿用 KinEditor / legacy 邏輯。
    useEffect(() => {
        const code = fields.c_kin_code;
        if (!code || Number(code) === 0) { setKinPairCandidates([]); setKinReversePair(''); setKinPairTouched(false); return; }
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
                setKinPairCandidates(opts);
                setKinReversePair(mode === 'create' && opts.length ? opts[0].code : '');
                setKinPairTouched(false);
            } catch { if (!aborted) { setKinPairCandidates([]); setKinReversePair(''); } }
        })();
        return () => { aborted = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [fields.c_kin_code]);

    // 關聯親屬關係碼（c_assoc_kin_code）變更時抓互逆配對候選（→ c_assoc_kinship_pair）；同為 KINSHIP pair 端點。
    useEffect(() => {
        const code = fields.c_assoc_kin_code;
        if (!code || Number(code) === 0) { setAssocKinPairCandidates([]); setAssocKinReversePair(''); setAssocKinPairTouched(false); return; }
        let aborted = false;
        (async () => {
            try {
                const res = await fetch(`/api/select/search/kinpair?kin_code=${encodeURIComponent(code)}&person_id=${encodeURIComponent(fields.c_assoc_kin_id ?? '0')}`, {
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
                setAssocKinPairCandidates(opts);
                setAssocKinReversePair(mode === 'create' && opts.length ? opts[0].code : '');
                setAssocKinPairTouched(false);
            } catch { if (!aborted) { setAssocKinPairCandidates([]); setAssocKinReversePair(''); } }
        })();
        return () => { aborted = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [fields.c_assoc_kin_code]);

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

    // 社會機構 c_inst_code 值為「code-namecode」，拆成兩欄（同 offices）。
    const instValue = (fields.c_inst_code && fields.c_inst_code !== '0')
        ? `${fields.c_inst_code}-${fields.c_inst_name_code ?? '0'}` : '';
    const onInstChange = (v: string, l: string) => {
        if (!v || v === '0' || v === '-999') {
            setFields((p) => ({ ...p, c_inst_code: '0', c_inst_name_code: '0' }));
            setLabel('c_inst_code', '');
            return;
        }
        const dash = v.indexOf('-');
        const code = dash >= 0 ? v.slice(0, dash) : v;
        const nameCode = dash >= 0 ? v.slice(dash + 1) : '';
        setFields((p) => ({ ...p, c_inst_code: code || '0', c_inst_name_code: nameCode || '0' }));
        setLabel('c_inst_code', l);
    };

    const onPickTextperson = (p: { source: string; pages: string; sourceLabel: string }) => {
        setFields((prev) => ({ ...prev, c_source: p.source, c_pages: p.pages }));
        setLabel('c_source', p.sourceLabel);
        setSourceHighlight(true);
        window.setTimeout(() => setSourceHighlight(false), 4000);
        setMessage(tr('update_source_success', '已自動回填出處與頁碼'));
    };

    const applyAiCode = (c: AiCandidate) => {
        set('c_assoc_code', String(c.code_id));
        setLabel('c_assoc_code', `${c.code_id} ${c.desc_chn ?? ''} ${c.desc_en ?? ''}`.trim());
        setAssocHighlight(true);
        window.setTimeout(() => setAssocHighlight(false), 4000);
    };

    const save = async (sm: 'direct' | 'proposal', force = false) => {
        // #80（§5-B）：direct 存檔且偵測到對面多筆對應時，第一次點擊只提示不送出（武裝確認），第二次才一併同步。
        // force（#66/#70 衝突強制覆寫/收斂）已是使用者的明確決定，不再二次攔截。
        if (sm === 'direct' && !force && oppositeEdge?.status === 'multiple' && !multiAckRef.current) {
            multiAckRef.current = true;
            setError(tr('opposite_edge_multiple_confirm', '對面有多筆對應的反向關係。直接保存會一併同步這些反向列。請先確認上方列出的記錄無誤，再次點擊「直接保存」以繼續。'));
            return;
        }
        // 社會關係 c_assoc_code / 關聯人物 c_assoc_id 為主碼，必填（拒絕 0/未詳）：僅新增時擋；編輯既有列不卡。
        if (mode === 'create') {
            if (!fields.c_assoc_code || fields.c_assoc_code === '0') {
                setError(tr('please_select_assoc', '請選擇社會關係')); return;
            }
            if (!fields.c_assoc_id || fields.c_assoc_id === '0') {
                setError(tr('please_select_assoc_person', '請選擇關聯人物')); return;
            }
        }
        setSaving(true); setError(null); setMessage(null); setConflict(null); setSuspected(null);
        // PK 段空值正規化：c_text_title→'[n/a]'、c_assoc_first_year→'-9999'、其餘 code 段→'0'。
        const pkVal = (k: string): number | string => {
            if (k === 'c_personid') return personId;
            if (k === 'c_text_title') return (fields[k]?.trim() ? fields[k] : TEXT_PK_SENTINEL);
            if (k === 'c_assoc_first_year') return (fields[k]?.trim() ? Number(fields[k]) : Number(YEAR_PK_SENTINEL));
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
            // 反向配對碼：create 送目前選取（預設第一候選，同 legacy）；後端據此寫對方鏡像列關係碼。
            if (reversePair) changes.c_assocship_pair = reversePair;
            // 親屬／關聯親屬互逆配對碼（同 legacy assoc/edit 三組配對）。
            if (kinReversePair) changes.c_kinship_pair = kinReversePair;
            if (assocKinReversePair) changes.c_assoc_kinship_pair = assocKinReversePair;
        } else {
            endpoint = mutateEndpoint; operation = 'update'; target = originalPk.current;
            const initial: Fields = JSON.parse(savedSnapshot);
            changes = {};
            for (const k of NON_PK) { const v = fields[k] ?? ''; if ((initial[k] ?? '') !== v) changes[k] = v === '' ? null : v; }
            // 可改主鍵段：與原值（正規化後）不同才送（PK NOT NULL，送哨兵而非空）。
            for (const k of EDITABLE_PK) {
                const cur = String(pkVal(k));
                const init = String(k === 'c_text_title'
                    ? (initial[k]?.trim() ? initial[k] : TEXT_PK_SENTINEL)
                    : k === 'c_assoc_first_year'
                        ? (initial[k]?.trim() ? Number(initial[k]) : Number(YEAR_PK_SENTINEL))
                        : Number(initial[k]?.trim() ? initial[k] : '0'));
                if (cur !== init) changes[k] = cur;
            }
            // 反向配對碼：edit 僅在使用者主動更改時送出覆寫（避免改備註等誤改鏡像反向碼）；
            // 可單獨送（無其他變更）→ 後端走 pair-only 鏡像修復路徑。
            if (reversePair && pairTouched) changes.c_assocship_pair = reversePair;
            if (kinReversePair && kinPairTouched) changes.c_kinship_pair = kinReversePair;
            if (assocKinReversePair && assocKinPairTouched) changes.c_assoc_kinship_pair = assocKinReversePair;
            if (Object.keys(changes).length === 0) { setSaving(false); setError(tr('no_change', '沒有變更')); return; }
        }
        // pair-only 修復（僅互逆配對碼變更、無任何 ASSOC_DATA 真實欄）屬「直接修復鏡像」維護動作，後端僅 direct 支援；
        // 若以 proposal 送出會被父類「changes 不可為空」擋成 422，故前端先攔截並引導改用「直接保存」。
        // 僅限 edit：create 走 AssociationCreateHandler，後端接受「PK + 互逆碼、無其他欄」的新建提案，不可誤擋。
        if (mode === 'edit' && sm === 'proposal') {
            const PAIR_KEYS = ['c_assocship_pair', 'c_kinship_pair', 'c_assoc_kinship_pair'];
            const realChanges = Object.keys(changes).filter((k) => !PAIR_KEYS.includes(k));
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
                body: JSON.stringify({ resource: 'associations', person_id: personId, mode: sm, operation, target: { pk: target }, changes, ...(Object.keys(meta).length ? { meta } : {}) }),
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
            setPairTouched(false); setKinPairTouched(false); setAssocKinPairTouched(false); // #88：存檔成功後重置三組配對 touched。
            if (mode === 'create') { redirectAfterSubresourceCreate(indexUrl, json, sm === 'direct'); } else if (sm === 'direct') {
                // 改鍵後以實際送出的 PK 變更覆寫 originalPk（不可用 fields 重建，避免清空 Number('')=0 失準）。
                const nextPk = { ...originalPk.current };
                for (const k of EDITABLE_PK) { if (Object.prototype.hasOwnProperty.call(changes, k)) nextPk[k] = (k === 'c_text_title' ? String(changes[k]) : Number(changes[k])); }
                originalPk.current = nextPk;
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('save_failed', '儲存失敗'));
        } finally { setSaving(false); }
    };

    const doDelete = async () => {
        if (!deleteEndpoint || !window.confirm(tr('assoc_delete_confirm', '確定刪除此社會關係？'))) return;
        setDeleting(true); setError(null);
        try {
            const res = await fetch(deleteEndpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ resource: 'associations', person_id: personId, mode: 'direct', operation: 'delete', target: { pk: originalPk.current } }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || !json?.ok) throw new Error(json?.message || `HTTP ${res.status}`);
            setSavedSnapshot(JSON.stringify(fields));
            window.location.assign(indexUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : tr('delete_failed', '刪除失敗'));
        } finally { setDeleting(false); }
    };

    const textRow = (key: string, label: string, code: string, highlight = false, hint?: string) => (
        gridCell(label, { code, hint }, gridInput({ value: fields[key] ?? '', onChange: (v) => set(key, v), disabled: !editable, highlight }))
    );
    const searchRow = (key: string, label: string, code: string, endpoint: string, highlight = false, sentinel = '0', required = false, sentinelLabel?: string, extraQuery?: Record<string, string>) => (
        gridCell(label, { code, required },
            <CodeAutocomplete mode="search" endpoint={endpoint} value={fields[key] ?? sentinel} initialLabel={labels[key] ?? ''}
                disabled={!editable} aria-invalid={highlight} sentinelLabel={sentinelLabel} extraQuery={extraQuery}
                onChange={(v, l) => { set(key, v || sentinel); setLabel(key, l); }} />)
    );
    const listRow = (key: string, label: string, code: string, model: string, idKey: string, labelKeys: string[]) => (
        gridCell(label, { code },
            <CodeAutocomplete mode="list" model={model} idKey={idKey} labelKeys={labelKeys}
                value={fields[key] ?? ''} initialLabel={labels[key] ?? ''} disabled={!editable}
                onChange={(v, l) => { set(key, v); setLabel(key, l); }} />)
    );
    // 互逆配對碼選擇器（社會關係 c_assocship_pair／親屬 c_kinship_pair／關聯親屬 c_assoc_kinship_pair 共用版型）：
    // 有候選→可手選（edit 額外提供「保持目前」空選項）；無候選→唯讀提示，系統自動處理。整列跨欄（含 hint）。
    const reversePairRow = (
        label: string, code: string, candidates: PairOpt[], value: string,
        onPick: (v: string) => void, hint: string, emptyText: string,
    ) => (
        gridCell(label, { code, full: true }, <>
            {candidates.length ? (
                <select value={value} disabled={!editable} onChange={(e) => onPick(e.target.value)} style={{ ...gInputStyle }}>
                    {mode === 'edit' ? <option value="">{tr('keep_current_pair', '（保持目前反向碼）')}</option> : null}
                    {candidates.map((o) => <option key={o.code} value={o.code}>{o.label}</option>)}
                </select>
            ) : (
                <input type="text" value={emptyText} readOnly disabled style={{ ...gInputStyle, ...gReadonlyStyle }} />
            )}
            <span style={gHintStyle}>{hint}</span>
        </>)
    );

    return (
        <div style={gridCardStyle}>
            <h3 style={titleStyle}>{mode === 'create' ? tr('assoc_create', '新增社會關係') : tr('assoc_edit', '編輯社會關係')}</h3>
            {message ? <div style={gOkStyle}>{message}</div> : null}
            {error ? <div style={gErrStyle}>{error}</div> : null}
            <OppositeEdgeNotice result={oppositeEdge} reverseCodeLabel={reverseCodeLabel} tr={tr} />

            {aiEnabled && aiSuggestEndpoint && editable ? (
                <AiCodeLookupPanel
                    table="ASSOC_CODES"
                    personId={personId}
                    aiSuggestEndpoint={aiSuggestEndpoint}
                    aiModel={aiModel}
                    routeName={routeName}
                    title={tr('ai_assoc_lookup', 'AI 智能識別社會關係代碼')}
                    placeholder={tr('ai_assoc_placeholder', '描述關係，例如「同年進士」「妻舅」')}
                    onApply={applyAiCode}
                />
            ) : null}

            {/* 核心社會關係：社會關係碼 | 關聯人物 + 關係次數（重要）+ 次序（不重要、後置）+ 互逆配對碼。 */}
            <GridSection title={tr('assoc_core_section', '社會關係')}>
                <div style={gGrid}>
                    {searchRow('c_assoc_code', tr('assoc_field', '社會關係'), 'c_assoc_code', '/api/select/search/assoccode', assocHighlight, '0', true)}
                    {searchRow('c_assoc_id', tr('assoc_person', '關聯人物'), 'c_assoc_id', '/api/select/search/biog', false, '0', true)}
                    {/* 關係次數（書信計次）：重要、非出處，移入核心區，置於社會關係/關聯人物之後。 */}
                    {textRow('c_assoc_count', tr('assoc_count_field', '數量'), 'c_assoc_count', false, tr('assoc_count_hint', '此欄位僅適用於書信：當無法以標題及日期區分多次信件時，則僅建「一筆」社會關係，並將信件總數填於此欄。請填阿拉伯數字'))}
                    {/* 次序不重要，下移至核心欄之後（去強調）。 */}
                    {textRow('c_sequence', tr('sequence', '序號'), 'c_sequence')}
                    {/* 互逆社會關係碼：依社會關係碼取候選，create 預設第一個（同 legacy）；反向有歧義故可手選。 */}
                    {reversePairRow(
                        tr('reverse_assoc_pair_label', '互逆社會關係碼'), 'c_assocship_pair',
                        pairCandidates, reversePair,
                        (v) => { setReversePair(v); setPairTouched(true); },
                        tr('reverse_pair_assoc_hint', '對方人物身上會建立鏡像社會關係；此為其「社會關係碼」的反向碼，系統自動雙向同步。預設取建議反向碼，可手動更正（一個關係可能有多個合法反向）。'),
                        tr('no_paired_assoc', '無對應社會關係'),
                    )}
                </div>
            </GridSection>

            {/* 時間：關係始年 / 末年。 */}
            <GridSection title={tr('assoc_time_section', '時間')}>
                <div style={gGrid}>
                    {gridCell(tr('assoc_start_year', '關係始年'), { code: 'first_year', full: true },
                        <EraTimeField values={buildEra(FY)} onChange={(p) => applyEra(FY, p)} dynastyCode={dynastyCode} showRange showLunar disabled={!editable} />)}
                    {gridCell(tr('assoc_end_year', '關係末年'), { code: 'last_year', full: true },
                        <EraTimeField values={buildEra(LY)} onChange={(p) => applyEra(LY, p)} dynastyCode={dynastyCode} showRange showLunar disabled={!editable} />)}
                </div>
            </GridSection>

            {/* 親屬關係（選填）：與社會關係並存的親屬／關聯親屬欄；兩組各有互逆配對碼（同 legacy assoc/edit）。 */}
            <GridSection title={tr('assoc_kin_section', '親屬關係（選填）')}>
                <div style={gGrid}>
                    {searchRow('c_kin_code', tr('kinship_field', '親屬關係'), 'c_kin_code', '/api/select/search/kincode', false, '0', false, tr('not_specified', '未詳'))}
                    {searchRow('c_kin_id', tr('kin_person', '親屬人物'), 'c_kin_id', '/api/select/search/biog', false, '0', false, tr('not_specified', '未詳'))}
                    {reversePairRow(
                        tr('reverse_kin_pair_label', '互逆親屬關係碼'), 'c_kinship_pair',
                        kinPairCandidates, kinReversePair,
                        (v) => { setKinReversePair(v); setKinPairTouched(true); },
                        tr('reverse_kin_pair_hint', '對方人物身上會建立鏡像親屬關係；此為「親屬關係」(c_kin_code) 的反向碼，系統自動雙向同步。預設取建議反向碼，可手動更正。'),
                        tr('no_paired_kinship', '無對應親屬關係'),
                    )}
                    {searchRow('c_assoc_kin_code', tr('assoc_kin_field', '關聯親屬關係'), 'c_assoc_kin_code', '/api/select/search/kincode', false, '0', false, tr('not_specified', '未詳'))}
                    {searchRow('c_assoc_kin_id', tr('assoc_kin_person', '關聯親屬人物'), 'c_assoc_kin_id', '/api/select/search/biog', false, '0', false, tr('not_specified', '未詳'))}
                    {reversePairRow(
                        tr('reverse_assoc_kin_pair_label', '互逆關聯親屬關係碼'), 'c_assoc_kinship_pair',
                        assocKinPairCandidates, assocKinReversePair,
                        (v) => { setAssocKinReversePair(v); setAssocKinPairTouched(true); },
                        tr('reverse_assoc_kin_pair_hint', '此為「關聯親屬關係」(c_assoc_kin_code) 的反向碼，系統依此於鏡像列雙向同步。預設取建議反向碼，可手動更正。'),
                        tr('no_paired_kinship', '無對應親屬關係'),
                    )}
                </div>
            </GridSection>

            {/* 分類 / 情境 / 關係人：主題、場合、中介/見證人物、地點、社會機構。 */}
            <GridSection title={tr('assoc_context_section', '分類 / 情境 / 關係人')}>
                <div style={gGrid}>
                    {listRow('c_topic_code', tr('topic_field', '主題'), 'c_topic_code', 'topic', 'c_topic_code', ['c_topic_desc_chn', 'c_topic_desc'])}
                    {listRow('c_occasion_code', tr('occasion_field', '場合'), 'c_occasion_code', 'occasion', 'c_occasion_code', ['c_occasion_desc_chn', 'c_occasion_desc'])}
                    {searchRow('c_tertiary_personid', tr('tertiary_person', '中介人物'), 'c_tertiary_personid', '/api/select/search/biog')}
                    {textRow('c_tertiary_type_notes', tr('tertiary_notes', '中介說明'), 'c_tertiary_type_notes')}
                    {searchRow('c_assoc_claimer_id', tr('claimer_person', '見證人物'), 'c_assoc_claimer_id', '/api/select/search/biog')}
                    {searchRow('c_addr_id', tr('place_name', '地點'), 'c_addr_id', '/api/select/search/addr', false, '0', false, undefined, { dy_start: dynastyStart ?? '', dy_end: dynastyEnd ?? '' })}
                    {gridCell(tr('socialinst_field', '社會機構'), { code: 'social_institution' },
                        <CodeAutocomplete mode="search" endpoint="/api/select/search/socialinstcode" value={instValue} initialLabel={labels.c_inst_code ?? ''} disabled={!editable} onChange={onInstChange} />)}
                </div>
            </GridSection>

            {/* 出處與內容：作品/出處標題、出處、頁碼、備註、候選出處（關係次數已移至核心區）。 */}
            <GridSection title={tr('assoc_source_section', '出處與內容')}>
                <div style={gGrid}>
                    {/* 作品標題自成一行（與「出處＋頁碼」非同類）；出處與頁碼成對同行（#121） */}
                    {gridCell(tr('text_title_field', '作品標題'), { code: 'c_text_title', full: true },
                        gridInput({ value: fields.c_text_title ?? '', onChange: (v) => set('c_text_title', v), disabled: !editable }))}
                    {gridCell(tr('source_field', '出處'), { code: 'c_source' },
                        <CodeAutocomplete mode="search" endpoint="/api/select/search/text" value={fields.c_source ?? '0'} initialLabel={labels.c_source ?? ''} disabled={!editable} onChange={(v, l) => { set('c_source', v || '0'); setLabel('c_source', l); }} />)}
                    {textRow('c_pages', tr('pages_entries', '頁碼'), 'c_pages', sourceHighlight)}
                    {gridCell(tr('notes_field', '備註'), { code: 'c_notes', full: true },
                        <textarea value={fields.c_notes ?? ''} disabled={!editable} onChange={(e) => set('c_notes', e.target.value)} rows={4} style={{ ...gInputStyle, height: 'auto', ...(!editable ? gReadonlyStyle : {}) }} />)}
                </div>
                <TextpersonPair personId={personId} label={tr('candidate_source_title', '候選出處')} onPick={onPickTextperson} disabled={!editable} />
            </GridSection>

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
                    <textarea value={comment} onChange={(e) => setComment(e.target.value)} rows={3} style={{ ...gInputStyle, height: 'auto' }} placeholder={tr('modification_note_placeholder', '提案時請說明修改原因')} />
                </div>
            )}

            {conflict ? (
                <MirrorConflictNotice
                    conflict={conflict}
                    mirrorUrl={`/app/basicinformation/${conflict.pk.c_personid}/assoc/edit-v2?${new URLSearchParams(Object.fromEntries(Object.entries(conflict.pk).map(([k, v]) => [k, String(v)]))).toString()}`}
                    onForce={() => void save('direct', true)}
                    onDismiss={() => setConflict(null)}
                    forcing={saving}
                    tr={tr}
                />
            ) : null}

            {suspected ? (
                <MirrorSuspectedNotice
                    suspected={suspected}
                    urlFor={(pk) => `/app/basicinformation/${pk.c_personid}/assoc/edit-v2?${new URLSearchParams(Object.fromEntries(Object.entries(pk).map(([k, v]) => [k, String(v)]))).toString()}`}
                    onForce={() => void save('direct', true)}
                    onDismiss={() => setSuspected(null)}
                    forcing={saving}
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
            {dirty ? <div style={{ marginTop: 8, color: '#92400e', fontSize: '0.8rem' }}>{tr('unsaved_changes', '有未儲存的變更')}</div> : null}
        </div>
    );
}

const titleStyle: React.CSSProperties = { fontSize: '1.1rem', fontWeight: 700, marginBottom: 12 };
