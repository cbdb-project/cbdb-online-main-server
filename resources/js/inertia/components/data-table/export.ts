/**
 * DataTable 匯出工具（CSV / 列印）。TanStack Table 無內建匯出，自建以對齊
 * 舊 DataTables 的匯出/列印按鈕功能（見遷移計畫決策 §二.2 / 風險登記）。
 *
 * ⚠️ 限制：伺服器端分頁下，這裡只能匯出「目前已載入的列」。若需匯出整個資料集，
 * 由各頁提供 fetchAllRows（呼叫伺服器匯出端點）再傳入 rows。
 */

export interface ExportColumn<T = unknown> {
    header: string;
    /** 從一列資料取出該欄位的純文字值。 */
    value: (row: T) => string | number | null | undefined;
}

/**
 * 防止試算表公式注入（CSV injection）：若值以 = + - @ 或 tab/CR 起頭，
 * Excel/Sheets 會當公式執行。前置單引號使其被視為純文字。
 */
function neutralizeFormula(s: string): string {
    if (s.length > 0 && /^[=+\-@\t\r]/.test(s)) {
        return "'" + s;
    }
    return s;
}

function escapeCsv(value: string | number | null | undefined): string {
    const raw = value === null || value === undefined ? '' : String(value);
    const s = neutralizeFormula(raw);
    if (/[",\n\r]/.test(s)) {
        return '"' + s.replace(/"/g, '""') + '"';
    }
    return s;
}

/** 產生 CSV 文字（含 UTF-8 BOM，Excel 可正確辨識中文）。 */
export function buildCsv<T>(columns: ExportColumn<T>[], rows: T[]): string {
    const header = columns.map((c) => escapeCsv(c.header)).join(',');
    const body = rows
        .map((row) => columns.map((c) => escapeCsv(c.value(row))).join(','))
        .join('\r\n');
    return '﻿' + header + '\r\n' + body;
}

/** 觸發瀏覽器下載 CSV。 */
export function downloadCsv<T>(filename: string, columns: ExportColumn<T>[], rows: T[]): void {
    const csv = buildCsv(columns, rows);
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename.endsWith('.csv') ? filename : `${filename}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

/** 開新視窗列印表格（簡單 HTML 表）。 */
export function printTable<T>(title: string, columns: ExportColumn<T>[], rows: T[]): void {
    const escapeHtml = (s: string) =>
        s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const head = columns.map((c) => `<th>${escapeHtml(c.header)}</th>`).join('');
    const body = rows
        .map(
            (row) =>
                '<tr>' +
                columns
                    .map((c) => {
                        const v = c.value(row);
                        return `<td>${escapeHtml(v === null || v === undefined ? '' : String(v))}</td>`;
                    })
                    .join('') +
                '</tr>'
        )
        .join('');
    const win = window.open('', '_blank');
    if (!win) {
        return;
    }
    win.document.write(
        `<!doctype html><html><head><title>${escapeHtml(title)}</title>` +
            '<style>table{border-collapse:collapse;width:100%;font-family:sans-serif;font-size:12px}' +
            'th,td{border:1px solid #999;padding:4px 8px;text-align:left}th{background:#eee}</style>' +
            `</head><body><h3>${escapeHtml(title)}</h3>` +
            `<table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>` +
            '</body></html>'
    );
    win.document.close();
    win.focus();
    win.print();
}
