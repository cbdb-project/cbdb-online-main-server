<?php

/*
|--------------------------------------------------------------------------
| 繁簡人工補充映射
|--------------------------------------------------------------------------
|
| OpenCC TSCharacters.txt 是嚴格的字元級繁簡對照表，不含「異體字／訛寫字」這類
| 在人名資料中常見、但非正式繁簡對應的字對。此處補充的映射由
| App\Support\TradSimpManualOverrides 讀取，經 App\Support\TradSimpMap::full()
| 疊加套用在 third_party/opencc/TSCharacters.txt（vendored 基礎資料）之上——不寫入該檔，
| 避免被 `php artisan cbdb:sync-opencc-trad-simp` 更新 vendored 檔案時覆蓋掉。
|
| 新增項目前請先以 SQL 確認 CBDB 資料中確實存在該異體字（例如
| BIOG_MAIN.c_name_chn / ALTNAME_DATA.c_alt_name_chn LIKE '%字%'），
| 避免無依據地擴大匹配範圍。修改後需重跑（vendored 基礎資料不受影響，只需重建姓名索引）：
|   php artisan cbdb:rebuild-name-search --truncate
|
*/

return [
    // 栢 是 柏 的異體／訛寫字，常見於人名（尤其姓氏），OpenCC 未收錄此對照。
    '栢' => '柏',
];
