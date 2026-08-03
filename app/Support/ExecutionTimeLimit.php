<?php

namespace App\Support;

/**
 * 延長 PHP 執行時間限制的統一入口。
 *
 * 為什麼需要包一層：`set_time_limit()`／`ini_set('max_execution_time', N)` 的作用域是**整個
 * PHP process**，不是單一 request。PHPUnit 預設 `processIsolation="false"`，所有測試共用一個
 * process，因此任何在請求處理中（或更糟：在 class 檔頂層、autoload 時）呼叫它的程式碼，一旦
 * 被觸發就會把 N 秒的牆鐘套到**其後整段測試流程**上——測試總時長超過 N 秒時整套直接
 * `Fatal error: Maximum execution time exceeded`，且錯誤指向當下剛好在跑的無關檔案
 * （實際踩到的有 BcryptHasher.php、Model.php、NamespacedItemResolver.php），極難追查。
 *
 * 具體踩過的兩層坑：
 *  1. `AiPostingAutofillController::extract()` 的 `set_time_limit(120)`。該測試檔依檔名排序在
 *     tests/Feature 最前面，於是整套測試從第 18 個測試起只剩 120 秒可用。
 *  2. 拔掉 (1) 之後換成 300 秒卡點：`Api/ApiController*.php` 與 `BiogMainRepository.php` 在
 *     class 宣告前的頂層 `ini_set('max_execution_time', 300)`，autoload 到該檔就生效。
 *
 * 語義為「**只放寬、絕不縮限**」（理由與生產實測值見 extendTo() 的 docblock）：
 *  - web 正式環境（php.ini 30 秒）行為完全不變，仍放寬到 300／600。
 *  - CLI／artisan（預設 0＝無限制）與測試環境一律 no-op。
 */
class ExecutionTimeLimit {
    /**
     * 把執行時間上限**放寬**到 $seconds 秒。只放寬、絕不縮限；測試環境下一律 no-op。
     *
     * 「只放寬不縮限」為何必要（生產實測 2026-08-03）：
     *  - web（php-fpm 8.4）`max_execution_time = 30` → 放寬到 300／600 是這些公開重查詢端點
     *    賴以存活的設定，必須保留。
     *  - CLI（artisan）`max_execution_time = 0`（無限制）→ 無條件 set_time_limit(300) 會**憑空
     *    加上 300 秒上限**。這些呼叫點多半在 class 檔頂層、autoload 到就生效，長跑命令若剛好
     *    載入該檔就會被砍在半途。舊寫法（頂層 ini_set）即有此問題，這裡一併封掉。
     *
     * 若目的是完全**移除**限制，直接用 `set_time_limit(0)`，不要走這裡。
     */
    public static function extendTo(int $seconds): void {
        if (self::runningTests()) {
            return;
        }

        // `max_execution_time` 恆存在，(int) 轉換無歧義；萬一取不到值折疊成 0＝視為無限制、
        // 放棄調整，方向與 MemoryLimit::toBytes() 回 null 一致（寧可不動，不猜測）。
        $current = (int) ini_get('max_execution_time');
        if ($current === 0 || $current >= $seconds) {
            // 0＝無限制（CLI 預設）；已更寬則沿用現值。兩者都不該被這裡收窄。
            return;
        }

        // set_time_limit() 會重置計時器（實測 ini_set('max_execution_time') 亦然，兩者等價）。
        // 注意：重置是**有條件**的——走到上面的 early return 就不會重置。因此本方法不可用來
        // 在長時間迴圈裡「續命」（現值已 >= 要求值時它什麼都不做）。若將來需要無條件續命，
        // 請另加一個語義明確的 reset()，不要改這裡的守衛。
        set_time_limit($seconds);
    }

    /**
     * 不透過容器判斷是否在跑測試。
     *
     * 刻意不用 `app()->runningUnitTests()`：部分呼叫點在 class 檔頂層、於 autoload 時執行，
     * 當下 Laravel 容器可能尚未 boot（甚至尚未建立），呼叫 `app()` 會炸。
     *
     * - `PHPUNIT_COMPOSER_INSTALL`：PHPUnit 的入口腳本在載入任何應用程式碼前就定義。
     * - `class_exists(..., false)`：第二參數 false＝不觸發 autoload，因此正式環境即使
     *   vendor 內裝著 phpunit（require-dev）也不會誤判為測試環境。
     */
    private static function runningTests(): bool {
        return defined('PHPUNIT_COMPOSER_INSTALL')
            || class_exists(\PHPUnit\Framework\TestCase::class, false);
    }
}
