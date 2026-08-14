<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #1248：`GET /api/user` 不得外洩 `confirmation_token`。
 *
 * 背景：該端點原本 `return $request->user()`，序列化範圍由 `User::$hidden` 決定，而
 * `confirmation_token` 不在其中。它是 `/api/operations/*` 舊眾包通道的長期憑證
 * （無到期、無撤銷、不驗白名單），因此任何 Sanctum token 都能經此換到一個
 * 可直接寫 operations 的憑證——提權路徑，不只是欄位過度曝露。
 *
 * 這裡釘住三件事：
 *  1. 端點回應是顯式白名單（含呼叫端需要的欄位，不含任何憑證欄）；
 *  2. User 模型的整包序列化也不再帶 `confirmation_token`（縱深防禦）；
 *  3. 屬性讀取仍可取得該值——否則 `/api/operations/token` 會被這次改動弄壞。
 */
class ApiUserPayloadTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config(['cache.default' => 'array']);
        config(['session.driver' => 'array']);

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

        Schema::dropIfExists('personal_access_tokens');
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // 舊眾包通道的整合測試需要這兩張表：token 端點會寫安全審計，add 端點會寫 operations。
        Schema::dropIfExists('operations');
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid')->default(0);
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->longText('resource_data');
            $table->longText('resource_original')->nullable();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->timestamps();
        });

        Schema::dropIfExists('audit_log');
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->char('operation_id', 26);
            $table->json('row_pk');
            $table->string('row_pk_text', 512);
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function makeActiveUser(): User {
        return User::unguarded(fn () => User::create([
            'name' => '王小明',
            'email' => 'u@example.com',
            'password' => bcrypt('secret'),
            'institution' => 'Harvard',
            'settings' => ['locale' => 'zh-TW'],
            'avatar' => 'avatars/u.png',
            'confirmation_token' => 'super-secret-legacy-credential',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]));
    }

    #[Test]
    public function api_user_does_not_expose_credential_columns(): void {
        $user = $this->makeActiveUser();
        $token = $user->createToken('cli')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk();

        $body = $response->json();

        // 憑證欄一律不得出現。confirmation_token 是本次的主角（見類別註解）。
        $this->assertArrayNotHasKey('confirmation_token', $body);
        $this->assertArrayNotHasKey('password', $body);
        $this->assertArrayNotHasKey('remember_token', $body);
        // 連字串比對也做一次：避免將來有人換個鍵名輸出同一個值。
        $response->assertDontSee('super-secret-legacy-credential');
    }

    #[Test]
    public function api_user_returns_exactly_the_documented_whitelist(): void {
        // 白名單以「相等」而非「包含」斷言：新增欄位必須是刻意的決定，
        // 否則 users 加一欄就會靜默對外（正是本次要治的根因）。
        $user = $this->makeActiveUser();
        $token = $user->createToken('cli')->plainTextToken;

        $body = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk()
            ->json();

        $this->assertSame([
            'avatar',
            'created_at',
            'email',
            'id',
            'institution',
            'is_active',
            'is_admin',
            'name',
            'updated_at',
        ], collect(array_keys($body))->sort()->values()->all());

        $this->assertSame($user->id, $body['id']);
        $this->assertSame('王小明', $body['name']);
        $this->assertSame('u@example.com', $body['email']);
        $this->assertSame(User::STATUS_ACTIVE, $body['is_active']);
    }

    #[Test]
    public function api_user_requires_a_token(): void {
        // 沒有憑證時不得回任何帳號資料（未啟用帳號的 403 已由 InactiveAccountAccessTest 覆蓋）。
        $this->getJson('/api/user')->assertUnauthorized();
    }

    #[Test]
    public function api_user_returns_the_token_owner_not_another_account(): void {
        $owner = $this->makeActiveUser();
        $other = User::unguarded(fn () => User::create([
            'name' => '另一個人',
            'email' => 'other@example.com',
            'password' => bcrypt('secret'),
            'confirmation_token' => 'other-secret',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]));

        $body = $this->withHeader('Authorization', 'Bearer '.$owner->createToken('cli')->plainTextToken)
            ->getJson('/api/user')
            ->assertOk()
            ->json();

        $this->assertSame($owner->id, $body['id']);
        $this->assertNotSame($other->id, $body['id']);
        $this->assertSame(User::ROLE_REGULAR, $body['is_admin']);
    }

    #[Test]
    public function user_model_serialization_hides_confirmation_token(): void {
        // 縱深防禦：任何把 User 整包序列化的路徑（回應、日誌、事件 payload）都不該帶憑證。
        $user = $this->makeActiveUser();

        $this->assertArrayNotHasKey('confirmation_token', $user->toArray());
        $this->assertStringNotContainsString('super-secret-legacy-credential', $user->toJson());
    }

    #[Test]
    public function confirmation_token_is_still_readable_as_an_attribute(): void {
        // $hidden 只影響序列化。舊眾包通道（/api/operations/token 回傳此值、
        // resolveActiveUserByToken() 以此查人）走屬性讀取，必須維持可用。
        $user = $this->makeActiveUser();

        $this->assertSame('super-secret-legacy-credential', $user->confirmation_token);
        $this->assertSame(
            $user->id,
            User::where('confirmation_token', 'super-secret-legacy-credential')->first()->id
        );
    }

    #[Test]
    public function legacy_crowdsourcing_channel_still_issues_and_accepts_the_token(): void {
        // 端到端釘住「這次改動沒有弄壞舊通道」：上面那條只驗屬性讀取，證明力不足——
        // token 端點與寫入端才是真正的使用者。眾包身分 + 啟用 + 密碼正確才發 token。
        $user = User::unguarded(fn () => User::create([
            'name' => '眾包投稿者',
            'email' => 'crowd@example.com',
            'password' => bcrypt('secret-password'),
            'confirmation_token' => 'legacy-channel-credential',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_CROWDSOURCING,
        ]));

        // 1) 發放：回應 body 就是 confirmation_token 原文。
        $issued = $this->get('/api/operations/token?q=crowd@example.com&p=secret-password')
            ->assertOk()
            ->getContent();
        $this->assertSame('legacy-channel-credential', $issued);

        // 2) 認證：用該 token 走舊寫入端點，應被接受並寫進 operations。
        $this->postJson('/api/operations/add', [
            'token' => $issued,
            'resource' => 'BIOG_MAIN',
            'json' => '{"c_personid":1762,"c_notes":"crowdsourced note"}',
        ])->assertOk()->assertJson(['status_code' => 200]);

        $this->assertDatabaseHas('operations', [
            'user_id' => $user->id,
            'resource' => 'BIOG_MAIN',
            'crowdsourcing_status' => 2,
            'op_type' => 1,
        ]);
    }
}
