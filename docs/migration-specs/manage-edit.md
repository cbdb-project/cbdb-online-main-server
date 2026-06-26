# Fidelity Spec：manage/edit（P5-3，使用者編輯）

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
