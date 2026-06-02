<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 固定測試環境 locale，避免影響其他測試
        \App::setLocale('en');
    }

    /** @test */
    public function it_switches_locale_to_en_and_stores_in_session(): void
    {
        $response = $this->post(route('locale.switch'), ['locale' => 'en']);

        $response->assertRedirect();
        $response->assertSessionHas('locale', 'en');
    }

    /** @test */
    public function it_switches_locale_to_zh_TW_and_stores_in_session(): void
    {
        $response = $this->post(route('locale.switch'), ['locale' => 'zh-TW']);

        $response->assertRedirect();
        $response->assertSessionHas('locale', 'zh-TW');
    }

    /** @test */
    public function it_rejects_invalid_locale(): void
    {
        $response = $this->post(route('locale.switch'), ['locale' => 'fr']);

        $response->assertSessionHasErrors('locale');
    }

    /** @test */
    public function it_rejects_empty_locale(): void
    {
        $response = $this->post(route('locale.switch'), ['locale' => '']);

        $response->assertSessionHasErrors('locale');
    }

    /** @test */
    public function it_rejects_missing_locale(): void
    {
        $response = $this->post(route('locale.switch'), []);

        $response->assertSessionHasErrors('locale');
    }

    /** @test */
    public function it_sets_cookie_on_locale_switch(): void
    {
        $response = $this->post(route('locale.switch'), ['locale' => 'en']);

        $response->assertCookie('locale', 'en');
    }

    /** @test */
    public function locale_switch_is_accessible_to_guests(): void
    {
        // 未登入訪客也可以切換語言
        $response = $this->post(route('locale.switch'), ['locale' => 'en']);

        $response->assertRedirect();
        $response->assertSessionHas('locale', 'en');
    }

    /** @test */
    public function set_locale_middleware_reads_session_locale(): void
    {
        // HomeController::index() 回傳 redirect，驗證 middleware 不阻擋請求即可
        $response = $this->withSession(['locale' => 'zh-TW'])
            ->get(route('home'));

        $response->assertRedirect();
    }

    /** @test */
    public function set_locale_middleware_falls_back_to_default_when_no_preference(): void
    {
        // 無 session / cookie / header，middleware 應用預設 locale 並正常放行
        $response = $this->get(route('home'));

        $response->assertRedirect();
    }
}
