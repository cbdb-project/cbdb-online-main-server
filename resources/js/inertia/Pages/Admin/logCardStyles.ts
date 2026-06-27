/**
 * 日誌卡片狀態視覺語言（NL Query Logs / AI Fill Logs 共用）。
 *
 * 設計目標：在長列表中「一眼」分辨每筆記錄的狀態（成功/失敗、已提交/未提交）。
 * 舊版 AdminLTE 以整條實色標題列達成可掃視性；此處改用更貼合 React 設計系統的
 * 三段式語言，既醒目又不刺眼，且支援深色模式：
 *   1. card   —— 四邊維持中性 border-border、僅左側 4px 強調條（border-l-4）染色，
 *               長列表縱向掃視的周邊視覺訊號；不再四邊上色，避免比全站其它元件更吵
 *   2. header —— 淡色標題帶，整列染色取代舊版實色 bar
 *   3. pill   —— 右側實色狀態徽章（含圖示），修正舊新版「灰底徽章兩態無區別」的問題
 *
 * tone 語意：
 *   success —— NL 查詢成功 / AI 結果已被使用者提交（醒目綠，代表「有效」記錄）
 *   danger  —— NL 查詢失敗，採全站 --destructive（Harvard 紅）語意 token，避免同頁兩種紅
 *   neutral —— AI 結果未提交（中性灰，與 success 形成強對比，讓已提交的記錄跳出來）
 */
export type LogTone = 'success' | 'danger' | 'neutral';

export interface LogToneClasses {
    /** <article> 外框：四邊細框 + 左側 4px 強調條 */
    card: string;
    /** 標題列背景帶 */
    header: string;
    /** 右側狀態徽章 */
    pill: string;
}

export const LOG_TONE: Record<LogTone, LogToneClasses> = {
    success: {
        card: 'border-border border-l-4 border-l-emerald-500',
        header: 'bg-emerald-50 dark:bg-emerald-950/40',
        pill: 'bg-emerald-700 text-white',
    },
    danger: {
        card: 'border-border border-l-4 border-l-destructive',
        header: 'bg-destructive/10 dark:bg-destructive/20',
        pill: 'bg-destructive text-destructive-foreground',
    },
    neutral: {
        card: 'border-border border-l-4 border-l-slate-300 dark:border-l-slate-600',
        header: 'bg-muted/40',
        pill: 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-100',
    },
};

/** <article> 外框共用基底（與 tone.card 併用）。 */
export const LOG_CARD_BASE = 'overflow-hidden rounded-lg border bg-card shadow-sm';

/** 標題列共用基底（與 tone.header 併用）。 */
export const LOG_HEADER_BASE =
    'flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border px-4 py-2.5 text-sm';

/** 狀態徽章共用基底（與 tone.pill 併用）。 */
export const LOG_PILL_BASE =
    'ml-auto inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold';
