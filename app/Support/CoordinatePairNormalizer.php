<?php

namespace App\Support;

/**
 * 經緯度「空白／零值 → NULL」的落庫前正規化。
 *
 * ## 為什麼需要這個
 *
 * `0,0` 不是東亞的任何地點，而是「沒有座標」被錯記成了一個看起來合法的數字。系統自己
 * 早就這麼認定：{@see CoordinateValidator} 把零軸與非數值都判為不可連結
 * （`zero_axis`／`non_numeric`）。但**判定端認定無效，寫入端卻照樣收**——於是 `ADDR_CODES`
 * 長年帶著 316 列 `0,0`（2026-09-14 已全數清理／回填）。
 *
 * 兩邊對「零」的判準**刻意不同，而且本類更窄**：`CoordinateValidator` 用
 * `|v| < config('chgis_map.epsilon')`（讀取端的容差，可由 `CHGIS_MAP_EPSILON` 調），本類
 * 用精確的 `=== 0.0`（見 `isZero()`）。關係是**包含**：本類清空的集合 ⊂ validator 判為無效的
 * 集合。所以「讓兩邊共用同一個 epsilon 以保持一致」是個**會造成資料遺失的錯誤修法**——
 * 那會讓一個地圖顯示參數變成破壞性寫入的開關。真正的一致性由
 * `CoordinatePairNormalizerTest` 的包含關係斷言保證，不是由共用旋鈕保證。
 *
 * 零值不只是「顯示不出來」，它會**主動製造錯誤答案**：舊版 v1 的鄰近地點查詢是
 * `ADDR_CODES.x_coord BETWEEN other.x_coord ± 0.03` 的自連接，所有 `0,0` 列因此互為
 * 「鄰居」（實測 316 列產生 316² ＝ 99,856 對）。`NULL` 會自然從這種比較中掉出去，
 * 這正是這一欄要表達的語義。
 *
 * ## 現行寫入端原本的缺口
 *
 * 三條路各自只擋掉一半：
 *  - 資料庫欄位是 `double DEFAULT NULL`，**沒有** `DEFAULT 0`——預設值不是來源。
 *  - 表單留白（Blade 與 React 的 `/app/codes` 共用 `CodesController::performUpdate`）
 *    經全域 middleware `ConvertEmptyStringsToNull` 變成 `null`，安全。
 *  - JSON 送 `""` 由 {@see CodeTableFieldValidator::normalize()} 轉 `null`，安全。
 *
 * 漏掉的是**顯式的零**：`0`、`"0"`、`"0.0"`、`"0.00000"` 在每一條路徑上都是合法浮點值，
 * 一路落庫成 `0`。而空字串之所以安全只是因為上游剛好有 middleware／validator 接住——
 * 任何繞過它們直接 `DB::table()->update()` 的寫入路徑，`''` 會被 MariaDB 在非 strict
 * sql_mode（本專案 `config/database.php` 設 `strict => false`，實測 session sql_mode 為
 * `NO_ENGINE_SUBSTITUTION`）下**靜默轉成 0**。實測：`''` → `0.0`、`'0.00000'` → `0.0`、
 * `null` → `NULL`。所以這一層必須自己認得空白，不能假設上游已經處理過。
 *
 * ## 語義：座標是一對，不是兩個獨立的欄
 *
 * 只有一軸有值的座標是不可用的（`CoordinateValidator` 同樣判 `zero_axis`／
 * `non_numeric`），所以本類**以「對」為單位**處理：一對之中只要有任一軸是空白或零，
 * 整對都寫成 `NULL`——包含另一軸，即使呼叫端這次沒有送它。
 *
 * 這是刻意的，代價也講清楚：使用者若打了 `x=105.3` 卻把 `y` 留空，那個 `105.3` 會被丟掉。
 * 但保留它只會存出一列 `105.3, NULL`（或更糟的 `105.3, 0`），對每一個消費端都同樣不可用。
 * 所以寧可原子化清空，並且**務必把 `cleared` 回報給使用者**——系統改了輸入就要讓人看見。
 * 為此 `cleared` 的原因是**逐欄**的（見 `REASON_*`）：告訴使用者「你留空的是緯度，被丟掉的
 * 是你打的經度」，而不是含混地說兩欄都「空白」。
 *
 * **這條規則只在「清空」方向成立，不要當成一個全域不變式。** 送
 * `changes: {x_coord: 113.1}` 給一列 `y_coord` 為 NULL 的資料，本類什麼也不會做
 * （送來的值是有效非零數），結果仍是半截的 `113.1, NULL`。要堵住那一側得在寫入端讀出
 * 現況並回 422，那是 handler 的事、不是這一層能決定的——這一層看不到資料庫。
 *
 * ## 邊界
 *
 * - `$data` 裡完全沒出現這一對的任何欄位時，不做任何事。逐欄更新（代碼表 update 是
 *   per-field）不該因為沒提到座標就把座標動掉。
 * - 非空白、非數值的字串（`"east"`、`"0e0"`）**原樣留下**，不清空也不猜。那是
 *   {@see CodeTableFieldValidator::validate()} 該回 422 的事；在這裡先清成 `NULL`
 *   會把一個該報錯的請求變成「靜默存成沒有座標」。同理，一對之中只要有這種值，
 *   整對都不動——否則 `x=0, y="east"` 會變成 200 而不是 422。
 *   **注意這個「交給驗證層」的前提只在 v2 API 上成立**：`CodesController` 與提案核准重放
 *   都沒有欄位驗證層（`CodeTableFieldValidator` 沒有任何 controller 引用它），
 *   `x_coord=0e0` 會被非 strict sql_mode 的 MariaDB 轉成 `0` 而重新造出這個類要防的那
 *   一列。那兩條路因此自己呼叫 {@see self::invalidColumns()}——表單回欄位錯誤、核准與
 *   還原中止。**新開的寫入路徑若沒有驗證層，必須比照辦理。**
 * - 未登記的資料表一律不處理（fail-closed，與 {@see VariantReplaceScope} 同樣的取向）。
 *   **新增任何帶經緯度的資料表時，要同步加進 `PAIRS`。**
 */
