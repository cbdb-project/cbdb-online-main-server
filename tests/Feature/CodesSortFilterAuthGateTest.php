<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * M2：app/codes/{table_name}（app.codes.show）sort_by/filters 登入門檻。
 * 見 docs/CODES_SORT_FILTER_AUTH_GATE.md §5（測試計劃 1-8）。Blade 版 codes/{table_name} 不受影響、不在此檔測試範圍。
 */
class CodesSortFilterAuthGateTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-codes-sort-filter-gate';
        if (!is_dir($compiledViewPath)) {
            mkdir($compiledViewPath, 0777, true);
        }
        config(['view.compiled' => $compiledViewPath]);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config(['codes.tables' => ['TEST_CODES' => '測試代碼']]);
        config(['codes.per_page' => 20]);

        Schema::create('TEST_CODES', function ($table) {
            $table->integer('code_id');
            $table->string('description')->nullable();
        });
        DB::table('TEST_CODES')->insert([
            ['code_id' => 1, 'description' => 'alpha'],
            ['code_id' => 2, 'description' => 'beta'],
            ['code_id' => 3, 'description' => 'gamma'],
        ]);
    }

    private function activeUser(string $name = 'active', int $id = 21): User {
        $user = new User(['name' => $name, 'email' => $name.'@example.com', 'confirmation_token' => Str::random(32)]);
        $user->id = $id;
        $user->is_active = 1;

        return $user;
    }

    private function inactiveUser(string $name = 'inactive', int $id = 22): User {
        $user = new User(['name' => $name, 'email' => $name.'@example.com', 'confirmation_token' => Str::random(32)]);
        $user->id = $id;
        $user->is_active = 0;

        return $user;
    }

    #[Test]
    public function testGuestWithoutSortOrFilterSeesTableNormally() {
        $this->get(route('app.codes.show', ['table_name' => 'TEST_CODES']))
            ->assertOk();
    }

    #[Test]
    public function testGuestWithSortByIsRedirectedToLoginWithIntendedUrlRecorded() {
        $url = route('app.codes.show', ['table_name' => 'TEST_CODES', 'sort_by' => 'description']);

        $this->get($url)
            ->assertRedirect(route('login'));

        $this->assertSame($url, session('url.intended'));
    }

    #[Test]
    public function testGuestWithNonEmptyFilterIsRedirectedToLogin() {
        $this->get(route('app.codes.show', ['table_name' => 'TEST_CODES', 'filters' => ['description' => 'beta']]))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function testGuestWithEmptyFilterValueIsNotBlocked() {
        $this->get(route('app.codes.show', ['table_name' => 'TEST_CODES', 'filters' => ['description' => '']]))
            ->assertOk();
    }

    #[Test]
    public function testGuestWithNonExistentSortColumnIsStillRedirected() {
        // gate 是簡化判定（見 §4.1），即使欄位不存在、buildShowPayload() 本來會忽略，也要擋。
        $this->get(route('app.codes.show', ['table_name' => 'TEST_CODES', 'sort_by' => 'not_a_real_column']))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function testActiveUserWithSortAndFilterIsUnaffected() {
        $this->actingAs($this->activeUser());

        $this->get(route('app.codes.show', [
            'table_name' => 'TEST_CODES',
            'sort_by' => 'description',
            'filters' => ['description' => 'beta'],
        ]))->assertOk();
    }

    #[Test]
    public function testInactiveUserWithSortByIsBlockedWithFlashNotInsteadOfLoginRedirect() {
        $this->actingAs($this->inactiveUser());

        $response = $this->get(route('app.codes.show', ['table_name' => 'TEST_CODES', 'sort_by' => 'description']));

        $response->assertStatus(302);
        $this->assertNotSame(route('login'), $response->headers->get('Location'));
        $this->assertTrue(session()->has('flash_notification'));
    }

    #[Test]
    public function testGuestWithSortByAndInertiaHeaderStillGetsPlainRedirectToLogin() {
        // 陷阱記錄：Inertia\Middleware::handle() 對 GET 請求會比對 X-Inertia-Version 與
        // Inertia::getVersion()（見 vendor/inertiajs/inertia-laravel/src/Middleware.php:133,169），
        // 版本不符時一律回 409 + X-Inertia-Location（導回原網址），跟登入門檻無關，會誤導測試結論。
        // 固定 app.asset_url 讓版本雜湊可預期，排除這個干擾，才能驗證我們真正想測的東西：
        // 手動 redirect()->guest() 本身在 X-Inertia 請求下是否仍是單純 302。
        config(['app.asset_url' => 'inertia-test-asset']);
        $version = hash('xxh128', 'inertia-test-asset');

        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
        ])->get(route('app.codes.show', ['table_name' => 'TEST_CODES', 'sort_by' => 'description']));

        $response->assertStatus(302);
        $response->assertRedirect(route('login'));
    }
}
