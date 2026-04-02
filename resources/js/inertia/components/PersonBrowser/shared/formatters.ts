/**
 * 格式化年份區間為顯示字串。
 */
export function formatYearRange(first: number | null, last: number | null): string | null {
    if (first && last) return `${first}–${last}`;
    if (first) return `${first}–`;
    if (last) return `–${last}`;
    return null;
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
