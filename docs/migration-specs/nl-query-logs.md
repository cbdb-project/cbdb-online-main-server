# Fidelity Spec：query_playground/nl-query-logs（P1-6）

> 舊頁 = `query-playground.nl-query-logs`（Blade）；新頁 = `app.query-playground.nl-query-logs`
> （Inertia）。flag = `query-playground.nl-query-logs`（預設 old）。授權：Super Admin（403）。

## 行為 / 篩選
共用 `buildNlQueryLogsQuery()`：join users + search（question/generated_sql/user）+ success +
user_id 篩選，created_at desc，paginate 20。舊 Blade nlQueryLogs() 行為不變
（QueryPlaygroundTest 27/27 綠）。

## 版面（卡片列表）
每筆 log 一張卡片，邊框/狀態色依 success（綠/紅）：
- 卡頭：#id、使用者（name+email）、時間、執行毫秒、成功/失敗 badge。
- 內容：使用者問題；成功時 generated_sql（pre）+ explanation；失敗時 error_message。
- LLM 摘要 badges：model / 回合數 / total tokens（後端 parseLlmSummary 萃取，相容
  rounds / OpenAI / Gemini 格式）。
- llm_prompt、llm_response 兩段折疊（原文完整保留）。

## 偏離決策
1. 整頁 GET → Inertia partial reload（套用篩選非草稿）。
2. **LLM 回應「關鍵資訊」詳細逐回合/工具結果表格不在 React 重建（成本取捨）**：
   改為後端 parseLlmSummary 萃取 model/token/回合數摘要 badges；完整原文 JSON 仍於
   llm_response 折疊區提供（資訊不遺失）。原 Blade 的多供應商格式表格屬便利呈現。
3. 折疊區由 Bootstrap collapse → 原生 `<details>`。

## parity 檢查清單
- [x] 授權 403（非 super admin）
- [x] 篩選 search/success/user_id（順序/型別/選項）
- [x] 卡片：id/使用者/時間/毫秒/狀態 badge
- [x] 問題 / SQL / explanation / error_message（依 success 分支）
- [x] llm_prompt / llm_response 折疊（原文）
- [x] LLM 摘要（model/token/回合）
- [x] 返回 Playground 連結
- [x] 統計/空狀態文案沿用 query.* 翻譯 key
- [x] i18n query/operations 群組 page_translations；無硬編碼中文
- [x] flag old；nav 節點 flag-aware
