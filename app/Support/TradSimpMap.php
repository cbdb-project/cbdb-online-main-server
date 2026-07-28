<?php

namespace App\Support;

/**
 * 繁簡字對照表的唯一讀取入口。
 *
 * 基礎資料直接解析 vendored 的 third_party/opencc/TSCharacters.txt（OpenCC 原始字典檔，
 * Apache License 2.0，見 third_party/opencc/LICENSE）——刻意不另外產生、提交一份衍生的
 * PHP 陣列檔：那樣會多出一道「更新完原始檔還要記得手動重新產生衍生檔」的步驟，兩份檔案
 * 也可能因為忘記重新產生而不同步。解析邏輯很單純（幾千行文字、字元級 tab 分隔對照），
 * 直接在讀取當下解析、行程內快取一次即可，不需要預先編譯成陣列。
 *
 * 更新 third_party/opencc/TSCharacters.txt 本身：
 *   php artisan cbdb:sync-opencc-trad-simp
 * 這個指令只做「下載最新版本、覆蓋 vendored 檔案」這一件事，不產生任何衍生檔——
 * 覆蓋後直接 `git diff third_party/opencc/TSCharacters.txt` 審查，提交後隨部署上線，
 * 下次任何程式碼讀取都會直接反映新內容，不需要額外步驟。
 *
 * 人工補充映射（見 TradSimpManualOverrides）維持獨立於這份 vendored 資料之外，在 full()
 * 讀取端疊加套用，不寫入 TSCharacters.txt。
 */
class TradSimpMap {
    /**
     * @var array<string,string>|null
     */
    protected static ?array $baseMap = null;

    /**
     * Vendored OpenCC 原始字典檔路徑。
     */
    public static function sourcePath(): string {
        return base_path('third_party/opencc/TSCharacters.txt');
    }

    /**
     * 解析 TSCharacters.txt，回傳基礎對照表，不含人工補充映射。
     *
     * @return array<string,string>
     */
    public static function baseMap(): array {
        if (self::$baseMap === null) {
            self::$baseMap = self::parseFile(self::sourcePath());
        }

        return self::$baseMap;
    }

    /**
     * 基礎資料疊加人工補充映射後的完整對照表——姓名索引建置唯一應該使用的入口。
     *
     * @return array<string,string>
     */
    public static function full(): array {
        return TradSimpManualOverrides::apply(self::baseMap());
    }

    /**
     * 清除靜態快取（測試用）。
     */
    public static function reset(): void {
        self::$baseMap = null;
    }

    /**
     * 解析 OpenCC TSCharacters.txt 格式（`trad\tsimp1 simp2 ...`，`#` 起為註解）。
     * 獨立成 public 方法供測試直接傳入任意路徑呼叫，不必依賴 sourcePath() 的固定位置。
     *
     * 規則：
     * - 每個繁體字對應多個簡體字時，只保留第一個候選
     * - 同形映射（trad === simp）一律排除——OpenCC 對「罕見簡化字可能無對應字型
     *   （tofu risk）」的字會保留原字作為預設候選，或對某些字給出「原字本身也是合法
     *   簡體」的候選（如 `沈	沈 沉`），這類映射對繁簡轉換無實際作用（未命中時本就
     *   fallback 回原字）
     *
     * @return array<string,string>
     */
    public static function parseFile(string $path): array {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $records = [];

        foreach ($lines as $line) {
            if (($commentPos = strpos($line, '#')) !== false) {
                $line = substr($line, 0, $commentPos);
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 2) {
                continue;
            }

            $trad = self::firstChar(array_shift($parts));
            $simp = self::firstChar(implode('', $parts));

            if ($trad === null || $simp === null || $trad === $simp) {
                continue;
            }

            $records[$trad] = $simp;
        }

        return $records;
    }

    protected static function firstChar(?string $value): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $char = mb_substr($value, 0, 1, 'UTF-8');

        return $char === '' ? null : $char;
    }
}
