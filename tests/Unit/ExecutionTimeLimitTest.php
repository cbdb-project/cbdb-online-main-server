<?php

namespace Tests\Unit;

use App\Support\ExecutionTimeLimit;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExecutionTimeLimitTest extends TestCase {
    #[Test]
    public function extend_to_is_a_no_op_under_tests() {
        // 回歸護欄：set_time_limit() 套住整個 PHP process，PHPUnit 又共用單一 process，
        // 因此測試環境下必須不設限——否則某個測試觸發後，整套測試會在 N 秒後被
        // 「Maximum execution time exceeded」攔腰砍斷，且錯誤指向無關檔案、極難追查。
        $before = ini_get('max_execution_time');

        ExecutionTimeLimit::extendTo(1);

        $this->assertSame($before, ini_get('max_execution_time'));
        $this->assertSame('0', (string) ini_get('max_execution_time'), 'CLI 下應維持無限制');
    }
}
