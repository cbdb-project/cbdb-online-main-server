<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OidcTestKeys;
use Tests\TestCase;

/**
 * `php artisan oidc:check`：開啟 OIDC Provider 之前的上線前提檢查。
 * 這些問題都不會讓應用啟動失敗，而是在第一個下游登入時才以 500 或「id_token 驗不過」冒出來。
 */
class OidcCheckCommandTest extends TestCase {
    protected function bootConfigOverrides(): array {
        OidcTestKeys::install();

        return ['oidc.enabled' => true];
    }

    protected function setUp(): void {
        parent::setUp();

        config([
            'openid.issuedBy' => 'https://op.test',
            'app.key' => 'base64:' . base64_encode(random_bytes(32)),
            'cache.default' => 'redis', // 不要在每個案例都帶出「單節點」警告；警告另有專門案例
        ]);
    }

    protected function tearDown(): void {
        Passport::loadKeysFrom(storage_path());
        parent::tearDown();
    }

    /** @return array{0: int, 1: string} 結束碼與輸出 */
    private function runCheck(): array {
        $exit = Artisan::call('oidc:check');

        return [$exit, Artisan::output()];
    }

    #[Test]
    public function it_succeeds_trivially_when_the_provider_is_disabled(): void {
        config(['oidc.enabled' => false]);

        [$exit, $output] = $this->runCheck();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('已關閉', $output);
    }

    #[Test]
    public function it_passes_with_a_correct_setup_and_reports_the_kid(): void {
        [$exit, $output] = $this->runCheck();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('檢查通過', $output);
        $this->assertStringContainsString(\App\Support\Oidc\SigningKey::kid(), $output);
    }

    #[Test]
    public function it_fails_when_the_keys_are_missing(): void {
        config(['passport.public_key' => '', 'passport.private_key' => '']);
        Passport::loadKeysFrom(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'definitely-no-keys-here');

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('找不到公鑰', $output);
        $this->assertStringContainsString('找不到私鑰', $output);
        $this->assertStringContainsString('passport:keys', $output);
        $this->assertStringContainsString('不要加 --force', $output, '必須提醒：重跑 --force 會換掉簽章金鑰。');
    }

    #[Test]
    public function it_fails_without_an_explicit_issuer(): void {
        foreach (['laravel', ''] as $unset) {
            config(['openid.issuedBy' => $unset]);

            [$exit, $output] = $this->runCheck();

            $this->assertSame(1, $exit, json_encode($unset));
            $this->assertStringContainsString('issuer', $output);
        }
    }

    #[Test]
    public function it_requires_an_https_issuer_outside_local_and_testing(): void {
        config(['openid.issuedBy' => 'http://op.test']);

        // testing／local 環境放行 http（本機開發）。
        [$exit] = $this->runCheck();
        $this->assertSame(0, $exit);

        $this->app['env'] = 'production';
        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('必須是 https', $output);
    }

    #[Test]
    public function it_warns_about_a_trailing_slash_in_the_issuer_without_failing(): void {
        config(['openid.issuedBy' => 'https://op.test/']);

        [$exit, $output] = $this->runCheck();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('結尾有斜線', $output);
    }

    #[Test]
    public function it_fails_when_an_unrelated_scope_such_as_mcp_use_leaked_into_passport(): void {
        Passport::tokensCan(array_merge(Passport::$scopes, ['mcp:use' => 'Use MCP server']));

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('mcp:use', $output);
    }

    #[Test]
    public function it_fails_for_a_malformed_or_non_hostname_issuer(): void {
        // 不只比對 https:// 前綴：殘缺的值 RP 一樣無法使用。
        foreach (['https://', 'op.test', 'https:///path', 'ftp://op.test', 'https://:443'] as $bad) {
            config(['openid.issuedBy' => $bad]);

            [$exit, $output] = $this->runCheck();

            $this->assertSame(1, $exit, $bad);
            $this->assertStringContainsString('issuer', $output, $bad);
        }
    }

    #[Test]
    public function it_fails_when_the_issuer_has_a_path_because_discovery_only_exists_at_the_host_root(): void {
        // OIDC Discovery 規定 RP 去抓 {issuer}/.well-known/openid-configuration；帶路徑時那個網址在這裡是 404。
        foreach (['https://op.test/idp', 'https://op.test/a/b', 'https://op.test?x=1', 'https://op.test#frag'] as $withPath) {
            config(['openid.issuedBy' => $withPath]);

            [$exit, $output] = $this->runCheck();

            $this->assertSame(1, $exit, $withPath);
            $this->assertStringContainsString('主機根路徑', $output, $withPath);
        }
    }

    #[Test]
    public function it_fails_for_an_rsa_key_shorter_than_2048_bits(): void {
        // 成對、格式正確、但只有 1024 位元：可被分解，攻擊者就能偽造 RP 會接受的 id_token。
        $weak = \phpseclib4\Crypt\RSA::createKey(1024);
        config([
            'passport.public_key' => (string) $weak->getPublicKey(),
            'passport.private_key' => (string) $weak,
        ]);

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('1024 位元', $output);
        $this->assertStringContainsString('至少 2048', $output);
        $this->assertStringNotContainsString('不是同一對', $output, '這把鑰是成對的，問題只在長度。');
    }

    #[Test]
    public function it_fails_for_an_app_key_the_encrypter_would_reject(): void {
        // 非空但格式錯誤或長度不符 cipher：授權碼加密時才丟例外，那已經是第一個下游登入的當下。
        foreach (['base64:abc', 'base64:' . base64_encode('too-short'), 'short', 'base64:!!!not-base64!!!'] as $bad) {
            config(['app.key' => $bad]);

            [$exit, $output] = $this->runCheck();

            $this->assertSame(1, $exit, $bad);
            $this->assertStringContainsString('APP_KEY', $output, $bad);
        }

        // 長度正確的原始（非 base64:）32 位元組金鑰也是合法的。
        config(['app.key' => str_repeat('k', 32)]);
        [$exit] = $this->runCheck();
        $this->assertSame(0, $exit);
    }

