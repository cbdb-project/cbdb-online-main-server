<?php

namespace Tests\Unit;

use App\Http\Controllers\CodesController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 單元測試：CodesController::guardSortFilterRequiresAuth()（M1，尚未接線到任何路由）。
 * 見 docs/CODES_SORT_FILTER_AUTH_GATE.md §4.1、4.2。
 */
class CodesControllerGuardSortFilterTest extends TestCase {
    private function invokeGuard(Request $request) {
        $controller = $this->app->make(CodesController::class);
        $method = new \ReflectionMethod(CodesController::class, 'guardSortFilterRequiresAuth');
        $method->setAccessible(true);

        return $method->invoke($controller, $request);
    }

    private function activeUser(int $id = 21): User {
        $user = new User(['name' => 'active', 'email' => 'active@example.com', 'confirmation_token' => Str::random(32)]);
        $user->id = $id;
        $user->is_active = 1;

        return $user;
    }

    private function inactiveUser(int $id = 22): User {
        $user = new User(['name' => 'inactive', 'email' => 'inactive@example.com', 'confirmation_token' => Str::random(32)]);
        $user->id = $id;
        $user->is_active = 0;

        return $user;
    }

    #[Test]
    public function testNoSortOrFilterAllowsGuest() {
        $request = Request::create('/app/codes/TEST_CODES', 'GET');

        $this->assertNull($this->invokeGuard($request));
    }

    #[Test]
    public function testEmptyFilterValueDoesNotCountAsFilter() {
        $request = Request::create('/app/codes/TEST_CODES', 'GET', ['filters' => ['c_name' => '']]);

        $this->assertNull($this->invokeGuard($request));
    }

    #[Test]
    public function testSortByAloneRequiresAuthForGuest() {
        $request = Request::create('/app/codes/TEST_CODES', 'GET', ['sort_by' => 'c_name']);

        $result = $this->invokeGuard($request);

        $this->assertNotNull($result);
        $this->assertTrue($result->isRedirect(route('login')));
    }

    #[Test]
    public function testSortByNonExistentColumnStillRequiresAuth() {
        // gate 是簡化判定，不驗證欄位是否真實存在（見 §4.1）：即使欄位不存在，也要擋，
        // 不可因為 buildShowPayload() 本來會忽略這個欄位就放行。
        $request = Request::create('/app/codes/TEST_CODES', 'GET', ['sort_by' => 'not_a_real_column']);

        $result = $this->invokeGuard($request);

        $this->assertNotNull($result);
        $this->assertTrue($result->isRedirect(route('login')));
    }

    #[Test]
    public function testNonEmptyFilterRequiresAuthForGuest() {
        $request = Request::create('/app/codes/TEST_CODES', 'GET', ['filters' => ['c_name' => 'foo']]);

        $result = $this->invokeGuard($request);

        $this->assertNotNull($result);
        $this->assertTrue($result->isRedirect(route('login')));
    }

    #[Test]
    public function testWhitespaceOnlySortByDoesNotCountAsSort() {
        $request = Request::create('/app/codes/TEST_CODES', 'GET', ['sort_by' => '   ']);

        $this->assertNull($this->invokeGuard($request));
    }

    #[Test]
    public function testArrayValuedFilterIsIgnoredNotCoercedToString() {
        // 比照 sanitizeColumnFilters()：非 scalar 的 filter 值直接略過，不可被 (string) 轉型成
        // "Array" 字面值而誤判為「有 filter」。
        $request = Request::create('/app/codes/TEST_CODES', 'GET', ['filters' => ['c_name' => ['nested' => '1']]]);

        $this->assertNull($this->invokeGuard($request));
    }

    #[Test]
    public function testSortDirAloneWithoutSortByDoesNotCountAsSort() {
        $request = Request::create('/app/codes/TEST_CODES', 'GET', ['sort_dir' => 'desc']);

        $this->assertNull($this->invokeGuard($request));
    }

    #[Test]
    public function testActiveUserWithSortByPassesThrough() {
        $this->actingAs($this->activeUser());
        $request = Request::create('/app/codes/TEST_CODES', 'GET', ['sort_by' => 'c_name']);

        $this->assertNull($this->invokeGuard($request));
    }

    #[Test]
    public function testInactiveUserWithSortByIsBlockedButNotSentToLogin() {
        $this->actingAs($this->inactiveUser());
        $request = Request::create('/app/codes/TEST_CODES', 'GET', ['sort_by' => 'c_name']);

        $result = $this->invokeGuard($request);

        $this->assertNotNull($result);
        // 不可導向 login：LoginController 掛 guest middleware，已登入使用者訪問會被攔截彈開，
        // flash 訊息會消失，使用者搞不清楚為什麼被擋（見 §4.2）。
        $this->assertFalse($result->isRedirect(route('login')));
        $this->assertTrue(session()->has('flash_notification'));
    }
}
