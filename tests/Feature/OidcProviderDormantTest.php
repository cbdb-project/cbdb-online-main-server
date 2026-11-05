<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OIDC Provider（docs/OIDC_PROVIDER_PLAN.md）「已安裝、全部關閉」階段的護欄。
 *
 * Passport 與 jeremy379/laravel-openid-connect 一裝上就會自動註冊 `/oauth/*`、`/.well-known/*`。
 * 計畫是分步落地——在授權端的 isActive() 把關與登入回跳測試完成前，這些端點不可以出現在任何
 * 被部署的環境。後續步驟要放行某個端點時，**有意地**改這個檔案對應的斷言，讓這個動作在 code review
 * 裡看得見，而不是某次 composer update 順手把它打開。
 */
class OidcProviderDormantTest extends TestCase {
    private const DROP_MIGRATION = '2025_12_18_050122_remove_passport_oauth_tables';

    /**
     * 「預設關閉」是這個類的前提，所以明確固定為關閉，不受開發者本機或 CI 的 .env 影響
     * （見 tests/CreatesApplication.php 對為什麼不用環境變數的說明）。
     */
    protected function bootConfigOverrides(): array {
        return ['oidc.enabled' => false];
    }

    /** @return list<string> 本專案發佈的 Passport 建表遷移（絕對路徑，依檔名排序） */
    private function oauthMigrationFiles(): array {
        $files = glob(database_path('migrations/*_create_oauth_*_table.php')) ?: [];
        sort($files);

        return $files;
    }

    #[Test]
    public function passport_routes_are_switched_off(): void {
        $this->assertFalse(Passport::$registersRoutes, 'AuthServiceProvider::register() 必須呼叫 Passport::ignoreRoutes()。');

        foreach (['passport.token', 'passport.authorizations.authorize', 'passport.authorizations.approve', 'passport.clients.index'] as $name) {
            $this->assertFalse(Route::has($name), "路由 {$name} 不應存在。");
        }
    }

    public static function dormantEndpointProvider(): array {
        return [
            'authorize' => ['GET', '/oauth/authorize'],
            'token' => ['POST', '/oauth/token'],
            'jwks' => ['GET', '/oauth/jwks'],
            'discovery' => ['GET', '/.well-known/openid-configuration'],
            'userinfo' => ['GET', '/oauth/userinfo'],
            // Mcp::oauthRoutes() 會註冊的三條（動態 client 註冊＋兩份 well-known metadata）。字串掃描擋不住
            // `Mcp::oauthRoutes ()`、call_user_func 之類的寫法，所以另以**執行期**請求確認它們不存在。
            'mcp dynamic client registration' => ['POST', '/oauth/register'],
            'mcp authorization-server metadata' => ['GET', '/.well-known/oauth-authorization-server'],
            'mcp protected-resource metadata' => ['GET', '/.well-known/oauth-protected-resource'],
        ];
    }

    #[Test]
    #[DataProvider('dormantEndpointProvider')]
    public function oidc_endpoints_are_not_reachable(string $method, string $uri): void {
        $this->call($method, $uri)->assertNotFound();
    }

    #[Test]
    public function openid_routes_are_disabled_in_config(): void {
        $this->assertSame(
            ['discovery' => false, 'jwks' => false, 'jwks_url' => '/oauth/jwks', 'userinfo' => false],
            config('openid.routes'),
            'config/openid.php 的 routes 必須全關；放行是第 3 步的事，要有意地改這個斷言。'
        );
    }

    #[Test]
    public function mcp_oauth_routes_are_never_registered(): void {
        // Mcp::oauthRoutes() 會開放 POST oauth/register（動態 client 註冊）並新增 `mcp:use` scope，
        // 與「只有手動建立的 first-party client」的前提相反。
        foreach (['app', 'routes', 'config', 'bootstrap'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $this->assertStringNotContainsString(
                    'oauthRoutes(',
                    (string) file_get_contents($file->getPathname()),
                    "{$file->getPathname()} 不可呼叫 Mcp::oauthRoutes()。"
                );
            }
        }
    }

    #[Test]
    public function oauth_migrations_sort_after_the_historical_drop_migration(): void {
        $files = $this->oauthMigrationFiles();
        $this->assertCount(5, $files, '預期 5 支 Passport 建表遷移（auth_codes、access_tokens、refresh_tokens、clients、device_codes）。');

        foreach ($files as $file) {
            $this->assertGreaterThan(
                self::DROP_MIGRATION,
                basename($file, '.php'),
                basename($file) . ' 的時戳必須晚於 ' . self::DROP_MIGRATION . '，否則全新 DB 會先建後刪、最後沒有 oauth_* 表。'
            );
        }
    }

