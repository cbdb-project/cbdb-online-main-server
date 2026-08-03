<?php

namespace Tests\Unit;

use App\Support\ExecutionTimeLimit;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ExecutionTimeLimitTest extends TestCase {
    #[Test]
    public function extend_to_is_a_no_op_under_tests() {
        // 回歸護欄：set_time_limit()／ini_set('max_execution_time') 套住整個 PHP process，
        // PHPUnit 又共用單一 process，因此測試環境下必須不設限——否則某個測試觸發後，整套測試
        // 會在 N 秒後被「Maximum execution time exceeded」攔腰砍斷，且錯誤指向無關檔案、極難追查。
        //
        // 刻意只斷言「前後相等」而非斷言某個具體數值：後者斷的是跑測試時的 php.ini 設定
        // （CI 若以 `php -d max_execution_time=30` 執行就會紅），不是本類別的行為。
        $before = ini_get('max_execution_time');

        ExecutionTimeLimit::extendTo(1);

        $this->assertSame($before, ini_get('max_execution_time'));
    }

    #[Test]
    public function extend_to_actually_raises_the_limit_outside_tests() {
        // 另一個方向的護欄：確認正式環境下 extendTo() 真的會設限。
        // 缺了這條，「測試偵測邏輯被改壞成永遠 no-op」就沒有任何測試會發現。
        //
        // 必須開子行程才測得到：子行程只 require vendor/autoload.php，既不定義
        // PHPUNIT_COMPOSER_INSTALL 也不載入 PHPUnit\Framework\TestCase，因此會走正式分支。
        $php = (new PhpExecutableFinder())->find();
        $this->assertNotFalse($php, '找不到 PHP 執行檔');

        $code = "require 'vendor/autoload.php';"
            ." App\\Support\\ExecutionTimeLimit::extendTo(300);"
            ." echo ini_get('max_execution_time');";

        $process = new Process([$php, '-d', 'max_execution_time=30', '-r', $code], base_path());
        $process->mustRun();

        $this->assertSame('300', trim($process->getOutput()));
    }
}
