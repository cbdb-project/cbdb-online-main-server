<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P2-1 codes/index Inertia 變體（app.codes.index）測試。
 */
class CodesIndexInertiaTest extends TestCase {
    #[Test]
    public function it_renders_codes_index_with_flag_aware_urls(): void {
        // 確定性表清單。
        config(['codes.tables' => [
            'OFFICE_CODES' => '官職代碼',
            'ADDR_CODES' => '地址代碼',
        ]]);
        config(['codes.ui_hidden' => []]);
        config(['migration_flags.pages.codes' => 'old']);

        // 說明欄現在改由 codes.table_desc.<表名> 翻譯驅動（見 CodesTableDescription），
        // 會蓋過 config 原文；此處覆寫翻譯以維持測試確定性、不耦合真實 lang 檔內容。
        app('translator')->addLines([
            'codes.table_desc.OFFICE_CODES' => '官職代碼',
            'codes.table_desc.ADDR_CODES' => '地址代碼',
        ], 'zh-TW');

        $this->get(route('app.codes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/Index')
                ->has('tables', 2)
                ->has('page_translations.codes')
                ->has('tables.0', fn (Assert $row) => $row
                    ->where('name', 'OFFICE_CODES')
                    ->where('description', '官職代碼')
                    ->where('url', '/codes/OFFICE_CODES')));
    }

    #[Test]
    public function show_url_follows_new_flag_when_show_route_exists(): void {
        config(['codes.tables' => ['OFFICE_CODES' => '官職代碼']]);
        config(['codes.ui_hidden' => []]);
        config(['migration_flags.pages.codes' => 'new']);

        // app.codes.show 已建立（P2-2），flag=new 時連結指向 React 單表頁。
        $this->get(route('app.codes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tables.0.url', '/app/codes/OFFICE_CODES'));
    }
}
