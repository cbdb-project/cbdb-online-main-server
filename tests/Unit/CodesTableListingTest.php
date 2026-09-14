<?php

namespace Tests\Unit;

use App\Repositories\CodesRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `CodesRepository::codes()` 的表清單行為與 codes 說明欄的 i18n。
 *
 * ── 2026-09-14（Blade 下架環節 4b-2a）─────────────────────────────
 *
 * 這 4 條原本住在 `CodesControllerTest`（那個檔整類打著 `#[Group('legacy-parity')]`、
 * setUp 呼叫 `useLegacyBladePages()`），但它們**完全不打 HTTP、不碰 Blade**——
 * 只是直呼 `new CodesRepository()->codes()` 與讀 config／lang 檔。
 *
 * 環節 4b 要刪掉 `CodesControllerTest`，**整檔刪掉會靜默毀掉它們**（與環節 4a 的
 * `OperationsIndexLinksTest` 同型陷阱），所以先搬出來。搬家時內容一字未改。
 *
 * 其中 `codes_table_desc_keys_stay_in_parity_with_config` 是 §6 i18n 的機械化護欄：
 * `config/codes.php` 的每個表名在 en 與 zh-TW 的 `table_desc` 都必須有 key，
 * 否則（`app.fallback_locale=en`）zh-TW 缺 key 會落回英文、en 缺 key 會退回中文原文。
 */
class CodesTableListingTest extends TestCase {
    #[Test]
    public function ui_hidden_excluded_from_codes_list_associative_config(): void {
        // 生產 config/codes.php 的 tables 是關聯陣列（表名 => 說明），走 codes() 第一分支。
        // 同時用全小寫 'pinyin' vs 大寫 'PINYIN' 鎖住 §9.1 C5 大小寫不敏感比對。
        config(['codes.tables' => ['pinyin' => '拼音表', 'TEST_CODES' => '測試表']]);
        config(['codes.ui_hidden' => ['PINYIN']]);

        $names = array_column((new CodesRepository())->codes(), 'name');

        // 從首頁清單隱藏（大小寫不敏感）
        $this->assertNotContains('pinyin', $names);
        // 其他表不受影響
        $this->assertContains('TEST_CODES', $names);
        // 共用白名單（codes.tables）維持完整，不受 ui_hidden 影響
        $this->assertArrayHasKey('pinyin', config('codes.tables'));
    }

    #[Test]
    public function ui_hidden_also_filters_legacy_indexed_config(): void {
        // 向後相容：索引陣列（舊格式）走 codes() 第二分支，過濾同樣生效。
        config(['codes.tables' => ['TEST_CODES', 'CBDB__NAME_FTS']]);
        config(['codes.ui_hidden' => ['CBDB__NAME_FTS']]);

        $names = array_column((new CodesRepository())->codes(), 'name');

        $this->assertNotContains('CBDB__NAME_FTS', $names);
        $this->assertContains('TEST_CODES', $names);
        $this->assertContains('CBDB__NAME_FTS', config('codes.tables'));
    }

    /**
     * `codes()` 的 description 必須**接到共用的 `CodesTableDescription` helper**，而不是自己實作。
     *
     * ⚠️ 這條刻意只驗**接線**（一組隨語系 + 一組 fallback），不重複驗 helper 的行為——
     * 那已由 `tests/Unit/CodesTableDescriptionTest.php` 用同一組 fixture 完整覆蓋。
     * 搬家時原樣照搬會讓兩個檔用一樣的 fixture 驗一樣的事，日後改 helper 要同步兩處。
     */
    #[Test]
    public function codes_list_routes_description_through_the_shared_helper(): void {
        config(['codes.tables' => [
            'TEST_CODES' => '測試代碼表',   // 有翻譯 → 隨語系
            'ZZZ_FAKE' => '假表原文說明',   // 無翻譯 → 退回 config 原文
        ]]);
        config(['codes.ui_hidden' => []]);
        app('translator')->addLines(['codes.table_desc.TEST_CODES' => 'Test Codes Table'], 'en');

        app()->setLocale('en');
        $rows = collect((new CodesRepository())->codes());

        $this->assertSame('Test Codes Table', $rows->firstWhere('name', 'TEST_CODES')['description']);
        $this->assertSame('假表原文說明', $rows->firstWhere('name', 'ZZZ_FAKE')['description']);
    }

    #[Test]
    public function codes_table_desc_keys_stay_in_parity_with_config(): void {
        // 鎖定不變量：config/codes.php tables 的每個表名，en 與 zh-TW 的 table_desc 都必須有對應 key。
        // 否則（因 app.fallback_locale=en）zh-TW 缺 key 會落回英文，或 en 缺 key 使英文欄退回中文原文。
        $config = require base_path('config/codes.php');
        $en = require base_path('resources/lang/en/codes.php');
        $zh = require base_path('resources/lang/zh-TW/codes.php');

        $configKeys = array_keys($config['tables']);
        $enKeys = array_keys($en['table_desc']);
        $zhKeys = array_keys($zh['table_desc']);
        sort($configKeys);
        sort($enKeys);
        sort($zhKeys);

        $this->assertSame($configKeys, $enKeys, 'en/codes.php table_desc 的 key 必須與 config/codes.php tables 完全一致');
        $this->assertSame($configKeys, $zhKeys, 'zh-TW/codes.php table_desc 的 key 必須與 config/codes.php tables 完全一致');
    }
}
