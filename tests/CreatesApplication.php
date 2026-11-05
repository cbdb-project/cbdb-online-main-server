<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;

trait CreatesApplication {
    /**
     * 在「設定載入完成之後、provider 啟動之前」套用的設定覆寫。
     *
     * **需要在 boot 時就生效的設定**（例如依設定決定要不要註冊路由的 provider）要用這個，而不是 setUp()
     * 裡的 `config()`——那時 provider 早就 boot 完了。
     *
     * **不要改用環境變數**：Laravel 的 .env 載入器是 immutable，而且記得「自己載入過什麼」。同一個行程裡第一個 app
     * 載入 .env 之後，後面的測試在建立 app 之前設定的環境變數，會在下一次載入 .env 時被 .env 的值蓋回去
     * （CI 會把 .env.example 複製成 .env；開發機的 .env 通常沒有那個變數，所以本機永遠看不出來——
     * OidcAuthorizationFlowTest 就是這樣在 CI 上 20 個失敗、本機全綠）。
     *
     * @return array<string, mixed>
     */
    protected function bootConfigOverrides(): array {
        return [];
    }

    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication() {
        $app = require __DIR__.'/../bootstrap/app.php';

        $overrides = $this->bootConfigOverrides();
        if ($overrides !== []) {
            $app->afterBootstrapping(LoadConfiguration::class, static function ($app) use ($overrides): void {
                $app['config']->set($overrides);
            });
        }

        $app->make(Kernel::class)->bootstrap();
        // Force in-memory SQLite for all tests to avoid touching external databases.
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        // Force non-file logging in tests to avoid permission issues.
        $app['config']->set('logging.default', 'errorlog');
        $app['config']->set('logging.channels.stack.channels', ['errorlog']);
        // Reset any pre-bootstrap connection that may have been opened by service providers.
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException('Tests must run with SQLite in-memory database.');
        }

        return $app;
    }
}
