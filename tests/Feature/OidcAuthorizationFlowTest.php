<?php

namespace Tests\Feature;

use App\Models\OidcClient;
use App\Models\User;
use App\Support\Oidc\UserIdentityRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OIDC Provider 授權端（docs/OIDC_PROVIDER_PLAN.md §8 第 2 步）的端到端測試。
 *
 * 總開關 `oidc.enabled` 預設關閉，路由是在 provider boot 時註冊的，所以用 `bootConfigOverrides()` 在
 * 「設定載入後、provider 啟動前」打開它。**不要改回用環境變數**：.env 載入器是 immutable 且記得自己載入過什麼，
 * 同一個行程裡較早的測試載入過 .env 之後，這裡預先設定的環境變數會被 .env 蓋回去——CI 會把 .env.example
 * 複製成 .env（其中 OIDC_PROVIDER_ENABLED=false），於是這個類在 CI 上 20 個全失敗，本機因為 .env 沒有那個變數而全綠。
 *
 * 「兌換授權碼時帳號被停用」只鎖住目前的**過渡**行為（簽發 id_token 的 identity 查詢會拒絕）：
 * token 本身已經寫進資料庫，完整的撤銷與 refresh 把關屬第 4 步，到時這裡的斷言要加強。
 */
class OidcAuthorizationFlowTest extends TestCase {
    private const REDIRECT = 'https://rp.example/callback';

    private static ?string $keyDir = null;

    protected function bootConfigOverrides(): array {
        return ['oidc.enabled' => true];
    }

    protected function setUp(): void {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\PrometheusMetrics::class);

        config([
            'openid.issuedBy' => 'https://op.test',
            'app.key' => 'base64:' . base64_encode(random_bytes(32)),
        ]);

