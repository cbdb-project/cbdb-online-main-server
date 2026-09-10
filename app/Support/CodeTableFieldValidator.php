<?php

namespace App\Support;

/**
 * config 驅動的代碼／查找表寫入 API 共用欄位正規化與校驗。
 *
 * 為什麼抽出來：更新端（{@see \App\Services\Mutations\AbstractCodeTableMutationHandler}）
 * 與新增端（{@see \App\Services\Mutations\CodeTableCreateHandler}）原本一個有校驗、一個
 * 完全沒有——新增端把值直接丟進 `DB::insert()`，型別不合由資料庫報錯，呼叫端拿到的是
 * **500 而不是 422**。兩端共用同一套判定與同一套正規化，是為了讓「同一張表、同一個
 * 欄位、同一個值」在 create 與 update 得到一致的結果；只在單邊放寬會製造出
 * 「新增進得去、改回同一個值卻 422」這種最難查的不對稱。
 *
 * 型別分類一律由各表 config 明文登記（預設最嚴：字串或 null、長度 ≤ 255）：
 * - integer_fields：整數欄（年份、代碼、旗標）。
 * - float_fields：浮點欄（經緯度）。
 * - long_text_fields：longtext／text 欄，不套 255 長度上限。
 * - not_null_fields：資料庫 NOT NULL 的欄；送 null 一律 422，否則會變成資料庫層
 *   1048 例外（500）。數值型的 NOT NULL 欄另外拒絕空字串（`''` 進不了 smallint）；
 *   文字型的 NOT NULL 欄**允許**空字串——那些欄多半是 `NOT NULL DEFAULT ''`，
 *   對它們來說 `''` 正是「清空」的合法寫法。
 */
