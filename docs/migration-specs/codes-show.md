# Fidelity Spec：codes/show（P2-2，Phase 2 最重頁）


> 🗄️ **歷史存檔（2026-09-14）**：本文件是遷移當時的 fidelity spec，記錄的是「新舊頁逐項對比」的
> 驗收依據，**不是現況說明**。其中關於「flag 預設 old」「舊頁可安全回退」「URL 依 flag 解析」的
> 敘述都已過時——Blade 下架計畫環節 2 把人物編輯全套**實體刪除**，環節 3 把其餘 legacy 頁面**封路**
> （顯示頁 302／寫入端 410，封路 middleware **不讀 migration flag**，回退鍵是
> `LEGACY_PAGE_RETIREMENT=false`）。現況請看
> [BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md](../BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md)。

> 舊頁 = `codes.show`（Blade）；新頁 = `app.codes.show`（Inertia）。flag = `codes`（群組共用，預設 old）。
> public 路由（與舊頁同）。

## 重構（單一來源）
`show()` 與 `showWithCursorPagination()` 的 props 組裝抽成 `buildShowPayload()`（標準）+
`buildCursorPayload()`（游標），回傳陣列；`show()`（Blade，array_merge 頁頭後 view()）與
`appShow()`（Inertia，序列化分頁）共用。所有查詢/篩選/排序/布林 AST/游標邏輯不變，仍在原 helper。
**舊 Blade show 行為不變**（CodesControllerTest + BooleanFilterIntegration + OfficeCodesExport 全綠，83/83）。

## 版面 parity（伺服器端全部運算）
- 工具列：搜尋（輸入+鈕+reset）、套用篩選、清除篩選、新增（can_edit）、匯出（exportable）、
  進階布林篩選開關（boolean_filter_available 且非游標）。
- 動態欄位 thead：可排序三態（asc→desc→取消）、JOIN 欄加括號、PK badge。
- 逐欄篩選輸入列（非游標）：布林模式下錯誤欄紅框 + 錯誤訊息；Enter 套用。
- 布林模式：語法範例、filter_errors 清單、filter_descriptions。
- 列：c_dy 朝代名映射；操作（編輯連結 + 刪除 ConfirmDialog）當 can_edit。
- 分頁：標準 offset（Pagination）或游標（prev/next + ID 範圍）依 use_cursor。

## 偏離決策
1. 整頁 GET 表單 → Inertia partial reload（保留 search/filters/sort/filter_bool；游標 after/before）。
2. 刪除 window.confirm/隱藏表單 → ConfirmDialog + router.delete。
3. create/edit/destroy 連結為 flag-aware 模板（新版 P2-3/P2-4 就緒前回退 Blade 路徑）。
4. 游標「跳至 ID」輸入框暫未重建（prev/next + ID 範圍已具）；游標表為單一大表 NAME_FTS 邊緣案例，登記為後續補強。
5. copyright_note 以 dangerouslySetInnerHTML 呈現（與舊頁 {!! !!} 同信任邊界，內容為伺服器設定字串）。

## parity 檢查清單
- [x] 動態欄位 + 排序三態 + PK/JOIN 標示
- [x] 搜尋 + 逐欄篩選 + 清除（伺服器端）
- [x] 布林模式開關 + 錯誤/說明顯示
- [x] 標準/游標分頁
- [x] c_dy 朝代映射
- [x] 編輯/刪除/新增/匯出（can_edit/exportable 閘門）
- [x] 404（guardTable）
- [x] i18n codes 群組 page_translations；nav/common shared；無硬編碼中文
- [x] 舊 Blade show() 未改行為（83/83 綠）；flag old；nav 節點 flag-aware
- [ ] 游標「跳至 ID」輸入（後續補強，已登記）
