# Fidelity Spec：admin/cbdb-table-maintenance（P5-10，CBDB 內部表維護，含非同步重建）

> 舊頁 = `admin.cbdb-table-maintenance`（Blade）；新頁 = `app.admin.cbdb-table-maintenance`（Inertia）。flag = `admin.cbdb-table-maintenance`。
> 授權：controller __construct 套 `auth` + `canRunBatchImport`（403）。

## 設計（兩表卡 + 同步/非同步重建）
`index()` 的統計迴圈抽出為 `buildTableStats(): array`，Blade `index()` 與 `appIndex()` 共用；Blade 行為不變。
`appIndex()` 將 `$this->tables` 設定（name/name_chn/description/command/icon/color）合併 table_key/exists/count 序列化。

## 寫入/非同步端點安全
`rebuild` / `getNameFtsProgress` / `startNameFtsRebuildTask` / `executeNameFtsRebuildTask` / `runConsoleCommand` 等**完全未改動**。
React：
- 重建：`fetch` POST 至 `rebuild`（JSON：table_name + 視情況 truncate / id_from / id_to，X-XSRF-TOKEN）。
- CBDB__TRAD_SIMP_MAP（同步）：回 JSON success → alert + router.reload。
- CBDB__NAME_FTS（非同步）：回 task_id → 每 5 秒輪詢 `progress/{taskId}` → 進度條；completed → alert + reload；error → alert。

## 版面 parity
- 兩張卡（依 color 著色）：DB 表名 / 描述 / Artisan 指令 / 記錄數或「資料表不存在」狀態。
- TRAD_SIMP 表單：truncate 勾選（**預設勾選**）+ 重建。
- NAME_FTS 表單：truncate（**預設不勾**）+ 增量提示 + id_from/id_to + 進度區 + 重建。
- 說明面板（繁簡 / 姓名索引 / 危險提示，含 {!! !!} HTML）。
- 送出前 confirm（以 name_chn 代入 :name）。

## parity 檢查清單
- [x] 兩卡資訊 + 記錄數/不存在狀態
- [x] TRAD_SIMP truncate 預設勾選、同步 JSON → reload；NAME_FTS truncate 預設不勾 + id 範圍 + 5 秒輪詢進度條
- [x] fetch POST + X-XSRF-TOKEN；confirm(name_chn)；錯誤/網路/伺服器文案
- [x] 說明面板（含 HTML）
- [x] 寫入/非同步端點全未改；index() 行為不變（buildTableStats 抽取）
- [x] 授權 auth + canRunBatchImport（403）；route ->middleware('inertia')；nav flag-aware（預設 old）
- [x] i18n admin/common；flash 由 DashboardLayout 橋接
- [x] 測試 CbdbTableMaintenanceInertiaTest（render / 計數 / 非 admin 403）
