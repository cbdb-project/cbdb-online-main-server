<?php

namespace Tests\Unit;

use App\Support\MemoryLimit;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MemoryLimitTest extends TestCase {
    #[Test]
    public function extend_to_raises_a_lower_limit() {
        // 低配環境（PHP 內建預設 128M）跑重查詢端點仍需要放寬——這是刻意保留呼叫點、
        // 而非直接刪掉那 11 行的原因。
        $this->assertSame('512M', $this->limitAfterExtendTo('128M', '512M'));
    }

    #[Test]
    public function extend_to_never_narrows_an_already_wider_limit() {
        // 生產實測（2026-08-03）：web memory_limit = 1G、CLI = -1（無限制）。
        // 舊寫法無條件 ini_set('memory_limit','512M') 在這兩種情況下都是**降級**——
        // 每次 /api/select/* 請求只剩伺服器願意給的一半。這裡確認不再發生。
        $this->assertSame('1G', $this->limitAfterExtendTo('1G', '512M'), '1G > 512M，不可被收窄');
        $this->assertSame('-1', $this->limitAfterExtendTo('-1', '512M'), '-1＝無限制，不可被收窄');
        $this->assertSame('512M', $this->limitAfterExtendTo('512M', '512M'), '相等時維持現值');
    }

    #[Test]
    public function extend_to_treats_1024m_as_equal_to_1g() {
        // 生產主場景：ApiController3 要求 '1024M'，而生產 web 的 memory_limit 是 '1G'。
        // 「這行在生產是 no-op、沒有降級」是整個改動的核心論據，必須有測試鎖住。
        // 同時鎖住 toBytes() 的單位換算——若有人誤用 1000 進位，這條會立刻紅。
        $this->assertSame('1G', $this->limitAfterExtendTo('1G', '1024M'));
    }

    #[Test]
    public function extend_to_ignores_unparsable_values() {
        // 解析不了就放棄調整，不猜測（避免把上限設成意料外的值）。
        //
        // 只測 requested 這一側：「現值無法解析」無法用 `php -d memory_limit=bogus` 造出來——
        // PHP 自己會先攔下並 fallback（Warning: Invalid "memory_limit" setting…），子行程拿到的
        // 已是合法值。該分支（toBytes(ini_get(...)) === null）因此在實務上不可達，留著純為防禦。
        $this->assertSame('256M', $this->limitAfterExtendTo('256M', 'not-a-size'), 'requested 無法解析');

        // 溢位同樣視為無法解析：若飽和成 PHP_INT_MAX 而判定「該放寬」，就會拿這個 PHP 認不得的
        // 原始字串去 ini_set()，發出 Invalid "memory_limit" setting warning。
        $this->assertSame('256M', $this->limitAfterExtendTo('256M', '99999999999999999999G'), 'requested 溢位');
    }

    /**
     * 在子行程裡以 $startingLimit 起始，呼叫 MemoryLimit::extendTo($requested)，回傳呼叫後的實際值。
     */
    private function limitAfterExtendTo(string $startingLimit, string $requested): string {
        $php = (new PhpExecutableFinder())->find();
        $this->assertNotFalse($php, '找不到 PHP 執行檔');

        $code = "require 'vendor/autoload.php';"
            ." App\\Support\\MemoryLimit::extendTo('{$requested}');"
            ." echo ini_get('memory_limit');";

        $process = new Process([$php, '-d', "memory_limit={$startingLimit}", '-r', $code], base_path());
        $process->mustRun();

        return trim($process->getOutput());
    }
}
