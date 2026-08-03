<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 護欄鎖在**呼叫點**上，而不只是 helper。
 *
 * ExecutionTimeLimitTest／MemoryLimitTest 驗證的是 helper 的「只放寬不縮限」語義；但真正要防的
 * 回歸是「有人把這些檔案頂層的呼叫改回 `ini_set()`／`set_time_limit()`」——那樣 helper 再正確也
 * 沒用，整套 phpunit 會再次跑不完，而且錯誤會指向無關檔案、極難追查（歷史上踩過 BcryptHasher.php、
 * Model.php、NamespacedItemResolver.php）。
 *
 * 做法：開子行程、以刻意壓低的上限起始，require 該檔（頂層敘述於 autoload／include 時就會執行），
 * 然後檢查兩個 ini 有沒有被改動。子行程只 require vendor/autoload.php，不 boot Laravel。
 */
class RuntimeLimitCallSitesTest extends TestCase {
    /**
     * 頂層帶有 ExecutionTimeLimit／MemoryLimit 呼叫的檔案（相對 base_path）。
     *
     * @return array<string, array{string}>
     */
    public static function topLevelCallSiteFiles(): array {
        $files = [
            'app/Http/Controllers/Api/ApiController.php',
            'app/Http/Controllers/Api/ApiController2.php',
            'app/Http/Controllers/Api/ApiController3.php',
            'app/Http/Controllers/Api/ApiController4.php',
            'app/Http/Controllers/Api/ApiController4_1.php',
            'app/Http/Controllers/Api/ApiController4_2.php',
            'app/Http/Controllers/Api/ApiController5.php',
            'app/Http/Controllers/Api/ApiController6.php',
            'app/Http/Controllers/Api/ApiController7.php',
            'app/Repositories/BiogMainRepository.php',
        ];

        return array_combine($files, array_map(static fn ($f) => [$f], $files));
    }

    #[Test]
    #[DataProvider('topLevelCallSiteFiles')]
    public function requiring_the_file_does_not_narrow_process_limits(string $relativePath) {
        // 起始值刻意比呼叫點要求的（300／600 秒、512M／1024M）更寬：
        //  - max_execution_time=0 模擬 CLI／artisan 的無限制
        //  - memory_limit=4G 高於任何呼叫點要求值
        // 只放寬不縮限成立時，require 之後兩者都必須原封不動。
        $output = $this->iniAfterRequiring($relativePath, '0', '4G');

        $this->assertSame(
            ['0', '4G'],
            $output,
            "require {$relativePath} 改動了 process 的執行時間／記憶體上限——"
            .'呼叫點應走 ExecutionTimeLimit／MemoryLimit（只放寬不縮限），不可直接 ini_set／set_time_limit。'
        );
    }

    #[Test]
    public function the_call_sites_still_raise_a_genuinely_lower_limit() {
        // 反向確認上面那條不是因為呼叫點被整個刪掉才過的：以低配環境起始時必須真的放寬。
        // ApiController3 要求 300 秒／1024M。
        $output = $this->iniAfterRequiring('app/Http/Controllers/Api/ApiController3.php', '30', '128M');

        $this->assertSame(['300', '1024M'], $output);
    }

    /**
     * 在子行程中以指定的起始 ini require $relativePath，回傳 [max_execution_time, memory_limit]。
     *
     * @return array{string, string}
     */
    private function iniAfterRequiring(string $relativePath, string $startingTime, string $startingMemory): array {
        $php = (new PhpExecutableFinder())->find();
        $this->assertNotFalse($php, '找不到 PHP 執行檔');
        $this->assertTrue(function_exists('set_time_limit'), 'set_time_limit 被 disable_functions 停用，本測試無意義');

        $code = "require 'vendor/autoload.php';"
            ." require '{$relativePath}';"
            ." echo ini_get('max_execution_time'), '|', ini_get('memory_limit');";

        $process = new Process(
            [$php, '-d', "max_execution_time={$startingTime}", '-d', "memory_limit={$startingMemory}", '-r', $code],
            base_path()
        );
        $process->mustRun();

        $parts = explode('|', trim($process->getOutput()));
        $this->assertCount(2, $parts, '子行程輸出格式非預期：'.$process->getOutput());

        return [$parts[0], $parts[1]];
    }
}
