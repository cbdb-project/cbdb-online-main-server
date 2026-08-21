<?php

/*
|--------------------------------------------------------------------------
| 異體字落地替換（char_variant_map）
|--------------------------------------------------------------------------
|
| 見 docs/CHAR_VARIANT_MAP_TEXT_COLUMN_ROLLOUT_PLAN.md。這些字串會出現在
| Codes UI 的 flash 訊息與 v2 mutate 回應的 notices 欄位（涵蓋 80+ 張代碼表
| 與所有人物子資源），所以依 AGENTS.md §6 必須走 __()，不可硬編。
|
*/

return [
    'notice' => '異體字：:pairs',
    'notice_pair' => '「:variant」已正規化為「:reference」',
    'notice_separator' => '、',

    'incomplete_payload' => '更新對照時必須同時提供異體字與參考字（或指定要更新的對照 id）。',
    'single_codepoint_required' => '異體字與參考字都必須是單一字元。',
    'self_reference_not_allowed' => '異體字與參考字不可相同。',
    'cycle_not_allowed' => '這筆對照會造成循環（「:char」繞回自己），請改用其他參考字。',
];
