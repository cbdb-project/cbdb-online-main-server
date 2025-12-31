<?php

namespace Tests\Unit;

use App\Console\Commands\RebuildNameSearchIndex;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RebuildNameSearchIndexTest extends TestCase {
    /**
     * 使用反射來測試 protected normalizeName() 方法
     */
    protected function invokeNormalizeName(string $name): ?string {
        $command = new RebuildNameSearchIndex();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('normalizeName');
        $method->setAccessible(true);

        return $method->invoke($command, $name);
    }

    #[Test]
    public function test_normalizeName_removes_fullwidth_parentheses_but_keeps_content(): void {
        // 全角括號應該被移除，但內容保留
        $result = $this->invokeNormalizeName('宗氏（李白妻）');
        $this->assertEquals('宗氏李白妻', $result);
    }

    #[Test]
    public function test_normalizeName_removes_halfwidth_parentheses_but_keeps_content(): void {
        // 半角括號應該被移除，但內容保留
        $result = $this->invokeNormalizeName('楊貴妃(楊玉環)');
        $this->assertEquals('楊貴妃楊玉環', $result);
    }

    #[Test]
    public function test_normalizeName_handles_mixed_parentheses(): void {
        // 混合使用全角和半角括號
        $result = $this->invokeNormalizeName('張三（李四）(王五)');
        $this->assertEquals('張三李四王五', $result);
    }

    #[Test]
    public function test_normalizeName_handles_multiple_parentheses(): void {
        // 多組括號
        $result = $this->invokeNormalizeName('王氏（趙錢妻）（孫李母）');
        $this->assertEquals('王氏趙錢妻孫李母', $result);
    }

    #[Test]
    public function test_normalizeName_handles_name_without_parentheses(): void {
        // 沒有括號的姓名應該保持不變
        $result = $this->invokeNormalizeName('李白');
        $this->assertEquals('李白', $result);
    }

    #[Test]
    public function test_normalizeName_handles_only_parentheses_content(): void {
        // 只有括號內容（邊界情況）
        $result = $this->invokeNormalizeName('（李白）');
        $this->assertEquals('李白', $result);
    }

    #[Test]
    public function test_normalizeName_trims_whitespace(): void {
        // 移除前後空白
        $result = $this->invokeNormalizeName('  李白  ');
        $this->assertEquals('李白', $result);
    }

    #[Test]
    public function test_normalizeName_returns_null_for_empty_string(): void {
        // 空字串應返回 null
        $result = $this->invokeNormalizeName('');
        $this->assertNull($result);
    }

    #[Test]
    public function test_normalizeName_returns_null_for_whitespace_only(): void {
        // 只有空白應返回 null
        $result = $this->invokeNormalizeName('   ');
        $this->assertNull($result);
    }

    #[Test]
    public function test_normalizeName_returns_null_when_only_parentheses_removed(): void {
        // 如果移除括號後只剩空白，應返回 null（邊界情況）
        $result = $this->invokeNormalizeName('（）()');
        $this->assertNull($result);
    }

    #[Test]
    public function test_normalizeName_handles_nested_parentheses(): void {
        // 嵌套括號（雖然實際數據中不太可能出現）
        $result = $this->invokeNormalizeName('王氏（李（白）妻）');
        $this->assertEquals('王氏李白妻', $result);
    }

    #[Test]
    public function test_normalizeName_preserves_other_special_characters(): void {
        // 保留其他特殊字符（如果有的話）
        $result = $this->invokeNormalizeName('李白・字太白');
        $this->assertEquals('李白・字太白', $result);
    }
}
