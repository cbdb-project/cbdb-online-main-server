<?php

namespace App\Support;

/**
 * 人物拼音搜尋的 ü／v 規範化。
 *
 * CBDB 的漢語拼音欄位（BIOG_MAIN.c_name / c_surname / c_mingzi）以 **v** 表示 ü 韻
 * （例：呂 = "Lv"、女 = "Nv"），全庫無字面 ü（已實測：c_name/surname/mingzi 字面 ü = 0）。
 * 但使用者可能輸入正規拼音的 ü（如「Lü」），亦可能輸入 CBDB 慣例的 v（如「Lv」）。
 *
 * 本規範化把查詢字串中的 ü／Ü 一律折成 v／V，使兩種輸入都能命中 v 形儲存的資料。
 *
 * 為何只折查詢端、且只能「ü→v」單向：
 * - 資料端無字面 ü，毋須改寫欄位（避免在 LIKE 兩側加 REPLACE 拖累，且維持簡單）。
 * - DB collation 為 utf8mb4_unicode_ci（重音不敏感，視 ü ≈ u）。若反向把 v 展開成 ü 去比對，
 *   "Lü" 會被 collation 當成 "Lu" 命中大量「盧/陸」等 u 群 → 誤撈。故**絕不可**把 v 改寫為 ü 比對。
 */
class PinyinSearchNormalizer {
    /**
     * 將搜尋字串的 ü／Ü 折成 v／V（其餘字元不動；中文／數字輸入為 no-op）。
     */
    public static function umlautToV(?string $term): string {
        return str_replace(['ü', 'Ü'], ['v', 'V'], (string) $term);
    }
}
