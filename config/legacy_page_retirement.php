<?php

/*
|--------------------------------------------------------------------------
| Legacy Blade 頁面封路開關（Blade 下架計畫環節 3）— 🔴 已無作用對象
|--------------------------------------------------------------------------
|
| 🔴🔴 **2026-09-15（環節 4b-4b）起這個開關已經沒有任何作用對象。**
|
|    所有 legacy Blade 頁面都已**實體刪除**，舊 URI 只剩 redirect／abort(410) 的 closure，
|    **`legacy.page` middleware 一條路由都沒掛**。把 LEGACY_PAGE_RETIREMENT 設成 false
|    **不會叫回任何 Blade 頁**——沒有可以復活的對象了。要回到 Blade 只能 git revert
|    並重新部署。
|
|    本檔、App\Http\Middleware\RetireLegacyBladePage 與 TestCase::useLegacyBladePages()
|    目前都是死碼，待專屬環節（4b-4c）連同 .env 與部署 runbook 一併移除。
|
|    護欄：
|      LegacyBladePageRetirementTest::no_route_is_gated_by_the_retirement_middleware_any_more()
|      LegacyBladePageRetirementTest::neither_the_kill_switch_nor_migration_flags_bring_legacy_pages_back()
|
|    ⚠️ **不要把 legacy.page 掛回任何路由**：那個 middleware 有兩條 fail-open 路徑
|    （導向目標不存在時放行、kill switch 關閉時放行），而視圖都已經不存在——
|    落下去只會得到 500，不會得到「看到舊頁」。
|
| ──────────────────────────────────────────────────────────────────────────
| 以下是它當初（環節 3）的設計說明，保留作為歷史脈絡：
|
| 環節 3 的語義是「先封路、不刪碼」：legacy 路由、Blade 視圖與 controller 全部留著，
| 只是請求不再抵達 controller——顯示頁 302 導向 /app 對應頁、legacy 寫入端回 410。
| 目的是讓「舊 URL 一律落到 React」先上線觀察 1–2 週，把書籤／外部連結的問題提前暴露，
| 再進環節 4 的實體刪除。
|
| 這個開關讓「可逆」名實相符：
|
|   1. **營運端的 kill switch**：觀察期間若發現某個 React 頁有問題，把
|      LEGACY_PAGE_RETIREMENT=false 一翻、清 config 快取，legacy 頁立刻復活——
|      **不需要重新部署、不需要 git revert**。這正是環節 4 之後就再也沒有的能力。
|
|   2. **測試可 opt-out**：當時仍有大量測試在驗 legacy Blade 頁的行為，而那些頁面在環節 3
|      並沒有被刪除、仍然部署著、仍然可以被 kill switch 叫回來——所以它們的覆蓋在觀察期
|      內依然有意義，不該因為封路就一併失效。這些測試以
|      TestCase::useLegacyBladePages() 局部關閉封路（比照已下架的 useLegacyPersonForms()
|      慣例）。封路本身的行為由 tests/Feature/LegacyBladePageRetirementTest.php 驗證，
|      該檔**不** opt-out。
|      📌 環節 4b-3 起那些測試全部改打 React 端，這個 opt-out 已零呼叫點。
|
| 🔴 **2026-09-14（環節 4a-3）：涵蓋範圍第一次縮小。** 9 條唯讀頁的 Blade 視圖與 controller
|    方法被實體刪除，那些路由改成純 redirect closure、不再掛 legacy.page，所以把本開關設成
|    false 對它們完全沒有作用。
|
| 🔴 **2026-09-15（環節 4b-4a／4b-4b）：縮小到零。** codes 全套（12 條）與其餘表單／寫入頁
|    （21 條）也都實體刪除、改成 closure。至此本開關的涵蓋範圍是空集合，見本檔最上方。
|
| 逐條的封路清單（純歷史）見 docs/BLADE_RETIREMENT_STAGE3_ROUTE_MANIFEST.md。
|
*/

return [

    // 🔴 已無作用對象（見檔頭）：沒有任何路由掛 legacy.page，所以這個值目前不影響任何行為。
    // 保留這個鍵只是為了讓既有部署的 .env 不必立刻改。
    'enabled' => env('LEGACY_PAGE_RETIREMENT', true),

];
