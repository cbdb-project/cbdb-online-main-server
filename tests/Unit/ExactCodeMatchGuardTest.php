<?php

namespace Tests\Unit;

use App\Support\ExactCodeMatchGuard;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 回歸測試：search/* 端點的「代碼相等」OR 分支必須只在輸入為純數字時生效。
 * 背景：MySQL/MariaDB 非嚴格模式比對「整數欄位 = 非數字字串」時會把字串寬鬆轉型成 0，
 * 導致使用者輸入人名（如「趙匡胤」）搜尋親屬姓名時，下拉候選誤中 c_personid=0（「未詳」占位列）。
 */
class ExactCodeMatchGuardTest extends TestCase {
    #[Test]
    public function purely_numeric_query_is_treated_as_a_code_lookup(): void {
        $this->assertTrue(ExactCodeMatchGuard::isNumeric('123'));
        $this->assertTrue(ExactCodeMatchGuard::isNumeric('0'));
        $this->assertTrue(ExactCodeMatchGuard::isNumeric(0));
    }

    #[Test]
    public function non_numeric_query_is_not_treated_as_a_code_lookup(): void {
        $this->assertFalse(ExactCodeMatchGuard::isNumeric('趙匡胤'));
        $this->assertFalse(ExactCodeMatchGuard::isNumeric('12a'));
        $this->assertFalse(ExactCodeMatchGuard::isNumeric('-1'));
        $this->assertFalse(ExactCodeMatchGuard::isNumeric(''));
        $this->assertFalse(ExactCodeMatchGuard::isNumeric(null));
        $this->assertFalse(ExactCodeMatchGuard::isNumeric(' 123 '));
    }
}
