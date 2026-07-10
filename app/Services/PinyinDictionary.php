<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 拼音字典查詢服務，取代原本的 app/Models/Pinyin.php 靜態陣列，
 * 資料改由 pinyin 表提供（見 docs/PINYIN_TABLE_CONSOLIDATION_PLAN.md）。
 *
 * 查詢規則：`c_lastname=1`（姓氏讀音）只給 BiogMainRepository::auto_pinyin() 的
 * 姓氏前綴比對使用（該處直接查 DB，不經過本服務）；本服務的 getPinyin() 屬於
 * 「其他所有轉換」，會同時查 c_lastname=1 與 c_lastname=0 兩種資料，
 * 同一字兩邊都有資料時優先採用 c_lastname=0（一般讀音）。
 */
class PinyinDictionary {
    /**
     * 字典快取（chn => pinyin），c_lastname=0 優先於 c_lastname=1。
     *
     * @var array<string,string>|null
     */
    private static $cache = null;

    /**
     * 取得字串的拼音，逐字查表後串接。查無此字時保留原字元
     * （完全比照 app/Models/Pinyin.php 的 chineseToPinyin() 既有行為）。
     */
    public static function getPinyin(string $string): string {
        self::ensureLoaded();

        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result = '';

        foreach ($chars as $char) {
            $result .= self::$cache[$char] ?? $char;
        }

        return $result;
    }

    /**
     * 載入整表快取（同一個 PHP request 生命週期內只查一次 DB）。
     *
     * 建構順序：先塞入 c_lastname=1（姓氏讀音）的資料當作退回值，
     * 再用 c_lastname=0（一般讀音）的資料覆蓋同名鍵值，確保「一般讀音優先、
     * 姓氏讀音當退回」的優先序，且此覆蓋邏輯不依賴查詢結果的列順序。
     */
    private static function ensureLoaded(): void {
        if (self::$cache !== null) {
            return;
        }

        $cache = [];

        foreach (DB::table('pinyin')->where('c_lastname', 1)->select('c_chn', 'c_pinyin')->get() as $row) {
            $cache[$row->c_chn] = $row->c_pinyin;
        }

        foreach (DB::table('pinyin')->where('c_lastname', 0)->select('c_chn', 'c_pinyin')->get() as $row) {
            $cache[$row->c_chn] = $row->c_pinyin;
        }

        self::$cache = $cache;
    }

    /**
     * 重置快取（供測試使用）。
     */
    public static function reset(): void {
        self::$cache = null;
    }
}
