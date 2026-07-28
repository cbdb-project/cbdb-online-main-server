<?php

namespace App\Support;

/**
 * 人工補充的繁簡映射（OpenCC 未收錄、但人名資料中常見的異體／訛寫字對，例如 栢→柏）。
 *
 * 唯一來源：config/trad_simp_manual_overrides.php。
 *
 * 刻意不寫入 third_party/opencc/TSCharacters.txt（vendored OpenCC 原始字典檔）：那份是
 * `php artisan cbdb:sync-opencc-trad-simp` 從上游完整覆蓋更新的第三方原始檔，若把人工
 * 映射也塞進去，每次更新都得記得重新合併，兩處都要維護、也容易漏掉。改為透過
 * App\Support\TradSimpMap::full()，在實際建索引（NameSearchIndexService／
 * RebuildNameSearchIndex）解析完 vendored 原始檔之後疊加套用一次，確保不論原始檔有沒有
 * 更新過，人工映射永遠生效、且只有一個修改點。
 */
class TradSimpManualOverrides {
    /**
     * @return array<string,string>
     */
    public static function all(): array {
        $overrides = config('trad_simp_manual_overrides', []);
        $result = [];

        foreach ($overrides as $trad => $simp) {
            $trad = self::normalizeChar((string) $trad);
            $simp = self::normalizeChar((string) $simp);
            if ($trad === null || $simp === null) {
                continue;
            }
            $result[$trad] = $simp;
        }

        return $result;
    }

    /**
     * 將人工映射疊加到既有的繁簡映射表上（人工映射優先，覆蓋原有結果）。
     *
     * @param array<string,string> $map
     * @return array<string,string>
     */
    public static function apply(array $map): array {
        return array_merge($map, self::all());
    }

    protected static function normalizeChar(string $value): ?string {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $char = mb_substr($value, 0, 1, 'UTF-8');

        return $char === '' ? null : $char;
    }
}
