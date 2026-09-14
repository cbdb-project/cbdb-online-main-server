/**
 * `Codes/Show` 的兩段純邏輯，抽出來讓它們測得到。
 *
 * ── 2026-09-15（Blade 下架環節 4b-2c-2）─────────────────────────
 * 這兩件事原本由 legacy Blade 在伺服器端做，所以 `CodesControllerTest` 可以用
 * `assertSee('/codes/TEXT_CODES/T001/edit')`／`assertSee('filters%5Bcode_sub%5D')` 直接驗。
 * 移植到 React 之後它們變成**前端**邏輯，伺服器端的測試只剩得到 prop 那一半
 * ⇒ 若只把測試改成斷言 prop，這兩個不變量就**沒有人守**（review 指出）。
 *
 * 所以抽成純函式並補上 vitest：
 *  - `buildRowId()`：單欄主鍵的 id 必須是 `T001`，不可被組成 `T001_._…`。
 *  - `buildShowParams()`：哪個互動帶「使用者正在編輯的值」、哪個帶「已送出／已套用的值」。
 *    對照依據是 `resources/views/codes/show.blade.php` 每個表單／連結實際送什麼，
 *    不是直覺——見 `Show.tsx` 裡 `visit()` 上方的表。
 */

import type { FormDataConvertible } from '@inertiajs/core';

export type Row = Record<string, unknown>;
/** 與 Show.tsx 一致：直接用 Inertia 的可序列化型別，避免兩邊各自定義而不相容。 */
export type Params = Record<string, FormDataConvertible>;

/**
 * 依主鍵欄組出列的 id（對齊 Blade 的 `implode('_._')`）。
 *
 * 沒有主鍵資訊時退回「前兩個非空欄」——這是 Blade 既有的退路，維持不變。
 */
export function buildRowId(row: Row, keyColumns: string[]): string {
    let parts: string[] = [];

    if (keyColumns.length) {
        parts = keyColumns.map((c) => String(row[c] ?? '')).filter((v) => v !== '');
    }

    if (!parts.length) {
        for (const v of Object.values(row)) {
            const s = String(v ?? '');
            if (s !== '') parts.push(s);
            if (parts.length >= 2) break;
        }
    }

    return parts.join('_._');
}

export interface ShowParamsInput {
    /** 這次導覽要帶的 filters：呼叫端明示是「輸入框的值」還是「已套用的值」。 */
    filters: Record<string, string>;
    /** 這次導覽要帶的 search：呼叫端明示是「輸入框的值」還是「已送出的值」。 */
    search: string;
    sortBy: string;
    sortDir: string;
    booleanEnabled: boolean;
    /** merge 進去的額外參數（page／after／before…）；`undefined` 代表刻意清掉。 */
    extra?: Params;
}

/** 組出 `router.get()` 要送的 query 參數。空值一律不送（與 Blade 的 `array_filter` 同義）。 */
export function buildShowParams(input: ShowParamsInput): Params {
    const params: Params = {};

    if (input.search) params.search = input.search;

    const applied = Object.fromEntries(Object.entries(input.filters).filter(([, v]) => v !== ''));
    if (Object.keys(applied).length) params.filters = applied;

    if (input.sortBy) {
        params.sort_by = input.sortBy;
        params.sort_dir = input.sortDir;
    }

    if (input.booleanEnabled) params.filter_bool = 1;

    Object.assign(params, input.extra ?? {});

    return params;
}
