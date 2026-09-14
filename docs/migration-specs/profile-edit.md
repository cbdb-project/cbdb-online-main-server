# Fidelity Spec：profile/edit（P1-5）


> 🗄️ **歷史存檔（2026-09-14）**：本文件是遷移當時的 fidelity spec，記錄的是「新舊頁逐項對比」的
> 驗收依據，**不是現況說明**。其中關於「flag 預設 old」「舊頁可安全回退」「URL 依 flag 解析」的
> 敘述都已過時——Blade 下架計畫環節 2 把人物編輯全套**實體刪除**，環節 3 把其餘 legacy 頁面**封路**
> （顯示頁 302／寫入端 410，封路 middleware **不讀 migration flag**，回退鍵是
> `LEGACY_PAGE_RETIREMENT=false`）。現況請看
> [BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md](../BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md)。

> 舊頁 = `profile.edit`/`profile.update`（Blade）；新頁 = `app.profile.edit`/`app.profile.update`
> （Inertia）。flag = `profile`（預設 old；側邊欄無此項，連結來自 navbar 下拉 shell.profile_url，
> 已 flag-aware）。授權：`auth`（本人）。

## 表單（useForm，PATCH）
- 基本資料：name（必填）、email（必填，唯一忽略本人）、institution（選填）、avatar（必填，
  allowlist avatar0..18.png）。
- avatar 選擇器：當前預覽 + 橫向縮圖網格，點選設值（取代舊 jQuery 點選）。
- 密碼變更（選填）：current_password / new_password（min6, confirmed）/ confirmation；
  未填 new_password 則不驗證密碼。current_password 不符 → 422 errors。
- 驗證/儲存邏輯共用 `rules()` + `applyProfileUpdate()`（舊 Blade update() 行為不變；
  institution 改 `?? null` 更穩健）。
- 成功：redirect 回 edit + `->with('success')`，由 share() flash 橋接（已擴充支援
  Laravel 慣用 session success/error/status）顯示。

## API Token 管理（Route::has('api-tokens.index') 時顯示）
TokenManager 元件：列表（GET index）/建立（POST store，可選 expires_in）/撤銷（DELETE
{id}）/全部撤銷（DELETE all）；新建 token 明碼一次性顯示。CSRF 以 XSRF-TOKEN cookie →
X-XSRF-TOKEN header（同源 fetch）。

## 偏離決策
1. 整頁 POST → Inertia useForm PATCH；old() 回填改受控 input。
2. 成功訊息改走 share() flash 橋接（擴充支援 session('success')）。
3. avatar 選擇器以 React 狀態取代 jQuery；視覺以 Tailwind 重建（保留預覽 + 網格 + 選中框）。
4. token 撤銷沿用 window.confirm（與舊頁一致；未改用 ConfirmDialog 以限縮範圍）。
5. token 時間以瀏覽器本地 toLocaleString（舊頁用 window.formatTimestamp，不在 inertia bundle）。

## parity 檢查清單
- [x] 基本資料欄位 + 必填/唯一/allowlist 驗證
- [x] 密碼選填變更 + current_password 檢查
- [x] avatar 預覽 + 網格選擇
- [x] 成功 flash 顯示
- [x] API token 列表/建立/撤銷/全部撤銷 + 明碼一次性顯示（路由存在時）
- [x] 授權：guest 重導；本人
- [x] i18n common 群組（shared）；無硬編碼中文
- [x] 舊 Blade update() 行為不變（UserProfileTest 17/17 綠）
