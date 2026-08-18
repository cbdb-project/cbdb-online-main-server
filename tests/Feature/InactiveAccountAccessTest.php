<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P0-2：讓「停用」真正等於「斷訪問」。
 *
 * 在此之前，`auth` / `auth:sanctum` 只確認「登入有效／token 有效」，不複查 is_active，
 * 所以被停用帳號的 session 與 API token 都還能繼續用。這裡把三件事釘住：
 *
 *  1. 未啟用（含被停用）帳號的 session 在所有 auth 路由一律 403；
 *  2. 未啟用帳號的 bearer token 在 auth:sanctum 與 auth.optional 一律 403；
 *  3. 停用／軟刪除帳號時，該帳號的 Sanctum token 會被真的刪掉並記入 audit_log。
 *
 * 同時釘住一條刻意的例外：auth.optional 的 **session** 路徑不擋（那組路由允許訪客讀取，
 * 一律 403 會讓「已登入但未啟用」比登出還不如）。所有 v2 寫入端點在控制器層自帶
 * isActive() 閘門，所以這個例外不會放過寫入。
 */
class InactiveAccountAccessTest extends TestCase {
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
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function makeUser(int $active, int $role = User::ROLE_REGULAR, string $email = 'u@example.com'): User {
        return User::unguarded(fn () => User::create([
            'name' => 'U',
            'email' => $email,
            'password' => bcrypt('secret'),
            'confirmation_token' => 'token',
            'is_active' => $active,
            'is_admin' => $role,
        ]));
    }

    // ───────── session 路徑：auth 路由 ─────────

    #[Test]
    public function inactive_session_is_forbidden_on_auth_routes(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE);