final class CoordinatePairNormalizer {
    /**
     * 帶經緯度的資料表 → 其座標欄位對（經度欄, 緯度欄）。
     *
     * 全庫只有這兩張表有 `x_coord`／`y_coord`（`PLACE_CODES` 已由
     * `2025_11_17_100000_drop_place_codes_table.php` 移除）。
     *
     * `ADDRESSES` 是 `cbdb:regenerate-addresses-table` 由 `ADDR_CODES` 以
     * `INSERT ... SELECT ac.x_coord, ac.y_coord` 重建的派生快取，那條 raw SQL 路徑沒有
     * PHP 陣列、本類掛不上去，也不需要——源頭乾淨，派生物就乾淨。
     *
     * 登記它是因為它**另有一條活的互動式寫入端，而那條路已經掛上本類**：`ADDRESSES` 在
     * `config/codes.php` 的清單裡、不在 `CodesController::$readOnlyTables`，而且它沒有真正
     * 的主鍵（原始 schema 只有一個非唯一 `KEY`），於是 `getKeyColumns()` 掉到「取前兩個
     * 物理欄」的 fallback，實測回 `['c_addr_id', 'c_addr_cbd']`——只要表單把這兩欄填了，
     * `POST /codes/ADDRESSES` 就會真的 insert，而它走的是已掛鉤的 `performStore()`。
     * 另一條生效路徑是 `ADDRESSES` 的 create 提案核准重放
     *（`OperationsProposalController::applyCreateProposal()`）。
     *（更新那一側到不了：`RegenerateAddresses` 對每一列都寫 `NULL AS c_addr_cbd`，
     * `where c_addr_cbd = <值>` 永遠命不中，「找不到目標列」守衛會先擋下。）
     *
     * 要留意這層保護是**短暫的**：下一次 `RegenerateAddresses` 會 truncate 整張表，手動
     * 插進去的列本來就會消失。所以這是便宜、fail-closed 的順手防護，不是承重結構。
     *
     * 這與 {@see VariantReplaceScope} 把 `ADDRESSES` 列為排除（「派生表：內容由源頭重建，
     * 改派生物只會與源頭不一致」）並不矛盾，兩者性質不同：異體字替換是**編輯性的內容改寫**
     *（對派生表做沒有意義），座標歸零是**完整性守衛**（任何寫得進去的地方都該守）。house
     * 本來就這樣分——`restoreUpdate()` 不做內容替換，卻仍然跑
     * `assertCharVariantMapWritable()` 與樹成環守衛。
     *
     * @var array<string, array<int, array{0: string, 1: string}>>
     */
    private const PAIRS = [
        'ADDR_CODES' => [['x_coord', 'y_coord']],
        'ADDRESSES' => [['x_coord', 'y_coord']],
    ];

