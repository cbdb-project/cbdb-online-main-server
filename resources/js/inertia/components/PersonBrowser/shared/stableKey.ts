/**
 * 從 pk 物件產生穩定的 React key 字串。
 *
 * 將 pk 各欄位值以 "|" 連接，null 值轉為空字串，
 * 確保同一筆資料在重新渲染時保持相同的 key。
 */
export function stableKey(pk: Record<string, unknown>): string {
    return Object.values(pk)
        .map((v) => (v == null ? '' : String(v)))
        .join('|');
}
