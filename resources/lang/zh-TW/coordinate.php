<?php

/*
|--------------------------------------------------------------------------
| 經緯度空白／零值正規化（CoordinatePairNormalizer）
|--------------------------------------------------------------------------
|
| 這些字串目前出現在 v2 mutate／create 回應的頂層 notices 欄位（與異體字替換共用
| 同一個欄位）。Codes UI 的 flash 尚未接上——那條寫入路徑還沒掛上歸零守衛。
| 依 AGENTS.md §6 必須走 __()，不可硬編。
|
| 文案的硬性要求：`cleared_with_partner` 不可以斷言「你填的值被丟棄」。呼叫端已
| 濾掉「原值本來就是 NULL」的無事發生項目，但剩下的仍可能是「原本有值、這次被
| 清掉」而不是「你剛才填的被丟掉」，兩者都得說得過去。
|
*/

return [
    'notice' => '經緯度：:items',
    'notice_separator' => '；',

    'cleared_blank' => '「:column」留空，視為 NULL',
    'cleared_zero' => '「:column」是 0，視為 NULL（0,0 不是有效座標）',
    'cleared_with_partner' => '「:column」一併視為 NULL（經緯度必須成對，另一軸為空或 0）',
];
