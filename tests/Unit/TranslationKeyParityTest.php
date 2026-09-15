<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * zh-TW ↔ en 翻譯鍵對稱性護欄。
 *
 * AGENTS.md §6 寫著「翻譯檔：`resources/lang/zh-TW/*.php` 與 `resources/lang/en/*.php`；
 * 兩者必須同步」——但在本測試之前**沒有任何機械化把關**。實測（2026-09-15）兩邊當時
 * 完全一致（19 個群組、2,181 個鍵），所以這條測試是把**現況**釘住，不是修複既有偏差。
 *
 * 為什麼值得釘：漏一邊的症狀是「切到英文時畫面上出現 `codes.some_key` 這種原始鍵名」，
 * 只有真的切語言才看得到；PHP 端 `__()` 找不到鍵時回傳鍵本身、不會拋錯，測試也不會紅。
 *
 * ⚠️ **這條測試不管「鍵有沒有人用」**（孤兒鍵）。那件事靜態分析做不到，理由見
 * `docs/BLADE_REACT_DUPLICATION_CLEANUP_PLAN.md` 的 7-T1：key 會被動態組出來
 * （`'codes.table_desc.'.$table`、`` `filter_err_${code}` ``、`__('person.tab_'.$tab)`），
 * 前端又普遍經 `tr(k, fallback)` 這類別名包裝呼叫，掃不出可信的孤兒清單。
 */
class TranslationKeyParityTest extends TestCase {
    private const LOCALES = ['zh-TW', 'en'];

    /** 把巢狀陣列攤平成 `a.b.c` 形式的鍵清單。 */
    private function flatten(array $data, string $prefix = ''): array {
        $keys = [];
        foreach ($data as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $keys = array_merge($keys, $this->flatten($value, $full));
            } else {
                $keys[] = $full;
            }
        }

