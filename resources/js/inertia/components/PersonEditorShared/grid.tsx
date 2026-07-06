import React from 'react';
import RequiredMark from './RequiredMark';

/**
 * 人物編輯器共用「網格設計語言」（#53 單一真相）：上標籤卡片 + 技術碼淡化 + 響應式雙欄網格。
 * 樣式值由 BasicInfoEditor 既有設計逐字抽出，所有 13 個編輯器共用以保跨頁一致。
 *
 * ⚠️ 焦點保留鐵則：欄位內容（input / autocomplete / 選擇器）必須由呼叫端直接建立並以 children 傳入；
 * 本檔只提供「模組層級的穩定函式 / 元件」（GridLabel / gridCell / gridInput），
 * 絕不可在編輯器 render 函式內部「定義新元件」再渲染欄位——否則每次 render 重新掛載 → input 失焦。
 */

// ─── 樣式常數（逐字對齊 BasicInfoEditor 既有值）──────────────────────────────
export const gridCardStyle: React.CSSProperties = { background: 'var(--card)', color: 'var(--card-foreground)', border: '1px solid var(--border)', borderRadius: 8, padding: 20 };
export const gridSectionStyle: React.CSSProperties = { marginBottom: 22 };
export const gridSectionHeadStyle: React.CSSProperties = { fontSize: '0.82rem', fontWeight: 700, color: 'var(--primary)', letterSpacing: '0.06em', textTransform: 'uppercase', paddingBottom: 8, marginBottom: 14, borderBottom: '1px solid var(--border)' };
// 響應式雙欄：寬螢幕 2 欄、窄螢幕自動降為 1 欄（min(100%,22rem) 使極窄時不溢出）。
export const gGrid: React.CSSProperties = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 22rem), 1fr))', gap: '14px 22px', marginBottom: 4 };
export const gFull: React.CSSProperties = { gridColumn: '1 / -1' };
// 成對欄位「共佔一行」：占滿外層整行（1/-1），內部再以響應式雙欄並排兩欄；
// 避免外層 auto-fit 在寬螢幕變 3 欄時把本該並排的兩欄拆到不同列（窄螢幕仍自動堆疊）。
export const gPairRow: React.CSSProperties = { gridColumn: '1 / -1', display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 16rem), 1fr))', gap: '14px 22px' };
export const gLabelStyle: React.CSSProperties = { display: 'block', fontSize: '0.9rem', fontWeight: 600, color: 'var(--foreground)', marginBottom: 5 };
export const gCodeStyle: React.CSSProperties = { fontWeight: 400, color: 'var(--muted-foreground)', fontSize: '0.78rem', marginLeft: 6 };
export const gInputStyle: React.CSSProperties = { width: '100%', height: 40, padding: '0 11px', borderRadius: 8, border: '1px solid var(--input)', fontSize: '1rem', boxSizing: 'border-box', background: 'var(--background)', color: 'var(--foreground)' };
export const gHintStyle: React.CSSProperties = { display: 'block', marginTop: 4, fontSize: '0.8rem', color: 'var(--muted-foreground)' };
export const gReadonlyStyle: React.CSSProperties = { background: 'var(--muted)', cursor: 'not-allowed' };
export const gAuditWrapStyle: React.CSSProperties = { marginTop: 16, paddingTop: 12, borderTop: '1px solid var(--border)' };
// 隱藏提交鈕：讓編輯器根 <form> 具備「預設提交按鈕」，使單行輸入框按 Enter 觸發原生隱式提交（復刻 legacy Blade 行為）。
// 可見動作按鈕皆為 type="button"（點擊行為不變）；此鈕僅供 Enter，並以 tabIndex=-1／aria-hidden 排除於鍵盤焦點與無障礙。
// 不可用 display:none／hidden，否則部分瀏覽器不觸發隱式提交。
export const gHiddenSubmitStyle: React.CSSProperties = { position: 'absolute', width: 1, height: 1, padding: 0, margin: -1, border: 0, overflow: 'hidden', clip: 'rect(0 0 0 0)' };

