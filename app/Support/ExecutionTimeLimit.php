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
 * CLI SAPI 的 `max_execution_time` 預設為 0（無限制），本來就不該有這些限制。
 * 因此：測試環境下不設限，正式環境行為完全不變。
 */
class ExecutionTimeLimit {
    /**
     * 把執行時間上限延長到 $seconds 秒；測試環境下為 no-op。
     *
     * 僅適用於「為了長時間操作而**放寬**限制」的情境。若目的是完全移除限制，
     * 直接用 `set_time_limit(0)` 即可——那不會縮限任何東西，無此問題。
     */
    public static function extendTo(int $seconds): void {
        if (self::runningTests()) {
            return;
        }

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
