<?php

namespace App\Support;

/**
 * 人物拼音搜尋的 ü／v 規範化與查詢展開。
 *
 * 歷史背景：CBDB 漢語拼音欄位長期以 **v** 表示 ü 韻（例：呂 = "Lv"、女 = "Nv"）。
 * v→ü 遷移（見 docs/PINYIN_V_TO_UMLAUT_MIGRATION.md）後，人名資料改以正字 **ü** 儲存，
 * 但使用者仍可能輸入 v 形（CBDB 舊慣例，如 Lv）、ü 形（正規拼音，如 Lü）或 u 形（Lu）。
 *
 * ── umlautToV（舊 #85 單向折疊，遷移前）：把 ü→v，命中 v 形儲存的資料。保留供
 *    過渡期與非展開呼叫點使用；遷移完成後其單獨使用已不足（見下）。
 *
 * ── expand（§D-8 查詢展開，遷移後）：回傳應以 **OR 同時比對**的拼音形式集合，處理 v↔ü：
 *    v 形輸入補 ü 形（命中已遷移 ü 資料）、ü 形輸入補 v 形（命中過渡期殘留 v 資料）。
 *    u 形輸入不在此程式展開——它由生產環境 accent-insensitive collation 直接摺疊命中 ü 資料
 *    （§D-8：「u 已由 collation 摺疊命中 ü，無需改」）；故 expand() 不做 u→v。
 *
 * collation 注意：生產環境 general_ci／unicode_ci 皆重音不敏感（ü ≈ u）。故 ü 形在 LIKE
 * 會連帶折成 u、命中 u 群（Lü≈Lu → 盧/陸）。此擴大命中為 §D-8 已接受之搜尋語意
 * （等同使用者直接打 u），與 #85 當年「資料端純 v、擴 ü 無益且誤撈」的前提已因遷移而反轉。
 * SQLite（測試）不折疊 ü/u，撰測時須以字面形式驗證，勿據 SQLite 推斷生產折疊行為。
 */
class PinyinSearchNormalizer {
    /**
     * 將搜尋字串的 ü／Ü 折成 v／V（其餘字元不動；中文／數字輸入為 no-op）。
     */
    public static function umlautToV(?string $term): string {
        return str_replace(['ü', 'Ü'], ['v', 'V'], (string) $term);
    }

    /**
     * 查詢展開：回傳應以 OR 同時比對的拼音形式（去重、保序）。
     *
     * 三個來源形式：
     *  - 原字：命中 ü 形輸入對 ü 資料、u 形輸入（prod 折疊）、過渡期殘留 v 資料。
     *  - PinyinUmlaut::normalize（v→ü，僅動 lv/lve/nv/nve 音節簇）：命中 v 形輸入對已遷移的 ü 資料。
     *  - umlautToV（ü→v）：命中 ü 形輸入對過渡期殘留 v 資料。
     *
     * 對中文／數字／不含可轉音節或 ü 的西文名（如 Calvin）三形式相等 → 回傳單一形，行為與展開前一致。
     *
     * @return array<int, string> 至少一個元素；空字串輸入回傳 ['']。
     */
    public static function expand(?string $term): array {
        $raw = (string) $term;

        return array_values(array_unique([
            $raw,
            PinyinUmlaut::normalize($raw),
            self::umlautToV($raw),
        ]));
    }
}
