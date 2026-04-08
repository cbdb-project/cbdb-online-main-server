/**
 * 格式化年份區間為顯示字串。
 * 過濾哨兵值 0 和 -9999（CBDB 預設「未知」）。
 * 當 postCE 為 true（人物朝代在公元後），額外過濾所有負數年份。
 */
export function formatYearRange(first: number | null, last: number | null, postCE: boolean = false): string | null {
    const validFirst = isValidYear(first, postCE) ? first : null;
    const validLast = isValidYear(last, postCE) ? last : null;
    if (validFirst && validLast) return `${validFirst}–${validLast}`;
    if (validFirst) return `${validFirst}–`;
    if (validLast) return `–${validLast}`;
    return null;
}

function isValidYear(year: number | null, postCE: boolean): year is number {
    if (year == null || year === 0 || year === -9999) return false;
    if (postCE && year < 0) return false;
    return true;
}

/**
 * 格式化人物標籤（含 ID 與中英文名稱）。
 */
export function formatPersonLabel(id: number | null, nameChn: string | null, name: string | null): string | null {
    if (!id) return null;
    const parts: string[] = [];
    if (nameChn) parts.push(nameChn);
    if (name) parts.push(name);
    const label = parts.join(' / ') || String(id);
    return `[${id}] ${label}`;
}

/**
 * 組合中英文標籤，如「中文名 / English Name」。
 */
export function formatBilingualLabel(chn: string | null, eng: string | null): string | null {
    if (chn && eng) return `${chn} / ${eng}`;
    return chn || eng || null;
}