    /** 清空原因：使用者把這一欄留空（null／空字串／全空白字串）。 */
    public const REASON_BLANK = 'blank';

    /** 清空原因：這一欄是數值零（0、"0"、"0.00000"、-0…）。 */
    public const REASON_ZERO = 'zero';

    /**
     * 清空原因：這一欄本身是有效的非零座標，但因為同一對的另一軸空白／為零而一併丟棄。
     *
     * 這個原因**必須**能傳到使用者眼前：它是唯一一個「系統丟掉了你真的打進去的值」的情形。
     * 沒有送來、由本類補寫的伙伴欄也用這個原因——本類看不到資料庫，無法判斷它原本是不是
     * 已經是 NULL，而在「可能沒變」與「可能靜默刪掉一個真值」之間，寧可多講一句。
     *
     * **代價與呼叫端的義務**：正因為本類看不到資料庫，這個原因在一種情形下會宣告一個
     * 沒發生的損失——v2 逐欄更新送 `{x_coord: 0}` 給一列 `y_coord` 本來就是 NULL 的資料時，
     * `cleared` 會含 `y_coord => cleared_with_partner`，但其實什麼都沒丟。**看得到原始列的
     * 呼叫端（handler 手上有 `$originalArray`）在組通知時應該把「原值已是 NULL」的
     * `REASON_PARTNER` 項目濾掉。** 過濾放在呼叫端而不是這裡，是因為只有呼叫端知道現況。
     */
    public const REASON_PARTNER = 'cleared_with_partner';

    /**
     * 某資料表登記的座標欄位對。未登記的表回空陣列。
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function pairsFor(?string $table): array {
        if ($table === null || $table === '') {
            return [];
        }

        return self::PAIRS[strtoupper($table)] ?? [];
    }

    /** 該資料表是否有登記的座標欄位對。 */
    public static function handles(?string $table): bool {
        return self::pairsFor($table) !== [];
    }

    /**
     * 所有登記了座標欄位對的資料表（大寫）。
     *
     * 存在的理由是機械把關：`CoordinatePairRegistryGuardTest` 拿它去比對各表的
     * `allowed_fields`。`PAIRS` 與那些白名單是兩份獨立清單，而補寫伙伴欄的動作發生在
     * 白名單收窄**之後**，所以漂移不會有任何人發出聲音。
     *
     * @return array<int, string>
     */
    public static function registeredTables(): array {
        return array_keys(self::PAIRS);
    }

