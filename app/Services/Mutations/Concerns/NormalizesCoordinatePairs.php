<?php

namespace App\Services\Mutations\Concerns;

use App\Support\CoordinatePairNormalizer;
use Illuminate\Http\JsonResponse;

/**
 * v2 mutation handler 的經緯度「空白／零值 → NULL」掛鉤。
 *
 * 形狀刻意比照 {@see AppliesVariantReplacement}（累積器 + reset + withNotices），
 * 但**掛鉤點不同**，而且差異是有理由的：
 *
 * - 落地替換必須早於 PK 計算與查重，因為有些表的文本欄是主鍵成員。座標永遠不是主鍵、
 *   不是查重鍵、不是拼音來源，所以那一組約束在這裡是空的。
 * - 取而代之的硬約束是**必須早於變更偵測**。`AbstractCodeTableMutationHandler` 用
 *   `($originalArray[$field] ?? null) !== $value` 判斷有沒有實質改動；若正規化跑在它
 *   之後，送 `{x_coord: 0}` 給一列 `x_coord` 本來就是 NULL 的資料會被判成「有改動」，
 *   於是寫一次 `NULL` 覆蓋 `NULL`（什麼都沒變），同時：
 *     1. 把 `c_modified_by`／`c_modified_date` 蓋成呼叫端與此刻，抹掉 AGENTS §1.2
 *        定義的「最後一次實際寫入」這個事實；
 *     2. 寫一筆 `resource_data` 與 `resource_original` 位元組相同的 `operations`；
 *     3. 寫一筆 before／after 相同的 `audit_log`。
 *   掛在偵測之前，同一個請求會正確地回 `422 no_effective_changes`。
 * - 也早於 `CodeTableFieldValidator::normalize()`（那一步會把 `''` 轉成 `null`）。
 *   **但要說清楚這條在 HTTP 上其實拿不到好處**：全域 middleware `ConvertEmptyStringsToNull`
 *   （`app/Http/Kernel.php`）連 JSON body 一起處理，所以 `"y_coord": ""` 早在任何 handler
 *   看到它之前就已經是 `null` 了——`REASON_BLANK` 經由 v2 API 永遠不會觸發。這個順序的實際
 *   價值有兩個：(a) 對**非 HTTP 呼叫端**（artisan、內部 service 直呼 handler）保留縱深，
 *   那些路徑沒有 middleware；(b) 補寫的伙伴欄會跟其他值一樣經過 `validateFields()`，
 *   而不是被偷渡過去。
 *
 *   順帶記一件不影響正確性的事實：使用者「填了經度、緯度留空」時，緯度以 `null` 抵達、
 *   走 `$wasNull` 分支而不進 `cleared`，但經度會拿到 `REASON_PARTNER`——所以「你的經度被
 *   一併清空了」這句該說的話**仍然說得出來**，只是措辭走 partner 那一條而非 blank。
 *
 * 白名單有一個 fail-open 要注意：`$allowed` 的收窄發生在掛鉤**之前**，所以本 trait
 * 補寫的伙伴欄不會再經過白名單檢查。`CoordinatePairNormalizer::PAIRS` 與各表的
 * `allowed_fields` 是兩份獨立清單，會漂移——`tests/Unit/CoordinatePairRegistryGuardTest.php`
 * 就是為此存在的機械把關。
 */
trait NormalizesCoordinatePairs {
    /**
     * 本次請求被清空的座標欄。
     *
     * @var array<string, array{reason: string, submitted: bool}>
     */
    protected array $coordinateCleared = [];

