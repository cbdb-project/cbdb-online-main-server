# Fidelity Spec：operations（P5-1，最近操作 / 提案核可）

> 舊頁 = `operations.index`（Blade，無 auth middleware，內部依 Auth::check 條件顯示）；新頁 = `app.operations.index`（Inertia）。flag = `operations`。
> 同一頁依 `proposals_only` 切換「最近操作」/「最近提案」兩模式（nav 兩個節點共用同一 flag）。

## 設計（index 重用）
`index()` 的查詢過濾與每列資料建構（resource_diff / audit_logs / affected_people / affected_person_ids）抽出為
`buildOperationsListing(Request): array`，Blade `index()` 與 `appIndex()` 共用，Blade 行為不變。
`appIndex()` 以 `serializeOperationRow($item, $codeTables)` 將每列攤平為 React 所需結構，完整對齊 Blade 每列的 `@php` 計算。

## 全域 helper 集中
`operations/index.blade.php` 原 `@include('biogmains.defense')` 才載入的 4 個全域函式
（`unionPKDef` / `unionPKDef_decode` / `unionPKDef_decode_for_convert` / `unionPKDef_for_url`）
移至自動載入的 `app/helpers.php`（以 `function_exists` 守衛，函式體與 defense.blade.php 逐字相同），
供 `serializeOperationRow` 共用；defense.blade.php 守衛重複定義時自動略過，行為一致。

## 寫入路徑安全
所有寫入端點（`operations.restore`、`operations.proposals.approve/reject`、`codes.proposals.edit/cancel`）**完全未改動**。
React 動作按鈕以 Inertia `router.post`/`router.delete` 對既有端點送出（CSRF 自動處理），處理後 redirect 回列表。

## 版面 parity
- 篩選列：修改人（editor）；提案模式為狀態多選（pending/approved/rejected/cancelled），一般模式為 op_type 多選（1-4）；篩選/清除。
- 表 7 欄：人物 / 修改資源（audit 表名合併或 resource）/ 資源定位（檢視頁連結 + 主鍵描述）/ 修改值 / 操作類型 / 修改人 / 修改時間（UTC→本地）。
- 多人物列（KIN_DATA/ASSOC_DATA 且 >1 人）以 rowspan 呈現，附 per-person 資源連結；主操作/關聯操作 badge。
- 修改值欄：提案狀態 badge + 提案/審核中繼資料；內容快照 Modal（過濾 `__` 欄位的鍵值表）；比對 Modal（有 audit logs 時逐筆 diff，否則 diff_source 四欄表）；備註 Modal；還原 / 編輯提案 / 撤回 / 核可 / 退回（含原因 Modal）動作，閘門對齊 Blade（`can_restore`/`can_edit_proposal`/`can_review_proposal`）。

## parity 檢查清單
- [x] 篩選（editor / op_type / status）+ history_context 隱藏參數透傳
- [x] 7 欄表 + 多人物 rowspan + per-person KIN/ASSOC 連結
- [x] 快照 / 比對（audit 逐筆 or diff_source）/ 備註 Modal
- [x] 提案 badge + 中繼資料 + 時間（submitted/reviewed/cancelled）
- [x] 動作閘門與 URL 對齊 Blade；寫入端點未改（router.post/delete）
- [x] unionPKDef* 移至 app/helpers.php（function_exists 守衛，逐字相同）
- [x] route ->middleware('inertia')（對齊舊頁無 auth）；nav 兩節點 flag-aware（operations flag）
- [x] i18n operations/codes/common；無硬編碼中文
- [x] 測試 OperationsInertiaTest（render / 序列化+diff / proposals 模式）；既有 Operations/Navigation 測試 189 綠
