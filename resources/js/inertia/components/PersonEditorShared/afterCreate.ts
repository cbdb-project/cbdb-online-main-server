/**
 * #120：子資源「新增（create）」存檔成功後，不再跳回列表，而是導向剛建立記錄的 edit 頁，
 * 讓錄入者就地複查／續編（避免再次按存檔變成重複新增）。
 *
 * 設計：子資源的 create 頁與 edit 頁是「同一 pathname」，edit 僅多帶 PK query string
 * （見各 BasicInformationController@appXxxEditV2：以 $request->has(pkCols) 判斷 edit 模式，
 *   並用這些 query 欄查回該列）。因此以 `window.location.pathname + '?' + result.pk` 即可
 * 構出該新記錄的 edit URL，重載後由後端以正確的 edit 模式渲染（含稽核欄、刪除鈕、唯讀派生欄）。
 *
 * 僅在「direct 儲存且回應帶 result.pk（實體列已建立）」時導向 edit 頁；
 * proposal 模式只排入待核准 operation、尚無實體列可複查，維持回列表（fallback indexUrl）。
 */
export function redirectAfterSubresourceCreate(
    indexUrl: string,
    json: { result?: { pk?: Record<string, unknown> | null } } | null | undefined,
    isDirect: boolean,
): void {
    const pk = isDirect && json?.result?.pk && typeof json.result.pk === 'object' ? json.result.pk : null;
    if (pk && Object.keys(pk).length > 0) {
        const params = new URLSearchParams();
        for (const [k, v] of Object.entries(pk)) {
            // c_personid 已由路由 {id} 帶入，editv2 不檢查 query 的 c_personid，故略過避免冗餘。
            if (k === 'c_personid' || v === null || v === undefined) continue;
            params.set(k, String(v));
        }
        window.location.assign(`${window.location.pathname}?${params.toString()}`);
        return;
    }
    window.location.assign(indexUrl);
}
