# Fidelity Spec：basicinformation/index（P3-1，實質首頁、高流量）


> 🗄️ **歷史存檔（2026-09-14）**：本文件是遷移當時的 fidelity spec，記錄的是「新舊頁逐項對比」的
> 驗收依據，**不是現況說明**。其中關於「flag 預設 old」「舊頁可安全回退」「URL 依 flag 解析」的
> 敘述都已過時——Blade 下架計畫環節 2 把人物編輯全套**實體刪除**，環節 3 把其餘 legacy 頁面**封路**
> （顯示頁 302／寫入端 410，封路 middleware **不讀 migration flag**，回退鍵是
> `LEGACY_PAGE_RETIREMENT=false`）。現況請看
> [BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md](../BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md)。
> 🔴 **本頁的 Blade 版已實體刪除**（視圖與 controller 方法都不存在了），所以它**沒有任何
> 回退鍵**——而且 `LEGACY_PAGE_RETIREMENT` 這個開關本身也已於環節 4b-4c 連同封路 middleware
> 一併移除。舊 URI 只剩 302 導向 `/app` 對應頁（寫入端 410）。

> 舊頁 = `basicinformation.index`（Blade）；新頁 = `app.basicinformation.index`（Inertia）。
> flag = `basicinformation.index`（預設 old）。public 路由（與舊頁同；控制器 middleware('auth')->except 已含 appIndex）。

## 設計
appIndex 鏡像 index()：搜尋 q、num、c_dy 朝代篩選、dynastyFacetsByQuery、c_dy 不在分佈時
重導乾淨 URL（導向 app 路由）、namesByQuery 分頁。index() 本身未改（純加法）。

## 版面 parity
- 搜尋列：q 輸入 + 朝代下拉（有 facets 時，含「全部朝代 (總數)」+ 各朝代計數，onChange 即送出）+ 搜尋鈕。
- 新增按鈕（can_add = 已登入且 active）。
- 表格 8 欄（c_personid/c_name_chn/c_name/dynasty/index year/index address/zi/hao），每格連結至
  人物編輯器（新分頁）。caption 顯示總筆數。空資料列。
- 分頁（保留 q/c_dy）。

## 偏離決策
1. 整頁 GET → Inertia partial reload（保留 q/c_dy）。
2. 人物編輯器（edit）仍為 Blade（Phase 4，受 F7 硬前置）；edit 連結模板 flag-aware
   （basicinformation.editor flag + app.basicinformation.edit 存在時才指新版）。新增頁同（Blade）。

## parity 檢查清單
- [x] 搜尋 q + 朝代 facets 篩選 + 重導乾淨 URL 邏輯
- [x] 8 欄表格 + 編輯器連結（新分頁）+ 總筆數 caption + 空列
- [x] 新增按鈕閘門（active）
- [x] 分頁保留 q/c_dy
- [x] public（與舊頁同）；i18n biogmains/person 群組；無硬編碼中文
- [x] 舊 Blade index() 未改（BasicInformationPagesLoadTest 綠）；flag old；nav 節點 flag-aware
