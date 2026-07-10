<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 匯入一般字典資料到 pinyin 表（c_lastname=0），資料來源為
 * database/data/pinyin_dictionary.php（原 app/Models/Pinyin.php 的 $dic 靜態陣列，
 * 見 docs/PINYIN_TABLE_CONSOLIDATION_PLAN.md 步驟2）。
 *
 * down() 的回滾安全閘門：只比對「筆數」無法偵測「筆數不變但內容被改過」
 * （例如管理員上線後透過 Codes UI 修改了某筆 c_pinyin，或刪 1 筆又補 1 筆）。
 * 因此改用 deterministic hash 比對整批資料的內容指紋——
 * self::EXPECTED_HASH 是資料檔最終確定時，對排序後的 c_chn=c_pinyin 配對
 * 串接後算出的 sha256（見本檔案 computeHash() 的計算方式），寫死為常數，
 * 不需要額外的 metadata 表。若目前資料庫內容與這個指紋不符，代表上線後
 * 曾被人為異動過，down() 會直接中止、不執行刪除，讓維運者自行決定。
 *
 * 本 migration 的 down() 是本計畫「唯一」的回滾安全閘門所在——上一個 migration
 * （2026_07_10_000000）的 down() 刻意不做任何檢查，原因是 Laravel migration
 * 回滾採 LIFO 順序，完整 migrate:rollback 一定是先跑本 migration 的 down()、
 * 才輪到上一個 migration 的 down()；若兩邊都檢查，等輪到上一個 migration 時，
 * 本 migration 已經清空 c_lastname=0 的資料，會被誤判成「不符預期」而卡住。
 */
return new class () extends Migration {
    private const EXPECTED_HASH = 'bbb557ce56ac99ac3f44e159498e57c98e519aadee5106cb221a6225f4d78a1d';
    private const BATCH_SIZE = 500;

    /**
     * Run the migrations.
     */
    public function up(): void {
        if (!Schema::hasTable('pinyin')) {
            return;
        }

        $dictionary = require base_path('database/data/pinyin_dictionary.php');

        $rows = [];
        foreach ($dictionary as $chn => $pinyin) {
            $rows[] = ['c_chn' => $chn, 'c_pinyin' => $pinyin, 'c_lastname' => 0];
        }

        foreach (array_chunk($rows, self::BATCH_SIZE) as $batch) {
            DB::table('pinyin')->insert($batch);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        if (!Schema::hasTable('pinyin')) {
            return;
        }

        $currentHash = $this->computeHash(
            DB::table('pinyin')->where('c_lastname', 0)->pluck('c_pinyin', 'c_chn')->all()
        );

        if ($currentHash !== self::EXPECTED_HASH) {
            throw new RuntimeException(
                'pinyin 一般字典資料回滾中止：偵測到內容與匯入時的指紋不符（可能上線後透過 Codes UI '
                .'新增/修改/刪除過資料），為避免靜默清空人為異動，拒絕自動回滾。'
                .'如確定要放棄這些異動，請手動清理 c_lastname=0 的資料後再重試。'
            );
        }

        DB::table('pinyin')->where('c_lastname', 0)->delete();
    }

    /**
     * 對 chn=>pinyin 對照計算 deterministic hash：依 chn 用 PHP SORT_STRING 排序後，
     * 以 "chn=pinyin" 逐筆用分號串接，取 sha256。排序與串接方式必須與產生
     * self::EXPECTED_HASH 時完全一致，才能保證同樣的資料內容永遠算出同一個 hash。
     *
     * @param array<string,string> $pairs
     */
    private function computeHash(array $pairs): string {
        ksort($pairs, SORT_STRING);

        $parts = [];
        foreach ($pairs as $chn => $pinyin) {
            $parts[] = $chn.'='.$pinyin;
        }

        return hash('sha256', implode(';', $parts));
    }
};
