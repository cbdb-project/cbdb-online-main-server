# Fidelity Spec：admin/batch-load-social-institutes（P5-8，批次匯入社會機構）

> 舊頁 = `admin.batch-load-social-institutes[.store]`；新頁 = `app.*`。flag = `admin.batch-load-social-institutes`。
> 授權：`canRunBatchImport()`（403）。與 P5-6/P5-7 同模式。

## 設計（store 重用）
store() 成功/3 個錯誤重導改用 `listRouteName($request)`；`backWithErrors` 改收 `Request`（3 呼叫點更新）。
解析/驗證(類型/朝代/地址/來源/既有名)/交易插入不變（AdminBatchLoadSocialInstitutesTest 綠）。

## 版面 parity
- 說明 + 批次錯誤 alert + entries textarea + 送出 + 清除 + 新增筆數提示。
- 結果表 10 欄（行/機構名/名稱代碼/拼音/是否新建名稱(是/否)/機構代碼/類型(label / code)/朝代(label / code)/地址ID/來源textid）。

## parity 檢查清單
- [x] textarea + 送出 + 清除 + 新增筆數提示
- [x] 批次錯誤 alert
- [x] 結果表 10 欄（name_created → 是/否；type/dynasty label/code 合併）
- [x] 授權 canRunBatchImport（403）
- [x] store 重導依請求路徑（app vs Blade）
- [x] i18n admin 群組；desc HTML 同舊頁；無硬編碼中文
- [x] 舊 Blade store 行為不變（AdminBatchLoadSocialInstitutesTest 綠）；flag old；nav flag-aware
