<?php

namespace Tests\Feature;

use App\Http\Controllers\OidcDiscoveryController;
use App\Http\Middleware\RestrictOidcGrantTypes;
use App\Support\Oidc\SigningKey;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use phpseclib4\Crypt\RSA;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OidcTestKeys;
use Tests\TestCase;

/**
 * OIDC Provider 的 discovery 文件、JWKS 與 `kid`（docs/OIDC_PROVIDER_PLAN.md §8 第 3 步）。
 *
 * 核心原則：**discovery 宣稱的每一項都必須對應一個實際的強制點**。套件自己的 discovery 宣稱了
 * `client_credentials`／`refresh_token`／`plain`，RP 的函式庫會據此行事，然後吃 `unsupported_grant_type`。
 */
class OidcDiscoveryAndJwksTest extends TestCase {
    protected function bootConfigOverrides(): array {
        // 金鑰目錄必須在 app 啟動之前指好：OidcServiceProvider 在 boot 時由公鑰算 `kid`。
        OidcTestKeys::install();

        return ['oidc.enabled' => true];
    }

    protected function setUp(): void {
        parent::setUp();

        config(['openid.issuedBy' => 'https://op.test']);
    }

    protected function tearDown(): void {
        Passport::loadKeysFrom(storage_path());
        parent::tearDown();
    }

    private function discovery(): array {
        return $this->getJson('/.well-known/openid-configuration')->assertOk()->json();
    }

    private function base64Url(string $binary): string {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    // ───────────── discovery 的內容必須與實際行為一致 ─────────────

    #[Test]
    public function discovery_only_advertises_what_the_server_really_supports(): void {
        $doc = $this->discovery();

        $this->assertSame('https://op.test', $doc['issuer']);
        $this->assertSame(['code'], $doc['response_types_supported']);
        $this->assertSame(['query'], $doc['response_modes_supported']);
        $this->assertSame(['RS256'], $doc['id_token_signing_alg_values_supported']);
        $this->assertSame(['public'], $doc['subject_types_supported']);
        $this->assertSame(['S256'], $doc['code_challenge_methods_supported'], 'RequirePkceS256 只接受 S256，不可宣稱 plain。');
        $this->assertSame(['openid', 'profile', 'email'], $doc['scopes_supported']);
        $this->assertSame(['normal'], $doc['claim_types_supported']);

        // 過渡期（第 4 步前）只有 authorization_code。第 4 步開放 refresh 時，這條要有意地改，並補 refresh 的把關測試。
        $this->assertSame(['authorization_code'], $doc['grant_types_supported']);
        $this->assertSame(RestrictOidcGrantTypes::ALLOWED, $doc['grant_types_supported'], 'grant 的單一真源是 RestrictOidcGrantTypes::ALLOWED。');

        // 套件預設會宣稱、但我們不支援的東西，一個都不能出現。
        $flat = json_encode($doc);
        foreach (['client_credentials', 'refresh_token', 'implicit', 'password', 'plain', 'device_code', 'id_token token'] as $forbidden) {
            $this->assertStringNotContainsString('"' . $forbidden . '"', (string) $flat, "discovery 不應宣稱 {$forbidden}。");
        }

        // 這些端點刻意不存在，所以不可出現。
        foreach (['userinfo_endpoint', 'end_session_endpoint', 'registration_endpoint', 'revocation_endpoint', 'introspection_endpoint', 'device_authorization_endpoint'] as $absent) {
            $this->assertArrayNotHasKey($absent, $doc, $absent);
        }
    }

    #[Test]
    public function the_advertised_claims_are_exactly_the_whitelist(): void {
        $this->assertSame(
            ['sub', 'iss', 'aud', 'exp', 'iat', 'nonce', 'name', 'email', 'email_verified'],
            $this->discovery()['claims_supported']
        );
    }

    #[Test]
    public function the_advertised_endpoints_are_https_and_really_exist(): void {
        $doc = $this->discovery();

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            $this->assertStringStartsWith('https://', $doc[$key], $key);
        }

        // 宣稱的路徑實際打得到（不是 404）：授權端被 PKCE／openid 中介層擋成 400、令牌端被 grant 限制擋成 400、JWKS 200。
        $this->get(parse_url($doc['authorization_endpoint'], PHP_URL_PATH))->assertStatus(400);
        $this->post(parse_url($doc['token_endpoint'], PHP_URL_PATH))->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
        $this->get(parse_url($doc['jwks_uri'], PHP_URL_PATH))->assertOk();
    }

    #[Test]
    public function endpoints_are_built_from_the_issuer_not_from_the_request_host(): void {
        // issuer 來自設定（可信），Host 標頭不是：偽造的 Host 不可以讓文件公布指向別的主機的端點。
        foreach (['evil.example', 'op.test:8443', 'attacker.example'] as $host) {
            $doc = $this->call('GET', '/.well-known/openid-configuration', [], [], [], ['HTTP_HOST' => $host, 'HTTPS' => 'off'])
                ->assertOk()
                ->json();

            $this->assertSame('https://op.test', $doc['issuer'], $host);
            $this->assertSame('https://op.test/oauth/authorize', $doc['authorization_endpoint'], $host);
            $this->assertSame('https://op.test/oauth/token', $doc['token_endpoint'], $host);
            $this->assertSame('https://op.test/oauth/jwks', $doc['jwks_uri'], $host);
        }
    }

