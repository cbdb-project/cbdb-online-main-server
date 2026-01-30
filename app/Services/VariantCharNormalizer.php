<?php

namespace App\Services;

/**
 * 異體字標準化服務
 *
 * 將異體字轉換為標準字形（如「菴」→「庵」），用於拼音轉換等場景。
 * 此服務僅用於臨時轉換，不修改原始數據（書名、人名等保持不變）。
 */
class VariantCharNormalizer {
    /**
     * 異體字映射緩存（異體字 → 標準字）
     *
     * @var array
     */
    protected static $variantMap = [];

    /**
     * 是否已載入映射表
     *
     * @var bool
     */
    protected static $loaded = false;

    /**
     * 內建的常用異體字映射
     *
     * @var array
     */
    protected static $fallbackMap = [
        '菴' => '庵',  // an
        '攷' => '考',  // kao
        '嶽' => '岳',  // yue
        '愼' => '慎',  // shen
        '註' => '注',  // zhu
        '于' => '於',  // yu
        '槀' => '稿',  // gao
    ];

    /**
     * 標準化文本中的異體字
     *
     * @param string $text 原始文本
     * @return string 標準化後的文本
     */
    public static function normalize(string $text): string {
        self::ensureLoaded();

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $result = '';

        foreach ($chars as $char) {
            $result .= self::$variantMap[$char] ?? self::$fallbackMap[$char] ?? $char;
        }

        return $result;
    }

    /**
     * 載入異體字映射
     *
     * @return void
     */
    protected static function ensureLoaded(): void {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        // 目前僅使用內建映射表
        return;
    }

    /**
     * 重置緩存（主要用於測試）
     *
     * @return void
     */
    public static function reset(): void {
        self::$variantMap = [];
        self::$loaded = false;
    }

    /**
     * 獲取當前載入的映射數量（用於調試）
     *
     * @return int
     */
    public static function getMappingCount(): int {
        self::ensureLoaded();

        return count(self::$variantMap);
    }
}