    /**
     * 對一列待寫入的資料套用正規化。
     *
     * @param array<string,mixed> $data 待寫入的欄位值（欄名大小寫不限）
     * @param string|null $table 目標資料表
     * @return array{data: array<string,mixed>, cleared: array<string,string>}
     *         `data` 是正規化後的列；`cleared` 是「欄名 → `REASON_*`」，空陣列代表沒有改動。
     *         `cleared` 的欄名用 `$data` 裡原本出現的拼法（同一欄有多種拼法時全部列出），
     *         沒出現過的補寫欄位用 `PAIRS` 登記的拼法。
     */
    public static function normalizeRow(array $data, ?string $table, bool $dataIsCompleteRow = false): array {
        $pairs = self::pairsFor($table);
        if ($pairs === []) {
            return ['data' => $data, 'cleared' => []];
        }

        // 欄名大小寫不敏感的索引：小寫欄名 → $data 裡**所有**符合的鍵。
        //
        // 一定要收全部而不是只留最後一個：MySQL 欄名大小寫不敏感，而
        // `CodesController::extractFormData()` 是 `$request->all()` 去掉三個鍵、**沒有**
        // 欄位白名單，所以 `x_coord=0&X_COORD=113.5` 這種請求兩個鍵都會進 SET 子句。
        // 只檢查其中一個，另一個就帶著零值原樣落庫——這個類的目的等於被繞過。
        $keysByLower = [];
        foreach (array_keys($data) as $key) {
            $keysByLower[strtolower((string) $key)][] = $key;
        }

        $cleared = [];
        foreach ($pairs as $pair) {
            /** @var array<string, array<int, string|int>> $present column => 實際出現的鍵 */
            $present = [];
            foreach ($pair as $column) {
                $keys = $keysByLower[strtolower($column)] ?? [];
                if ($keys !== []) {
                    $present[$column] = $keys;
                }
            }
            if ($present === []) {
                continue;  // 這次完全沒碰這一對，不要動它
            }

            // 先掃一遍：有任何「非空白但也不是數值」的值就整對不動，讓驗證層回 422。
            foreach ($present as $keys) {
                foreach ($keys as $key) {
                    if (!self::isBlank($data[$key]) && !self::isNumeric($data[$key])) {
                        continue 3;
                    }
                }
            }
            // **必須排在上面那道「非數值就整對不動」的預掃之後。** 先跑整列模式的話,
            // `POST /api/v2/create {x_coord: "east"}` 會被靜默清成 NULL 而不是讓
            // validator 回 422——正是本類註解明文禁止的形狀（把該報錯的請求變成
            // 「存成沒有座標」）。這個順序是自己的單元測試抓出來的。
            // 整列模式（`create`）：缺席的那一軸**必然**落庫成 NULL，所以「送了經度、
            // 沒送緯度」在這裡就已經是一個半截座標，而不是「等資料庫裡另一半」。
            // 這是 update 與 create 的真實差異：逐欄 update 看不到資料庫、無從判斷，
            // 但 create 手上的 $row 就是要 insert 的完整列。所以只有 create 能、也應該
            // 在這裡就把半截對清掉——否則 `POST /api/v2/create {x_coord: 105.36}` 會存出
            // `105.36, NULL`，正是本類宣告「對每一個消費端都同樣不可用」的那個形狀。
            if ($dataIsCompleteRow && count($present) < count($pair)) {
                foreach ($pair as $column) {
                    $keys = $present[$column] ?? [$column];
                    foreach ($keys as $key) {
                        $submitted = array_key_exists($key, $data);
                        $wasNull = $submitted && $data[$key] === null;
                        $data[$key] = null;
                        if (!$wasNull) {
                            $cleared[(string) $key] = self::REASON_PARTNER;
                        }
                    }
                }

                continue;
            }

            // 逐欄判定：只要有任何一欄是空白或零，整對都要清空。
            $columnReasons = [];
            $triggered = false;
            foreach ($present as $keys) {
                foreach ($keys as $key) {
                    $reason = self::isBlank($data[$key])
                        ? self::REASON_BLANK
                        : (self::isZero($data[$key]) ? self::REASON_ZERO : null);
                    if ($reason !== null) {
                        $triggered = true;
                        $columnReasons[(string) $key] = $reason;
                    }
                }
            }
            if (!$triggered) {
                continue;  // 這一對每一欄都是有效的非零數值
            }

            foreach ($pair as $column) {
                $keys = $present[$column] ?? [$column];
                foreach ($keys as $key) {
                    // 已經是 null 的**送來的**欄位不記進 cleared：我們確知它沒有改動。
                    // 反之，沒有送來、由這裡補寫的伙伴欄一律記上 REASON_PARTNER——
                    // 本類看不到資料庫，不能假設它原本就是 NULL。
                    $submitted = array_key_exists($key, $data);
                    $wasNull = $submitted && $data[$key] === null;
                    $data[$key] = null;
                    if ($wasNull) {
                        continue;
                    }
                    $cleared[(string) $key] = $columnReasons[(string) $key] ?? self::REASON_PARTNER;
                }
            }
        }

        return ['data' => $data, 'cleared' => $cleared];
    }

