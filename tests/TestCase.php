<?php

namespace Tests;

use App\Http\Controllers\CodesController;
use App\Services\CharVariantMapService;
use App\Support\VariantReplaceScope;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {
    use CreatesApplication;

    protected function setUp(): void {
        parent::setUp();
        $this->serverVariables['HTTP_ACCEPT_LANGUAGE'] = 'zh-TW,zh;q=0.9,en;q=0.1';

        // 清異體字替換的兩份行程內快取。**必須做**：測試自建的是簡化版合成表，
        // 同一個表名在不同檔案有不同欄位集，而 PHPUnit 單一行程跑完全部 ⇒
        // 被前一個檔案暖起來的型別／對照表快取，對下一個檔案就是錯的，
        // 測試結果會依檔案順序漂移。
        CharVariantMapService::reset();
        VariantReplaceScope::reset();

        // CodesController 的主鍵欄快取同理：測試會為同一個表名建不同的合成 schema，
        // 一旦被某個測試快取成錯的主鍵欄，後面的測試就會拿到污染值（症狀是
        // 「請確認主鍵欄位已填寫完整」這類與該測試無關的失敗）。
        CodesController::resetKeyColumnCache();
    }

    /**
     * 🔴 **死碼（2026-09-15，Blade 下架環節 4b-4b）**：自環節 4b-3 起**零呼叫點**，
     * 而且自環節 4b-4b 起連它操作的那個 kill switch 都已無作用對象——所有 legacy Blade
     * 頁面都已實體刪除，沒有任何路由掛 `legacy.page`。**呼叫它不會讓任何舊頁復活。**
     * 與 `config/legacy_page_retirement.php`、`RetireLegacyBladePage` 一併待 4b-4c 移除。
     *
     * ── 以下是它服役時的說明（歷史）─────────────────────────────
     * 局部關閉 legacy Blade 頁面封路（Blade 下架計畫環節 3），供仍在驗 legacy 頁行為的測試使用。
     * 環節 3 的語義是「先封路、不刪碼」——那些 Blade 視圖與 controller 都還在、還部署著、
     * 還能被 kill switch 叫回來，所以它們的測試覆蓋在觀察期內依然有意義。
     */
    protected function useLegacyBladePages(): void {
        config(['legacy_page_retirement.enabled' => false]);
    }
}
