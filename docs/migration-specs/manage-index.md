# Fidelity Spec：manage/index（P5-2，使用者管理列表）


> 🗄️ **歷史存檔（2026-09-14）**：本文件是遷移當時的 fidelity spec，記錄的是「新舊頁逐項對比」的
> 驗收依據，**不是現況說明**。其中關於「flag 預設 old」「舊頁可安全回退」「URL 依 flag 解析」的
> 敘述都已過時——Blade 下架計畫環節 2 把人物編輯全套**實體刪除**，環節 3 把其餘 legacy 頁面**封路**
> （顯示頁 302／寫入端 410，封路 middleware **不讀 migration flag**，回退鍵是
> `LEGACY_PAGE_RETIREMENT=false`）。現況請看
> [BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md](../BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md)。
> 🔴 **本頁的 Blade 版已實體刪除**（視圖與 controller 方法都不存在了），所以它**沒有任何
> 回退鍵**——而且 `LEGACY_PAGE_RETIREMENT` 這個開關本身也已於環節 4b-4c 連同封路 middleware
> 一併移除。舊 URI 只剩 302 導向 `/app` 對應頁（寫入端 410）。

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