        $this->createSchema();
        $this->ensureKeys();
    }

    protected function tearDown(): void {
        Passport::loadKeysFrom(storage_path());
        parent::tearDown();
    }

    private function createSchema(): void {
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->json('settings')->nullable();
            $table->string('avatar')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        $paths = array_map(
            static fn (string $f) => 'database/migrations/' . basename($f),
            glob(database_path('migrations/*_create_oauth_*_table.php')) ?: []
        );
        $this->assertSame(0, Artisan::call('migrate', ['--path' => $paths, '--no-interaction' => true]), Artisan::output());
    }

    /** RSA 金鑰整個測試類只生成一次（phpseclib 純 PHP 生成 2048 位元較慢；本機 openssl 也沒有設定檔，不能用 openssl_pkey_new）。 */
    private function ensureKeys(): void {
        if (self::$keyDir === null) {
            self::$keyDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oidc-test-keys-' . bin2hex(random_bytes(4));
            mkdir(self::$keyDir, 0700, true);
            Passport::loadKeysFrom(self::$keyDir);
            $this->assertSame(0, Artisan::call('passport:keys', ['--force' => true, '--length' => 2048]), Artisan::output());
        }

        Passport::loadKeysFrom(self::$keyDir);
    }

    private function makeUser(int $active = User::STATUS_ACTIVE, string $email = 'alice@example.org'): User {
        $user = new User();
        $user->forceFill([
            'name' => 'Alice 愛麗絲',
            'email' => $email,
            'password' => Hash::make('secret-password'),
            'is_active' => $active,
            'is_admin' => User::ROLE_SUPER_ADMIN,
            'confirmation_token' => 'LONG-LIVED-LEGACY-CREDENTIAL',
            'settings' => ['last_login_ip' => '203.0.113.9'],
        ])->save();

        return $user;
    }

    /** @return array{0: OidcClient, 1: ?string} client 與明文 secret（public client 為 null） */
    private function makeClient(bool $confidential = true, ?User $owner = null): array {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Downstream RP', [self::REDIRECT], $confidential);

        if ($owner !== null) {
            // Passport 的 createAuthorizationCodeGrantClient($user) 會呼叫 $user->oauthApps()（Passport 版 HasApiTokens），
            // 本專案的 User 刻意不用它——直接設定擁有者欄位。
            $client->forceFill(['owner_type' => $owner::class, 'owner_id' => $owner->getKey()])->save();
            $client->refresh();
        }

        return [$client, $confidential ? $client->plainSecret : null];
    }

    /** @return array{0: string, 1: string} verifier 與 S256 challenge */
    private function pkcePair(): array {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    private function authorizeUrl(OidcClient $client, string $challenge, array $override = []): string {
        return '/oauth/authorize?' . http_build_query(array_merge([
            'response_type' => 'code',
            'client_id' => $client->getKey(),
            'redirect_uri' => self::REDIRECT,
            'scope' => 'openid profile email',
            'state' => 'st-123',
            'nonce' => 'nonce-xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], $override));
    }

    /** @return array<string, string> Location 的 query 參數 */
    private function redirectQuery(\Illuminate\Testing\TestResponse $response): array {
        $response->assertStatus(302);
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return ['__host' => (string) parse_url($location, PHP_URL_HOST)] + $query;
    }

    /** 走完授權碼流程，回傳 token 端點的 response。 */
    private function exchange(OidcClient $client, ?string $secret, User $user, ?callable $beforeExchange = null): \Illuminate\Testing\TestResponse {
        [$verifier, $challenge] = $this->pkcePair();

        $query = $this->redirectQuery($this->actingAs($user)->get($this->authorizeUrl($client, $challenge)));
        $this->assertSame('rp.example', $query['__host']);
        $this->assertArrayHasKey('code', $query, '應已發出授權碼。');
        $this->assertSame('st-123', $query['state']);

        if ($beforeExchange !== null) {
            $beforeExchange();
        }

        return $this->post('/oauth/token', array_filter([
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'client_secret' => $secret,
            'redirect_uri' => self::REDIRECT,
            'code' => $query['code'],
            'code_verifier' => $verifier,
        ], static fn ($v) => $v !== null));
    }

    private function decodeIdToken(string $jwt): array {
        $public = (string) file_get_contents(self::$keyDir . DIRECTORY_SEPARATOR . 'oauth-public.key');

        return (array) JWT::decode($jwt, new Key($public, 'RS256'));
    }

    // ───────────── 端點與設定 ─────────────

    #[Test]
    public function only_the_authorize_and_token_routes_are_registered(): void {
        $this->assertTrue(Route::has('passport.authorizations.authorize'));
        $this->assertTrue(Route::has('passport.token'));

        // Passport 完整路由集裡的 JSON API 與其餘端點一律不存在：
        // /oauth/clients 會讓任何已登入使用者自行建立 OAuth client。
        foreach (['GET /oauth/clients', 'POST /oauth/clients', 'GET /oauth/personal-access-tokens', 'GET /oauth/tokens', 'GET /oauth/scopes', 'GET /oauth/device'] as $endpoint) {
            [$method, $uri] = explode(' ', $endpoint);
            $this->actingAs($this->makeUser(User::STATUS_ACTIVE, 'u' . md5($endpoint) . '@example.org'))
                ->call($method, $uri)
                ->assertNotFound();
        }

        // 沒有同意頁，所以核准／拒絕的路由也不存在：URI 與 GET 共用，回 405（不是 404）。
        $user = $this->makeUser(User::STATUS_ACTIVE, 'approve@example.org');
        $this->actingAs($user)->post('/oauth/authorize')->assertStatus(405);
        $this->actingAs($user)->delete('/oauth/authorize')->assertStatus(405);

        // discovery／JWKS 屬第 3 步，仍關閉。
        $this->get('/.well-known/openid-configuration')->assertNotFound();
        $this->get('/oauth/jwks')->assertNotFound();
    }

    #[Test]
    public function token_lifetimes_are_short_and_explicit_not_passports_one_year_default(): void {
        $this->assertSame(10, (int) Passport::tokensExpireIn()->i);
        $this->assertSame(0, (int) Passport::tokensExpireIn()->y);
        $this->assertSame(7, (int) Passport::refreshTokensExpireIn()->d);
        $this->assertSame(0, (int) Passport::refreshTokensExpireIn()->y);
    }

    #[Test]
    public function only_openid_profile_and_email_scopes_exist(): void {
        // laravel/mcp 會在 booted() 把 `mcp:use` 合併進 Passport::$scopes；OidcServiceProvider 的 booted 回調
        // 必須在它之後執行並整個取代。這條斷言同時鎖住那個順序依賴。
        $this->assertSame(['openid', 'profile', 'email'], array_keys(Passport::$scopes));
        $this->assertArrayNotHasKey('mcp:use', Passport::$scopes);
    }

    // ───────────── 完整流程 ─────────────

    #[Test]
    public function the_authorization_code_flow_with_pkce_issues_an_id_token_with_whitelisted_claims(): void {
        $user = $this->makeUser();
        [$client, $secret] = $this->makeClient();

        $response = $this->exchange($client, $secret, $user);
        $response->assertOk();
        $body = $response->json();

        $this->assertSame('Bearer', $body['token_type']);
        $this->assertNotEmpty($body['access_token']);
        $this->assertNotEmpty($body['refresh_token']);
        $this->assertLessThanOrEqual(600, $body['expires_in']);
        $this->assertGreaterThan(590, $body['expires_in']);
        $this->assertSame('openid profile email', $body['scope']);

        $claims = $this->decodeIdToken($body['id_token']);

        $this->assertSame('https://op.test', $claims['iss']);
        $this->assertSame([$client->getKey()], (array) $claims['aud']);
        $this->assertSame('nonce-xyz', $claims['nonce'], 'nonce 必須原樣回到 id_token。');
        $this->assertGreaterThan(time(), $claims['exp']);

        // sub：users.id 的字串形式（OIDC 規定 JSON string；整數會讓合規的 RP 庫拒絕）。
        $this->assertIsString($claims['sub']);
        $this->assertSame((string) $user->getKey(), $claims['sub']);

        $this->assertSame('Alice 愛麗絲', $claims['name']);
        $this->assertSame('alice@example.org', $claims['email']);
        $this->assertFalse($claims['email_verified'], 'email 沒有所有權驗證，不可宣稱已驗證。');
    }

    #[Test]
    public function id_token_never_leaks_account_fields_or_the_packages_demo_identity(): void {
        $user = $this->makeUser();
        [$client, $secret] = $this->makeClient();

        $claims = $this->decodeIdToken($this->exchange($client, $secret, $user)->json('id_token'));

        $allowed = ['iss', 'aud', 'exp', 'iat', 'nbf', 'jti', 'sub', 'nonce', 'name', 'email', 'email_verified'];
        $this->assertSame([], array_values(array_diff(array_keys($claims), $allowed)), '出現白名單以外的 claim：' . json_encode(array_keys($claims)));

        $flat = json_encode($claims, JSON_UNESCAPED_UNICODE);
        foreach (['LONG-LIVED-LEGACY-CREDENTIAL', 'is_admin', 'is_active', '203.0.113.9', 'Jon Snow', 'dorne.com', 'phone_number', 'address'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $flat);
        }
    }

    #[Test]
    public function the_scope_filter_withholds_claims_that_were_not_requested(): void {
        $user = $this->makeUser();
        [$client, $secret] = $this->makeClient();
        [$verifier, $challenge] = $this->pkcePair();

        $query = $this->redirectQuery($this->actingAs($user)->get($this->authorizeUrl($client, $challenge, ['scope' => 'openid'])));
        $token = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->getKey(), 'client_secret' => $secret,
            'redirect_uri' => self::REDIRECT, 'code' => $query['code'], 'code_verifier' => $verifier,
        ])->assertOk()->json();

        $claims = $this->decodeIdToken($token['id_token']);
        $this->assertArrayNotHasKey('email', $claims);
        $this->assertArrayNotHasKey('name', $claims);
        $this->assertSame((string) $user->getKey(), $claims['sub']);
    }

    #[Test]
    public function a_public_client_can_complete_the_flow_without_a_secret(): void {
        $user = $this->makeUser();
        [$client] = $this->makeClient(confidential: false);

        $response = $this->exchange($client, null, $user);

        $response->assertOk();
        $this->assertSame((string) $user->getKey(), $this->decodeIdToken($response->json('id_token'))['sub']);
    }

    #[Test]
    public function a_wrong_code_verifier_is_rejected(): void {
        $user = $this->makeUser();
        [$client, $secret] = $this->makeClient();
        [, $challenge] = $this->pkcePair();

        $query = $this->redirectQuery($this->actingAs($user)->get($this->authorizeUrl($client, $challenge)));

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->getKey(), 'client_secret' => $secret,
            'redirect_uri' => self::REDIRECT, 'code' => $query['code'], 'code_verifier' => str_repeat('x', 64),
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    // ───────────── PKCE ─────────────

    #[Test]
    public function pkce_s256_is_required_even_for_confidential_clients(): void {
        $user = $this->makeUser();
        [$client] = $this->makeClient(confidential: true);
        [, $challenge] = $this->pkcePair();

        $cases = [
            'no challenge' => ['code_challenge' => null, 'code_challenge_method' => null],
            'plain method' => ['code_challenge' => $challenge, 'code_challenge_method' => 'plain'],
            'no method' => ['code_challenge' => $challenge, 'code_challenge_method' => null],
            'too short' => ['code_challenge' => 'abc', 'code_challenge_method' => 'S256'],
            'illegal chars' => ['code_challenge' => str_repeat('!', 50), 'code_challenge_method' => 'S256'],
        ];

        foreach ($cases as $label => $override) {
            $url = '/oauth/authorize?' . http_build_query(array_filter(array_merge([
                'response_type' => 'code', 'client_id' => $client->getKey(), 'redirect_uri' => self::REDIRECT,
                'scope' => 'openid', 'state' => 's',
            ], $override), static fn ($v) => $v !== null));

            $this->actingAs($user)->get($url)
                ->assertStatus(400)
                ->assertJsonPath('error', 'invalid_request');
            // 不可重導向：這一步在 redirect_uri 被驗證之前。
            $this->assertNull($this->actingAs($user)->get($url)->headers->get('Location'), $label);
        }
    }

    // ───────────── 停用帳號 ─────────────

    #[Test]
    public function a_deactivated_account_with_a_live_session_gets_no_code_and_is_logged_out(): void {
        $user = $this->makeUser();
        [$client] = $this->makeClient();
        [, $challenge] = $this->pkcePair();

        $this->actingAs($user);
        $user->is_active = User::STATUS_INACTIVE;
        $user->save();

        $response = $this->get($this->authorizeUrl($client, $challenge));

        $response->assertRedirect(route('login'));
        $this->assertStringNotContainsString('rp.example', (string) $response->headers->get('Location'));
        $this->assertGuest();
    }

    #[Test]
    public function a_deactivated_account_looks_like_no_login_to_the_rp_under_prompt_none(): void {
        $user = $this->makeUser();
        [$client] = $this->makeClient();
        [, $challenge] = $this->pkcePair();

        $this->actingAs($user);
        $user->is_active = User::STATUS_INACTIVE;
        $user->save();

        $query = $this->redirectQuery($this->get($this->authorizeUrl($client, $challenge, ['prompt' => 'none'])));

        $this->assertSame('rp.example', $query['__host']);
        $this->assertSame('login_required', $query['error'], '不可洩漏「帳號存在但被停用」。');
        $this->assertArrayNotHasKey('code', $query);
    }

    #[Test]
    public function an_account_deactivated_between_authorize_and_exchange_gets_no_id_token(): void {
        $user = $this->makeUser();
        [$client, $secret] = $this->makeClient();

        $response = $this->exchange($client, $secret, $user, function () use ($user) {
            $user->is_active = User::STATUS_INACTIVE;
            $user->save();
        });

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertNull($response->json('id_token'));
        $this->assertNull($response->json('access_token'), '失敗的兌換不可把 access token 交給 RP。');
    }

    // ───────────── prompt／同意頁／未登入 ─────────────

    #[Test]
    public function an_unauthenticated_user_is_sent_to_login_with_the_whole_authorize_url_preserved(): void {
        [$client] = $this->makeClient();
        [, $challenge] = $this->pkcePair();
        $url = $this->authorizeUrl($client, $challenge);

        $this->get($url)->assertRedirect(route('login'));

        $intended = (string) session('url.intended');
        $this->assertStringContainsString('code_challenge=' . $challenge, $intended);
        $this->assertStringContainsString('state=st-123', $intended);
        $this->assertStringContainsString('nonce=nonce-xyz', $intended);
        $this->assertStringContainsString('code_challenge_method=S256', $intended);
    }

    #[Test]
    public function prompt_none_without_a_session_returns_login_required_to_the_rp(): void {
        [$client] = $this->makeClient();
        [, $challenge] = $this->pkcePair();

        $query = $this->redirectQuery($this->get($this->authorizeUrl($client, $challenge, ['prompt' => 'none'])));

        $this->assertSame('rp.example', $query['__host']);
        $this->assertSame('login_required', $query['error']);
        $this->assertSame('st-123', $query['state']);
    }

    #[Test]
    public function prompt_consent_is_an_error_not_a_silent_approval(): void {
        $user = $this->makeUser();
        [$client] = $this->makeClient();
        [, $challenge] = $this->pkcePair();

        $query = $this->redirectQuery($this->actingAs($user)->get($this->authorizeUrl($client, $challenge, ['prompt' => 'consent'])));

        $this->assertSame('rp.example', $query['__host']);
        $this->assertSame('consent_required', $query['error']);
        $this->assertSame('st-123', $query['state']);
        $this->assertArrayNotHasKey('code', $query);
    }

    #[Test]
    public function a_client_that_has_an_owner_is_not_auto_approved(): void {
        $owner = $this->makeUser(User::STATUS_ACTIVE, 'owner@example.org');
        $user = $this->makeUser(User::STATUS_ACTIVE, 'bob@example.org');
        [$client] = $this->makeClient(confidential: true, owner: $owner);
        [, $challenge] = $this->pkcePair();

        $query = $this->redirectQuery($this->actingAs($user)->get($this->authorizeUrl($client, $challenge)));

        $this->assertSame('consent_required', $query['error']);
        $this->assertArrayNotHasKey('code', $query);
    }

    // ───────────── redirect_uri／scope ─────────────

    #[Test]
    public function an_unregistered_redirect_uri_is_never_redirected_to(): void {
        $user = $this->makeUser();
        [$client] = $this->makeClient();
        [, $challenge] = $this->pkcePair();

        foreach (['https://evil.example/callback', self::REDIRECT . '/extra', 'https://rp.example/callback?x=1', 'http://rp.example/callback'] as $uri) {
            $response = $this->actingAs($user)->get($this->authorizeUrl($client, $challenge, ['redirect_uri' => $uri]));

            $this->assertNotSame(302, $response->getStatusCode(), $uri);
            $this->assertNull($response->headers->get('Location'), $uri);
        }
    }

    #[Test]
    public function scopes_the_project_does_not_offer_are_rejected(): void {
        $user = $this->makeUser();
        [$client] = $this->makeClient();
        [, $challenge] = $this->pkcePair();

        // 帶了 openid（沒帶的情況由 openid_scope_is_required 測），再夾帶本專案不提供的 scope。
        foreach (['openid phone', 'openid address', 'openid admin', 'openid mcp:use', 'phone openid'] as $scope) {
            $query = $this->redirectQuery($this->actingAs($user)->get($this->authorizeUrl($client, $challenge, ['scope' => $scope])));

            $this->assertSame('invalid_scope', $query['error'], $scope);
            $this->assertArrayNotHasKey('code', $query);
        }
    }

    // ───────────── 身分來源 ─────────────

    #[Test]
    public function the_identity_repository_returns_only_whitelisted_claims_and_a_string_identifier(): void {
        $user = $this->makeUser();

        $entity = (new UserIdentityRepository())->getByIdentifier((string) $user->getKey());

        $this->assertSame((string) $user->getKey(), $entity->getIdentifier());
        $this->assertIsString($entity->getIdentifier());
        $this->assertSame(['name', 'email', 'email_verified'], array_keys($entity->getClaims()));
        $this->assertFalse($entity->getClaims()['email_verified']);
    }

    #[Test]
    public function the_identity_repository_refuses_unknown_and_inactive_accounts(): void {
        $inactive = $this->makeUser(User::STATUS_INACTIVE, 'off@example.org');
        $reserved = $this->makeUser(User::STATUS_RESERVED, 'reserved@example.org');
        $repository = new UserIdentityRepository();

        foreach ([(string) $inactive->getKey(), (string) $reserved->getKey(), '999999', 'not-a-number', '', '1; DROP TABLE users'] as $identifier) {
            try {
                $repository->getByIdentifier($identifier);
                $this->fail("身分 '{$identifier}' 不應被接受。");
            } catch (OAuthServerException $e) {
                $this->assertSame('access_denied', $e->getErrorType());
            }
        }
    }

    // ───────────── openid scope 必填（isActive() 把關的前提） ─────────────

    #[Test]
    public function the_openid_scope_is_required_because_the_active_check_lives_in_id_token_issuance(): void {
        // 套件的 IdTokenResponse 在 token 沒有 openid scope 時整段跳過（含 UserIdentityRepository 的 isActive() 檢查）。
        // scope 是授權請求的選用參數，不強制的話，client 不帶 scope 就能繞過停用帳號的把關。
        $user = $this->makeUser();
        [$client] = $this->makeClient();
        [, $challenge] = $this->pkcePair();

        foreach ([null, '', 'profile', 'email', 'profile email', 'openidx', 'OPENID'] as $scope) {
            $override = $scope === null ? ['scope' => null] : ['scope' => $scope];
            $url = '/oauth/authorize?' . http_build_query(array_filter(
                array_merge([
                    'response_type' => 'code', 'client_id' => $client->getKey(), 'redirect_uri' => self::REDIRECT,
                    'state' => 's', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
                ], $override),
                static fn ($v) => $v !== null
            ));

            $response = $this->actingAs($user)->get($url);

            $response->assertStatus(400)->assertJsonPath('error', 'invalid_scope');
            $this->assertNull($response->headers->get('Location'), json_encode($scope));
        }
    }

    // ───────────── token 端點只放行 authorization_code ─────────────

    #[Test]
    public function the_token_endpoint_only_accepts_the_authorization_code_grant(): void {
        $user = $this->makeUser();
        [$client, $secret] = $this->makeClient();
        $flow = $this->exchange($client, $secret, $user)->assertOk()->json();

        $machine = app(ClientRepository::class)->createClientCredentialsGrantClient('machine client');

        $attempts = [
            // client 自己的 grant_types 欄位允許，也不可以：不需要使用者的 token 不該存在。
            'client_credentials' => ['grant_type' => 'client_credentials', 'client_id' => $machine->getKey(), 'client_secret' => $machine->plainSecret],
            // refresh 的 isActive() 把關是第 4 步；完成前整條路徑關閉。
            'refresh_token' => ['grant_type' => 'refresh_token', 'client_id' => $client->getKey(), 'client_secret' => $secret, 'refresh_token' => $flow['refresh_token']],
            'password' => ['grant_type' => 'password', 'client_id' => $client->getKey(), 'client_secret' => $secret, 'username' => 'alice@example.org', 'password' => 'secret-password'],
            'missing' => ['client_id' => $client->getKey(), 'client_secret' => $secret],
            'bogus' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'client_id' => $client->getKey(), 'client_secret' => $secret],
        ];

        foreach ($attempts as $label => $body) {
            $response = $this->post('/oauth/token', $body);

            $response->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
            $this->assertNull($response->json('access_token'), $label);
        }
    }
}