// 動作列：主要動作靠左、危險/另存靠右。
export const gSubmitRow: React.CSSProperties = { display: 'flex', gap: 8, marginTop: 16, flexWrap: 'wrap', alignItems: 'center' };
export const gBtnGroupRight: React.CSSProperties = { display: 'flex', gap: 8, flexWrap: 'wrap', marginLeft: 'auto' };
const actionBtnBase: React.CSSProperties = { padding: '8px 16px', borderRadius: 6, fontSize: '0.95rem', fontWeight: 700, cursor: 'pointer', textDecoration: 'none', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', border: '1px solid transparent' };
export const gPrimaryBtn: React.CSSProperties = { ...actionBtnBase, border: '1px solid var(--primary)', background: 'var(--primary)', color: 'var(--primary-foreground)' };
export const gInfoBtn: React.CSSProperties = { ...actionBtnBase, border: '1px solid var(--info)', background: 'var(--info)', color: 'var(--info-foreground)' };
export const gDangerBtn: React.CSSProperties = { ...actionBtnBase, border: '1px solid var(--destructive)', background: 'var(--destructive)', color: 'var(--destructive-foreground)' };
export const gSuccessBtn: React.CSSProperties = { ...actionBtnBase, border: '1px solid var(--success)', background: 'var(--success)', color: 'var(--success-foreground)' };
export const gCancelBtn: React.CSSProperties = { ...actionBtnBase, border: '1px solid var(--input)', background: 'var(--card)', color: 'var(--foreground)' };

// 訊息橫幅。
export const gOkStyle: React.CSSProperties = { background: 'var(--success-subtle)', border: '1px solid var(--success-border)', color: 'var(--success-subtle-foreground)', padding: '8px 12px', borderRadius: 6, marginBottom: 10, fontSize: '1rem' };
export const gErrStyle: React.CSSProperties = { background: 'var(--danger-subtle)', border: '1px solid var(--danger-border)', color: 'var(--danger-subtle-foreground)', padding: '8px 12px', borderRadius: 6, marginBottom: 10, fontSize: '1rem' };
export const gWarnStyle: React.CSSProperties = { background: 'var(--warning-subtle)', border: '1px solid var(--warning-border)', color: 'var(--warning-subtle-foreground)', padding: '8px 12px', borderRadius: 6, marginBottom: 10, fontSize: '1rem' };

// ─── 標籤 / 欄位輔助（模組層級、穩定身分；安全不致 input 失焦）──────────────
/** 上標籤：label + 可選紅 *（必填）+ 可選淡化技術碼後綴。 */
export function GridLabel({ label, code, required }: { label: string; code?: string; required?: boolean }) {
    return (
        <label style={gLabelStyle}>
            {label}
            {required ? <RequiredMark /> : null}
            {code ? <span style={gCodeStyle}>{code}</span> : null}
        </label>
    );
}

export interface GridCellOpts {
    code?: string;
    required?: boolean;
    full?: boolean;
    hint?: string;
}

/**
 * 網格欄位：上標籤 + 任意欄位內容（children，由呼叫端建立）+ 可選 hint。
 * 以「函式呼叫」使用：gridCell('標籤', { full:true }, <CodeAutocomplete .../>)；children 為呼叫端既有元素，
 * React 依型別/位置 reconcile，不會重掛載、不失焦。
 */
export function gridCell(label: string, opts: GridCellOpts, children: React.ReactNode): React.ReactElement {
    return (
        <div style={opts.full ? gFull : undefined}>
            <GridLabel label={label} code={opts.code} required={opts.required} />
            {children}
            {opts.hint ? <span style={gHintStyle}>{opts.hint}</span> : null}
        </div>
    );
}

export interface GridInputOpts {
    value: string;
    onChange: (v: string) => void;
    type?: string;
    disabled?: boolean;
    readonly?: boolean;
    highlight?: boolean;
    placeholder?: string;
    maxLength?: number;
    /** 欄位技術碼（如 c_pages）。傳入後同時設為 input 的 name/id，讓瀏覽器原生表單記憶
     * （點擊即彈出曾手動輸入過的值）能以此為 key 生效；不傳則維持匿名 input（如僅供單次輸入的欄位）。 */
    name?: string;
}

/** 純文字/數字輸入（取代各編輯器的 textRow/numRow 內層 input）。value/onChange 由呼叫端綁定其 fields/set。 */
export function gridInput(o: GridInputOpts): React.ReactElement {
    return (
        <input
            className="cbdb-historical-text"
            type={o.type ?? 'text'}
            name={o.name}
            id={o.name}
            value={o.value}
            disabled={o.disabled || o.readonly}
            readOnly={o.readonly}
            placeholder={o.placeholder}
            maxLength={o.maxLength}
            onChange={(e) => o.onChange(e.target.value)}
            style={{ ...gInputStyle, ...(o.readonly || o.disabled ? gReadonlyStyle : {}), ...(o.highlight ? { background: 'var(--highlight)', color: 'var(--highlight-foreground)' } : {}) }}
        />
    );
}

/** 區塊分組：留白 + 藍系標題列；內部欄位走一致響應式網格。 */
export function GridSection({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div style={gridSectionStyle}>
            <div style={gridSectionHeadStyle}>{title}</div>
            {children}
        </div>
    );
}
