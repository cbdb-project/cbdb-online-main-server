<?php

namespace App\Support;

/**
 * 漢語拼音 v → ü 正規化（止血 helper）。
 *
 * 依《漢語拼音方案》，`ü` 只出現在聲母 `l` / `n` 之後，可窮舉的音節僅四種：
 *   lv → lü、lve → lüe、nv → nü、nve → nüe（大小寫各自處理）。
 *
 * 判定規則：`l/n` 之後的 `v`，且其後**不接母音 a/i/o/u**時才轉換——
 *   - 接 `e` 屬 `lve/nve`（即 lüe/nüe），仍轉；
 *   - 接 `a/i/o/u` 屬西文名（如 Silva、Calvin、Melvin、Sylvia），**不轉**；
 *   - 接子音或邊界（空白/字串結尾）屬完整音節（如 `Yelv`、`Lvzhai`、`Lv Yin`），轉。
 * 大小寫保留：`Lv→Lü`、`LV→LÜ`、`lv→lü`。
 *
 * ⚠️ 適用範圍：本 helper 設計用於「已是合法漢語拼音」的字串——即生成入口（auto_pinyin /
 * 批次 buildPinyin / searchPinyin）由中文轉出的拼音輸出。對「任意自由英文文字」（如 `Denver`）
 * 可能誤判，故**不得**直接套用於未經人工複核的自由文字；資料批量修正以人工複核清單為準
 * （見 docs/PINYIN_V_TO_UMLAUT_MIGRATION.md §D）。
 */
class PinyinUmlaut {
    /** l/n 之後、其後非 a/i/o/u 的 v（保留大小寫）。'e' 不在排除集，故 lve/nve 亦會命中。 */
    private const PATTERN = '/([LlNn])([Vv])(?![aiouAIOU])/u';

    /**
     * 保存時「靜默」歸一化的 BIOG_MAIN 漢語拼音欄（Tier 1）。
     *
     * 僅列**定義上即漢語拼音**的欄：`c_surname`/`c_mingzi`（其他羅馬化另存於 `c_*_rm`／`c_*_proper`）、
     * 以及由二者組出的 `c_name`。**刻意不含** `c_*_rm`（Wade-Giles）／`c_*_proper`（母語拉丁名，
     * 可能含真 `v`）。ALTNAME_DATA 沒有 Tier 1 欄位——其唯一的別名羅馬字欄 `c_alt_name` 可能含
     * 西文別名（如 Denver），走前端 Tier 2 互動確認。設計見 docs/PINYIN_SAVE_NORMALIZE_DESIGN.md。
     */
    public const BIOG_MAIN_PINYIN_V_FIELDS = ['c_surname', 'c_mingzi', 'c_name'];

    /** 將字串中作為 ü 代寫的 v 正規化為 ü。null/空字串原樣返回。 */
    public static function normalize(?string $value): string {
        if ($value === null || $value === '') {
            return (string) $value;
        }

        return preg_replace_callback(
            self::PATTERN,
            static fn (array $m): string => $m[1] . ($m[2] === 'V' ? 'Ü' : 'ü'),
            $value
        ) ?? $value;
    }

    /**
     * 對 $data 中列於 $fields 的字串欄套用 normalize()；非字串／缺欄／null 原樣略過。
     *
     * 保存前歸一化用：搭配 self::BIOG_MAIN_PINYIN_V_FIELDS。冪等。
     *
     * @param array<string,mixed> $data
     * @param list<string>        $fields
     * @return array<string,mixed>
     */
    public static function normalizeFields(array $data, array $fields): array {
        foreach ($fields as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = self::normalize($data[$field]);
            }
        }

        return $data;
    }
}