        foreach (['/profile', '/api-tokens', '/dashboard'] as $path) {
            $this->actingAs($user)->get($path)
                ->assertForbidden();
        }
    }

    #[Test]
    public function inactive_session_cannot_mint_api_tokens(): void {
        // 這是原本最刺眼的洞：未啟用帳號可以自己簽發一個能力為 ['*'] 的 token。
        $user = $this->makeUser(User::STATUS_INACTIVE);

        $this->actingAs($user)->postJson('/api-tokens', ['name' => 'pwn'])
            ->assertForbidden();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    #[Test]
    public function reserved_status_is_also_treated_as_inactive(): void {
        // is_active=2（「激活郵件」保留值）不是 STATUS_ACTIVE，一樣不得放行。
        $user = $this->makeUser(User::STATUS_RESERVED);

        $this->actingAs($user)->get('/profile')->assertForbidden();
    }

    #[Test]
    public function active_session_still_passes(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE);

        $this->actingAs($user)->get('/profile')->assertOk();
    }

    #[Test]
    public function guest_is_still_redirected_to_login_not_forbidden(): void {
        // 未登入的處理不能被這次改動波及：應該是導向登入頁，而不是 403。
        $this->get('/profile')->assertRedirect(route('login'));
    }

    // ───────── bearer token 路徑 ─────────

    #[Test]
    public function inactive_bearer_token_is_forbidden_on_sanctum_route(): void {
        $user = $this->makeUser(User::STATUS_INACTIVE);
        $token = $user->createToken('cli')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertForbidden()
            ->assertJson(['message' => __('auth.account_inactive')]);
    }

    #[Test]
    public function active_bearer_token_still_passes_on_sanctum_route(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE);
        $token = $user->createToken('cli')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk();
    }

    #[Test]
    public function inactive_bearer_token_is_forbidden_on_optional_auth_route(): void {
        // auth.optional 允許訪客，但「明確帶了憑證」就該按憑證主體的帳號狀態判定，
        // 否則停用帳號留下的 token 仍能繼續讀取。
        $user = $this->makeUser(User::STATUS_INACTIVE);
        $token = $user->createToken('cli')->plainTextToken;

        // 這條由 OptionalAuthentication 自己擋（訊息措辭屬該 middleware），這裡只驗安全性質。
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/select/dynasty')
            ->assertForbidden();
    }

    // ───────── 停用即撤銷憑證 ─────────

    #[Test]
    public function deactivating_a_user_revokes_all_api_tokens_and_audits_it(): void {
        $admin = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN, 'admin@example.com');
        $victim = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_REGULAR, 'victim@example.com');
        $victim->createToken('laptop');
        $victim->createToken('script');

        $this->assertSame(2, DB::table('personal_access_tokens')->count());

        $this->actingAs($admin)->put('/manage/'.$victim->id, [
            'is_active' => User::STATUS_INACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ])->assertRedirect(route('manage.index'));

        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        $audit = DB::table('audit_log')->where('table_name', 'personal_access_tokens')->first();
        $this->assertNotNull($audit);
        $this->assertSame('DELETE', $audit->operation);
        $this->assertSame((string) $admin->id, $audit->actor_id);
        // DELETE：被銷毀的狀態記在 old_data，new_data 為 null。
        $this->assertNull($audit->new_data);
        $oldData = json_decode($audit->old_data, true);
        $this->assertSame('account_deactivated', $oldData['reason']);
        $this->assertSame('management_ui', $oldData['context']);
        $this->assertCount(2, $oldData['tokens']);
    }

    #[Test]
    public function soft_deleting_a_user_revokes_all_api_tokens(): void {
        $admin = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN, 'admin@example.com');
        $victim = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_REGULAR, 'victim@example.com');
        $victim->createToken('laptop');

        $this->actingAs($admin)->put('/manage/'.$victim->id, [
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
            'delete_user' => 1,
        ])->assertRedirect(route('manage.index'));

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    // ───────── 登入本身就擋掉 ─────────
    //
    // 「未啟用不得登入／已啟用可登入」由 InactiveAccountLoginTest 覆蓋，這裡不重複，
    // 只補一條它沒測的區分：別把「帳號待啟用」和「密碼打錯」混成同一個訊息。

    #[Test]
    public function wrong_password_still_reports_bad_credentials_not_inactive(): void {
        $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'pending@example.com');

        $this->post('/login', ['email' => 'pending@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }

    // ───────── 軟刪除的帳號不得留下任何可用憑證 ─────────

    #[Test]
    public function soft_deleting_a_user_also_deactivates_the_account(): void {
        // 軟刪除原本只換 password／confirmation_token／remember_token，is_active 不動，
        // 所以被刪帳號殘留的 session 仍會通過 is_active 複查而保有完整權限。
        $admin = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN, 'admin@example.com');
        $victim = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN, 'victim@example.com');

        $this->actingAs($admin)->put('/manage/'.$victim->id, [
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
            'delete_user' => 1,
        ])->assertRedirect(route('manage.index'));

        $victim->refresh();
        $this->assertSame(User::STATUS_INACTIVE, $victim->is_active);
    }

    #[Test]
    public function the_password_less_email_verify_login_endpoint_is_gone(): void {
        // 這條端點不掛 auth 卻直接 Auth::login()，而 confirmation_token 永久有效、會經 URL
        // 流入 access log／Referer。曾可用 GET /email/verify/- 命中軟刪除的哨兵值直接登入超管，
        // 也可用另一端點換到的 token 免密碼登入。已於 P0-2 整條下架。
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('email.verify'),
            'email.verify 路由應已下架'
        );

        $this->get('/email/verify/-')->assertNotFound();
        $this->assertGuest();
    }

    #[Test]
    public function the_v1_token_handout_endpoint_is_gone(): void {
        // GET /api/v1/user 用密碼換 confirmation_token，既不查 isActive() 也不查眾包身分，
        // 是 Api\OperationsController@token 那道閘門的直通後門。已於 P0-2 下架。
        $this->makeUser(User::STATUS_INACTIVE, User::ROLE_REGULAR, 'pending@example.com');

        $this->get('/api/v1/user?q=pending@example.com&p=secret')->assertNotFound();
    }

    #[Test]
    public function deactivated_account_cannot_exchange_password_for_a_token(): void {
        $this->makeUser(User::STATUS_INACTIVE, User::ROLE_CROWDSOURCING, 'crowd@example.com');

        $response = $this->get('/api/operations/token?q=crowd@example.com&p=secret');

        // #1264 起改回 403（原本三條失敗路徑都回 200，會讓「把 200 的 body 當 token 用」的
        // 客戶端拿錯誤字串當憑證）。body 文字不變。
        $response->assertStatus(403);
        $this->assertSame(__('auth.account_inactive'), $response->getContent());
    }

    #[Test]
    public function the_token_handout_endpoint_never_creates_a_session(): void {
        // 這條路由不掛 auth，但 api group 的 EnsureFrontendRequestsAreStateful 在請求來自
        // 前端網域時會補上 StartSession——若用 Auth::attempt()，一個「發放 token」的端點
        // 就會有登入副作用，停用帳號那條分支還會留下已認證 session 等帳號重啟後復活。
        $this->makeUser(User::STATUS_ACTIVE, User::ROLE_CROWDSOURCING, 'crowd@example.com');

        $this->get('/api/operations/token?q=crowd@example.com&p=secret')->assertOk();
        $this->assertGuest();

        $this->makeUser(User::STATUS_INACTIVE, User::ROLE_CROWDSOURCING, 'off@example.com');

        // 停用帳號在 #1264 之後是 403（不是 200），重點不變：不得留下 session。
        $this->get('/api/operations/token?q=off@example.com&p=secret')->assertStatus(403);
        $this->assertGuest();
    }

    // ───────── 停用者要能看到原因、不能被彈開 ─────────

    #[Test]
    public function inactive_user_with_a_stale_session_can_still_reach_the_login_page(): void {
        // guest middleware（RedirectIfAuthenticated）原本只看 Auth::check()，會把握著舊 session
        // 的停用者從 /login 彈回 /home，於是他永遠看不到「此帳號尚未啟用」的說明。
        $user = $this->makeUser(User::STATUS_INACTIVE);

        $this->actingAs($user)->get('/login')->assertOk();
    }

    #[Test]
    public function inactive_user_with_a_stale_session_gets_the_reason_when_submitting_login(): void {
        // 不釘死訊息字串（那是 LoginController 的措辭），只要求「有錯誤回饋且沒被登入」。
        $user = $this->makeUser(User::STATUS_INACTIVE);

        $this->actingAs($user)
            ->post('/login', ['email' => 'u@example.com', 'password' => 'secret'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function active_user_is_still_bounced_away_from_the_login_page(): void {
        $user = $this->makeUser(User::STATUS_ACTIVE);

        $this->actingAs($user)->get('/login')->assertRedirect('/home');
    }

    #[Test]
    public function keeping_a_user_active_and_unchanged_does_not_touch_their_tokens(): void {
        // 角色異動也會撤銷憑證（見 performUserUpdate），所以這裡兩者都不動，
        // 單純驗證「沒有停用就不該動 token」。
        $admin = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_SUPER_ADMIN, 'admin@example.com');
        $target = $this->makeUser(User::STATUS_ACTIVE, User::ROLE_REGULAR, 'target@example.com');
        $target->createToken('laptop');

        $this->actingAs($admin)->put('/manage/'.$target->id, [
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ])->assertRedirect(route('manage.index'));

        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->assertSame(0, DB::table('audit_log')->where('table_name', 'personal_access_tokens')->count());
    }
}
