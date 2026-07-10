<?php

namespace Tests\Concerns;

use App\Services\PinyinDictionary;
use Illuminate\Support\Facades\DB;

/**
 * 供需要「真實拼音轉換結果與現行行為一致」的測試使用，把整份
 * database/data/pinyin_dictionary.php（~6910 筆一般字典資料）塞進測試用的
 * SQLite 記憶體 `pinyin` 表（c_lastname=0）。
 *
 * 使用時機：測試斷言的拼音結果經過 PinyinDictionary::getPinyin()（名字/書名/
 * 職官名/機構名等「一般轉換」路徑），而不只是姓氏拆分本身。若測試只關心
 * 姓氏拆分邏輯、不關心名字部分實際拼出什麼，可以不呼叫本 trait，只塞姓氏資料
 * 即可，跑起來更快。
 *
 * 使用前必須已經建立好含 c_chn/c_pinyin/c_lastname 欄位的 `pinyin` 表。
 */
trait SeedsPinyinDictionary {
    /**
     * 把整份字典資料（c_lastname=0）塞進目前測試的 `pinyin` 表，並重置
     * PinyinDictionary 的靜態快取，確保後續查詢會重新讀取剛塞入的資料。
     */
    protected function seedPinyinDictionary(): void {
        $dictionary = require base_path('database/data/pinyin_dictionary.php');

        $rows = [];
        foreach ($dictionary as $chn => $pinyin) {
            $rows[] = ['c_chn' => $chn, 'c_pinyin' => $pinyin, 'c_lastname' => 0];
        }

        foreach (array_chunk($rows, 500) as $batch) {
            DB::table('pinyin')->insert($batch);
        }

        PinyinDictionary::reset();
    }
}