    /**
     * 找出「不可能是座標」的值——`normalizeRow()` 刻意放過、但也不該落庫的那些。
     *
     * ## 為什麼需要這個方法
     *
     * `normalizeRow()` 對非空白、非數值的值（`"east"`、`"0e0"`、`"+40.5"`、`INF`）**整對不動**，
     * 前提是「下游的 {@see CodeTableFieldValidator} 會回 422」。那個前提在 v2 API 上成立，
     * 但**不是每條寫入路徑都有驗證層**：
     *
     *  - 提案核准重放（`OperationsProposalController::apply*Proposal()`）直接 insert／update，
     *    從不呼叫 `CodeTableFieldValidator`。實測一筆帶 `x_coord: "0e0"` 的歷史提案被核准後，
     *    MariaDB 在非 strict sql_mode 下把它**靜默轉成 `0`**——正好重新造出這整套機制要防的那一列。
     *  - `CodesController` 的表單路徑同樣從未引用過 `CodeTableFieldValidator`。
     *
     * 所以這些路徑必須自己檢查。刻意**只檢查座標欄**而不是套用整個
     * `CodeTableFieldValidator`：那份驗證的型別清單是照各表的 v2 `allowed_fields` 寫的，
     * 而表單路徑會把整列所有欄位一起送回來——例如 `long_text_fields` 只為三張表登記過，
     * 於是 `TEXT_CODES.c_notes`（longtext）會撞上 255 字元上限，讓使用者改一個無關欄位就被
     * 422 擋下。窄範圍的檢查沒有那個爆炸半徑。
     *
     * 判定沿用本類的 `isNumeric()`／`isBlank()`，所以「什麼算合法座標」在歸一與檢查兩邊
     * **由構造保證一致**，不會漂移成兩套規則。
     *
     * 注意這裡**不**判斷值域（界內／界外）：那是 {@see CoordinateValidator} 在讀取端的職責。
     * 這裡只回答「這個值根本不是一個數」。
     *
     * @param array<string,mixed> $data
     * @return array<string,string> 欄名（`$data` 裡的拼法）→ 'non_numeric'；空陣列代表通過
     */
    public static function invalidColumns(array $data, ?string $table): array {
        $pairs = self::pairsFor($table);
        if ($pairs === []) {
            return [];
        }

        $columns = [];
        foreach ($pairs as $pair) {
            foreach ($pair as $column) {
                $columns[strtolower($column)] = true;
            }
        }

        $invalid = [];
        foreach ($data as $key => $value) {
            if (!isset($columns[strtolower((string) $key)])) {
                continue;
            }
            if (!self::isBlank($value) && !self::isNumeric($value)) {
                $invalid[(string) $key] = 'non_numeric';
            }
        }

        return $invalid;
    }

    /**
     * 把 `normalizeRow()` 的 `cleared` 渲染成給使用者看的通知字串。
     *
     * 形狀與 {@see \App\Services\CharVariantMapService::buildNotices()} 一致（回一個
     * 字串陣列），好讓 v2 回應的 `notices` 欄位與 Codes UI 的 flash 兩邊都能直接用。
     *
     * **措辭的硬性要求**：`REASON_PARTNER` 涵蓋兩種情形——使用者真的填了一個有效值卻
     * 被丟棄、以及那一欄根本沒送而由系統補寫 NULL。呼叫端應該先用
     * `NormalizesCoordinatePairs::dropNoOpCoordinateNotices()` 濾掉後者裡「原值本來就是
     * NULL」的項目（那是真正什麼都沒發生的情形）；剩下的都是實質改動，所以文案可以直說
     * 「已一併存為 NULL」，但**不可以**斷言「你填的值被丟棄」——過濾之後仍可能是
     * 「原本有值、這次被清掉」而非「你剛才填的被丟掉」。
     *
     * @param array<string,string> $cleared `normalizeRow()` 回的 `cleared`（欄名 → REASON_*）
     * @return array<int,string>
     */
    public static function buildNotices(array $cleared): array {
        if ($cleared === []) {
            return [];
        }

        $items = [];
        foreach ($cleared as $column => $reason) {
            $key = match ($reason) {
                self::REASON_BLANK => 'coordinate.cleared_blank',
                self::REASON_ZERO => 'coordinate.cleared_zero',
                default => 'coordinate.cleared_with_partner',
            };
            $items[] = __($key, ['column' => (string) $column]);
        }

        return [__('coordinate.notice', ['items' => implode(__('coordinate.notice_separator'), $items)])];
    }

