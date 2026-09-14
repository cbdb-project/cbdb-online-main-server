# Fidelity Spec：admin/batch-load-offices（P5-7，批次匯入官職）


> 🗄️ **歷史存檔（2026-09-14）**：本文件是遷移當時的 fidelity spec，記錄的是「新舊頁逐項對比」的
> 驗收依據，**不是現況說明**。其中關於「flag 預設 old」「舊頁可安全回退」「URL 依 flag 解析」的
> 敘述都已過時——Blade 下架計畫環節 2 把人物編輯全套**實體刪除**，環節 3 把其餘 legacy 頁面**封路**
> （顯示頁 302／寫入端 410，封路 middleware **不讀 migration flag**，回退鍵是
> `LEGACY_PAGE_RETIREMENT=false`）。現況請看
> [BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md](../BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md)。

> 舊頁 = `admin.batch-load-offices[.store]`；新頁 = `app.admin.batch-load-offices[.store]`。flag = `admin.batch-load-offices`。授權：`canRunBatchImport()`（403）。同 P5-6 模式但更簡（無 force/undo/batch_id/toast）。

## 設計（store 重用）
store() 完成/錯誤重導改用 `listRouteName($request)`（app/* → app；否則 Blade）；`backWithErrors`
改收 `Request` 以套用相同邏輯。解析/驗證(朝代/類型/來源)/交易插入不變（AdminBatchLoadOfficesTest 綠）。
appShowForm 讀同 session（results/batch_errors）。

## 版面 parity
- 說明 + 批次錯誤 alert + entries textarea + 送出 + 清除重設。
- 結果表 9 欄（行/官職ID/中文名/英文名/拼音/朝代(label / code)/類型/單位/來源textid）。

## 偏離決策
1. 整頁 POST → Inertia useForm。
2. 行號 gutter 未複刻（純視覺）。

## parity 檢查清單
- [x] textarea + 送出 + 清除
- [x] 批次錯誤 alert
- [x] 結果表 9 欄（朝代欄 label / code 合併）
- [x] 授權 canRunBatchImport（403）
- [x] store 重導依請求路徑（app vs Blade）
- [x] i18n admin 群組；desc HTML 同舊頁 {!! !!}；無硬編碼中文
- [x] 舊 Blade store 行為不變（AdminBatchLoadOfficesTest 綠）；flag old；nav flag-aware