    #[Test]
    public function the_shared_signing_key_reminder_is_shown_regardless_of_the_cache_store(): void {
        // 與 cache store 無關：用 redis 的多節點部署，各節點用各自的金鑰檔一樣會讓登入時好時壞。
        foreach (['file', 'array', 'redis', 'database'] as $store) {
            config(['cache.default' => $store]);

            [$exit, $output] = $this->runCheck();

            $this->assertSame(0, $exit, $store);
            $this->assertStringContainsString('同一對', $output, $store);
            $this->assertStringContainsString('PASSPORT_PUBLIC_KEY', $output, $store);
        }
    }

    #[Test]
    public function the_node_local_cache_warning_is_only_shown_for_file_and_array(): void {
        config(['cache.default' => 'redis']);
        [, $redis] = $this->runCheck();
        $this->assertStringNotContainsString('節點數 × 上限', $redis);

        config(['cache.default' => 'file']);
        [, $file] = $this->runCheck();
        $this->assertStringContainsString('節點數 × 上限', $file);
    }

    #[Test]
    public function it_rejects_userinfo_in_the_issuer_and_accepts_an_uppercase_scheme_and_a_port(): void {
        config(['openid.issuedBy' => 'https://user:secret@op.test']);
        [$exit, $output] = $this->runCheck();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('使用者資訊', $output);

        // scheme 大小寫不拘；非預設埠、IPv6 位址都是合法的 issuer。
        foreach (['HTTPS://op.test', 'https://op.test:8443', 'https://[::1]'] as $ok) {
            config(['openid.issuedBy' => $ok]);
            [$exit, $output] = $this->runCheck();
            $this->assertSame(0, $exit, $ok . ' ' . $output);
        }
    }

    #[Test]
    public function it_fails_without_an_app_key(): void {
        config(['app.key' => '']);

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('APP_KEY', $output);
    }

    #[Test]
    public function it_warns_about_a_node_local_cache_store_without_failing(): void {
        foreach (['file', 'array'] as $store) {
            config(['cache.default' => $store]);

            [$exit, $output] = $this->runCheck();

            $this->assertSame(0, $exit, $store);
            $this->assertStringContainsString('共享 store', $output, $store);
        }
    }

    #[Test]
    public function it_fails_when_the_private_key_is_readable_by_other_users(): void {
        if (windows_os()) {
            $this->markTestSkipped('Windows 的檔案權限模型不同，命令也不檢查。');
        }

        $private = Passport::keyPath('oauth-private.key');
        chmod($private, 0644);

        try {
            [$exit, $output] = $this->runCheck();
        } finally {
            chmod($private, 0600);
        }

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('可被其他使用者讀取', $output);
    }

    // ───────────── 金鑰來源可以是環境變數或檔案，兩邊各自獨立檢查 ─────────────

    #[Test]
    public function it_passes_when_both_keys_come_from_environment_variables(): void {
        config([
            'passport.public_key' => str_replace("
", '
', OidcTestKeys::publicKeyPem()),
            'passport.private_key' => str_replace("
", '
', OidcTestKeys::privateKeyPem()),
        ]);
        Passport::loadKeysFrom(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'definitely-no-keys-here');

        [$exit, $output] = $this->runCheck();

        $this->assertSame(0, $exit, $output);
    }

    #[Test]
    public function a_private_key_from_env_does_not_excuse_a_missing_public_key(): void {
        // 曾經的漏洞：任一邊來自環境變數就整段跳過，於是這種組合顯示「通過」，實際上 /oauth/jwks 會 500。
        config([
            'passport.private_key' => OidcTestKeys::privateKeyPem(),
            'passport.public_key' => '',
        ]);
        Passport::loadKeysFrom(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'definitely-no-keys-here');

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('找不到公鑰', $output);
        $this->assertStringNotContainsString('檢查通過', $output);
    }

    #[Test]
    public function a_public_key_from_env_does_not_excuse_a_missing_private_key(): void {
        config([
            'passport.public_key' => OidcTestKeys::publicKeyPem(),
            'passport.private_key' => '',
        ]);
        Passport::loadKeysFrom(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'definitely-no-keys-here');

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('找不到私鑰', $output);
    }

    #[Test]
    public function it_fails_when_the_public_and_private_keys_are_not_a_pair(): void {
        // 兩個檔案單獨看都「存在、格式正確」，但 JWKS 公布 A 的公鑰、id_token 卻用 B 的私鑰簽，RP 永遠驗不過。
        config(['passport.public_key' => (string) \phpseclib4\Crypt\RSA::createKey(1024)->getPublicKey()]);

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('不是同一對', $output);
    }

    #[Test]
    public function it_fails_when_the_private_key_is_not_a_valid_rsa_key(): void {
        config(['passport.private_key' => 'not a private key']);

        [$exit, $output] = $this->runCheck();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('不是同一對', $output);
    }

    #[Test]
    public function an_empty_configured_key_is_reported_as_empty_not_as_a_missing_file(): void {
        // 同一個環境變數設成空字串與「沒設」不同：前者通常是部署腳本寫壞了。
        config(['passport.public_key' => '', 'passport.private_key' => '']);
        Passport::loadKeysFrom(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'definitely-no-keys-here');

        [, $output] = $this->runCheck();

        $this->assertStringContainsString('PASSPORT_PUBLIC_KEY', $output);
        $this->assertStringContainsString('PASSPORT_PRIVATE_KEY', $output);
    }
}
