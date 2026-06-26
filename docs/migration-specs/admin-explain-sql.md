# Fidelity Spec：admin/explainsql（P1-3）

> 舊頁 = `admin.explainsql`（GET 顯示 / POST explain，Blade）；新頁 = `app.admin.explainsql`
> + `app.admin.explainsql.explain`（Inertia）。flag = `admin.explain-sql`（預設 old）。
> 授權：`canManageUsers()`（active admin）否則 403。

## 行為
- GET 顯示表單（SQL textarea + 說明文字）。
- 送出（POST）：`runExplain()` 共用——以 ReadOnlyTableQueryService 檢查唯讀 SQL，
  通過則 `EXPLAIN`，結果以表格呈現；不通過或 DB 錯誤則 error 紅框。
- 驗證：sql required（422 → form.errors.sql / session errors）。

## parity
| 項目 | 舊 | 新 | parity |
|---|---|---|---|
| 授權 403 | canManageUsers | 同（ensureAdmin 共用） | ✅ |
| 唯讀 SQL 白名單 | ReadOnlyTableQueryService | 同（runExplain 共用） | ✅ |
| 說明/標籤/按鈕文案 | admin.explain_sql_* | 同 key | ✅ |
| 結果表格欄位 = EXPLAIN 欄 | yes | yes | ✅ |
| 空結果提示 | explain_no_results | 同 | ✅ |
| 錯誤紅框 | error | 同 | ✅ |
| 驗證錯誤 | @error/old() | useForm errors（FormField） | ✅ 等價 |

## 偏離決策
1. POST 後不重導，改由 Inertia 重新 render 同元件帶回 results/error（Inertia 慣例）。
2. textarea 用受控 React input + useForm，取代 old() 回填。

## parity 檢查清單
- [x] 授權 403；唯讀白名單共用後端邏輯
- [x] 表單 / 結果表 / 空提示 / 錯誤框
- [x] sql required 驗證
- [x] i18n admin 群組 page_translations；無硬編碼中文
- [x] flag old；nav 節點 flag-aware
