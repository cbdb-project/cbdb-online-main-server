# Fidelity Spec：manage/edit（P5-3，使用者編輯）


> 🗄️ **歷史存檔（2026-09-14）**：本文件是遷移當時的 fidelity spec，記錄的是「新舊頁逐項對比」的
> 驗收依據，**不是現況說明**。其中關於「flag 預設 old」「舊頁可安全回退」「URL 依 flag 解析」的
> 敘述都已過時——Blade 下架計畫環節 2 把人物編輯全套**實體刪除**，環節 3 把其餘 legacy 頁面**封路**
> （顯示頁 302／寫入端 410，封路 middleware **不讀 migration flag**，回退鍵是
> `LEGACY_PAGE_RETIREMENT=false`）。現況請看
> [BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md](../BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md)。
> 🔴 **本頁的 Blade 版已實體刪除**（視圖與 controller 方法都不存在了），所以它**沒有任何
> 回退鍵**——而且 `LEGACY_PAGE_RETIREMENT` 這個開關本身也已於環節 4b-4c 連同封路 middleware
> 一併移除。舊 URI 只剩 302 導向 `/app` 對應頁（寫入端 410）。

> 舊頁 = `manage.edit`/`manage.update`（Blade）；新頁 = `app.manage.edit`/`app.manage.update`（Inertia）。
> flag = `manage`。授權：`canManageUsers()` 否則 redirect back。

## write-path 單一來源
`update()` 核心抽成 `performUserUpdate($req,$user,$indexRoute)`（delete_user 軟刪除分支 +
is_active/is_admin 驗證 in:0,1 / in:0,1,2,3 + save），僅參數化完成重導；Blade update() 行為不變
（ManagePagesLoadTest 12/12 綠）。appEdit/appUpdate 同 canManageUsers 閘門 + 找不到使用者重導。

## 表單 parity
- 使用者資訊（id/name/email/institution）。
- 帳號狀態 select（manage_activated_opt/manage_not_activated_opt）。
- 角色 select（general/expert/crowdsource/sysadmin = 0/1/2/3）。
- 儲存 + 取消 + 刪除使用者（ConfirmDialog → delete_user=1 軟刪除）。
- 422 錯誤逐欄；完成 → app.manage.index + flash。

## 偏離決策
1. 刪除使用者由 checkbox + JS 確認 → ConfirmDialog + 只送 delete_user=1（useForm.transform）。
2. 角色說明（_role-descriptions）暫以選項呈現，未重建完整說明（後續補強）。

## parity 檢查清單
- [x] 使用者資訊顯示
- [x] 帳號狀態 / 角色 select（值與 label 對齊舊頁 key）
- [x] 儲存（PATCH，驗證白名單）/ 取消 / 軟刪除
- [x] canManageUsers 閘門 + 找不到使用者重導
- [x] i18n admin 群組；無硬編碼中文（role label 以 key 對應）
- [x] 舊 Blade update() 行為不變（12/12 綠）；flag old；list 編輯連結 flag-aware
- [ ] 角色說明完整區塊（後續補強）
