# Fidelity Spec：dashboard（P1-4）


> 🗄️ **歷史存檔（2026-09-14）**：本文件是遷移當時的 fidelity spec，記錄的是「新舊頁逐項對比」的
> 驗收依據，**不是現況說明**。其中關於「flag 預設 old」「舊頁可安全回退」「URL 依 flag 解析」的
> 敘述都已過時——Blade 下架計畫環節 2 把人物編輯全套**實體刪除**，環節 3 把其餘 legacy 頁面**封路**
> （顯示頁 302／寫入端 410，封路 middleware **不讀 migration flag**，回退鍵是
> `LEGACY_PAGE_RETIREMENT=false`）。現況請看
> [BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md](../BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md)。
> 🔴 **本頁的 Blade 版已於環節 4a-3 實體刪除**（視圖與 controller 方法都不存在了），
> 所以它**沒有任何回退鍵**——上面提到的 `LEGACY_PAGE_RETIREMENT=false` 對本頁無作用。
> 舊 URI 只剩 302 導向 `/app` 對應頁。


> 舊頁 = `dashboard`（Blade）；新頁 = `app.dashboard`（Inertia）。flag = `dashboard`（預設 old）。
> 授權：route `auth` 中介層（任何已登入者）。

## 行為 / 統計
共用 `buildStats()`：6 個基礎計數（persons/altnames/offices/texts/users/operations）+
近期修改（daily/weekly/monthly，依使用者分組計數）+ 操作類型統計（近一月，op_type → 中文名）。
舊 Blade index() 改為呼叫 buildStats()，行為不變。

## 版面 parity
- 6 個 info-box（圖示 + 標籤 + 千分位數字），色彩對齊舊頁（persons 藍/altnames 綠/
  offices 黃/texts 深藍/users 灰/operations 紅）。
- 操作類型統計卡片區（每類型一格 count + 名稱）。
- daily/weekly/monthly 三張卡片表（submitted_by / op_count；空時顯示 no_data_yet）。
- 所有文案沿用 common.* 翻譯 key（shared，無需 page_translations）。

## 偏離決策
1. operationTypeStats 的類型名稱於後端以 __() 解析為字串（與舊頁一致），前端直接顯示。
2. 數字以 Intl.NumberFormat 千分位（對齊舊頁 number_format）。

## parity 檢查清單
- [x] 6 計數卡片數值/標籤/色彩
- [x] 操作類型統計
- [x] daily/weekly/monthly 表（欄位/空狀態）
- [x] 授權：guest 重導登入
- [x] i18n common 群組（shared）；無硬編碼中文
- [x] flag old；nav dashboard 節點 flag-aware
