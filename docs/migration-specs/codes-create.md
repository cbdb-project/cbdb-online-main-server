# Fidelity Spec：codes/create（P2-3，含 store/propose write-path）


> 🗄️ **歷史存檔（2026-09-14）**：本文件是遷移當時的 fidelity spec，記錄的是「新舊頁逐項對比」的
> 驗收依據，**不是現況說明**。其中關於「flag 預設 old」「舊頁可安全回退」「URL 依 flag 解析」的
> 敘述都已過時——Blade 下架計畫環節 2 把人物編輯全套**實體刪除**，環節 3 把其餘 legacy 頁面**封路**
> （顯示頁 302／寫入端 410，封路 middleware **不讀 migration flag**，回退鍵是
> `LEGACY_PAGE_RETIREMENT=false`）。現況請看
> [BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md](../BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md)。
> 🔴 **本頁的 Blade 版已實體刪除**（視圖與 controller 方法都不存在了），所以它**沒有任何
> 回退鍵**——而且 `LEGACY_PAGE_RETIREMENT` 這個開關本身也已於環節 4b-4c 連同封路 middleware
> 一併移除。舊 URI 只剩 302 導向 `/app` 對應頁（寫入端 410）。

> 舊頁 = `codes.create`（GET 表單）/`codes.store`（直接寫）/`codes.propose.store`（提案）。
> 新頁 = `app.codes.create`/`app.codes.store`/`app.codes.propose.store`（Inertia）。flag = `codes`。

## write-path 單一來源（重構）
`store()` 與 `proposalStore()` 的核心邏輯抽成 `performStore($req,$table,$showRoute,$editRoute)`
與 `performProposalStore($req,$table,$showRoute)`，僅將重導目標 route 名稱參數化；授權/驗證/
寫入/稽核/提案記錄邏輯完全共用。Blade store()/proposalStore() 改為薄包裝，行為不變
（CodesControllerTest + BooleanFilter 58/58 綠）。

## 表單 parity
- 每欄一個文字輸入（依 orderColumnsForCreate 排序）；首主鍵欄帶 guessNextKeyValue 預設。
- 提案說明 textarea（can_propose = 已登入且 active）。
- 兩個提交：直接儲存（→ app.codes.store）、提交提案（→ app.codes.propose.store）。
- 422 驗證錯誤逐欄渲染；缺主鍵 → 錯誤重導 back。
- 直接儲存成功 → redirect（editRoute=app.codes.show，edit 尚未遷移）；提案成功 → app.codes.show。
- 特定表欄位輔助說明（ADDR_BELONGS_DATA、TEXT_INSTANCE_DATA）以靜態文字 + 連結重建。

## 偏離決策
1. 整頁 POST → Inertia useForm；old() 回填改受控 input。
2. **直接儲存成功暫導向 app.codes.show**（舊版導向 codes.edit）；edit 頁 P2-4 遷移後改導向 app.codes.edit。
3. TEXT_INSTANCE_DATA 的「Load Data」AJAX 便利按鈕暫未重建（單表 niche；欄位可手動填）——登記後續補強。
4. 唯讀表 → flash + redirect app.codes.show。

## parity 檢查清單
- [x] 欄位逐一輸入 + 主鍵預設
- [x] 直接儲存 + 提案雙路徑（write-path 共用 performStore/performProposalStore）
- [x] 缺主鍵錯誤 / 422
- [x] 提案不直寫、記錄 operation
- [x] can_propose 閘門
- [x] i18n codes 群組；無硬編碼中文（特定表中文助語為原頁文案）
- [x] 舊 Blade store/proposalStore 行為不變（58/58 綠）；flag old；show 新增連結 flag-aware
- [ ] Load Data AJAX（TEXT_INSTANCE_DATA，後續補強）
