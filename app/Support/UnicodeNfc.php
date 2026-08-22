<?php

namespace App\Support;

use Normalizer;

/**
 * Unicode NFC（Normalization Form C）正規化：把**相容表意文字**折疊成統一表意文字。
 *
 * 這與異體字落地替換（`CharVariantMapService`）是**兩件不同性質的事**，不要混為一談：
 *
 * | | 異體字落地替換 | 本類（NFC） |
 * |---|---|---|
 * | 例 | 愼(U+613C) → 慎(U+614E) | 慎(U+FA87) → 慎(U+614E) |
 * | 兩者是同一個字嗎 | **否**——Unicode 視為不同字，關係記在 Unihan 的 kSemanticVariant／kZVariant | **是**——canonical equivalence，Unicode 定義上就是同一個字 |
 * | 誰決定 | 應用層的**編輯判斷**（本專案以 char_variant_map 逐筆策管） | Unicode 標準，無判斷空間 |
 * | 可逆性 | 丟失原錄入字形 | 不丟失任何字元身分（相容碼位存在的目的只是與舊編碼往返轉換） |
 *
 * Unicode 從不給統一表意文字 canonical decomposition（穩定性政策），所以 NFC **不會**
 * 碰 愼／峯／靑 這類異體字——實測 7 筆 char_variant_map 種子字全部 NFC 不變。反過來
 * 相容表意文字有 canonical decomposition，NFC 一定折疊。兩套機制的作用域不重疊。
 *
 * 為什麼要做：相容碼位與統一碼位在資料庫層是**不同的位元組**，唯一鍵擋不住、精確比對
 * 找不到、搜尋互不可見。生產庫實測（2026-08）`ALTNAME_DATA.c_alt_name_chn` 107 列、
 * `OFFICE_CODES.c_office_chn` 23 列、`BIOG_MAIN.c_name_chn` 17 列、`TEXT_CODES.c_title_chn`
 * 16 列含相容表意文字，其中 c_personid=551931 的「李」是 U+F9E1、而其他人的「李」是
 * U+674E——用「李」搜尋找不到前者。
 *
 * **已知取捨**：少數相容碼位在來源編碼裡帶有「讀音」資訊（U+F9E1 李 來自 KS X 1001 的
 * 「이」讀音、與 U+674E 的「리」相對），NFC 會抹掉那個區別。對 CBDB 不構成損失——讀音存在
 * 獨立的拼音欄（`c_name`／`c_surname`／`c_alt_name_pinyin`），不靠碼位承載；而庫中這些字
 * 出現在漢人姓名與官名裡，來源是輸入法／舊編碼轉換的意外，不是刻意的讀音標記。
 * 這也是 W3C Character Model 對網路上文本的建議儲存形式。
 *
 * 相依：`Normalizer` 由 `symfony/polyfill-intl-normalizer`（隨 Laravel 進來的既有相依）提供，
 * **不需要安裝 ext-intl**；若日後裝了 ext-intl，polyfill 會自動讓位給原生實作。
 */
final class UnicodeNfc {
    /**
     * 把字串正規化為 NFC。已是 NFC（絕大多數情況）時原樣回傳，不配置新字串。
     *
     * 失敗時（`Normalizer::normalize()` 對格式錯誤的 UTF-8 回 false）**保留原值**：
     * 這是資料寫入路徑，寧可存進未正規化的原字，也不能把欄位變成 `false`／空字串。
     */
    public static function normalize(string $text): string {
        if ($text === '') {
            return $text;
        }

        // 純 ASCII 必然已是 NFC，省下 isNormalized() 的逐字檢查（拼音／代碼／URL 欄走這條）。
        if (!preg_match('/[\x80-\xFF]/', $text)) {
            return $text;
        }

        if (Normalizer::isNormalized($text, Normalizer::FORM_C)) {
            return $text;
        }

        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $text;
    }
}
