<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * inertia-laravel v3 起首屏資料一律以 <script type="application/json"> 輸出，不再有 data-page 屬性；
 * 前端 @inertiajs/react 也必須是 v3 才讀得到。這個測試鎖住伺服器端的輸出形狀，
 * 避免之後有人把 composer 升級而前端（package.json）沒跟上，或反過來——
 * 兩邊不同步時整個前端會白屏，而 CI 其他測試完全看不出來。
 */
class InertiaInitialPageScriptElementTest extends TestCase {
    use RefreshDatabase;

    public function test_initial_page_is_emitted_as_a_json_script_element_not_a_data_page_attribute() {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<script[^>]*data-page="app"[^>]*type="application\/json"[^>]*>|<script[^>]*type="application\/json"[^>]*data-page="app"[^>]*>/',
            $html,
            '首屏資料應以 <script data-page="app" type="application/json"> 輸出（inertia-laravel v3）。'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<div[^>]*\bdata-page=/',
            $html,
            '不應再把首屏資料放在 <div data-page="..."> 屬性上（v2 形狀）。'
        );
    }

    /**
     * 比對的是 lock 檔裡「實際鎖定」的版本（composer.lock／package-lock.json），而不是 composer.json／
     * package.json 的約束字串——約束可以寫 ^3，但 lock 被單獨回退到 2.x 時只有 lock 看得出來。
     */
    public function test_locked_server_and_client_inertia_majors_match() {
        $composerLock = json_decode((string) file_get_contents(base_path('composer.lock')), true);
        $server = '';
        foreach ($composerLock['packages'] ?? [] as $package) {
            if (($package['name'] ?? '') === 'inertiajs/inertia-laravel') {
                $server = (string) $package['version'];
            }
        }

        $npmLock = json_decode((string) file_get_contents(base_path('package-lock.json')), true);
        $client = (string) ($npmLock['packages']['node_modules/@inertiajs/react']['version'] ?? '');

        $this->assertNotSame('', $server, 'composer.lock 找不到 inertiajs/inertia-laravel');
        $this->assertNotSame('', $client, 'package-lock.json 找不到 @inertiajs/react');
        $this->assertNotSame('', $this->major($server));
        $this->assertSame(
            $this->major($server),
            $this->major($client),
            "inertia-laravel ({$server}) 與 @inertiajs/react ({$client}) 的主版本必須一致，否則首屏資料格式對不上、前端會白屏。"
        );
    }

    private function major(string $version): string {
        preg_match('/(\d+)/', $version, $m);

        return $m[1] ?? '';
    }
}
