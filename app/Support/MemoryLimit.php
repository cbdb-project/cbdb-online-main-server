<?php

namespace App\Support;

/**
 * 放寬 PHP 記憶體上限的統一入口。與 ExecutionTimeLimit 同一個問題、同一套語義。
 *
 * 為什麼需要包一層：`ini_set('memory_limit', ...)` 的作用域是**整個 PHP process**，不是單一
 * request。舊寫法把它放在 `Api/ApiController*.php` 與 `BiogMainRepository.php` 的 class 宣告
 * **之前**（檔案頂層），autoload 到該檔就生效，於是：
 *  - PHPUnit 共用單一 process，autoload 到任一檔就把整個測試 process 的上限改掉，且「哪個檔
 *    最後被載入就贏」（512M vs 1024M），會隨測試檔排序漂移。
 *  - 更嚴重的是在正式環境它其實在**降級**（見下方實測），與寫這行的原意相反。
 *
 * 生產實測（2026-08-03，php-fpm 8.4 / CLI 8.4）：
 *  - web `memory_limit = 1G` → 舊寫法的 `ini_set('memory_limit', '512M')` 把每一次
 *    `/api/select/*` 請求從 1G **壓到 512M**，只剩伺服器願意給的一半；`'1024M'` 則等於 1G、
 *    是 no-op。也就是說這些行在生產沒有一行達成了它想達成的事。
 *  - CLI `memory_limit = -1`（無限制）→ 舊寫法把 artisan 壓到 512M。
 *
 * 因此語義同樣是「**只放寬、絕不縮限**」。刻意不直接刪掉呼叫點：低配環境（PHP 內建預設 128M）
 * 跑這些重查詢端點仍需要放寬，直接刪會讓那些環境 OOM。
 *
 * 註：本類別不需要 ExecutionTimeLimit 那樣的「測試環境 no-op」判斷——測試環境的上限本來就
 * 遠高於這裡要求的值（本機 CLI 4G、生產 CLI 無限制），「只放寬」天然就是 no-op；萬一某環境
 * 以更低的上限跑測試，放寬也只會讓測試更不容易 OOM，不會製造新的失敗。
 */
class MemoryLimit {
    /**
     * 把記憶體上限放寬到 $limit（PHP shorthand，如 '512M'、'1G'）。只放寬、絕不縮限。
     */
    public static function extendTo(string $limit): void {
        $requested = self::toBytes($limit);
        if ($requested === null) {
            return;
        }

        $current = self::toBytes((string) ini_get('memory_limit'));
        if ($current === null) {
            return;
        }

        // -1（無限制）在 toBytes() 回傳 PHP_INT_MAX，因此這個比較同時涵蓋「已無限制」。
        if ($current >= $requested) {
            return;
        }

        ini_set('memory_limit', $limit);
    }

    /**
     * 把 PHP shorthand 記憶體字串換算成 bytes；'-1'（無限制）回傳 PHP_INT_MAX。
     * 無法解析時回傳 null——呼叫端會放棄調整、不做任何猜測。
     *
     * 本函式刻意比 PHP 自身的 shorthand 規則更嚴（不收 '1024MB'／'1T'／'+512M'／'-2'）。
     * 這是安全的方向：所有不認得的輸入都走 null → 提早 return → 完全不動 ini。特別是負值
     * （PHP 語義上任何負值都代表無限制）雖然走 null 而非 PHP_INT_MAX 分支，最終效果一致
     * ——都不會把上限收窄。
     */
    private static function toBytes(string $value): ?int {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if ($value === '-1') {
            return PHP_INT_MAX;
        }

        if (!preg_match('/^(\d+)([KMG])?$/i', $value, $matches)) {
            return null;
        }

        $bytes = (int) $matches[1];
        $multiplier = match (strtoupper($matches[2] ?? '')) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            default => 1,
        };

        // 溢位視為「無法解析」而非飽和成 PHP_INT_MAX。兩個理由：
        //  1. $bytes * $multiplier 超過 int 範圍時 PHP 會轉成 float，而回傳型別是 ?int，會拋
        //     TypeError——在檔案頂層呼叫點就是 autoload 期的 500，正是本次要修掉的那種難查故障。
        //  2. 若飽和成 PHP_INT_MAX 而讓 extendTo() 判定「該放寬」，它接著會拿**原始 shorthand
        //     字串**（如 '99999999999999999999G'）去 ini_set()，PHP 認不得而發 Invalid
        //     "memory_limit" setting warning，還可能在 ini_get() 留下異常值。回 null 則提早
        //     return、完全不動 ini，與其他無法解析的輸入一致。
        if ($bytes > intdiv(PHP_INT_MAX, $multiplier)) {
            return null;
        }

        return $bytes * $multiplier;
    }
}