    /**
     * 對整列套用座標正規化，並把結果**併入**（而非覆寫）累積器。
     *
     * 併入而非 assign 的理由與 `AppliesVariantReplacement::applyVariantReplacement()`
     * 相同：子類若在別處再補一次呼叫並 assign，會把上游收集到的通知靜默吃掉。
     *
     * @param array<string,mixed> $data
     * @param string|null $table 目標資料表；省略時取 `$this->tableName()`。
     *                           **沒有 `tableName()` 的 handler 必須顯式傳表名**
     *                           （`CodeTableCreateHandler` 的表由請求決定，就是這種）。
     * @return array<string,mixed>
     */
    protected function applyCoordinateNormalization(array $data, ?string $table = null): array {
        $result = CoordinatePairNormalizer::normalizeRow($data, $table ?? $this->tableName());

        foreach ($result['cleared'] as $column => $reason) {
            $this->coordinateCleared[(string) $column] = [
                'reason' => $reason,
                // 這一欄是呼叫端自己送來的，還是本類為了維持成對而補寫的？
                // 判斷依據必須是**正規化之前**的 $data：`cleared` 的鍵在「送來了」的情形
                // 用送來的拼法、在「補寫」的情形用登記的拼法，所以這個 array_key_exists
                // 分得開兩者。dropNoOpCoordinateNotices() 靠它避免謊報損失。
                'submitted' => array_key_exists($column, $data),
            ];
        }

        return $result['data'];
    }

    /**
     * 重置累積器。
     *
     * handler 由容器解析，同一個 process／請求內可能被重複使用（批次多筆 mutate），
     * 不重置會把上一筆的通知帶到下一筆的回應上。
     */
    protected function resetCoordinateCleared(): void {
        $this->coordinateCleared = [];
    }

    /**
     * 濾掉「什麼都沒發生」的通知。
     *
     * 只有一種情形該濾：該欄**沒有被送來**（是本類補寫的）**而且**原值本來就是 NULL。
     * 那時補寫 NULL 是個 no-op，通知只會讓使用者困惑——尤其在掛鉤早於變更偵測之後，
     * 送 `{x_coord: 0}` 給一列座標本來就是 NULL 的資料會回 `422 no_effective_changes`：
     * 一個什麼都沒寫的回應，卻附著一句「另一軸也被清空了」。
     *
     * 反過來，**送來了**的欄一律保留通知，即使原值是 NULL——那正是「使用者填了
     * `x=105.3`、因為 `y` 留空而被整對丟棄」的情形，是唯一真的丟掉使用者輸入的場景，
     * 絕不能因為「原值是 NULL 所以沒有改動」就吞掉。
     *
     * create 端傳空陣列即可：沒有原始列，就沒有任何既存值會被丟掉。
     *
     * @param array<string,mixed> $originalRow 寫入前的那一列（欄名大小寫不限）
     */
    protected function dropNoOpCoordinateNotices(array $originalRow): void {
        if ($this->coordinateCleared === []) {
            return;
        }

        $originalByLower = [];
        foreach ($originalRow as $key => $value) {
            $originalByLower[strtolower((string) $key)] = $value;
        }

        foreach ($this->coordinateCleared as $column => $info) {
            if ($info['submitted'] || $info['reason'] !== CoordinatePairNormalizer::REASON_PARTNER) {
                continue;
            }
            if (($originalByLower[strtolower($column)] ?? null) === null) {
                unset($this->coordinateCleared[$column]);
            }
        }
    }

    /**
     * 把 `notices` 掛到回應上（沒有清空時原樣回傳）。
     *
     * **成功、409、422 都要掛**——被擋下來時使用者更需要知道「我的座標被改成 NULL 了」。
     * 這條與 §1.3 對異體字通知的要求同源。
     */
    protected function withCoordinateNotices(JsonResponse $response): JsonResponse {
        $notices = CoordinatePairNormalizer::buildNotices($this->coordinateClearedReasons());
        if ($notices === []) {
            return $response;
        }

        $data = $response->getData(true);
        // 與異體字通知共用同一個頂層欄位：一個請求可能同時觸發兩者，分成兩個欄位會讓
        // 呼叫端得知道去看幾個地方。合併時保留既有項目。
        $data['notices'] = array_merge($data['notices'] ?? [], $notices);

        return response()->json($data, $response->getStatusCode());
    }

    /**
     * 累積器攤平成 `buildNotices()` 要的「欄名 → 原因」。
     *
     * @return array<string,string>
     */
    protected function coordinateClearedReasons(): array {
        return array_map(static fn (array $info): string => $info['reason'], $this->coordinateCleared);
    }
}
