<?php

namespace Tests\Unit;

use App\Services\QueryPlaygroundService;
use App\Support\CodesTableDescription;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CodesTableDescriptionTest extends TestCase {
    #[Test]
    public function it_prefers_translation_and_follows_locale() {
        Config::set('codes.tables', ['TEST_CODES' => '測試代碼表']);
        app('translator')->addLines(['codes.table_desc.TEST_CODES' => 'Test Codes Table'], 'en');
        app('translator')->addLines(['codes.table_desc.TEST_CODES' => '測試代碼表（譯）'], 'zh-TW');

        app()->setLocale('en');
        $this->assertSame('Test Codes Table', CodesTableDescription::for('TEST_CODES'));

        app()->setLocale('zh-TW');
        $this->assertSame('測試代碼表（譯）', CodesTableDescription::for('TEST_CODES'));
    }

    #[Test]
    public function it_falls_back_to_config_when_no_translation() {
        Config::set('codes.tables', ['ZZZ_FAKE' => '假表原文說明']);

        app()->setLocale('en');
        $this->assertSame('假表原文說明', CodesTableDescription::for('ZZZ_FAKE'));

        app()->setLocale('zh-TW');
        $this->assertSame('假表原文說明', CodesTableDescription::for('ZZZ_FAKE'));
    }

    #[Test]
    public function it_returns_empty_string_for_unknown_table() {
        Config::set('codes.tables', []);

        $this->assertSame('', CodesTableDescription::for('NOT_A_TABLE'));
    }

    #[Test]
    public function qbe_table_list_descriptions_follow_locale() {
        // QueryPlaygroundService::getQbeTables() 的說明欄改用共用 helper，隨語系顯示。
        // 用不存在於真實 lang 檔的合成表名，確保驗到的是 addLines 而非真實翻譯碰巧同字。
        Config::set('codes.tables', ['QBE_FAKE' => '假 QBE 表原文']);
        app('translator')->addLines(['codes.table_desc.QBE_FAKE' => 'Fake QBE Table'], 'en');

        // 合成表名只在 en 有翻譯 → 證明 getQbeTables 確實走 helper 並隨語系取到英文
        //（config-fallback 路徑已由 it_falls_back_to_config_when_no_translation 覆蓋）。
        app()->setLocale('en');
        $tablesEn = (new QueryPlaygroundService())->getQbeTables();
        $this->assertSame('Fake QBE Table', collect($tablesEn)->firstWhere('name', 'QBE_FAKE')['description']);
    }
}
