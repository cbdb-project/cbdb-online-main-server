# Fidelity Spec：admin/batch-load-book-titles（P5-6，批次匯入書稿）

> 舊頁 = `admin.batch-load-book-titles[.store/.undo]`（Blade）；新頁 = `app.admin.batch-load-book-titles[.store/.undo]`（Inertia）。flag = `admin.batch-load-book-titles`。授權：`canRunBatchImport()` 否則 403。

## 設計（write/undo path 重用）
store()/undo() 完成後的重導改用 `listRouteName($request)`：請求路徑 `app/*` → app 列表，否則 Blade
列表。store/undo 的解析/驗證/交易插入/撤回邏輯完全不變（AdminBatchLoadBookTitlesTest 綠）。
appShowForm 讀取與 Blade 相同的 session（batch_results/batch_errors/batch_id/toast）。

## 版面 parity
- 說明 + batch_id alert + 批次錯誤 alert。
- entries textarea（tab 分隔）+ 送出 / 強制送出（ConfirmDialog）/ 清除重設。
- 結果表 11 欄（行/作者ID/書名/拼音/來源textid/朝代/類型/批次/created_by/created_date/新textid）。
- 複製 textid+書名按鈕、撤回批次（ConfirmDialog → POST undo）。
- toast（session('toast')）3 秒淡出。

## 偏離決策
1. 整頁 POST → Inertia useForm；強制送出/撤回的 confirm() → ConfirmDialog。
2. **結果表的拼音 inline 編輯（update-pinyin AJAX）暫未重建**——結果拼音以唯讀呈現；
   update-pinyin 端點保留（Blade 仍可用）。登記後續補強。
3. entries 行號編輯器（line-numbers gutter）未複刻（純視覺輔助）。

## parity 檢查清單
- [x] textarea + 送出/強制送出/清除
- [x] batch_id / 錯誤 alert / toast
- [x] 結果表 11 欄 + 複製 + 撤回
- [x] 授權 canRunBatchImport（403）
- [x] store/undo 重導依請求路徑（app vs Blade）
- [x] i18n admin 群組；無硬編碼中文（desc 含 HTML，與舊頁 {!! !!} 同信任邊界）
- [x] 舊 Blade store/undo 行為不變（AdminBatchLoadBookTitlesTest 綠）；flag old；nav flag-aware
- [ ] 拼音 inline 編輯 / 行號 gutter（後續補強）
