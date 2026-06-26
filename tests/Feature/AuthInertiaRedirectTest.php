<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 6 回歸 — React 認證頁（Inertia XHR）成功後的重導行為。
 *
 * 背景：login/register/reset 成功後導向的 dashboard 仍是 Blade（非 Inertia）頁。
 * 若回傳一般 302，Inertia client 跟隨後會收到非 Inertia 的 HTML 而無法處理（dev 下
 * AdminLTE 在錯誤 iframe 內 auto-init 拋 autoIframeMode null，頁面卡在 /login）。
 * 修正：對帶 X-Inertia 標頭的請求改用 Inertia::location（409 + X-Inertia-Location 硬導向）。
 *
 * 本測試鎖定：
 *   - Inertia 請求成功 → 409 + X-Inertia-Location（而非 302）。
 *   - 一般（Blade）請求成功 → 維持 302（行為不變）。
 */
class AuthInertiaRedirectTest extends TestCase {
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\PrometheusMetrics::class);
    }

    #[Test]
    public function login_via_inertia_returns_location_redirect(): void {
        $user = User::factory()->active()->create(['password' => bcrypt('secret123')]);

        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->post('/login', ['email' => $user->email, 'password' => 'secret123']);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location');
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function login_via_blade_returns_plain_redirect(): void {
        $user = User::factory()->active()->create(['password' => bcrypt('secret123')]);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'secret123']);

        // 非 Inertia 請求維持原行為：一般 302 重導，不是 409。
        $response->assertStatus(302);
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function register_via_inertia_returns_location_redirect(): void {
        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->post('/register', [
                'name' => 'Inertia Reg',
                'email' => 'inertia-reg@example.test',
                'institution' => 'Test',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ]);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location');
        $this->assertDatabaseHas('users', ['email' => 'inertia-reg@example.test']);
    }

    #[Test]
    public function reset_password_via_inertia_returns_location_redirect(): void {
        $user = User::factory()->active()->create([
            'email' => 'inertia-reset@example.test',
            'password' => bcrypt('oldpass123'),
        ]);
        $token = Password::createToken($user);

        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->post('/password/reset', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'newpass456',
                'password_confirmation' => 'newpass456',
            ]);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location');
        $this->assertAuthenticatedAs($user->fresh());
    }
}
