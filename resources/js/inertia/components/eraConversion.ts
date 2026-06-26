/**
 * 西元 ↔ 年號 轉換邏輯。逐一移植自 legacy resources/js/app.js 的 initEraConversion 系列函式
 * （getNianhaoData / findNianhaoIdByNameAndYear / findNianhaoIdByNameFallback /
 *  convertNianhaoIdToYear + 特殊映射），供 React EraTimeField 使用。行為須與舊版一致。
 */
import { convertYear } from 'cn-era';

export interface NianhaoRecord {
    c_nianhao_id: number | string;
    c_nianhao_chn?: string;
    c_str?: string;
    [k: string]: unknown;
}

export interface EraResult {
    dynasty: number;
    dynasty_name: string;
    reign_title: string;
    year: number; // 年號第幾年
    year_num: number | string;
}

// 直接 ID 映射（CBDB 年號記錄 ID）：與 app.js 一致。
const SPECIAL_ID_MAPPING: Record<string, number> = {
    '至元 (世祖)': 623,
    '至元 (順帝)': 635,
};
// 名稱映射（cn-era 名 → CBDB 名）。
const SPECIAL_NAME_MAPPING: Record<string, string> = {
    民國: '中華民國',
};

let nianhaoDataCache: NianhaoRecord[] | null = null;

/** 取年號資料（/api/select/nianhao，含 c_str 年份範圍），帶快取。 */
export async function getNianhaoData(): Promise<NianhaoRecord[]> {
    if (nianhaoDataCache) {
        return nianhaoDataCache;
    }
    const res = await fetch('/api/select/nianhao', {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!res.ok) {
        throw new Error('載入年號資料失敗');
    }
    const data = await res.json();
    nianhaoDataCache = Array.isArray(data) ? data : (data?.data ?? []);
    return nianhaoDataCache!;
}

function parseRange(cStr?: string): [number, number] | null {
    if (!cStr) return null;
    const m = cStr.match(/\[(-?\d+)\]~\[(-?\d+)\]/);
    if (!m) return null;
    return [parseInt(m[1], 10), parseInt(m[2], 10)];
}

/**
 * 西元 → 年號：以年號名 + 西元年（年份範圍精確比對）找 c_nianhao_id。對齊 app.js
 * findNianhaoIdByNameAndYear（含特殊 ID/名稱映射、c_str 範圍 + 年數驗證）。
 */
export async function findNianhaoIdByNameAndYear(
    reignTitle: string,
    gregorianYear: number,
    yearNum: number,
): Promise<number | string | null> {
    if (SPECIAL_ID_MAPPING[reignTitle]) {
        return SPECIAL_ID_MAPPING[reignTitle];
    }
    const searchTitle = SPECIAL_NAME_MAPPING[reignTitle] || reignTitle;
    const data = await getNianhaoData();
    const candidates = data.filter((it) => it.c_nianhao_chn === searchTitle);
    if (candidates.length === 0) return null;

    for (const it of candidates) {
        const range = parseRange(it.c_str);
        if (!range) continue;
        const [firstYear, lastYear] = range;
        if (gregorianYear >= firstYear && gregorianYear <= lastYear) {
            const calculatedYearNum = gregorianYear - firstYear + 1;
            if (calculatedYearNum === yearNum) {
                return it.c_nianhao_id;
            }
        }
    }
    return null;
}

/** 模糊匹配（降級）：對齊 app.js findNianhaoIdByNameFallback。 */
export async function findNianhaoIdByNameFallback(
    reignTitle: string,
    gregorianYear: number,
): Promise<{ found: boolean; id: number | string | null; dbInfo: string }> {
    if (SPECIAL_ID_MAPPING[reignTitle]) {
        return { found: true, id: SPECIAL_ID_MAPPING[reignTitle], dbInfo: `${reignTitle} (特殊映射)` };
    }
    const searchTitle = SPECIAL_NAME_MAPPING[reignTitle] || reignTitle;
    const data = await getNianhaoData();
    const candidates = data.filter((it) => it.c_nianhao_chn === searchTitle);
    if (candidates.length === 0) return { found: false, id: null, dbInfo: '' };
    if (candidates.length === 1) {
        return { found: true, id: candidates[0].c_nianhao_id, dbInfo: `${candidates[0].c_nianhao_chn} ${candidates[0].c_str}` };
    }
    let best: NianhaoRecord | null = null;
    let minDist = Infinity;
    for (const it of candidates) {
        const range = parseRange(it.c_str);
        if (!range) continue;
        const [firstYear, lastYear] = range;
        let dist: number;
        if (gregorianYear >= firstYear && gregorianYear <= lastYear) dist = 0;
        else if (gregorianYear < firstYear) dist = firstYear - gregorianYear;
        else dist = gregorianYear - lastYear;
        if (dist < minDist) {
            minDist = dist;
            best = it;
        }
    }
    if (best) {
        return { found: true, id: best.c_nianhao_id, dbInfo: `${best.c_nianhao_chn} ${best.c_str} (距離: ${minDist === 0 ? '範圍內' : minDist + '年'})` };
    }
    return { found: false, id: null, dbInfo: '' };
}

/**
 * 年號 → 西元：依年號 ID + 年數，從 c_str 範圍算西元年。對齊 app.js convertNianhaoIdToYear。
 */
export async function convertNianhaoIdToYear(
    nianhaoId: number | string,
    yearNum: number,
): Promise<{ success: boolean; year?: number; nianhaoName?: string; message?: string }> {
    const data = await getNianhaoData();
    const rec = data.find((it) => String(it.c_nianhao_id) === String(nianhaoId));
    if (!rec) return { success: false, message: `找不到年號 ID：${nianhaoId}` };
    const range = parseRange(rec.c_str);
    if (!range) {
        return { success: false, message: `年號 ${rec.c_nianhao_chn} 的年份範圍格式錯誤` };
    }
    const [firstYear, lastYear] = range;
    const duration = lastYear - firstYear + 1;
    if (yearNum < 1 || yearNum > duration) {
        return { success: false, message: `年號 ${rec.c_nianhao_chn} 的年數應在 1-${duration} 之間（西元 ${firstYear}-${lastYear}）` };
    }
    return { success: true, year: firstYear + yearNum - 1, nianhaoName: rec.c_nianhao_chn };
}

/**
 * 西元 → 年號候選：用 cn-era convertYear 取所有年號，若有朝代則優先過濾。對齊 app.js
 * era-convert-btn 的處理（多結果交由呼叫端以 dialog 選）。回傳候選陣列。
 */
export function gregorianToReignCandidates(year: number, dynastyCode?: number | null): EraResult[] {
    const all = (convertYear(year, { mode: 'all' }) as EraResult[]) || [];
    if (!all.length) return [];
    if (dynastyCode && !Number.isNaN(dynastyCode) && dynastyCode > 0) {
        const filtered = all.filter((r) => r.dynasty === dynastyCode);
        if (filtered.length > 0) return filtered;
    }
    return all;
}

/** 重置年號快取（測試用）。 */
export function __resetNianhaoCache(): void {
    nianhaoDataCache = null;
}
