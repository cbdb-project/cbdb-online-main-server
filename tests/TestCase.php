<?php

namespace Tests;

use App\Services\CharVariantMapService;
use App\Support\VariantReplaceScope;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {
    use CreatesApplication;

    protected function setUp(): void {
        parent::setUp();
        $this->serverVariables['HTTP_ACCEPT_LANGUAGE'] = 'zh-TW,zh;q=0.9,en;q=0.1';

        // 清異體字替換的兩份行程內快取。**必須做**：測試自建的是簡化版合成表，
        // 同一個表名在不同檔案有不同欄位集，而 PHPUnit 單一行程跑完全部 ⇒
        // 被前一個檔案暖起來的型別／對照表快取，對下一個檔案就是錯的，
        // 測試結果會依檔案順序漂移。
        CharVariantMapService::reset();
        VariantReplaceScope::reset();
    }

    /**
     * 把 basicinformation.* 全部 migration flag 撥回 'old'，供仍在測 legacy Blade
     * CRUD 行為的測試使用：flag=new 時 LegacyBladeFormGate 會把 legacy 表單 GET
     * 導向 /app、寫入端點回 410（見 app/Http/Middleware/LegacyBladeFormGate.php）。
     */
    protected function useLegacyPersonForms(): void {
        $keys = [
            'index', 'show', 'editor', 'altname', 'addresses', 'texts', 'sources', 'offices',
            'assoc', 'kinship', 'events', 'entries', 'statuses', 'possession', 'socialinst',
        ];
        foreach ($keys as $key) {
            config(["migration_flags.pages.basicinformation.$key" => 'old']);
        }
    }
}