    #[Test]
    public function every_oidc_endpoint_refuses_to_run_without_an_explicit_issuer(): void {
        // issuedBy 為 'laravel'／'server' 時，套件會從請求的 Host 標頭推導 issuer——不只 discovery，連 id_token 的 iss 也是。
        // Host 是攻擊者可控的輸入，所以「忘了設 OIDC_ISSUER」必須是 fail-closed，不能靠人記得跑 oidc:check。
        foreach (['laravel', 'server', ''] as $derived) {
            config(['openid.issuedBy' => $derived]);

            foreach ([['GET', '/.well-known/openid-configuration'], ['GET', '/oauth/authorize'], ['POST', '/oauth/token']] as [$method, $uri]) {
                $this->call($method, $uri, [], [], [], ['HTTP_HOST' => 'evil.example'])
                    ->assertStatus(503)
                    ->assertJsonPath('error', 'server_error');
            }
        }
    }

    #[Test]
    public function the_explicit_issuer_requirement_is_the_first_thing_checked_on_each_endpoint(): void {
        // 即使請求本身也不合法（沒帶 PKCE、grant 不對），也先報 503 而不是別的錯誤：不能讓任何一條路徑在
        // 沒有 issuer 的情況下繼續往下走、甚至簽出 code。
        config(['openid.issuedBy' => 'laravel']);

        $this->get('/oauth/authorize?response_type=code')->assertStatus(503);
        $this->post('/oauth/token', ['grant_type' => 'password'])->assertStatus(503);
    }

    #[Test]
    public function a_trailing_slash_in_the_issuer_does_not_double_the_slash_in_endpoints(): void {
        config(['openid.issuedBy' => 'https://op.test/']);

        $doc = $this->discovery();

        $this->assertSame('https://op.test/', $doc['issuer'], 'issuer 逐字回傳，RP 會逐字比對。');
        $this->assertSame('https://op.test/oauth/token', $doc['token_endpoint']);
    }

    #[Test]
    public function discovery_does_not_mutate_the_shared_url_generator(): void {
        // 舊做法用 URL::forceScheme('https')，會改動共用的 URL 產生器，常駐行程裡會影響之後的請求。
        config(['openid.issuedBy' => 'http://op.test']);
        $before = url('/anything');

        $this->discovery();

        $this->assertSame($before, url('/anything'));
        $this->assertStringStartsWith('http://', url('/anything'));
    }

    #[Test]
    public function the_package_discovery_route_stays_off_and_ours_is_the_only_one(): void {
        $this->assertFalse(config('openid.routes.discovery'), '套件的 discovery 宣稱了我們不支援的東西，必須保持關閉。');

        $matching = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->uri() === '.well-known/openid-configuration');

        $this->assertCount(1, $matching);
        $this->assertSame(OidcDiscoveryController::class, $matching->first()->getActionName());
    }

    // ───────────── JWKS 與 kid ─────────────

    #[Test]
    public function jwks_publishes_exactly_one_rsa_signing_key_with_an_rfc7638_kid(): void {
        $jwks = $this->getJson('/oauth/jwks')->assertOk()->json();

        $this->assertCount(1, $jwks['keys']);
        $key = $jwks['keys'][0];

        $this->assertSame('RSA', $key['kty']);
        $this->assertSame('RS256', $key['alg']);
        $this->assertSame('sig', $key['use']);
        // 成員集合必須**恰好**等於公鑰允許的那幾個。只排除 d／p／q 不夠：dp、dq、qi、oth 同樣是私鑰材料。
        $members = array_keys($key);
        sort($members);
        $this->assertSame(['alg', 'e', 'kid', 'kty', 'n', 'use'], $members, 'JWK 出現公鑰以外的成員：' . json_encode($members));

        // kid ＝ 由公布的 n／e 獨立重算的 RFC 7638 指紋。
        $canonical = '{"e":"' . $key['e'] . '","kty":"RSA","n":"' . $key['n'] . '"}';
        $this->assertSame($this->base64Url(hash('sha256', $canonical, true)), $key['kid']);
        $this->assertSame(SigningKey::kid(), $key['kid']);
        $this->assertSame($key['kid'], config('openid.token_headers.kid'), 'id_token 頭部與 JWKS 必須讀同一個 kid。');
    }

    #[Test]
    public function the_kid_follows_the_key_so_rotation_needs_no_manual_step(): void {
        $before = SigningKey::kid();
        $this->assertNotEmpty($before);
        $this->assertSame($before, SigningKey::kid(), '同一把鑰的 kid 必須穩定。');

        // 同一把公鑰以環境變數形式（字面 `\n`）提供，kid 不變。
        $pem = OidcTestKeys::publicKeyPem();
        config(['passport.public_key' => str_replace("\n", '\n', $pem)]);
        $this->assertSame($before, SigningKey::kid());

        // 換一把鑰，kid 就變：RP 看到不認得的 kid 會重抓 JWKS。
        $other = (string) RSA::createKey(1024)->getPublicKey();
        config(['passport.public_key' => $other]);
        $this->assertNotSame($before, SigningKey::kid());
    }

    #[Test]
    public function a_missing_or_malformed_key_gives_no_kid_instead_of_crashing(): void {
        config(['passport.public_key' => 'not a key']);
        $this->assertNull(SigningKey::kid());

        config(['passport.public_key' => '']);
        Passport::loadKeysFrom(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'definitely-no-keys-here');
        $this->assertSame('', SigningKey::publicKeyPem());
        $this->assertNull(SigningKey::kid());
    }
}
