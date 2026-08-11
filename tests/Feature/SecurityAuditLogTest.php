<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P1-6：安全敏感操作留下應用層審計（含操作者、IP、User-Agent）。
 *
 * 為什麼不能只靠 DB trigger：trigger 看得到「哪一列的哪個欄位變了」，但看不到**是誰、
 * 從哪個 IP、用什麼客戶端**做的——那些只存在於 HTTP 請求裡。而 audit_log 原本只審計業務表
 * （BIOG_MAIN 等），users 與 personal_access_tokens 的變更完全沒有紀錄。
 *
 * 同時釘住一條紅線：**審計不得記錄密碼雜湊或 token 明文／雜湊**。洩漏的審計日誌不該
 * 變成第二個憑證來源。
 */
class SecurityAuditLogTest extends TestCase {
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

    private function activeUser(string $email = 'u@example.com'): User {
        return User::unguarded(fn () => User::create([
            'name' => '張三',
            'email' => $email,
            'password' => Hash::make('secret123'),
            'confirmation_token' => 'token',
            'avatar' => 'avatar0.png',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]));
    }

    /**
     * 取某個事件的 __security 脈絡，並可一併驗 table_name／operation／row_pk。
     *
     * 加上這些條件而不只比對事件名：只掃 event 的話，任何「事件寫到了錯的表、錯的
     * operation、或錯的受影響列」都不會被抓到。
     *
     * @return array<string, mixed>|null
     */
    private function securityContextFor(
        string $event,
        ?string $table = null,
        ?string $operation = null,
        ?array $rowPk = null
    ): ?array {
        foreach (DB::table('audit_log')->get() as $row) {
            foreach (['new_data', 'old_data'] as $column) {
                $payload = json_decode((string) $row->{$column}, true);
                if (!is_array($payload) || ($payload['__security']['event'] ?? null) !== $event) {
                    continue;
                }

                if ($table !== null) {
                    $this->assertSame($table, $row->table_name, "事件 {$event} 寫到了錯的表");
                }
                if ($operation !== null) {
                    $this->assertSame($operation, $row->operation, "事件 {$event} 用了錯的 operation");
                }
                if ($rowPk !== null) {
                    $this->assertSame($rowPk, json_decode((string) $row->row_pk, true), "事件 {$event} 的 row_pk 不符");
                }

                return $payload['__security'];
            }
        }

        return null;
    }

