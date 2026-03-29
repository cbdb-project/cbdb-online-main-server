/**
 * 從 pk 物件產生穩定且無碰撞的 React key 字串。
 *
 * 使用 JSON.stringify 保留欄位名與型別資訊，
 * 確保不同 pk 值（含分隔符字元、null 與空字串）不會產生相同 key。
 */
export function stableKey(pk: Record<string, unknown>): string {
    return JSON.stringify(pk);
}
