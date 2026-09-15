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
    public function it_renders_codes_index_with_react_urls_regardless_of_flags(): void {
        // 確定性表清單。
        config(['codes.tables' => [
            'OFFICE_CODES' => '官職代碼',
            'ADDR_CODES' => '地址代碼',
        ]]);
        config(['codes.ui_hidden' => []]);
        // ── 2026-09-15（環節 4d）：原本翻 codes flag=old 來驗「url 指回 legacy」。
        // flag 分支已移除（一律指 React 版），所以這裡改成**翻了也不該有影響**——
        // 保留這一行正是為了證明那件事。
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
                    ->where('url', route('app.codes.show', ['table_name' => 'OFFICE_CODES'], false))));
    }

    #[Test]
    public function show_url_points_at_the_react_table_page(): void {
        config(['codes.tables' => ['OFFICE_CODES' => '官職代碼']]);
        config(['codes.ui_hidden' => []]);
        // 📌 原名 `show_url_follows_new_flag_when_show_route_exists`，body 還設過
        // `migration_flags.pages.codes => 'new'`。環節 4d-1 把 flag 機制整組移除之後，
        // 那個設定是對幽靈 key 賦值、名字也不再描述任何事（同檔兄弟測試已改，這條漏了）。
        $this->get(route('app.codes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tables.0.url', '/app/codes/OFFICE_CODES'));
    }

    /**
     * 🔴 `ui_hidden` 在**路由層**也要生效：`/app/codes` 首頁不得列出被隱藏的表。
     *
     * 為什麼單獨一條：`codes.ui_hidden` 的 repository 層過濾由
     * `tests/Unit/CodesTableListingTest.php` 守著，但**路由層**（`appIndex()` 真的把
     * 過濾後的清單傳成 `tables` prop）原本只有 legacy Blade 版的
     * `CodesControllerTest::testUiHiddenTableAbsentFromCodesIndexRoute` 在驗——
     * 而那條會隨環節 4b 刪除。本檔其餘測試（以及全 repo 的 codes 測試）**一律把
     * `ui_hidden` 設成 `[]`** 來排除干擾，所以刪掉之後會留下一個真空。
     *
     * `ui_hidden` 的用途是把 `CBDB__NAME_FTS` 這類「在共用白名單裡、但不該出現在使用者
     * 首頁」的表藏起來；漏了它等於把內部索引表暴露在代碼表總覽上。
     */
    #[Test]
    public function ui_hidden_tables_are_absent_from_the_index_route(): void {
        config(['codes.tables' => [
            'OFFICE_CODES' => '官職代碼',
            'CBDB__NAME_FTS' => '姓名索引',
        ]]);
        config(['codes.ui_hidden' => ['CBDB__NAME_FTS']]);

        $names = null;
        $this->get(route('app.codes.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$names) {
                $names = array_column($page->component('Codes/Index')->toArray()['props']['tables'], 'name');
            });

        $this->assertSame(['OFFICE_CODES'], $names, 'ui_hidden 的表不得出現在 /app/codes 首頁');

        // 共用白名單本身不受影響——被隱藏的表仍可直連（guardTable 只看 codes.tables）。
        $this->assertArrayHasKey('CBDB__NAME_FTS', config('codes.tables'));
    }
}
