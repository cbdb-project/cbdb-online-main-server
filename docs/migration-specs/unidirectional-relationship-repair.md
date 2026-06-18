# Fidelity Spec：admin/unidirectional-relationship-repair（P5-11，單向關係修復）

> 舊頁 = `admin.unidirectional-relationship-repair`（Blade，表單頁）；新頁 = `app.admin.unidirectional-relationship-repair`（Inertia）。flag = `admin.unidirectional-relationship-repair`。
> 授權：controller __construct 套 `auth` + `canRunBatchImport`（403）。

## 設計（純表單頁）
`index()` 僅渲染含兩個 AJAX 表單（親屬 / 社會關係）的頁面，無資料建構。新增 `appIndex()` 渲染 Inertia，
傳遞兩個寫入端點 URL 與 page_translations；`index()` 與所有 repair 邏輯完全未改。

## 寫入路徑安全
`repairKinship` / `repairAssoc` 及其全部 helper（executeRepair/buildReverseRelation/...）為敏感寫入端點，**完全未改動**。
React 表單以 `fetch` POST 至既有 JSON 端點，CSRF 採 XSRF-TOKEN cookie → `X-XSRF-TOKEN` header（與 Profile/TokenManager 相同範式）。

## 版面 parity
- 親屬卡片：c_personid / c_kin_id / c_kin_code / new_c_kin_code（number，required）+ 修復 / 重置；送出前 confirm。
- 社會關係卡片：c_personid / c_assoc_id / c_assoc_code / new_c_assoc_code + 修復 / 重置；送出前 confirm。
- 結果：成功 → 綠色 alert（訊息 + original/created 的 c_personid/idField/codeField 明細），並清空表單；失敗/錯誤 → 紅色 alert（訊息，回退 network/server 文案）。
- 說明面板：功能 / 親屬 / 社會關係 / 注意事項 / 相關資料表（含 {!! !!} HTML 字串以 dangerouslySetInnerHTML 還原）。
- help-text 映射對齊 Blade（assoc personid → unidirect_assoc_personid_help；assoc_id → unidirect_kinship_kin_id_help；assoc_code → unidirect_kinship_kin_code_help）。

## parity 檢查清單
- [x] 兩表單欄位名與 required 對齊；送出前 confirm（kinship/assoc 各自文案）
- [x] fetch POST + X-XSRF-TOKEN；成功清空表單 + original/created 明細；失敗/錯誤 alert
- [x] 說明面板（含 HTML 字串）
- [x] 授權 auth + canRunBatchImport（403）；route ->middleware('inertia')
- [x] 寫入端點 repairKinship/repairAssoc 未改；index() 未改
- [x] i18n admin/common；無硬編碼中文；nav flag-aware（預設 old）
- [x] 測試 UnidirectionalRepairInertiaTest（render / 非 admin 403）