        return $keys;
    }

    private function langPath(string $locale): string {
        return dirname(__DIR__, 2).'/resources/lang/'.$locale;
    }

    /** @return array<int, string> 群組名（不含 .php） */
    private function langGroups(string $locale): array {
        $files = glob($this->langPath($locale).'/*.php') ?: [];
        $groups = array_map(static fn ($p) => basename($p, '.php'), $files);
        sort($groups);

        return $groups;
    }

    #[Test]
    public function both_locales_have_the_same_translation_groups(): void {
        [$zh, $en] = [$this->langGroups('zh-TW'), $this->langGroups('en')];

        $this->assertNotEmpty($zh, '一個翻譯群組都沒讀到——這條測試會變成空轉');
        $this->assertSame(
            $zh,
            $en,
            "zh-TW 與 en 的翻譯群組檔不一致。\n"
            ."只在 zh-TW：".implode(', ', array_diff($zh, $en))."\n"
            ."只在 en：".implode(', ', array_diff($en, $zh))
        );
    }

    /**
     * ⚠️ 本條只迭代 **zh-TW 的群組清單**，所以「只存在於 en 的群組檔」它看不到——
     * 那一半由上面的 `both_locales_have_the_same_translation_groups()` 負責。
     * 兩條測試是**分工**而非重複，刪掉任一條都會留下缺口。
     */
    #[Test]
    public function every_group_has_identical_keys_in_both_locales(): void {
        $groups = $this->langGroups('zh-TW');
        $total = 0;

        foreach ($groups as $group) {
            $sets = [];
            foreach (self::LOCALES as $locale) {
                $path = $this->langPath($locale).'/'.$group.'.php';
                $this->assertFileExists($path);
                $data = require $path;
                $this->assertIsArray($data, "{$locale}/{$group}.php 沒有回傳陣列");
                $keys = $this->flatten($data);
                sort($keys);
                $sets[$locale] = $keys;
            }

            $onlyZh = array_diff($sets['zh-TW'], $sets['en']);
            $onlyEn = array_diff($sets['en'], $sets['zh-TW']);
            $this->assertSame(
                $sets['zh-TW'],
                $sets['en'],
                "翻譯群組 `{$group}` 的鍵在兩個語系不一致——切到缺鍵的那一邊時，畫面上會直接出現原始鍵名。\n"
                ."只在 zh-TW：".(implode(', ', $onlyZh) ?: '（無）')."\n"
                ."只在 en：".(implode(', ', $onlyEn) ?: '（無）')
            );
            $total += count($sets['zh-TW']);
        }

        // 防空轉：檔案讀不到／攤平壞掉時上面每一組都會是兩個空陣列，assertSame 照樣綠。
        $this->assertGreaterThan(1500, $total, "只數到 {$total} 個翻譯鍵，遠少於實際數量——攤平邏輯可能壞了");
    }

    /**
     * 允許為空陣列的 key（`group.key`）。
     *
     * `validation.attributes` 是 **Laravel 自己的慣例**：框架語言檔預設就給一個空陣列，
     * 讓專案可以逐欄位覆寫欄位名稱。它不是「忘了填的翻譯」，兩個語系也都有。
     * 其餘任何空陣列都要紅——`__()` 拿到陣列時回傳的是陣列，畫面上不會是文字。
     */
    private const EMPTY_ARRAY_ALLOWED = ['validation.attributes'];

    #[Test]
    public function no_translation_value_is_empty_or_non_string(): void {
        $bad = [];
        $checked = 0;
        foreach (self::LOCALES as $locale) {
            foreach ($this->langGroups($locale) as $group) {
                $data = require $this->langPath($locale).'/'.$group.'.php';
                $flat = [];
                // ⚠️ **空陣列要當成葉節點收下，不能遞迴進去就算了**：`'x' => []` 遞迴 0 次、
                // 什麼都不留，整個 key 就從清單裡消失——codex 實測在兩個語系各加一個 `'_x' => []`，
                // 三條測試全綠。空陣列同樣是不可用的翻譯值（`__()` 會回傳陣列）。
                $walk = function (array $node, string $prefix = '') use (&$walk, &$flat) {
                    foreach ($node as $key => $value) {
                        $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                        if (is_array($value) && $value !== []) {
                            $walk($value, $full);
                        } else {
                            $flat[$full] = $value;
                        }
                    }
                };
                $walk($data);

                foreach ($flat as $key => $value) {
                    if ($value === [] && in_array($group.'.'.$key, self::EMPTY_ARRAY_ALLOWED, true)) {
                        continue;
                    }
                    // ⚠️ **不可寫成 `assertNotSame('', is_string($v) ? trim($v) : $v)`**：
                    // 那樣 `null`／`0`／`false` 會原值丟進去比對，`assertNotSame('', null)` 永遠通過
                    //——而 `null` 正是這條測試宣稱要抓的症狀（`__()` 回傳 null，畫面一片空白）。
                    // review 實測過：把某個值改成 `null`，舊寫法照樣綠。
                    ++$checked;
                    // `trim()` 只剝 ASCII 空白，剝不掉全形空格 U+3000／NBSP／BOM——那些在畫面上
                    // 一樣是空白（codex 實測：把某個值改成單一全形空格，只用 trim() 的版本照樣綠）。
                    $blank = is_string($value)
                        && preg_replace('/^[\s\x{00A0}\x{3000}\x{FEFF}]+|[\s\x{00A0}\x{3000}\x{FEFF}]+$/u', '', $value) === '';
                    if (!is_string($value) || $blank) {
                        $bad[] = sprintf(
                            '%s/%s.php 的 `%s`（%s）',
                            $locale,
                            $group,
                            $key,
                            is_string($value) ? '空字串或純空白（含全形空格／NBSP）' : '非字串：'.get_debug_type($value)
                        );
                    }
                }
            }
        }

        // 防空轉：路徑或 glob 壞掉時 $bad 會是空陣列、斷言照樣綠。
        // （上面兩條測試會先紅，但這條單獨跑時也要能自證不是空轉。）
        $this->assertGreaterThan(3000, $checked, "只檢查到 {$checked} 個值（兩個語系合計），遠少於實際數量");

        // 一次報完所有壞鍵，而不是逐鍵 assert 撞到第一個就中斷。
        $this->assertSame(
            [],
            $bad,
            "下列翻譯值是空的或不是字串——`__()` 會回傳空白／null 而不是可辨識的鍵名，比缺鍵更難發現：\n  "
            .implode("\n  ", $bad)
        );
    }
}
