<?php

/*
|--------------------------------------------------------------------------
| Legacy Blade 頁面封路開關（Blade 下架計畫環節 3）
|--------------------------------------------------------------------------
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
|   2. **測試可 opt-out**：仍有大量測試在驗 legacy Blade 頁的行為，而那些頁面在環節 3
|      並沒有被刪除、仍然部署著、仍然可以被 kill switch 叫回來——所以它們的覆蓋在觀察期
|      內依然有意義，不該因為封路就一併失效。這些測試以
|      TestCase::useLegacyBladePages() 局部關閉封路（比照已下架的 useLegacyPersonForms()
|      慣例）。封路本身的行為由 tests/Feature/LegacyBladePageRetirementTest.php 驗證，
|      該檔**不** opt-out。
|
| 🔴 **2026-09-14 更新（環節 4a-3）：本開關的涵蓋範圍已經縮小。**
|
|    環節 4a-3 已**實體刪除** 9 條唯讀頁的 Blade 視圖與 controller 方法
|    （operations／dashboard／view／view/{key}／merge-preview／crowdsourcing／
|    nl-query-logs／admin.audit-logs／admin.ai-fill-logs）。那些路由改成純 redirect
|    closure、**不再掛 legacy.page middleware**，所以把本開關設成 false 對它們
|    **完全沒有作用**——它們照樣 302，因為已經沒有可以復活的對象。
|
|    本開關現在只涵蓋**表單／寫入頁**（codes 全套／manage／profile／admin.explainsql／
|    3 個 batch-load／cbdb-table-maintenance／unidirectional-repair），也就是環節 4b
|    的範圍。反面的護欄是
|    LegacyBladePageRetirementTest::legacy_readonly_pages_redirect_without_the_kill_switch()。
|
| ⚠️ 環節 4b 把剩下那批也刪掉之後，這整個開關與所有 opt-out 都要一併移除——屆時沒有可以
|    復活的對象，留著開關只會讓人誤以為還能回退。
|
| 逐條的封路清單見 docs/BLADE_RETIREMENT_STAGE3_ROUTE_MANIFEST.md。
|
*/

return [

    // true＝封路生效（顯示頁 302、寫入端 410）；false＝放行原 legacy controller。
    'enabled' => env('LEGACY_PAGE_RETIREMENT', true),

];
