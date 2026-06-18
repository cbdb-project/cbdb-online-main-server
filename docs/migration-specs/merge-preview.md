# Fidelity Spec：merge-preview（P5-4，人物記錄合併預覽）

> 舊頁 = `merge-preview.index`（Blade，GET/POST，controller __construct 套 `auth`，內部 `isAdmin` 否則 redirect /home）；
> 新頁 = `app.merge-preview.index`（Inertia GET）。flag = `merge-preview`。

## 設計（純唯讀預覽，無寫入）
此工具僅產生「合併預覽」（人物摘要、欄位比對、關聯資料、SQL 預覽），**不執行任何資料庫寫入**；
實際合併由人工複製產生的 SQL 執行。因此無寫入端點需保護。
`index()` 的預覽建構（preview 陣列 + auto_arrange/merge_reason/form_*）抽出為 `buildMergePreview(Request): array`，
Blade `index()` 與 `appIndex()` 共用；兩者皆保留 `isAdmin → redirect('/home')` 權限檢查；Blade 行為不變。

## 表單參數
React 表單以 GET 送出（對齊 index 支援的查詢參數）：`primary_id`、`secondary_id`、`reason`（= merge_reason）、
`merge_to_min`（auto_arrange → 'true'/'false'）。`shouldPreview` 需 query 含 primary_id/secondary_id 且兩者非空。
複製連結按鈕重建同一可分享 URL（primary_id/secondary_id/merge_to_min/reason）。

## 版面 parity
- 表單：primary_id / secondary_id / merge_reason / auto_arrange 勾選 / 預覽 + 複製連結。
- 預覽：主/次人物表（ID 連結、姓名、性別[diff 紅]、朝代）；基本比對 alert（姓名/性別/朝代 same/different/unknown + 警告）；
  合併原因 alert；策略；欄位比對表（biog_columns，diff/姓名差異高亮，merged = merged_person ?? secondary ?? primary）；
  ALTNAME/KIN/ASSOC（4 鍵）/12 張其他資料表的主次內容摘要 + raw JSON；SQL 預覽 `<pre>`；id 調整提示；notes。

## parity 檢查清單
- [x] 表單 4 欄 + auto_arrange + 預覽/複製連結（GET 可分享 URL）
- [x] 主/次人物表、基本比對 alert、欄位比對表（diff/姓名紅字）
- [x] ALTNAME/KIN/ASSOC/其他 12 表內容摘要 + raw JSON；計數顯示
- [x] SQL 預覽 join；id_adjust_hint 條件（auto_min_target != primary_id）
- [x] isAdmin 權限（否則 redirect /home）於 index 與 appIndex 皆保留
- [x] buildMergePreview 抽取，Blade 行為不變；無寫入端點
- [x] route ->middleware('inertia')（__construct 已套 auth）；nav flag-aware（merge-preview flag，預設 old）
- [x] i18n admin/common；無硬編碼中文
- [x] 測試 MergePreviewInertiaTest（render 無預覽 / 非 admin redirect）；既有 MergePreview 測試 7 綠
