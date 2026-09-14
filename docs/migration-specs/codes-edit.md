# Fidelity Spec：codes/edit（P2-4，含 update/destroy/propose-update write-path）


> 🗄️ **歷史存檔（2026-09-14）**：本文件是遷移當時的 fidelity spec，記錄的是「新舊頁逐項對比」的
> 驗收依據，**不是現況說明**。其中關於「flag 預設 old」「舊頁可安全回退」「URL 依 flag 解析」的
> 敘述都已過時——Blade 下架計畫環節 2 把人物編輯全套**實體刪除**，環節 3 把其餘 legacy 頁面**封路**
> （顯示頁 302／寫入端 410，封路 middleware **不讀 migration flag**，回退鍵是
> `LEGACY_PAGE_RETIREMENT=false`）。現況請看
> [BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md](../BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md)。

> 舊頁 = `codes.edit`/`codes.update`/`codes.destroy`/`codes.propose.update`。
> 新頁 = `app.codes.edit`/`app.codes.update`/`app.codes.destroy`/`app.codes.propose.update`。flag = `codes`。

## write-path 單一來源（重構）
`update()`/`destroy()`/`proposalUpdate()` 核心抽成 `performUpdate()`/`performDestroy()`/
`performProposalUpdate()`（僅參數化重導 route）；授權/唯讀/驗證/稽核/重複鍵/diff/提案記錄
邏輯完全共用。Blade 方法改薄包裝，行為不變（CodesControllerTest 53/53 綠）。

## 表單 parity
- appEdit 鏡像 edit()：唯讀守門、buildConditionsFromId（含 '-' 複合鍵回退）、找不到資料 →
  flash + redirect show、orderAuditFieldsForDisplay、buildCompositeId。
- React Edit：每欄受控輸入（PK 標示）、提案說明（can_propose）、直接儲存（PATCH update）/
  提交提案（propose.update）/刪除（ConfirmDialog + DELETE destroy）/取消（→ show）。
- 422 逐欄錯誤；更新成功 → app.codes.edit；刪除 → app.codes.show；提案 → app.codes.edit。

## 偏離決策
1. 整頁表單 → Inertia useForm（PATCH update）；old() 回填改受控 input。
2. 刪除由隱藏表單 → ConfirmDialog + router.delete。
3. show 頁 edit/destroy 連結模板改 flag-aware。

## parity 檢查清單
- [x] 編輯表單載入既有值（含複合鍵回退）
- [x] 直接更新 / 刪除 / 提交修改提案三路徑（write-path 共用 perform*）
- [x] 唯讀守門 / 找不到資料處理
- [x] guest/非 active 無法寫入（後端把關）
- [x] 422 / 重複鍵錯誤
- [x] i18n codes 群組；無硬編碼中文
- [x] 舊 Blade update/destroy/proposalUpdate 行為不變（53/53 綠）；flag old；show 連結 flag-aware
