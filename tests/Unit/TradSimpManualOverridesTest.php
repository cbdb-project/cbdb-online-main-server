<?php

namespace Tests\Unit;

use App\Support\TradSimpManualOverrides;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TradSimpManualOverridesTest extends TestCase {
    #[Test]
    public function it_normalizes_and_returns_configured_overrides(): void {
        config(['trad_simp_manual_overrides' => [
            '栢' => '柏',
            ' 儷 ' => ' 丽 ', // 前後空白應被裁剪
        ]]);

        $this->assertEquals([
            '栢' => '柏',
            '儷' => '丽',
        ], TradSimpManualOverrides::all());
    }

    #[Test]
    public function it_skips_blank_entries(): void {
        config(['trad_simp_manual_overrides' => [
            '栢' => '柏',
            '' => '柏',
            '栢' => '', // 同鍵覆蓋，仍為空值，PHP 陣列僅保留最後一筆
        ]]);

        $this->assertEquals([], TradSimpManualOverrides::all());
    }

    #[Test]
    public function apply_lets_manual_overrides_win_on_conflict(): void {
        config(['trad_simp_manual_overrides' => [
            '乾' => '甲', // 刻意與既有映射衝突，驗證人工映射優先
            '栢' => '柏',
        ]]);

        $result = TradSimpManualOverrides::apply(['乾' => '干']);

        $this->assertEquals([
            '乾' => '甲',
            '栢' => '柏',
        ], $result);
    }

    #[Test]
    public function apply_is_noop_when_no_overrides_configured(): void {
        config(['trad_simp_manual_overrides' => []]);

        $this->assertEquals(['乾' => '干'], TradSimpManualOverrides::apply(['乾' => '干']));
    }
}
