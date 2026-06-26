# Fidelity Spec：codes/create（P2-3，含 store/propose write-path）

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
