<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use OpenccPinyin\PinyinData;

/**
 * 拼音字典查詢服務，取代原本的 app/Models/Pinyin.php 靜態陣列，
 * 資料改由 pinyin 表提供（見 docs/PINYIN_TABLE_CONSOLIDATION_PLAN.md）。
 *
 * 查詢規則：`c_lastname=1`（姓氏讀音）只給 BiogMainRepository::auto_pinyin() 的
 * 姓氏前綴比對使用（該處直接查 DB，不經過本服務）；本服務的 getPinyin() 屬於
 * 「其他所有轉換」，會同時查 c_lastname=1 與 c_lastname=0 兩種資料，
 * 同一字兩邊都有資料時優先採用 c_lastname=0（一般讀音）。
 *
 * 資料分層：pinyin 表是人工策展的權威層（姓氏讀音、多音字取捨、歷史人名特殊讀法），
 * 查無此字時退回 frankslin/opencc-pinyin 套件的 zdic 全量字典（約 4.2 萬字、
 * 無聲調首讀音）。因此「查無拼音」如今只剩極生僻字與非漢字，不再是常用異體字。
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
        $result = '';

        foreach (self::getSyllables($string) as $syllable) {
            $result .= $syllable['pinyin'] ?? $syllable['char'];
        }

        return $result;
    }

    /**
     * 逐字音節分解，保留音節邊界供呼叫端自行決定連接規則
     * （空格分隔、人名連寫加隔音符、首字母大寫等）。
     *
     * @return list<array{char:string,pinyin:?string}> 查無讀音（含非漢字）時 pinyin 為 null
     */
    public static function getSyllables(string $string): array {
        self::ensureLoaded();

        $out = [];
        foreach (preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $out[] = [
                'char' => $char,
                'pinyin' => self::$cache[$char] ?? PinyinData::lookup($char, false),
            ];
        }

        return $out;
    }

    /**
     * 人名（名字段）連寫：音節直接相連，後一音節以 a/o/e 開頭時依
     * 漢語拼音正詞法（GB/T 16159）插入隔音符，如 長安 → chang'an、
     * 西安 → xi'an。查無讀音的字元原樣保留（維持「無拼音」信號），
     * 且其前後不插隔音符。
     */
    public static function getNamePinyin(string $string): string {
        $result = '';
        $prevWasSyllable = false;

        foreach (self::getSyllables($string) as $syllable) {
            if ($syllable['pinyin'] === null) {
                $result .= $syllable['char'];
                $prevWasSyllable = false;

                continue;
            }
            if ($prevWasSyllable && preg_match('/^[aoeAOE]/u', $syllable['pinyin'])) {
                $result .= "'";
            }
            $result .= $syllable['pinyin'];
            $prevWasSyllable = true;
        }

        return $result;
    }

    /**
     * 判斷某個字元是否直接存在於人工策展的 `pinyin` 表（不含 opencc-pinyin
     * 靜態字典的退回層）。供「罕見字檢測」使用：一個字若僅能靠 opencc 退回層
     * 或完全查無讀音，isInTable() 會回傳 false，代表它不在權威表內。
     *
     * 注意：這裡刻意「只看表」，與 getPinyin()/getSyllables() 會退回 opencc
     * 字典的行為不同——罕見字檢測的目的正是找出表未覆蓋的字。
     */
    public static function isInTable(string $char): bool {
        self::ensureLoaded();

        return array_key_exists($char, self::$cache);
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
