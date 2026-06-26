# Fidelity Spec：manage/index（P5-2，使用者管理列表）

> 舊頁 = `manage.index`（Blade）；新頁 = `app.manage.index`（Inertia）。flag = `manage`（預設 old）。
> 授權：`isAdmin()` 否則 redirect /home（與舊頁同）。

## 設計
`index()` 與 `appIndex()` 共用 `buildUserListing(Request)`（有效用戶過濾 + 搜尋 + 排序白名單 +
分頁 + 近 7 天未激活用戶）。index() 行為不變（ManagePagesLoadTest 綠）。

## 版面 parity
- 未激活用戶警示面板（近 7 天，最多 15，含 ID/Name/Email/Institution/狀態/角色/編輯）。
- 主表：可排序欄（id/name/email/institution/is_active/is_admin，三角箭頭）+ 搜尋 + 清除 +
  每頁筆數下拉(10/25/50/75/100，onChange 送出) + 分頁(顯示 from-to/total)。
- 狀態 badge（已激活綠/未激活黃）、角色 badge、編輯連結（manage.edit，Blade；flag-aware）。
- 空資料：搜尋無結果 / 無用戶 兩種文案。

## 偏離決策
1. 整頁 GET → Inertia partial reload（保留 search/sort/per_page）。
2. 編輯連結 flag-aware（manage.edit 仍 Blade，P5-3 未遷移；Route::has 守門）。
3. 角色說明 alert（_role-descriptions）暫以角色 badge 呈現，未重建完整說明區塊——登記後續補強。

## parity 檢查清單
- [x] 未激活用戶面板
- [x] 可排序欄 + 搜尋 + 清除 + 每頁筆數 + 分頁
- [x] 狀態/角色 badge + 編輯連結
- [x] 空狀態雙文案
- [x] 授權 isAdmin → /home
- [x] i18n admin/nav 群組；無硬編碼中文
- [x] 舊 Blade index() 行為不變（12/12 綠）；flag old；nav 節點 flag-aware
- [ ] 角色說明完整區塊（後續補強）
