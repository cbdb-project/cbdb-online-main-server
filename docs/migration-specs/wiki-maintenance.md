# Fidelity Spec：admin/wiki-maintenance（P5-9，Wiki 對照資料維護，含非同步導入）

> 舊頁 = `admin.wiki-maintenance`（Blade）；新頁 = `app.admin.wiki-maintenance`（Inertia）。flag = `admin.wiki-maintenance`。
> 授權：controller __construct 套 `auth` + `canRunBatchImport`（403）。

## 設計（列表 + 非同步導入）
`index()` 的查詢/統計/分頁建構抽出為 `buildWikiListing(Request): array`，Blade `index()` 與 `appIndex()` 共用；Blade 行為不變。
`appIndex()` 序列化每筆記錄（含與 Blade 相同的 `link` 組裝）+ sources（id/name/count）+ pagination + urls。

## 寫入/非同步端點安全
`deleteAll` / `reimport` / `importFromUrl` / `cancelImport` / `getImportProgress` / `executeImportTask` 及所有 private helper **完全未改動**。
React：
- 導入：`fetch` POST 至 `import-url`（JSON `import_url`+`target_source`，X-XSRF-TOKEN）→ 取回 task_id → 每 2 秒輪詢 `progress/{taskId}` → 進度條 + 取消；完成/錯誤/取消停止輪詢並 alert（完成則 router.reload 更新統計）。
- 取消：`fetch` POST `cancel/{taskId}`。
- 刪除全部：`router.post` delete-all（confirm）→ 後端 redirect back → Inertia 重載回 app 頁（同 shell）。

## 版面 parity
- 三來源統計卡（圖示/名稱/筆數，點選切換 source_id；當前高亮）。
- 導入卡：URL 輸入 + hint、目標來源（current）、導入按鈕、刪除全部按鈕、導入說明（HTML）、進度區（標題/進度條/訊息/task id/取消）。
- 記錄表 4 欄（c_personid→/sources 連結、c_name_chn、c_textid、c_pages→wiki 連結）；空狀態。
- 分頁（顯示區間文案 + page-2..page+2 視窗 + 上/下一頁停用態）。

## parity 檢查清單
- [x] 三來源卡 + 切換 + 高亮；統計筆數
- [x] 導入 fetch POST + task_id + 2 秒輪詢 + 進度條 + 取消；完成 reload
- [x] 刪除全部 confirm + router.post（redirect back，同 shell）
- [x] 記錄表 4 欄 + link 組裝（CJK rawurlencode）對齊 Blade；分頁視窗
- [x] 寫入/非同步端點全未改；index() 行為不變（buildWikiListing 抽取）
- [x] 授權 auth + canRunBatchImport（403）；route ->middleware('inertia')；nav flag-aware（預設 old）
- [x] i18n admin/common；flash 由 DashboardLayout 橋接
- [x] 測試 WikiMaintenanceInertiaTest（render+link 編碼 / 非 admin 403）；既有 Wiki 測試 9 綠
