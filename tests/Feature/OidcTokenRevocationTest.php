<?php

namespace Tests\Feature;

use App\Http\Controllers\ManagementController;
use App\Models\User;
use App\Services\AccountAccessRevoker;
use App\Services\SecurityAuditLogger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 停用帳號時撤銷 OIDC（Passport）憑證（docs/OIDC_PROVIDER_PLAN.md §8 第 4 步）。
 *
 * 停用帳號原本只撤銷 Sanctum 的 personal_access_tokens；Passport 是另一套表，已簽出的 access token 會活到自己過期、
 * 尚未兌換的授權碼也還能換出新 token。這裡鎖住 AccountAccessRevoker::revokeOidcTokens() 的語意與兩個呼叫點。
 */
class OidcTokenRevocationTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

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

    private function makeUser(string $email): User {
        $user = new User();
        $user->forceFill(['name' => $email, 'email' => $email, 'password' => 'x', 'is_active' => User::STATUS_ACTIVE])->save();

        return $user;
    }

    /** 為使用者寫入 2 個 access token（其中一個已撤銷）、1 張授權碼、各 access token 一個 refresh token。 */
    private function seedTokens(User $user, string $prefix): void {
        $clientId = '00000000-0000-0000-0000-000000000001';
        $now = now();

        DB::table('oauth_access_tokens')->insert([
            ['id' => "{$prefix}-a1", 'user_id' => $user->id, 'client_id' => $clientId, 'scopes' => '[]', 'revoked' => false, 'created_at' => $now, 'updated_at' => $now, 'expires_at' => $now->copy()->addMinutes(10)],
            ['id' => "{$prefix}-a2", 'user_id' => $user->id, 'client_id' => $clientId, 'scopes' => '[]', 'revoked' => true, 'created_at' => $now, 'updated_at' => $now, 'expires_at' => $now->copy()->addMinutes(10)],
        ]);
        DB::table('oauth_auth_codes')->insert([
            ['id' => "{$prefix}-c1", 'user_id' => $user->id, 'client_id' => $clientId, 'scopes' => '[]', 'revoked' => false, 'expires_at' => $now->copy()->addMinutes(10)],
        ]);
        DB::table('oauth_refresh_tokens')->insert([
            ['id' => "{$prefix}-r1", 'access_token_id' => "{$prefix}-a1", 'revoked' => false, 'expires_at' => $now->copy()->addDays(7)],
            // 其 access token 已被撤銷，refresh token 仍要被撤。
            ['id' => "{$prefix}-r2", 'access_token_id' => "{$prefix}-a2", 'revoked' => false, 'expires_at' => $now->copy()->addDays(7)],
        ]);
    }

    private function unrevokedRows(string $prefix): int {
        return DB::table('oauth_access_tokens')->where('id', 'like', "{$prefix}-%")->where('revoked', false)->count()
            + DB::table('oauth_auth_codes')->where('id', 'like', "{$prefix}-%")->where('revoked', false)->count()
            + DB::table('oauth_refresh_tokens')->where('id', 'like', "{$prefix}-%")->where('revoked', false)->count();
    }

    private function revoker(): AccountAccessRevoker {
        return app(AccountAccessRevoker::class);
    }

    #[Test]
    public function it_revokes_every_credential_of_that_user_and_nothing_of_anyone_else(): void {
        $target = $this->makeUser('target@example.org');
        $bystander = $this->makeUser('bystander@example.org');
        $this->seedTokens($target, 't');
        $this->seedTokens($bystander, 'b');

        $revoked = $this->revoker()->revokeOidcTokens($target, 'management_ui');

        // 未撤銷的：access t-a1、授權碼 t-c1、refresh t-r1 與 t-r2（t-a2 本來就已撤銷）。
        $this->assertSame(4, $revoked);
        $this->assertSame(0, $this->unrevokedRows('t'), '該使用者不可再有任何有效的 OIDC 憑證。');
        $this->assertSame(4, $this->unrevokedRows('b'), '其他使用者的憑證不可被動到。');
    }

    #[Test]
    public function it_keeps_the_rows_for_forensics_and_is_idempotent(): void {
        $user = $this->makeUser('target@example.org');
        $this->seedTokens($user, 't');

        $this->assertSame(4, $this->revoker()->revokeOidcTokens($user, 'cli'));
        $this->assertSame(0, $this->revoker()->revokeOidcTokens($user, 'cli'), '第二次沒有東西可撤。');

        // 撤銷而非刪除：列仍在，供事後追溯；清理交給 passport:purge。
        $this->assertSame(2, DB::table('oauth_access_tokens')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('oauth_auth_codes')->where('user_id', $user->id)->count());
        $this->assertSame(2, DB::table('oauth_refresh_tokens')->count());
    }

    #[Test]
    public function it_also_revokes_the_refresh_token_of_an_already_revoked_access_token(): void {
        $user = $this->makeUser('target@example.org');
        $this->seedTokens($user, 't');

        $this->revoker()->revokeOidcTokens($user, 'management_ui');

        $this->assertSame(1, (int) DB::table('oauth_refresh_tokens')->where('id', 't-r2')->value('revoked'));
    }

    #[Test]
    public function it_handles_a_user_with_many_tokens_without_hitting_binding_limits(): void {
        $user = $this->makeUser('busy@example.org');
        $now = now();
        $rows = [];
        $refresh = [];
        for ($i = 0; $i < 1200; $i++) {
            $rows[] = ['id' => "m-a{$i}", 'user_id' => $user->id, 'client_id' => 'c', 'scopes' => '[]', 'revoked' => false, 'created_at' => $now, 'updated_at' => $now, 'expires_at' => $now];
            $refresh[] = ['id' => "m-r{$i}", 'access_token_id' => "m-a{$i}", 'revoked' => false, 'expires_at' => $now];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('oauth_access_tokens')->insert($chunk);
        }
        foreach (array_chunk($refresh, 200) as $chunk) {
            DB::table('oauth_refresh_tokens')->insert($chunk);
        }

        $this->assertSame(2400, $this->revoker()->revokeOidcTokens($user, 'cli'));
        $this->assertSame(0, $this->unrevokedRows('m'));
    }

    #[Test]
    public function it_is_a_no_op_when_the_oauth_tables_do_not_exist(): void {
        $user = $this->makeUser('target@example.org');
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_auth_codes');
        Schema::dropIfExists('oauth_access_tokens');

        $this->assertSame(0, $this->revoker()->revokeOidcTokens($user, 'management_ui'));
    }

    #[Test]
    public function it_records_an_audit_event_only_when_something_was_revoked(): void {
        $logger = Mockery::spy(SecurityAuditLogger::class);
        $revoker = new AccountAccessRevoker($logger);
        $user = $this->makeUser('target@example.org');

        $revoker->revokeOidcTokens($user, 'management_ui');
        $logger->shouldNotHaveReceived('record');

        $this->seedTokens($user, 't');
        $revoker->revokeOidcTokens($user, 'management_ui');

        $logger->shouldHaveReceived('record')->once()->withArgs(function (...$args) use ($user): bool {
            $named = $args;

            return ($named['event'] ?? $args[3] ?? null) === 'oidc_tokens_revoked_on_deactivation'
                && ($named['operation'] ?? $args[1] ?? null) === 'UPDATE'
                && ($named['rowPk'] ?? $args[2] ?? null) === ['user_id' => $user->id];
        });
    }

    // ───────────── 兩個呼叫點 ─────────────

    #[Test]
    public function the_management_ui_revocation_path_also_revokes_oidc_tokens(): void {
        $user = $this->makeUser('target@example.org');
        $this->seedTokens($user, 't');

        // 停用／軟刪除／角色異動都走這個私有方法（ManagementController 的兩個呼叫點）。
        $method = new ReflectionMethod(ManagementController::class, 'revokeApiTokens');
        $method->setAccessible(true);
        $method->invoke(new ManagementController(), $user);

        $this->assertSame(0, $this->unrevokedRows('t'));
    }

    #[Test]
    public function both_deactivation_paths_call_the_oidc_revoker(): void {
        // 靜態守衛：有人重構時把這兩行拿掉，整個「停用後 token 仍有效」的洞就回來了。
        // 只認真正的方法呼叫（`->revokeOidcTokens(`）：註解裡也會出現這個名字，單純比對字串會讓守卫在呼叫被刪掉後仍然通過。
        foreach (['app/Http/Controllers/ManagementController.php', 'app/Console/Commands/ManageUser.php'] as $file) {
            $this->assertMatchesRegularExpression('/->revokeOidcTokens\(\$user,/', (string) file_get_contents(base_path($file)), $file);
        }
    }
}
