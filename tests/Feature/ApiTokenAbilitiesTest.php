<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ApiTokenAbilities;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P1-4：API token 不再簽發 Sanctum 的通配能力 `*`。
 *
 * `*` 的意思是「這個 token 自動擁有將來新增的每一種能力」。全站目前只有 EnsureMcpAbility
 * 會檢查 abilities，所以通配今天沒有多給權限；但只要日後有人加一個 ability-gated 的寫入
 * 端點，所有既存 token 就立刻獲得授權。
 */
class ApiTokenAbilitiesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        // 「設定漂移只記一次」是 process 級狀態，測試之間要清掉才不會互相干擾。
        ApiTokenAbilities::forgetReportedDrift();

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
    }

    protected function tearDown(): void {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    /** 每次 include 都會回傳一個新的匿名 class 實例，可安全重複呼叫。 */
    private function runDowngradeMigration(): void {
        (include database_path('migrations/2026_08_11_000001_downgrade_wildcard_api_token_abilities.php'))->up();
    }

    private function activeUser(): User {
        return User::unguarded(fn () => User::create([
            'name' => 'U',
            'email' => 'u@example.com',
            'password' => bcrypt('secret'),
            'confirmation_token' => 'token',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]));
    }

    #[Test]
    public function created_token_defaults_to_the_minimum_ability_not_wildcard(): void {
        $user = $this->activeUser();

        $this->actingAs($user)
            ->postJson('/api-tokens', ['name' => 'laptop'])
            ->assertOk()
            ->assertJsonPath('token.abilities', ApiTokenAbilities::default());

        $stored = json_decode(DB::table('personal_access_tokens')->value('abilities'), true);
        $this->assertNotContains(ApiTokenAbilities::WILDCARD, $stored);
        $this->assertSame(ApiTokenAbilities::default(), $stored);
    }

    #[Test]
    public function wildcard_ability_is_rejected_even_when_asked_for_explicitly(): void {
        $user = $this->activeUser();

        $this->actingAs($user)
            ->postJson('/api-tokens', ['name' => 'pwn', 'abilities' => ['*']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('abilities.0');

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    #[Test]
    public function unknown_abilities_are_rejected(): void {
        $user = $this->activeUser();

        $this->actingAs($user)
            ->postJson('/api-tokens', ['name' => 'x', 'abilities' => ['biog:write']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('abilities.0');

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    #[Test]
    public function explicitly_requesting_an_allowed_ability_still_works(): void {
        $user = $this->activeUser();
        $allowed = ApiTokenAbilities::allowed()[0];

        $this->actingAs($user)
            ->postJson('/api-tokens', ['name' => 'mcp', 'abilities' => [$allowed]])
            ->assertOk()
            ->assertJsonPath('token.abilities', [$allowed]);
    }

    #[Test]
    public function mcp_still_accepts_a_default_token(): void {
        // 預設值必須真的能用在唯一的既有消費者上，否則「最小權限」等於「不能用」。
        $user = $this->activeUser();
        $token = $user->createToken('mcp', ApiTokenAbilities::default())->accessToken;

        $this->assertTrue($token->can(config('mcp.cbdb.required_ability', 'mcp:read')));
        $this->assertFalse($token->can('some:future:write'));
    }

    #[Test]
    public function no_production_code_calls_createToken_without_explicit_abilities(): void {
        // Sanctum 的 HasApiTokens::createToken() 第二參數預設是 ['*']，所以只要有人在
        // 生產碼寫 createToken('name') 就會重新產生通配 token，而且不會有任何報錯。
        // 這條把「必須顯式傳能力」釘住。
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach ($this->createTokenArgCounts((string) file_get_contents($file->getPathname())) as $line => $args) {
                if ($args < 2) {
                    $offenders[] = $file->getPathname().':'.$line.' 只傳了 '.$args.' 個參數';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "以下呼叫沒有顯式指定 abilities，會落回 Sanctum 的通配預設值：\n".implode("\n", $offenders)
        );
    }

    /**
     * 用 tokenizer 數每個 createToken( 呼叫的頂層參數個數。
     *
     * 刻意不用正則：呼叫是多行的、參數本身又含括號（`$request->input('name')`），
     * 字元類別式的正則會跨行並在第一個內層 `)` 就收尾，把合格的呼叫誤判成違規
     * （第一版就踩了這個坑）。
     *
     * @return array<int, int> line => 頂層參數個數
     */
    private function createTokenArgCounts(string $code): array {
        $tokens = token_get_all($code);
        $counts = [];

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'createToken') {
                continue;
            }

            // 找緊接的 '('（中間只可能有空白／註解）。
            $j = $i + 1;
            while ($j < count($tokens) && is_array($tokens[$j])
                && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j++;
            }
            if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
                continue;
            }

            $depth = 0;
            $commas = 0;
            $sawContent = false;

            for ($k = $j; $k < count($tokens); $k++) {
                $t = $tokens[$k];

                if ($t === '(' || $t === '[' || $t === '{') {
                    $depth++;

                    continue;
                }
                if ($t === ')' || $t === ']' || $t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }

                    continue;
                }
                if ($t === ',' && $depth === 1) {
                    $commas++;

                    continue;
                }
                if ($depth === 1 && (!is_array($t) || $t[0] !== T_WHITESPACE)) {
                    $sawContent = true;
                }
            }

            $counts[$token[2]] = $sawContent ? $commas + 1 : 0;
        }

        return $counts;
    }

    #[Test]
    public function a_wildcard_token_would_have_authorized_any_future_ability(): void {
        // 反向對照：說明為什麼要拿掉 `*`。
        $user = $this->activeUser();
        $token = $user->createToken('legacy', ['*'])->accessToken;

        $this->assertTrue($token->can('some:future:write'));
    }

    #[Test]
    public function the_migration_downgrades_existing_wildcard_tokens(): void {
        $user = $this->activeUser();
        $user->createToken('legacy-a', ['*']);
        $user->createToken('legacy-b', ['*', ApiTokenAbilities::allowed()[0]]);
        $user->createToken('already-scoped', ApiTokenAbilities::default());

        $this->runDowngradeMigration();

        foreach (DB::table('personal_access_tokens')->get() as $row) {
            $abilities = json_decode((string) $row->abilities, true);
            $this->assertNotContains(ApiTokenAbilities::WILDCARD, $abilities, "token {$row->name} 仍是通配");
            $this->assertSame(ApiTokenAbilities::default(), $abilities);
        }
    }

    #[Test]
    public function store_rechecks_is_active_inside_the_row_lock(): void {
        // 這條專門釘住 store() 交易內的重讀，所以必須繞過 middleware——否則 403 是
        // App\Http\Middleware\Authenticate 給的，把 store() 裡的 abort_if 整塊刪掉也會綠，
        // 等於假保證（實測過：刪掉後 8 個測試全過）。
        //
        // 手法：actingAs 用 setUser() 注入這個實例，middleware 的 isActive() 讀到的是
        // 記憶體裡還是 1 的舊值而放行；但交易內是重新 SELECT users 列，看到的是 0。
        // 這正是「管理員在請求進行中把帳號停用」的那個交錯。
        $user = $this->activeUser();
        $this->actingAs($user);

        DB::table('users')->where('id', $user->id)->update(['is_active' => User::STATUS_INACTIVE]);
        $this->assertTrue($user->isActive(), '前置條件：記憶體中的實例必須仍是啟用狀態');

        $this->postJson('/api-tokens', ['name' => 'x'])->assertForbidden();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    #[Test]
    public function a_deactivated_account_is_blocked_before_reaching_the_controller(): void {
        $user = $this->activeUser();
        $user->is_active = User::STATUS_INACTIVE;
        $user->save();

        $this->actingAs($user)
            ->postJson('/api-tokens', ['name' => 'x'])
            ->assertForbidden();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    #[Test]
    public function an_empty_abilities_array_is_rejected_rather_than_minting_a_useless_token(): void {
        $user = $this->activeUser();

        $this->actingAs($user)
            ->postJson('/api-tokens', ['name' => 'x', 'abilities' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('abilities');

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    #[Test]
    public function duplicate_abilities_are_collapsed_not_rejected(): void {
        // 重複元素對授權毫無意義，不該因此被擋下——但也不能原樣存進去。
        $user = $this->activeUser();
        $allowed = ApiTokenAbilities::allowed()[0];

        $this->actingAs($user)
            ->postJson('/api-tokens', ['name' => 'x', 'abilities' => [$allowed, $allowed, $allowed]])
            ->assertOk()
            ->assertJsonPath('token.abilities', [$allowed]);

        $this->assertSame(
            [$allowed],
            json_decode(DB::table('personal_access_tokens')->value('abilities'), true)
        );
    }

    #[Test]
    public function an_oversized_abilities_payload_is_rejected(): void {
        // abilities 是 TEXT 且生產 sql_mode 沒有 STRICT_TRANS_TABLES：上千個元素會被靜默
        // 截斷到 65535 bytes，之後 json_decode 回 null、Sanctum 的 can() 直接拋 TypeError，
        // 那個 token 就永久壞掉。去重之後仍超過允許能力總數的請求一律擋下。
        $user = $this->activeUser();

        $this->actingAs($user)
            ->postJson('/api-tokens', [
                'name' => 'x',
                'abilities' => array_map(fn (int $i) => 'bogus:'.$i, range(1, 5000)),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('abilities');

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    #[Test]
    public function the_migration_does_not_escalate_a_token_that_already_has_allowed_abilities(): void {
        // 判準是「含通配／不可解碼／完全沒有合法能力」；已帶合法能力的 token 應保留原有
        // 那一組。若日後 ALLOWED 長成多個能力，merge 式的寫法會把刻意只帶一項的 token 擴權。
        $user = $this->activeUser();
        $user->createToken('scoped', ApiTokenAbilities::default());
        $before = DB::table('personal_access_tokens')->value('abilities');

        $this->runDowngradeMigration();

        $this->assertSame($before, DB::table('personal_access_tokens')->value('abilities'));
    }

    #[Test]
    public function config_drift_is_reported_once_per_process_not_per_request(): void {
        // 每次建立 token 都寫一筆 Log::error 會把 log 洗爆。
        ApiTokenAbilities::forgetReportedDrift();
        config(['mcp.cbdb.required_ability' => 'mcp:nonexistent']);

        Log::shouldReceive('error')->once()->withAnyArgs();

        ApiTokenAbilities::assertMcpAbilityIsIssuable();
        ApiTokenAbilities::assertMcpAbilityIsIssuable();
        ApiTokenAbilities::assertMcpAbilityIsIssuable();
    }

    #[Test]
    public function the_wildcard_cannot_be_re_enabled_through_config(): void {
        // 曾經 allowed() 是從 config('mcp.cbdb.required_ability') 推導的，於是
        // MCP_REQUIRED_ABILITY=* 就能讓驗證放行通配、migration 也把 `*` 寫回去——
        // 一個環境變數無聲還原整個 P1-4。現在 ISSUABLE 是字面值清單且會過濾通配。
        config(['mcp.cbdb.required_ability' => '*']);

        $this->assertNotContains(ApiTokenAbilities::WILDCARD, ApiTokenAbilities::allowed());
        $this->assertNotContains(ApiTokenAbilities::WILDCARD, ApiTokenAbilities::default());

        $user = $this->activeUser();
        $this->actingAs($user)
            ->postJson('/api-tokens', ['name' => 'x', 'abilities' => ['*']])
            ->assertStatus(422);
    }

    #[Test]
    public function the_migration_heals_tokens_with_unreadable_abilities(): void {
        // abilities 為 NULL 的列會讓 can() 拋 TypeError；只判「含有 *」的版本永遠修不到它。
        $user = $this->activeUser();
        $user->createToken('legacy', ['*']);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'orphan',
            'token' => str_repeat('a', 64),
            'abilities' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runDowngradeMigration();

        foreach (DB::table('personal_access_tokens')->get() as $row) {
            $this->assertSame(
                ApiTokenAbilities::default(),
                json_decode((string) $row->abilities, true),
                "token {$row->name} 未被修復"
            );
        }
    }

    #[Test]
    public function the_migration_is_idempotent(): void {
        $user = $this->activeUser();
        $user->createToken('legacy', ['*']);

        $this->runDowngradeMigration();
        $first = DB::table('personal_access_tokens')->value('abilities');

        $this->runDowngradeMigration();

        $this->assertSame($first, DB::table('personal_access_tokens')->value('abilities'));
    }
}
