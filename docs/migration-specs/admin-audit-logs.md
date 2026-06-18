# Fidelity Spec：admin/audit-logs（P1-1 參考頁）

> 遷移前快照 + 遷移後 parity 對照。舊頁 = `admin.audit-logs`（Blade，dashboard-v3）；
> 新頁 = `app.admin.audit-logs`（Inertia，DashboardLayout）。兩者並存，側邊欄指向由
> `config/migration_flags.php` 的 `admin.audit-logs` flag 控制（預設 old）。

## 授權 / 邊界
| 項目 | 舊頁 | 新頁 | parity |
|---|---|---|---|
| 授權 | `canViewAuditLogs()` 否則 403 | 同（共用 `guard()`） | ✅ |
| 無 audit_log 表 | 404 | 同 | ✅ |
| 歷史脈絡 c_personid/history_page | 支援 | 同（保留參數於 reload） | ✅ |

## 篩選列（欄位順序與型別保留）
search（文字，placeholder `operation_id / row_pk_text / table_name`）｜table_name（select，含「全部」）｜operation（select INSERT/UPDATE/DELETE）｜actor_type（select）｜actor_id（文字）｜搜尋鈕｜清除鈕（有篩選時才顯示）。

- 舊頁：GET 表單整頁送出。新頁：Enter 或搜尋鈕 → Inertia partial reload（`only`），URL 同步（可分享連結，對齊 `withQueryString`）。**這是刻意採新特性**（partial reload 取代整頁送出）。

## 表格欄位（順序一致）
`#`｜時間｜表｜操作（badge：DELETE 紅 / INSERT 綠 / UPDATE 黃）｜actor（type + id 小字）｜PK（換行多段，monospace）｜operation_id（monospace）｜資料（diff / old_data / new_data 三鈕，無資料則 disabled）。

- 排序：新頁支援點欄位排序（id/occurred_at/table_name/operation/actor_type 白名單），舊頁固定 occurred_at desc。**刻意採新特性**（可排序），預設仍 occurred_at desc 與舊頁一致。
- 時間：舊頁用 `window.formatTimestamp` 客戶端轉時區；新頁以 `occurred_at_iso`（UTC）於前端 `toLocaleString()` 顯示，title 帶 ISO。**偏離**：未複用 Blade 世界的 `formatTimestamp`（不在 inertia bundle）；以瀏覽器本地時區呈現，read-only 日誌可接受。
- diff/old/new：舊頁為 Bootstrap modal；新頁為 Radix Modal（focus trap/Esc），diff 以 field/before/after 表呈現（後端 `prepareRow` 已算好，重用既有邏輯與 `CompositePrimaryKey` 解析）。

## 空 / 載入 / 摘要狀態
- 摘要 alert：`audit_summary`（total/first/last）保留於表格上方。
- 空資料：`audit_no_records`（DataTable empty）。
- 載入：partial reload 期間 DataTable 顯示 `common.loading` 疊層（舊頁無此態）。

## 匯出（新增能力）
DataTable 提供 CSV / 列印（舊頁無）。**注意**：伺服器端分頁下僅匯出目前頁；整資料集匯出未實作（plan 已標為自建缺口）。

## 偏離決策登記
1. 整頁 GET → Inertia partial reload（採新特性）。
2. 可排序欄位（採新特性；預設排序與舊頁一致）。
3. 時區顯示改用瀏覽器本地 `toLocaleString`（成本取捨；read-only 日誌）。
4. 新增 CSV/列印匯出（功能增強；僅目前頁）。
5. diff 表由舊版 4 欄（field/before/after/current）改為 3 欄（field/before/after）：舊版 `current` 欄恆為 `audit_current_unknown`（從未實際計算當前值），故移除不損失資訊（合理化）。

## parity 檢查清單（review 對照）
- [x] 授權 403 / 404 行為一致（測試覆蓋）
- [x] 篩選欄位順序、型別、選項一致
- [x] 表格欄位順序一致、操作 badge 色一致
- [x] PK 多段描述、operation_id monospace
- [x] diff/old/new 內容與舊頁一致（後端共用邏輯）
- [x] 摘要/空狀態文案沿用同翻譯 key
- [x] 歷史脈絡參數保留
- [x] i18n：admin 群組以 page_translations 傳入；zh-TW/en 同步
- [x] feature flag 預設 old；側邊欄 audit-logs 節點依 flag 指向
