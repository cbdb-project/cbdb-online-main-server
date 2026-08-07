<?php

namespace App\Support;

/**
 * 各 search/* 端點常見寫法：LIKE 模糊比對 OR 代碼欄位精確比對（讓使用者可直接輸入代碼查詢）。
 * 但代碼欄位多為整數型別，MySQL/MariaDB 在非嚴格模式下比對「整數欄位 = 非數字字串」時，
 * 字串會被寬鬆轉型成 0，導致誤中代碼 0（多為「未詳」占位列）——使用者輸入人名／關鍵字搜尋時，
 * 偶爾會在下拉候選中看到一筆不相干的「0 未詳」。
 *
 * 用法：只在 $q 為純數字時才附加代碼相等的 orWhere 分支，否則完全略過該分支
 * （不使用任何「保證不存在」的哨兵值——代碼欄位多為有號 int 且無 UNSIGNED／CHECK 限制，
 * 無法保證未來或既有資料不會出現負值代碼）。
 *
 *     $query->where('c_name_chn', 'like', '%'.$q.'%')
 *         ->when(ExactCodeMatchGuard::isNumeric($q), fn ($q2) => $q2->orWhere('c_personid', $q));
 */
class ExactCodeMatchGuard {
    public static function isNumeric(mixed $q): bool {
        return ctype_digit((string) $q);
    }
}
