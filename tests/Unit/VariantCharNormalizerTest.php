<?php

namespace Tests\Unit;

use App\Services\VariantCharNormalizer;
use Tests\TestCase;

/**
 * 異體字標準化服務測試
 */
class VariantCharNormalizerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        // 重置緩存，確保每個測試都是獨立的
        VariantCharNormalizer::reset();
    }

    /**
     * 測試備援映射表（fallback map）
     *
     * 即使沒有數據庫連接，也應該能轉換常用異體字
     */
    public function testFallbackMappings(): void {
        // 這些是硬編碼在 VariantCharNormalizer 中的常用異體字
        $testCases = [
            ['菴', '庵'],   // 定菴集 -> 定庵集
            ['攷', '考'],   // 史攷 -> 史考
            ['嶽', '岳'],   // 周君嶽 -> 周君岳
            ['愼', '慎'],   // 四書愼獄 -> 四書慎獄
            ['註', '注'],   // 左氏補註 -> 左氏補注
            ['于', '於'],   // 于謙 -> 於謙
            ['槀', '稿'],   // 槀本 -> 稿本
        ];

        foreach ($testCases as [$variant, $standard]) {
            $result = VariantCharNormalizer::normalize($variant);
            $this->assertEquals($standard, $result, "異體字 '{$variant}' 應轉換為 '{$standard}'");
        }
    }

    /**
     * 測試完整文本中的異體字標準化
     */
    public function testTextNormalization(): void {
        $testCases = [
            ['定菴集', '定庵集'],
            ['史攷', '史考'],
            ['周君嶽', '周君岳'],
            ['四書愼獄講義', '四書慎獄講義'],
            ['左氏補註', '左氏補注'],
            ['于謙', '於謙'],
            ['槀本', '稿本'],
            ['清輝堂詩: 一卷', '清輝堂詩: 一卷'],  // 沒有異體字，應保持不變
        ];

        foreach ($testCases as [$input, $expected]) {
            $result = VariantCharNormalizer::normalize($input);
            $this->assertEquals($expected, $result, "文本 '{$input}' 標準化後應為 '{$expected}'");
        }
    }

    /**
     * 測試標準字不被改變
     */
    public function testStandardCharsUnchanged(): void {
        $standardTexts = [
            '定庵集',
            '史考',
            '岳飛',
            '四書慎思',
            '注釋',
        ];

        foreach ($standardTexts as $text) {
            $result = VariantCharNormalizer::normalize($text);
            $this->assertEquals($text, $result, "標準字文本 '{$text}' 應保持不變");
        }
    }

    /**
     * 測試空字串和特殊情況
     */
    public function testEdgeCases(): void {
        $this->assertEquals('', VariantCharNormalizer::normalize(''));
        $this->assertEquals(' ', VariantCharNormalizer::normalize(' '));
        $this->assertEquals('123', VariantCharNormalizer::normalize('123'));
        $this->assertEquals('ABC', VariantCharNormalizer::normalize('ABC'));
    }

    /**
     * 測試混合內容（漢字 + 標點 + 數字）
     */
    public function testMixedContent(): void {
        $testCases = [
            ['定菴集: 三卷', '定庵集: 三卷'],
            ['史攷（修訂版）', '史考（修訂版）'],
            ['周君嶽 123', '周君岳 123'],
        ];

        foreach ($testCases as [$input, $expected]) {
            $result = VariantCharNormalizer::normalize($input);
            $this->assertEquals($expected, $result);
        }
    }
}
