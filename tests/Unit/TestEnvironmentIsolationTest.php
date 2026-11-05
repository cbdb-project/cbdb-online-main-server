<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 測試環境不得被機器上殘留的 `config:cache`／`route:cache` 影響。
 *
 * 背景見 phpunit.xml 的 APP_CONFIG_CACHE／APP_ROUTES_CACHE 註解：有快取時 `.env` 與 phpunit.xml
 * 的環境變數全部失效，測試會悄悄用舊的 local／prod 設定跑，產生只在開發機出現的假紅
 * （限流計數落在磁碟 file 快取、跨測試殘留）。這個測試在**有快取檔的機器上**才真的有鑑別力，
 * 在乾淨的 CI 上它是「phpunit.xml 的兩行設定還在」的回歸護欄。
 */
class TestEnvironmentIsolationTest extends TestCase {
    #[Test]
    public function configuration_is_never_loaded_from_the_config_cache(): void {
        $this->assertFalse(
            $this->app->configurationIsCached(),
            'phpunit.xml 的 APP_CONFIG_CACHE 必須指向不存在的檔案；測試不可讀 bootstrap/cache/config.php。'
        );
    }

    #[Test]
    public function routes_are_never_loaded_from_the_route_cache(): void {
        $this->assertFalse(
            $this->app->routesAreCached(),
            'phpunit.xml 的 APP_ROUTES_CACHE 必須指向不存在的檔案；測試不可讀 bootstrap/cache/routes-v7.php。'
        );
    }

    #[Test]
    public function the_phpunit_environment_wins_over_the_developer_dotenv(): void {
        // 這幾個值在 phpunit.xml 設定、而開發者本機 .env 通常是別的（mysql／file／local）。
        // 若有快取配置，它們會變回 .env 當時的值。
        //
        // 另一個失敗來源是宿主 shell 自己 export 了同名變數：Laravel 的 env() 先讀 $_SERVER，而 PHPUnit 的
        // force 只更新 $_ENV／putenv，所以 phpunit.xml 擋不住。這不是誤報——測試確實正在用別的設定跑——
        // 所以不放寬斷言，而是把原因寫進訊息，讓人知道該 unset 哪個變數。
        $hint = ' 若不是快取造成的，請檢查 shell 是否 export 了 APP_ENV／CACHE_DRIVER／SESSION_DRIVER'
            . '（Laravel 的 env() 先讀 $_SERVER，phpunit.xml 的設定擋不住它），unset 後再跑。';

        $this->assertSame('testing', $this->app->environment(), 'APP_ENV 應為 testing。' . $hint);
        $this->assertSame('array', config('cache.default'), 'cache.default 應為 array。' . $hint);
        $this->assertSame('array', config('session.driver'), 'session.driver 應為 array。' . $hint);
    }
}
