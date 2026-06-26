/** 讀取頁面 meta[name=csrf-token]，供 /api/v2 mutation 呼叫使用。 */
export function getCsrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}
