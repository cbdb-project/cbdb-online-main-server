<?php

namespace App\Services;

/**
 * 括號正規化服務
 *
 * 中文欄位：僅將全角括號轉為半角（不加空格，避免破壞搜尋索引與中文閱讀習慣）。
 * 拼音／外文欄位：全角轉半角，並確保半角括號與前後文字之間有一個半角空格。
 */
class BracketNormalizer {
    /**
     * BIOG_MAIN 中需要正規化的中文欄位（僅全角→半角）
     */
    public const BIOG_MAIN_CHN_FIELDS = [
        'c_surname_chn',
        'c_mingzi_chn',
        'c_name_chn',
    ];

    /**
     * BIOG_MAIN 中需要正規化的拼音／外文欄位（全角→半角 + 空格）
     */
    public const BIOG_MAIN_PINYIN_FIELDS = [
        'c_surname',
        'c_mingzi',
        'c_surname_proper',
        'c_mingzi_proper',
        'c_surname_rm',
        'c_mingzi_rm',
        'c_name',
        'c_name_proper',
        'c_name_rm',
    ];

    /**
     * ALTNAME_DATA 中的中文欄位（僅全角→半角）
     */
    public const ALTNAME_CHN_FIELDS = [
        'c_alt_name_chn',
    ];

    /**
     * ALTNAME_DATA 中的拼音欄位（全角→半角 + 空格）
     */
    public const ALTNAME_PINYIN_FIELDS = [
        'c_alt_name',
    ];

    /**
     * 僅將全角括號轉為半角，不加空格
     *
     * 適用於中文欄位，避免在中文字之間插入空格，
     * 同時保持與 NameSearchIndexService 搜尋索引的相容性。
     *
     * @param string|null $value 原始值
     * @return string|null 正規化後的值
     */
    public static function normalizeChineseField(?string $value): ?string {
        if ($value === null || $value === '') {
            return $value;
        }

        return str_replace(['（', '）'], ['(', ')'], $value);
    }

    /**
     * 正規化拼音／外文欄位中的括號
     *
     * 規則：
     * 1. 全角括號（、）轉為半角括號 (、)
     * 2. 半角左括號 ( 前面如果不是空格或字串開頭，補一個空格
     * 3. 半角右括號 ) 後面如果不是空格或字串結尾，補一個空格
     *
     * @param string|null $value 原始值
     * @return string|null 正規化後的值
     */
    public static function normalizePinyinField(?string $value): ?string {
        if ($value === null || $value === '') {
            return $value;
        }

        // Step 1: 全角括號轉半角
        $value = str_replace(['（', '）'], ['(', ')'], $value);

        // Step 2: 確保左括號前有空格（除非在字串開頭）
        $value = preg_replace('/(?<=\S)\(/', ' (', $value);

        // Step 3: 確保右括號後有空格（除非在字串結尾）
        $value = preg_replace('/\)(?=\S)/', ') ', $value);

        // 清理多餘空格（連續空格合併為一個）
        $value = preg_replace('/  +/', ' ', $value);

        return trim($value);
    }

    /**
     * 正規化陣列中指定中文欄位的括號
     */
    public static function normalizeChineseFields(array $data, array $fields): array {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = self::normalizeChineseField($data[$field]);
            }
        }

        return $data;
    }

    /**
     * 正規化陣列中指定拼音欄位的括號
     */
    public static function normalizePinyinFields(array $data, array $fields): array {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = self::normalizePinyinField($data[$field]);
            }
        }

        return $data;
    }

    /**
     * 正規化 BIOG_MAIN 相關欄位
     *
     * @param array $data 資料陣列
     * @return array 正規化後的資料陣列
     */
    public static function normalizeBiogMain(array $data): array {
        $data = self::normalizeChineseFields($data, self::BIOG_MAIN_CHN_FIELDS);
        $data = self::normalizePinyinFields($data, self::BIOG_MAIN_PINYIN_FIELDS);

        return $data;
    }

    /**
     * 正規化 ALTNAME_DATA 相關欄位
     *
     * @param array $data 資料陣列
     * @return array 正規化後的資料陣列
     */
    public static function normalizeAltname(array $data): array {
        $data = self::normalizeChineseFields($data, self::ALTNAME_CHN_FIELDS);
        $data = self::normalizePinyinFields($data, self::ALTNAME_PINYIN_FIELDS);

        return $data;
    }

    /**
     * 正規化 Request 物件中指定欄位的括號（中文 + 拼音）
     *
     * @param \Illuminate\Http\Request $request
     * @param array $chnFields 中文欄位列表
     * @param array $pinyinFields 拼音欄位列表
     * @return void
     */
    public static function normalizeRequest(
        \Illuminate\Http\Request $request,
        array $chnFields = [],
        array $pinyinFields = []
    ): void {
        $merge = [];
        foreach ($chnFields as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $normalized = self::normalizeChineseField($value);
                if ($normalized !== $value) {
                    $merge[$field] = $normalized;
                }
            }
        }
        foreach ($pinyinFields as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $normalized = self::normalizePinyinField($value);
                if ($normalized !== $value) {
                    $merge[$field] = $normalized;
                }
            }
        }
        if (!empty($merge)) {
            $request->merge($merge);
        }
    }
}
