# Fidelity Spec：admin/ai-fill-logs（P1-2）

> 舊頁 = `admin.ai-fill-logs`（Blade）；新頁 = `app.admin.ai-fill-logs`（Inertia，DashboardLayout）。
> flag = `admin.ai-fill-logs`（預設 old）。授權：僅 Super Admin（403）。

## 授權 / 篩選
| 項目 | 舊 | 新 | parity |
|---|---|---|---|
| 授權 | `isSuperAdmin()` 否則 403 | 同（共用 `guard()`） | ✅ |
| 篩選 search/user_id/category | GET 表單 | Inertia partial reload（套用篩選非草稿） | ✅ 行為等價，採新特性 |
| 排序 | created_at desc 固定 | 同 | ✅ |

## 版面（卡片列表，非表格）
每筆 log 一張卡片，邊框色依是否已提交（綠/藍）：
- 卡頭：`#id`、類別 badge（posting 藍 / assoc 青 / status 黃）、使用者（name + email）、人物連結（依類別指向 offices/assoc/statuses 子資源，新分頁）、時間、執行毫秒、已提交/未提交 badge。
- 內容：原始文本、匹配統計 badges（matched/suggested/not_found/empty）、路由資訊、比較按鈕（有 comparison_rows 時）、AI raw / AI matched / user_submitted 三段折疊 JSON。
- 比較 Modal：來源文本 + diff 表（field / AI 值（matched 綠 / suggested 黃） / 用戶值（matches 綠））。

## 後端預備（prepareLog）
comparison_rows 重用既有 buildComparisonRows/buildCodeComparisonRows（含 resolveCodeLabels 代碼中文名查詢）；JSON 以 prettyJson 美化；人物連結以相對 route 解析。

## 偏離決策登記
1. 整頁 GET → Inertia partial reload（採新特性）。
2. 折疊區由 Bootstrap collapse → 原生 `<details>`（合理化，零依賴）。
3. 比較 Modal 由 Bootstrap modal → Radix Modal（a11y）。
4. 時間直接顯示 created_at 字串（與舊頁卡頭一致，未做客戶端時區轉換）。
5. 折疊區標題省略字元數 `(ai_log_chars)` 標註；diff 表改以文字顏色標示（matched 綠/suggested 黃/matches 綠），不複刻舊版 badge、空值 `-` 佔位與整列底色（cosmetic 合理化，diff 資訊等價）。

## parity 檢查清單
- [x] 授權 403
- [x] 篩選欄位順序/型別/選項一致（search/user_id/category）
- [x] 卡片資訊欄位齊全（id/類別/使用者/人物連結/時間/毫秒/提交狀態）
- [x] 統計 badges、路由資訊、三段 JSON 折疊
- [x] 比較 diff（AI 值色彩、matches 綠）內容與舊頁一致（後端共用邏輯）
- [x] 摘要/空狀態文案沿用同翻譯 key
- [x] i18n：admin 群組 page_translations；無硬編碼中文
- [x] flag 預設 old；nav 節點 flag-aware
