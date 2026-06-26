# Fidelity Spec：crowdsourcing（P5-5，最近眾包錄入記錄）

> 舊頁 = `crowdsourcing.index`（Blade，superadmin）；新頁 = `app.crowdsourcing.index`（Inertia）。flag = `crowdsourcing`。
> 授權：`superadmin` middleware。

## 設計（index 重用）
`index()` 每列 `resource_diff` 建構邏輯抽出為 `buildCrowdsourcingLists()`，Blade `index()` 與 `appIndex()` 共用；
Blade 路徑行為不變（仍 `view('crowdsourcing.index', ...)`）。`appIndex()` 序列化每列為陣列 + 分頁 meta。

## 寫入路徑安全
`confirm($id)` / `reject($id)`（套用眾包編輯的敏感寫入）完全未改動。React 確認/退回按鈕以**整頁導覽**連到既有 Blade GET 路由
（`crowdsourcing/{id}/confirm`、`.../reject`），處理後 redirect back。此為刻意取捨：寫入流程留在 Blade，列表頁為 React。

## 版面 parity
- 說明文字（op_type_desc + status_desc）。
- 表 9 欄：modified_resource / modified_value（resource_data + compare 兩按鈕）/ resource_tts(resource_id) / operation_type / modified_by(user.name) / count(rate) / entry_time(UTC→本地) / status_label / actions。
- resource_data Modal：鍵值表。compare Modal：四欄 diff（field/before/after/current），`compare` 按鈕在無 diff 內容時 disabled。
- `appIndex()` 以與 Blade 完全相同的 `resource_diff ?? resource_original` 回退與 `hasDiff` 判斷計算 `has_diff`，並序列化 `resource_diff`（可能為字串回退）；React 以 `row.has_diff` 控制 disabled，DiffTable 支援字串回退渲染（與 Blade diff-table 字串分支對齊）。
- confirm/reject 連結僅在 `crowdsourcing_status == 2 && !isCrowdsourcingUser()` 顯示。
- 伺服器分頁（每頁 20，limit 100）。

## parity 檢查清單
- [x] 9 欄表 + 兩個 Modal（resource_data 鍵值 / compare 四欄 diff）
- [x] compare 無內容時 disabled（hasDiffContent）
- [x] confirm/reject gate（status==2 && !crowdsourcing user）→ 連既有 Blade GET 路由
- [x] 授權 superadmin middleware；route ->middleware('inertia')
- [x] i18n operations/codes 群組（page_translations）；nav 標題；無硬編碼中文
- [x] 舊 Blade index 行為不變（buildCrowdsourcingLists 抽取）；confirm/reject 未改
- [x] flag old；nav flag-aware（self::url('crowdsourcing', ...)）
- [x] 測試綠（CrowdsourcingInertiaTest：render / 序列化+diff+review flag / 非 superadmin 403）