    #[Test]
    public function oauth_migrations_use_no_cascade(): void {
        // AGENTS.md §1.1：外鍵一律 RESTRICT，禁止 CASCADE。Passport 的遷移本來就沒有外鍵，這裡鎖住它不要被改壞。
        foreach ($this->oauthMigrationFiles() as $file) {
            $body = strtolower((string) file_get_contents($file));
            $this->assertStringNotContainsString('cascade', $body, basename($file));
            $this->assertStringNotContainsString('->foreign(', $body, basename($file) . ' 不應宣告外鍵約束。');
        }
    }

    #[Test]
    public function oauth_migrations_build_the_tables_on_a_fresh_sqlite_database_and_are_reentrant(): void {
        $paths = array_map(fn (string $f) => 'database/migrations/' . basename($f), $this->oauthMigrationFiles());

        $exit = Artisan::call('migrate', ['--path' => $paths, '--no-interaction' => true]);
        $this->assertSame(0, $exit, Artisan::output());

        $expected = [
            'oauth_auth_codes' => ['id', 'user_id', 'client_id', 'scopes', 'revoked', 'expires_at'],
            'oauth_access_tokens' => ['id', 'user_id', 'client_id', 'name', 'scopes', 'revoked', 'expires_at'],
            'oauth_refresh_tokens' => ['id', 'access_token_id', 'revoked', 'expires_at'],
            'oauth_clients' => ['id', 'owner_type', 'owner_id', 'name', 'secret', 'provider', 'redirect_uris', 'grant_types', 'revoked'],
            'oauth_device_codes' => ['id', 'user_id', 'client_id', 'user_code', 'scopes', 'revoked', 'user_approved_at', 'last_polled_at', 'expires_at'],
        ];
        foreach ($expected as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "{$table} 應已建立。");
            $this->assertTrue(Schema::hasColumns($table, $columns), "{$table} 缺欄位。");
        }

        // 重入：表已存在時再跑一次 up() 不可丟例外（殘留同名表的環境）。
        foreach ($this->oauthMigrationFiles() as $file) {
            $migration = require $file;
            $migration->up();
        }
        $this->assertTrue(Schema::hasTable('oauth_clients'));
    }

    private function migrateOauthTables(): void {
        $paths = array_map(fn (string $f) => 'database/migrations/' . basename($f), $this->oauthMigrationFiles());
        $this->assertSame(0, Artisan::call('migrate', ['--path' => $paths, '--no-interaction' => true]), Artisan::output());
    }

    #[Test]
    public function an_existing_table_missing_passport_columns_makes_the_migration_fail_loudly(): void {
        // 舊版 Passport 殘留的不完整表：不可被悄悄標成「已套用」，Passport 之後才在執行期缺欄位出錯。
        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->string('id');
        });

        $migration = require database_path('migrations/2026_11_05_000003_create_oauth_refresh_tokens_table.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('access_token_id');
        $migration->up();
    }

    #[Test]
    public function an_existing_complete_table_and_its_rows_are_left_untouched(): void {
        $this->migrateOauthTables();
        DB::table('oauth_refresh_tokens')->insert(['id' => 'keep-me', 'access_token_id' => 'a', 'revoked' => false]);

        $migration = require database_path('migrations/2026_11_05_000003_create_oauth_refresh_tokens_table.php');
        $migration->up();

        $this->assertSame(1, DB::table('oauth_refresh_tokens')->where('id', 'keep-me')->count());
    }

    #[Test]
    public function rollback_refuses_to_drop_a_table_that_still_has_rows(): void {
        // 表可能是遷移跳過建立的既有表，也可能存著尚未過期的憑證——刪除要是有意識的動作。
        $this->migrateOauthTables();
        DB::table('oauth_refresh_tokens')->insert(['id' => 'live', 'access_token_id' => 'a', 'revoked' => false]);

        $migration = require database_path('migrations/2026_11_05_000003_create_oauth_refresh_tokens_table.php');

        try {
            $migration->down();
            $this->fail('非空的表不應被 rollback 刪除。');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('truncate', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('oauth_refresh_tokens'));
        $this->assertSame(1, DB::table('oauth_refresh_tokens')->count());
    }

    #[Test]
    public function rollback_drops_an_empty_table(): void {
        $this->migrateOauthTables();

        $migration = require database_path('migrations/2026_11_05_000003_create_oauth_refresh_tokens_table.php');
        $migration->down();

        $this->assertFalse(Schema::hasTable('oauth_refresh_tokens'));
    }
}
