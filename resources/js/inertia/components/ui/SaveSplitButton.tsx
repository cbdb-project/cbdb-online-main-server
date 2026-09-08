import React, { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from '../../hooks/useTranslation';
import { cn } from '../../lib/utils';
import { buttonVariants } from './Button';
import { gPrimaryBtn } from '../PersonEditorShared/grid';

/**
 * 表單送出的 split button：主按鈕「直接保存」，旁邊的小箭頭展開選單才有「提交提案」。
 *
 * 取代原本「直接保存／提交建議」兩顆並排＋有直接寫入權者按提案要再過一次確認彈窗的設計：
 * 提案要點兩次（箭頭→選單項）就足以防誤觸，不必再多一個對話框。
 *
 * 四種形態（由呼叫端的授權旗標決定，元件不自己判斷角色）：
 * - `isResubmit`：修改提案模式，只有單一「更新提案」按鈕（後端亦強制 proposal）。
 * - `directAvailable && proposalAvailable`：split button（主按鈕直接保存＋箭頭選單提案）。
 * - 只 `directAvailable`：單一「直接保存」（例：Codes 頁對訪客仍顯示送出鈕，由後端擋）。
 * - 只 `proposalAvailable`：單一「提交提案」主按鈕，與過去只可提案者的體驗一致。
 *
 * `disabled` 同時作用於主按鈕與箭頭（儲存中／未變更時選單也不該打得開）。
 * 鍵盤：箭頭鈕 ArrowDown／ArrowUp 開啟並聚焦第一項；選單內 Esc 關閉並把焦點還給箭頭鈕，
 * 點擊元件外部亦關閉。`aria-haspopup="menu"`／`aria-expanded`／`role="menu"`／`role="menuitem"`。
 *
 * 外觀對齊 Gmail 的「傳送 ▾」：展開後在正下方貼齊出現另一顆同色同風格的「提交提案」按鈕
 * （寬度等於主按鈕＋箭頭，兩者之間零間距、相接處收直角），箭頭開啟時翻上、再按一次收起。
 * 說明字放在 title（tooltip）。它與箭頭鈕的 aria-label 走 shared `common` 翻譯群組，呼叫端只需傳主要標籤。
 */
export interface SaveSplitButtonProps {
    /** 可直接寫入：主按鈕為「直接保存」。 */
    directAvailable: boolean;
    /** 可提案：有 direct 時進選單，否則自己當主按鈕。 */
    proposalAvailable: boolean;
    /** 修改提案模式：只剩單一「更新提案」。 */
    isResubmit?: boolean;
    disabled?: boolean;
    /** 儲存中：主按鈕顯示轉圈＋「儲存中…」。 */
    saving?: boolean;
    onSaveDirect?: () => void;
    onSubmitProposal: () => void;
    /**
     * 直接保存主按鈕的 type。預設 'button'；Codes 頁靠原生 form submit 走直接保存
     * （含拼音 ü 確認閘），傳 'submit' 並省略 onSaveDirect。
     */
    saveDirectType?: 'button' | 'submit';
    labels: {
        saveDirect: string;
        submitProposal: string;
        /** 修改提案模式的主按鈕文案；缺省沿用 submitProposal。 */
        resubmitProposal?: string;
        saving?: string;
    };
    /**
     * 'grid'：人物編輯器的 inline style（PersonEditorShared/grid）；'ui'：Tailwind Button 樣式。
     * TODO：兩套按鈕樣式（字級、字重、高度）本來就不一致，統一時應整頁一起改（grid.ts 對齊 Tailwind Button
     * 或反之），屆時此參數可移除。見 CHANGELOG 2026-09「表單送出改為 split button」的已知限制。
     */
    appearance?: 'grid' | 'ui';
    /** 掛在最外層容器，供 e2e／樣式定位。 */
    'data-testid'?: string;
}

export function SaveSplitButton({
    directAvailable,
    proposalAvailable,
    isResubmit = false,
    disabled = false,
    saving = false,
    onSaveDirect,
    onSubmitProposal,
    saveDirectType = 'button',
    labels,
    appearance = 'ui',
    'data-testid': testId,
}: SaveSplitButtonProps) {
    const tc = useTranslation('common');
    const [open, setOpen] = useState(false);
    const wrapRef = useRef<HTMLDivElement>(null);
    const toggleRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const menuId = useId();

    const showDirect = directAvailable && !isResubmit;
    const showProposal = proposalAvailable || isResubmit;
    const isSplit = showDirect && showProposal;

    // 不可用時（儲存中／未變更）選單一併收起，避免主按鈕灰掉但選單還開著。
    useEffect(() => {
        if (disabled || !isSplit) setOpen(false);
    }, [disabled, isSplit]);

    // 點擊元件外部關閉。
    useEffect(() => {
        if (!open) return;
        const onDocMouseDown = (e: MouseEvent) => {
            if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', onDocMouseDown);
        return () => document.removeEventListener('mousedown', onDocMouseDown);
    }, [open]);

    // 開啟後把焦點移到第一個選單項，鍵盤使用者不必再按 Tab。
    useEffect(() => {
        if (!open) return;
        const first = menuRef.current?.querySelector<HTMLElement>('[role="menuitem"]');
        first?.focus();
    }, [open]);

    if (!showDirect && !showProposal) return null;

    const savingLabel = labels.saving ?? tc('saving');
    const busyContent = (
        <>
            <i className="fas fa-spinner fa-spin" aria-hidden="true" style={{ marginRight: 6 }} />
            {savingLabel}
        </>
    );

    const closeAndFocusToggle = () => {
        setOpen(false);
        toggleRef.current?.focus();
    };

    const onToggleKeyDown = (e: React.KeyboardEvent<HTMLButtonElement>) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!disabled) setOpen(true);
        }
    };

    const onMenuKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            closeAndFocusToggle();
            return;
        }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const items = Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);
            if (items.length === 0) return;
            const idx = items.indexOf(document.activeElement as HTMLElement);
            const next = e.key === 'ArrowDown'
                ? items[(idx + 1) % items.length]
                : items[(idx - 1 + items.length) % items.length];
            next?.focus();
            return;
        }
        if (e.key === 'Tab') setOpen(false);
    };

    const pickProposal = () => {
        setOpen(false);
        onSubmitProposal();
    };

    // ---- 樣式：兩套外觀，共用行為。 ----
    const grid = appearance === 'grid';
    // split 時主按鈕加寬、箭頭鈕收窄：主要動作要好按，箭頭只是次要入口，窄一點才不會誤觸。
    // 展開時主按鈕與箭頭的下緣收直角，讓下方貼齊出現的提案鈕看起來是同一組。
    const mainStyle: React.CSSProperties | undefined = grid
        ? { ...gPrimaryBtn, ...(isSplit ? { borderTopRightRadius: 0, borderBottomRightRadius: 0, minWidth: 128, paddingLeft: 24, paddingRight: 24 } : {}), ...(open ? { borderBottomLeftRadius: 0 } : {}) }
        : undefined;
    const toggleStyle: React.CSSProperties | undefined = grid
        ? { ...gPrimaryBtn, borderTopLeftRadius: 0, borderBottomLeftRadius: 0, padding: '8px 7px', minWidth: 0, borderLeft: '1px solid rgba(255,255,255,0.35)' }
        : undefined;
    const mainClass = grid ? undefined : cn(buttonVariants({ variant: 'default' }), isSplit && 'rounded-r-none min-w-[7rem] px-6', open && 'rounded-bl-none');
    const toggleClass = grid ? undefined : cn(buttonVariants({ variant: 'default' }), 'rounded-l-none border-l border-primary-foreground/30 px-1.5');
    // 展開的提案鈕：與主按鈕同色同風格、同寬（撐滿主按鈕＋箭頭），上緣收直角與上方相接，只留 1px 分隔線。
    const menuItemStyle: React.CSSProperties | undefined = grid
        ? { ...gPrimaryBtn, width: '100%', borderTopLeftRadius: 0, borderTopRightRadius: 0, borderTop: '1px solid rgba(255,255,255,0.35)', boxShadow: '0 4px 12px rgba(0,0,0,0.18)', whiteSpace: 'nowrap' }
        : undefined;
    const menuItemClass = grid ? undefined : cn(buttonVariants({ variant: 'default' }), 'w-full rounded-t-none border-t border-primary-foreground/30 shadow-lg whitespace-nowrap');
    const disabledStyle: React.CSSProperties = disabled && grid ? { opacity: 0.5, cursor: 'not-allowed' } : {};

    // 單一按鈕：直接保存、只可提案、或修改提案模式。
    if (!isSplit) {
        const proposalOnly = !showDirect;
        const label = proposalOnly
            ? (isResubmit ? (labels.resubmitProposal ?? labels.submitProposal) : labels.submitProposal)
            : labels.saveDirect;
        return (
            <div ref={wrapRef} data-testid={testId} style={{ position: 'relative', display: 'inline-flex' }}>
                <button
                    type={proposalOnly ? 'button' : saveDirectType}
                    style={mainStyle ? { ...mainStyle, ...disabledStyle } : undefined}
                    className={mainClass}
                    disabled={disabled}
                    data-action={proposalOnly ? 'submit-proposal' : 'save-direct'}
                    onClick={proposalOnly ? onSubmitProposal : (saveDirectType === 'submit' ? undefined : onSaveDirect)}
                >
                    {saving ? busyContent : label}
                </button>
            </div>
        );
    }

    return (
        <div ref={wrapRef} data-testid={testId} style={{ position: 'relative', display: 'inline-flex' }}>
            <button
                type={saveDirectType}
                style={mainStyle ? { ...mainStyle, ...disabledStyle } : undefined}
                className={mainClass}
                disabled={disabled}
                data-action="save-direct"
                onClick={saveDirectType === 'submit' ? undefined : onSaveDirect}
            >
                {saving ? busyContent : labels.saveDirect}
            </button>
            <button
                ref={toggleRef}
                type="button"
                style={toggleStyle ? { ...toggleStyle, ...disabledStyle } : undefined}
                className={toggleClass}
                disabled={disabled}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                aria-label={tc('more_submit_options')}
                title={tc('more_submit_options')}
                data-action="toggle-submit-menu"
                onClick={() => setOpen((v) => !v)}
                onKeyDown={onToggleKeyDown}
            >
                {/* 箭頭隨開合翻轉（開啟朝上），再按一次收起。 */}
                <i className={cn('fas', open ? 'fa-caret-up' : 'fa-caret-down')} aria-hidden="true" />
            </button>
            {open ? (
                <div
                    ref={menuRef}
                    id={menuId}
                    role="menu"
                    aria-label={tc('more_submit_options')}
                    onKeyDown={onMenuKeyDown}
                    style={{ position: 'absolute', left: 0, right: 0, top: '100%', zIndex: 30 }}
                >
                    <button
                        type="button"
                        role="menuitem"
                        data-action="submit-proposal"
                        title={tc('proposal_menu_hint')}
                        onClick={pickProposal}
                        style={menuItemStyle}
                        className={menuItemClass}
                    >
                        <i className="fas fa-clipboard-check" aria-hidden="true" style={{ marginRight: 6 }} />
                        {labels.submitProposal}
                    </button>
                </div>
            ) : null}
        </div>
    );
}

export default SaveSplitButton;