    #[Test]
    public function changing_the_password_is_audited_with_actor_ip_and_user_agent(): void {
        $user = $this->activeUser();

        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'ProbeBrowser/1.0'])
            ->patch('/profile', [
                'name' => '張三',
                'email' => 'u@example.com',
                'avatar' => 'avatar0.png',
                'current_password' => 'secret123',
                'new_password' => 'newsecret123',
                'new_password_confirmation' => 'newsecret123',
            ])->assertRedirect();

        $context = $this->securityContextFor('password_changed');

        $this->assertNotNull($context, '改密碼必須留下審計');
        $this->assertSame((int) $user->id, $context['actor_id']);
        $this->assertSame('張三', $context['actor_name']);
        $this->assertSame('203.0.113.9', $context['ip']);
        $this->assertSame('ProbeBrowser/1.0', $context['user_agent']);
    }

    #[Test]
    public function the_audit_never_contains_the_password_hash(): void {
        $user = $this->activeUser();
        $user->refresh();

        $this->actingAs($user)->patch('/profile', [
            'name' => '張三',
            'email' => 'u@example.com',
            'avatar' => 'avatar0.png',
            'current_password' => 'secret123',
            'new_password' => 'newsecret123',
            'new_password_confirmation' => 'newsecret123',
        ])->assertRedirect();

        $user->refresh();
        $dump = DB::table('audit_log')->get()->toJson();

        $this->assertStringNotContainsString($user->password, $dump, '審計不得包含密碼雜湊');
        $this->assertStringNotContainsString('newsecret123', $dump, '審計不得包含密碼明文');
    }

    #[Test]
    public function changing_the_email_is_audited_with_before_and_after(): void {
        // email 變更可用來劫持密碼重設，等於換走帳號的復原管道。
        $user = $this->activeUser();

        $this->actingAs($user)->patch('/profile', [
            'name' => '張三',
            'email' => 'moved@example.com',
            'avatar' => 'avatar0.png',
        ])->assertRedirect();

        // 精準取 email_changed 那一列：同一次提交若同時改密碼＋改 email（正是接管帳號的
        // 組合）會落兩列 users 審計，用 ->first() 可能拿到 password_changed 那列。
        $row = DB::table('audit_log')
            ->where('table_name', 'users')
            ->where('new_data', 'like', '%email_changed%')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('u@example.com', json_decode((string) $row->old_data, true)['email']);
        $this->assertSame('moved@example.com', json_decode((string) $row->new_data, true)['email']);
        $this->assertNotNull($this->securityContextFor('email_changed'));
    }

    #[Test]
    public function changing_both_password_and_email_records_both_events(): void {
        // 「改走 email 再換密碼」是接管帳號的標準劇本，兩件事都必須各自留下紀錄。
        $user = $this->activeUser();

        $this->actingAs($user)->patch('/profile', [
            'name' => '張三',
            'email' => 'moved@example.com',
            'avatar' => 'avatar0.png',
            'current_password' => 'secret123',
            'new_password' => 'newsecret123',
            'new_password_confirmation' => 'newsecret123',
        ])->assertRedirect();

        $this->assertNotNull($this->securityContextFor('password_changed'));
        $this->assertNotNull($this->securityContextFor('email_changed'));
        $this->assertSame(2, DB::table('audit_log')->where('table_name', 'users')->count());
    }

    #[Test]
    public function resetting_the_password_via_email_link_is_audited(): void {
        // 密碼的第二條寫入路徑。少了它，攻擊鏈的後半段（用重設連結換密碼）就沒有紀錄。
        $user = $this->activeUser();

        Schema::dropIfExists('password_resets');
        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        $token = app('auth.password.broker')->createToken($user);

        $this->post('/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertRedirect();

        $context = $this->securityContextFor(
            'password_reset_via_email',
            table: 'users',
            operation: 'UPDATE',
            rowPk: ['id' => (int) $user->id]
        );
        $this->assertNotNull($context, '經重設連結改密碼必須留下審計');
        $this->assertSame('http', $context['channel']);

        // actor 必須留空：trait 會在寫入密碼後 Auth::login()，若照常歸因就會把「持有重設連結
        // 的人」記成受影響帳號本人——攻擊者用竊得的 token 改密碼，事後卻顯示「使用者自己改的」，
        // 比不記更糟。受影響帳號由 row_pk 標明，可用線索是 IP。
        $this->assertNull($context['actor_id'], '重設連結的動作者不可歸因，actor 必須留空');
        $this->assertNull($context['actor_name']);

        $row = DB::table('audit_log')->where('table_name', 'users')->first();
        $this->assertSame('system', $row->actor_type);
        $this->assertSame('system', $row->actor_id);

        Schema::dropIfExists('password_resets');
    }

    #[Test]
    public function cli_audit_records_null_ip_instead_of_a_fake_local_address(): void {
        // Laravel 的 SetRequestForConsole 會造一個假請求，ip() 回 127.0.0.1、
        // userAgent() 回 'Symfony'。把那組值寫進審計，事故調查會讀成「有人從本機發 HTTP
        // 請求」而被帶偏——誤導的證據比沒有證據更糟。
        $this->activeUser('cli@example.com');

        // 每個選項都要給：缺任何一個，指令會改走互動式 confirm 而在測試中卡住。
        $this->artisan('cbdb:manage-user', [
            '--email' => 'cli@example.com',
            '--name' => 'CLI 使用者',
            '--password' => 'clisecret123',
            '--active' => '1',
            '--role' => 'expert',
        ])->assertExitCode(0);

        $context = $this->securityContextFor('password_changed_via_cli');
        $this->assertNotNull($context, 'CLI 改密碼必須留下審計');
        $this->assertSame('cli', $context['channel']);
        $this->assertNull($context['ip'], 'CLI 不得記假 IP');
        $this->assertNull($context['user_agent'], 'CLI 不得記假 User-Agent');

        $row = DB::table('audit_log')->where('table_name', 'users')->first();
        $this->assertSame('system', $row->actor_type, 'CLI 沒有登入使用者，actor 應為 system');
        $this->assertSame('system', $row->actor_id);
    }

    #[Test]
    public function issuing_a_crowdsourcing_token_is_audited_and_denials_too(): void {
        // 這是唯一剩下的長期憑證簽發路徑，且是無 throttle 的密碼驗證端點。
        User::unguarded(fn () => User::create([
            'name' => '眾包',
            'email' => 'crowd@example.com',
            'password' => Hash::make('secret123'),
            'confirmation_token' => 'crowd-token',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_CROWDSOURCING,
        ]));

        $this->get('/api/operations/token?q=crowd@example.com&p=secret123')->assertOk();
        $this->assertNotNull($this->securityContextFor('crowdsourcing_token_issued'));

        $this->get('/api/operations/token?q=crowd@example.com&p=wrong')->assertOk();
        $this->assertNotNull($this->securityContextFor('crowdsourcing_token_denied'));

        // 憑證本身絕不入庫。
        $dump = DB::table('audit_log')->get()->toJson();
        $this->assertStringNotContainsString('crowd-token', $dump);
    }

    #[Test]
    public function a_denial_for_an_unknown_account_does_not_invent_a_user_row(): void {
        // 記成 id=0 會憑空生出一列「受影響的 users.id=0」，反覆用不存在的 email 嘗試時，
        // 調查者會誤以為真有這個使用者。也不得把未驗證、無長度限制的請求輸入原樣入庫——
        // 這個端點沒有 throttle。
        $longEmail = str_repeat('a', 5000).'@example.com';

        $this->get('/api/operations/token?q='.urlencode($longEmail).'&p=whatever')->assertOk();

        $context = $this->securityContextFor(
            'crowdsourcing_token_denied',
            table: 'users',
            operation: 'UPDATE',
            rowPk: []
        );
        $this->assertNotNull($context);

        $row = DB::table('audit_log')->first();
        $payload = json_decode((string) $row->new_data, true);
        $this->assertFalse($payload['matched_user']);
        $this->assertNull($payload['email'], '查不到帳號時不得回記請求輸入的 email');
        $this->assertStringNotContainsString(str_repeat('a', 100), (string) $row->new_data);
    }

    #[Test]
    public function soft_delete_records_that_credentials_were_invalidated(): void {
        // 軟刪除實際改寫了 email／password／confirmation_token／remember_token，但審計原本
        // 只看得到 is_active／is_admin，事後無從確認那些憑證是否同步作廢。只記布林旗標。
        $admin = User::unguarded(fn () => User::create([
            'name' => '管理員',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret123'),
            'confirmation_token' => 'token',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]));
        $victim = $this->activeUser('victim@example.com');
        $victim->refresh();
        $originalHash = $victim->password;

        $this->actingAs($admin)->put('/manage/'.$victim->id, [
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
            'delete_user' => 1,
        ])->assertRedirect(route('manage.index'));

        $this->assertNotNull($this->securityContextFor(
            'user_soft_deleted',
            table: 'users',
            operation: 'DELETE',
            rowPk: ['id' => (int) $victim->id]
        ));

        $row = DB::table('audit_log')->where('table_name', 'users')->first();
        $payload = json_decode((string) $row->new_data, true);
        $this->assertTrue($payload['password_invalidated']);
        $this->assertTrue($payload['confirmation_token_invalidated']);
        $this->assertTrue($payload['remember_token_invalidated']);

        // 旗標而已，被作廢的實際憑證不得入庫（比對刪除前的雜湊；刪除後的值是哨兵 '-'，
        // 拿它去斷言「dump 不含 -」毫無意義，日期裡就有）。
        $dump = DB::table('audit_log')->get()->toJson();
        $this->assertStringNotContainsString($originalHash, $dump);
        $this->assertStringNotContainsString('victim@example.com', $dump);
    }

    #[Test]
    public function a_cosmetic_profile_edit_is_not_audited(): void {
        // 只改姓名／機構不是安全事件；審計要保持信噪比，否則沒人會去看。
        $user = $this->activeUser();

        $this->actingAs($user)->patch('/profile', [
            'name' => '張三豐',
            'email' => 'u@example.com',
            'institution' => '武當',
            'avatar' => 'avatar0.png',
        ])->assertRedirect();

        // 先確認寫入真的發生了：assertRedirect() 對驗證失敗的 302-back 也成立，
        // 哪天欄位規則改動讓這個請求被擋下，下面那句 count()===0 會空跑成永遠綠。
        $user->refresh();
        $this->assertSame('張三豐', $user->name);
        $this->assertSame('武當', $user->institution);

        $this->assertSame(0, DB::table('audit_log')->count());
    }

    #[Test]
    public function creating_an_api_token_is_audited_without_leaking_the_token(): void {
        $user = $this->activeUser();

        $response = $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_USER_AGENT' => 'TokenClient/2.0'])
            ->postJson('/api-tokens', ['name' => 'laptop'])
            ->assertOk();

        $plain = $response->json('token.plainTextToken');
        $this->assertNotEmpty($plain);

        $context = $this->securityContextFor('api_token_created');
        $this->assertNotNull($context, '簽發 token 必須留下審計');
        $this->assertSame('198.51.100.7', $context['ip']);
        $this->assertSame('TokenClient/2.0', $context['user_agent']);

        $dump = DB::table('audit_log')->get()->toJson();
        $this->assertStringNotContainsString($plain, $dump, '審計不得包含 token 明文');
        $this->assertStringNotContainsString(
            hash('sha256', explode('|', $plain)[1] ?? $plain),
            $dump,
            '審計不得包含 token 雜湊'
        );
    }

    #[Test]
    public function revoking_own_token_is_audited(): void {
        $user = $this->activeUser();
        $tokenId = $user->createToken('laptop', ['mcp:read'])->accessToken->id;

        $this->actingAs($user)
            ->deleteJson('/api-tokens/'.$tokenId)
            ->assertOk();

        $context = $this->securityContextFor('api_token_revoked_by_owner');
        $this->assertNotNull($context, '自行撤銷 token 必須留下審計');
        $this->assertSame((int) $user->id, $context['actor_id']);
    }

    #[Test]
    public function revoking_all_own_tokens_is_audited_once(): void {
        $user = $this->activeUser();
        $user->createToken('a', ['mcp:read']);
        $user->createToken('b', ['mcp:read']);

        // 建立時已各留一筆審計；先清掉，只看撤銷那一筆。
        DB::table('audit_log')->delete();

        $this->actingAs($user)->deleteJson('/api-tokens')->assertOk();

        $rows = DB::table('audit_log')->get();
        $this->assertCount(1, $rows, '批次撤銷應只寫一筆彙總審計');
        $before = json_decode((string) $rows->first()->old_data, true);
        $this->assertCount(2, $before['tokens']);
        $this->assertNotNull($this->securityContextFor('api_tokens_revoked_by_owner'));
    }

    #[Test]
    public function admin_status_changes_carry_request_context(): void {
        $admin = User::unguarded(fn () => User::create([
            'name' => '管理員',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret123'),
            'confirmation_token' => 'token',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]));
        $victim = $this->activeUser('victim@example.com');

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.44', 'HTTP_USER_AGENT' => 'AdminBrowser/3.0'])
            ->put('/manage/'.$victim->id, [
                'is_active' => User::STATUS_INACTIVE,
                'is_admin' => User::ROLE_REGULAR,
            ])->assertRedirect(route('manage.index'));

        $context = $this->securityContextFor('user_role_or_status_changed');
        $this->assertNotNull($context, '停用帳號必須帶請求脈絡');
        $this->assertSame((int) $admin->id, $context['actor_id']);
        $this->assertSame('管理員', $context['actor_name']);
        $this->assertSame('192.0.2.44', $context['ip']);
        $this->assertSame('AdminBrowser/3.0', $context['user_agent']);
    }

    #[Test]
    public function a_failing_audit_insert_does_not_break_the_business_action(): void {
        // 使用者改完密碼卻看到 500，只會讓他以為沒改成功而重試。
        //
        // 關鍵：必須讓 **insert 本身失敗**，而不是把表 drop 掉——後者會被
        // SecurityAuditLogger 的 Schema::hasTable 提早 return，根本走不到 try/catch，
        // 那樣這條測試就變成假保證（第一版正是如此，實測 Log::warning 從未被呼叫）。
        // 這裡加一個 NOT NULL 且無預設值的欄位，讓 insert 必定違反約束。
        $user = $this->activeUser();
        DB::statement('ALTER TABLE audit_log ADD COLUMN forced_failure TEXT NOT NULL');

        Log::spy();

        $this->actingAs($user)->patch('/profile', [
            'name' => '張三',
            'email' => 'u@example.com',
            'avatar' => 'avatar0.png',
            'current_password' => 'secret123',
            'new_password' => 'newsecret123',
            'new_password_confirmation' => 'newsecret123',
        ])->assertRedirect();

        $user->refresh();
        $this->assertTrue(Hash::check('newsecret123', $user->password), '密碼變更必須已生效');
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    #[Test]
    public function a_missing_audit_table_is_a_silent_no_op(): void {
        // 與上一條互補：表不存在時走 hasTable 早退，不該留 warning 噪音。
        $user = $this->activeUser();
        Schema::drop('audit_log');

        Log::spy();

        $this->actingAs($user)->patch('/profile', [
            'name' => '張三',
            'email' => 'u@example.com',
            'avatar' => 'avatar0.png',
            'current_password' => 'secret123',
            'new_password' => 'newsecret123',
            'new_password_confirmation' => 'newsecret123',
        ])->assertRedirect();

        $user->refresh();
        $this->assertTrue(Hash::check('newsecret123', $user->password));
        Log::shouldNotHaveReceived('warning');
    }
}