final class CodeTableFieldValidator {
    /**
     * 落庫前的型別正規化。**必須在 validate() 之前呼叫，create／update 兩端都要呼叫。**
     *
     * 一律做的事：整數欄收到「小數部分為 0 的浮點數」（`1200.0`）→ 轉成 int。很多 JSON
     * 序列化器（Python 的 `float`、JS 的某些數值路徑）就是這樣送整數的。真的有小數部分
     * 時**不轉**，留給 validate() 以 422 拒絕——靜默截斷年份比報錯糟。
     *
     * `$coerceScalarsToText`：文字欄收到 JSON 數字（例如把頁碼寫成 `"c_pages": 12`）
     * 時轉成字串。**只有 create 端開啟，這是刻意的不對稱**：
     * - create 端在本次加上校驗之前**完全沒有型別檢查**，值原樣丟給 `DB::insert()` 由
     *   資料庫隱式轉型，外部 token 客戶端這樣送一直是能用的。突然改判 422 是破壞性變更，
     *   所以由這裡補回資料庫原本替我們做的事，落庫結果一字不差。
     * - update 端從第一天就要求 string|null，而且那是**刻意驗過的**契約
     *   （`ApiV2MutateCodeTablesTest::testGanzhiDirectUpdateStillRejectsIntegerValueForTextField`：
     *   integer_fields 的放寬不得波及未登記的欄位）。為了對稱而在此放寬，等於默默拆掉
     *   一條有意設計的護欄——寧可保留不對稱並寫進 API.md。
     *
     * @param array<string,mixed> $data
     * @param array<string,array<int,string>> $spec
     * @return array<string,mixed>
     */
    public static function normalize(array $data, array $spec, bool $coerceScalarsToText = false): array {
        $integerFields = $spec['integer_fields'] ?? [];
        $floatFields = $spec['float_fields'] ?? [];

        $notNullFields = $spec['not_null_fields'] ?? [];

        foreach ($data as $field => $value) {
            // 可為 null 的數值欄收到空字串＝「清空」。不轉成 null 的話 MariaDB（本專案
            // sql_mode 非 strict）會存成 0，而 0 在年份／代碼欄都是合法值——看起來像
            // 使用者填的，其實是清空被靜默曲解。
            if ($value === ''
                && !in_array($field, $notNullFields, true)
                && (in_array($field, $integerFields, true) || in_array($field, $floatFields, true))
            ) {
                $data[$field] = null;

                continue;
            }

            if (!is_int($value) && !is_float($value)) {
                continue;
            }

            if (in_array($field, $integerFields, true)) {
                if (is_float($value) && is_finite($value) && floor($value) === $value) {
                    $data[$field] = (int) $value;
                }

                continue;
            }

            if (in_array($field, $floatFields, true)) {
                continue;
            }

            if ($coerceScalarsToText) {
                $data[$field] = (string) $value;
            }
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $data 待校驗的欄位值（已過白名單與 normalize()）
     * @param array<string,array<int,string>> $spec integer_fields／float_fields／long_text_fields／not_null_fields
     * @return array<string,array<int,string>> Laravel 風格的 errors（空陣列＝通過）
     */
    public static function validate(array $data, array $spec): array {
        $integerFields = $spec['integer_fields'] ?? [];
        $floatFields = $spec['float_fields'] ?? [];
        $longTextFields = $spec['long_text_fields'] ?? [];
        $notNullFields = $spec['not_null_fields'] ?? [];
        // 由呼叫端從實際 schema 推導（HandlesCodeTableWrites::integerRanges()），
        // 不是手抄的 config。沒提供的欄位不檢查值域。
        $integerRanges = $spec['integer_ranges'] ?? [];

        $errors = [];
        foreach ($data as $field => $value) {
            $allowInt = in_array($field, $integerFields, true);
            $allowFloat = in_array($field, $floatFields, true);

            if (in_array($field, $notNullFields, true)) {
                // 空字串只對數值欄是錯的；文字型 NOT NULL 欄（多為 DEFAULT ''）允許以 '' 清空。
                $isEmpty = $value === null || (($allowInt || $allowFloat) && $value === '');
                if ($isEmpty) {
                    $errors[$field] = [$field . ' 不可為空'];

                    continue;
                }
            }

            if ($value !== null && !is_string($value)
                && !($allowInt && is_int($value))
                && !($allowFloat && (is_int($value) || is_float($value)))
            ) {
                $errors[$field] = [$field . ' 必須為' . ($allowFloat ? '字串、數值或 null' : ($allowInt ? '字串、整數或 null' : '字串或 null'))];
            } elseif ($allowInt && !$allowFloat
                && is_string($value) && $value !== ''
                && self::looksNumeric($value, false)
                && self::overflowsPhpInt($value)
            ) {
                $errors[$field] = [$field . ' 整數值超出可表示範圍'];
            } elseif ($allowInt && !$allowFloat
                && ($value !== null && $value !== '')
                && isset($integerRanges[strtolower($field)])
                && self::looksNumeric(is_string($value) ? $value : (string) $value, false)
                && ((int) $value < $integerRanges[strtolower($field)][0] || (int) $value > $integerRanges[strtolower($field)][1])
            ) {
                // 超出欄位型別值域。語法檢查放行、資料庫在非 strict sql_mode 下**靜默截斷**
                // （smallint 收到 40000 存成 32767），回 200 但資料錯了，而且錯成一個看起來
                // 很正常的年份。截斷是 warning 不是 exception，兜底的例外分類抓不到。
                [$min, $max] = $integerRanges[strtolower($field)];
                $errors[$field] = [$field . ' 必須在 ' . $min . ' 與 ' . $max . ' 之間'];
            } elseif (($allowInt || $allowFloat) && is_string($value) && !self::looksNumeric($value, $allowFloat)) {
                // 數值欄收到非數值字串（`c_firstyear: "not-a-year"`、`x_coord: "east"`、
                // `c_source: "12foo"`）。不擋的話後果分兩種、都很糟：本專案的
                // config/database.php 設 `strict => false`，MariaDB 會把它**靜默轉成 0**
                // ——新地名的年份／座標變成 0 而不是報錯，事後看不出是壞資料；
                // 而在 strict 的部署上則是 1366／1264，一路冒成 500。
                $errors[$field] = [$field . ($allowFloat ? ' 必須為數值' : ' 必須為整數')];
            } elseif (is_string($value)
                && !in_array($field, $longTextFields, true)
                && mb_strlen($value) > 255
            ) {
                $errors[$field] = [$field . ' 長度不可超過 255 字元'];
            }
        }

        return $errors;
    }

    /**
     * 數值欄可接受的字串形式。刻意收窄成「整數」與「十進位小數」，不用 `is_numeric()`：
     * 後者接受 `0x1A`、`1e5`、前導空白等寫法，落到 smallint／double 上的結果因資料庫
     * 版本與 sql_mode 而異，不是我們想放進資料的東西。
     */
    private static function looksNumeric(string $value, bool $allowFloat): bool {
        if ($value === '') {
            // 空字串在數值欄的語義是「清空」。NOT NULL 的數值欄在上面已被擋下；
            // 可為 null 的則由 normalize() 轉成 null（不轉的話 MariaDB 會存成 0）。
            return true;
        }

        return preg_match($allowFloat ? '/\A-?\d+(\.\d+)?\z/' : '/\A-?\d+\z/', $value) === 1;
    }

    /**
     * 整數字串是否超出 PHP int 的精度。
     *
     * 為什麼獨立成一條：值域檢查是用 `(int)` 轉型後比較的，而 `(int) '99999999999999999999'`
     * 在 64-bit PHP 上會飽和成 PHP_INT_MAX——正好等於某些型別的上限，於是「超大值」
     * 反而比較成「剛好在範圍內」而放行。bigint 欄更是沒有值域可比（見 trait 的
     * INTEGER_TYPE_RANGES 註解），只剩這條擋得住。往返比對是最直接的判斷：
     * 真的能用 int 表示的字串，轉回來一定一模一樣。
     */
    private static function overflowsPhpInt(string $value): bool {
        $normalized = ltrim($value, '+');
        // 去掉多餘前導零（'007' 與 '7' 都是合法輸入，往返比對前先歸一）。
        if (preg_match('/\A(-?)0+(\d)/', $normalized) === 1) {
            $normalized = preg_replace('/\A(-?)0+(?=\d)/', '$1', $normalized);
        }

        return (string) (int) $normalized !== $normalized;
    }
}
