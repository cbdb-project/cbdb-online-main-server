<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 人物頁 URL helper。
 *
 * 原名所指的「flag-aware」行為已不存在：`basicinformation.*` 的 15 個 migration flag
 * 隨 legacy Blade 人物編輯全套於 Blade 下架計畫環節 2 一併移除，helper 也收斂為
 * 無條件回傳 React `/app` 路由（legacy URI 僅剩 302 導向，再指過去只是多一跳）。
 *
 * 本檔改為鎖住收斂後的契約：**不得再依 config 分歧、也不得回到 `/basicinformation`**。
 */
class FlagAwareUrlHelpersTest extends TestCase {
    public function test_person_page_url_always_returns_react_routes(): void {
        $this->assertSame('/app/basicinformation/123', person_page_url(123, 'show'));
        $this->assertSame('/app/basicinformation/123/edit', person_page_url(123, 'edit'));
    }

    public function test_person_index_helpers_always_return_react_routes(): void {
        $this->assertSame('/app/basicinformation', person_index_base_url());
        $this->assertSame('/app/basicinformation?q=%E8%98%87%E8%BB%BE', person_index_url(['q' => '蘇軾']));
        $this->assertSame('/app/basicinformation?q=42', person_index_url(['q' => 42]));
    }

    public function test_person_show_and_create_helpers_always_return_react_routes(): void {
        $this->assertSame('/app/basicinformation', person_show_base_url());
        $this->assertSame('/app/basicinformation/create', person_create_url());
    }

    /**
     * 護欄：即使有人把 `basicinformation.*` flag 塞回 config（例如誤以為還能回退），
     * helper 也不得再指向 legacy——那些 legacy 頁已經不存在了。
     */
    public function test_reintroducing_basicinformation_flags_does_not_resurrect_legacy_urls(): void {
        config([
            'migration_flags.pages.basicinformation.show' => 'old',
            'migration_flags.pages.basicinformation.editor' => 'old',
            'migration_flags.pages.basicinformation.index' => 'old',
        ]);

        $this->assertSame('/app/basicinformation/123', person_page_url(123, 'show'));
        $this->assertSame('/app/basicinformation/123/edit', person_page_url(123, 'edit'));
        $this->assertSame('/app/basicinformation', person_index_base_url());
        $this->assertSame('/app/basicinformation/create', person_create_url());
    }
}