    /** @param mixed $value */
    private static function isBlank($value): bool {
        if ($value === null) {
            return true;
        }

        return is_string($value) && trim($value) === '';
    }

    /**
     * 可視為數值的值。
     *
     * 字串的規則與 {@see CodeTableFieldValidator} 的 `looksNumeric()` **逐字一致**
     * （`/\A-?\d+(\.\d+)?\z/`，不接受前導 `+`、不 trim）。這件事很重要而且曾經寫錯：
     * 本類放寬到接受 `+40.5` 時，`{"x_coord":"0","y_coord":"+40.5"}` 會被判成「一對數值、
     * 其中一個是零」而整對清成 NULL 回 200——但 validator 本來要對 `+40.5` 回
     * 422「必須為數值」。放寬這裡就等於把該報錯的請求變成靜默成功。
     * 空白（含全空白字串）由 `isBlank()` 先接手，所以不 trim 不會誤判留空。
     *
     * 非有限浮點（`INF`／`NAN`，`json_decode('{"x":1e999}')` 就會產生）視為非數值，
     * 整對不動、交給驗證層拒絕（`CodeTableFieldValidator` 的「必須為有限數值」那一條就是
     * 為了接住這裡刻意放過的東西而補的）。
     *
     * 布林不是數值：`true` 不是座標。這裡沒有 `is_bool()` 的早退，因為布林過不了下面
     * 任何一個型別分支、自然落到 `return false`——刻意不加那個早退是為了不留死碼。
     *
     * @param mixed $value
     */
    private static function isNumeric($value): bool {
        if (is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if (!is_string($value)) {
            return false;
        }

        return preg_match('/\A-?\d+(\.\d+)?\z/', $value) === 1;
    }

    /**
     * 是否為數值零。
     *
     * **刻意用精確比較，而且刻意不讀 `config('chgis_map.epsilon')`。** 讀那個 config 的話，
     * 一個本意是調整地圖顯示／連結判定的部署設定（`CHGIS_MAP_EPSILON`）就會變成破壞性寫入的
     * 開關：設成 1 會讓每次保存都靜默刪掉任何 `|lon| < 1` 或 `|lat| < 1` 的座標。
     * {@see VariantReplaceScope} 為同樣的理由把排除清單寫成常數而非 config。
     *
     * 破壞性的規則要取「覆蓋得到目標情形的最窄定義」：本類要處理的 `0`、`0.0`、`"0"`、
     * `"0.00000"`、`-0` 轉成 double 之後**全都精確等於 0.0**，所以精確比較就夠了。
     * 極小但非零的值（`1e-9`）不在這條規則內——它一樣連不上地圖，但那是
     * `CoordinateValidator` 的「界外／零軸」判定該說的話，不是這一層該刪掉的資料。
     *
     * 一個順帶的後果，刻意留著：**下溢到零的值會被清空**（浮點字面值 `1e-400`，或
     * `'0.' . str_repeat('0', 400) . '1'` 這種字串，`(float)` 之後就是 `0.0`）。這是對的
     * ——資料庫真正要存進 double 的那個值就是精確的 0，正是本類要防的那一列；判定「是不是
     * 零」要看**落庫後的值**，不是看使用者打了幾個字。所以這不是漏網，而是同一條規則。
     *
     * @see CoordinatePairNormalizerTest::testTheZeroSubsetIsNarrowerThanTheValidatorsZeroAxis()
     *
     * @param mixed $value
     */
    private static function isZero($value): bool {
        return (float) $value === 0.0;
    }
}
