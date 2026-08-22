<?php

namespace App\Services;

use App\Exceptions\VariantMappingException;
use App\Support\UnicodeNfc;
use App\Support\VariantReplaceScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 異體字落地替換服務
 *
 * 查詢 char_variant_map 表，把文字裡的異體字替換成參考字。與 VariantCharNormalizer
 * 的差異：VariantCharNormalizer 只影響拼音查詢、不改動原始資料；本服務會實際改寫
 * 呼叫端傳入的文字（呼叫端自行決定要不要把回傳的 text 存進資料庫）。
 *
 * 寬鬆模式（replaceLenient）：表裡任何一筆對照都套用，忽略 c_strict_excluded。
 * 嚴格模式（replaceStrict）：只套用 c_strict_excluded = 0 的記錄，用於 BIOG_MAIN／
 * ALTNAME_DATA 等人名相關欄位。
 */
class CharVariantMapService {
    /**
     * 寬鬆模式對照表快取（異體字 => 參考字）。
     *
     * @var array<string,string>|null
     */
    protected static ?array $lenientMap = null;

    /**
     * 嚴格模式對照表快取（異體字 => 參考字，僅 c_strict_excluded = 0）。
     *
     * @var array<string,string>|null
     */
    protected static ?array $strictMap = null;

    /**
     * 對照表是否缺表（null = 尚未判定）。
     *
     * 缺表是**確定性**條件，所以可以在 process 內快取；reset() 會清掉。
     * 不快取的代價是逐欄放大：replaceRow() 對整列每個欄位都會走一次 map 載入，
     * 缺表時就變成「每欄一次 metadata 查詢 + 每欄一行 warning」——一次 20 欄的儲存
     * ＝20 次查詢，而 S3 接上批次匯入後會變成「列數 × 欄數」。
     *
     * 這與「不快取瞬時錯誤」的決定不衝突：瞬時錯誤現在一律往上拋（見 loadEdges()），
     * 所以根本沒有「把失敗結果快取住」的路徑。
     */
    protected static ?bool $tableMissing = null;

    /**
     * 寬鬆模式：對整段文字做落地替換，表裡任何一筆都套用（忽略 c_strict_excluded）。
     *
     * @return array{text: string, replaced: array<string,string>}
     */
    public static function replaceLenient(string $text): array {
        return self::replaceUsing($text, self::lenientMap());
    }

    /**
     * 嚴格模式：對整段文字做落地替換，只套用 c_strict_excluded = 0 的記錄。
     *
     * @return array{text: string, replaced: array<string,string>}
     */
    public static function replaceStrict(string $text): array {
        return self::replaceUsing($text, self::strictMap());
    }

    /**
     * 對整列資料套落地替換：逐欄查 VariantReplaceScope::modeFor() 決定模式，
     * 非文本欄／排除欄／未知表原樣保留。
     *
     * **只做淺層掃描**：非字串值（int／null／**陣列**）原樣跳過。這是刻意的——
     * POSTED_TO_ADDR_DATA 的 resource_data['rows'] 與 PostingMutationHandler 的
     * __proposal_aux／ADDRESS_AUX_KEYS 是騎在 $changes 裡的嵌套結構／非欄位鍵，
     * 不該被當成欄位處理。
     *
     * **呼叫約定**：$table 一律傳**目標資料表**，永不傳 'operations'——
     * operations.resource_id 是序列化的複合主鍵、內含中文 PK 成員，
     * 改寫它會讓提案與目標列脫鉤。
     *
     * @param array<string,mixed> $data
     * @return array{data: array<string,mixed>, replaced: array<string,string|array<int,string>>}
     *         replaced 的值通常是字串；當同一個變體在這一列被解析成**不同**的參考字
     *         （strict 欄與 lenient 欄的閉包終點不同）時會是陣列，兩個結果都保留。
     */
    public static function replaceRow(array $data, string $table): array {
        $replaced = [];

        foreach ($data as $column => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            $result = self::replaceFor($table, (string) $column, $value);

            // 以**文字是否改變**判斷要不要寫回，不能用 `replaced === []` 當條件：
            // NFC 正規化（相容表意文字折疊）刻意不記進 replaced，用後者當閘門會把
            // 已正規化的值整個丟掉。replaced 仍只累積**異體字**替換，供通知使用。
            if ($result['text'] !== $value) {
                $data[$column] = $result['text'];
            }
            if ($result['replaced'] !== []) {
                $replaced = self::mergeReplaced($replaced, $result['replaced']);
            }
        }

        return ['data' => $data, 'replaced' => $replaced];
    }

    /**
     * 合併兩份 replaced，**保留衝突的兩個參考字**。
     *
     * 為什麼不能用 `+=` 或 `array_merge`：同一個變體在同一次操作裡可以被解析成不同的
     * 參考字——strict 與 lenient 的閉包終點可以不同（`龴→峯`(excluded=0) +
     * `峯→峰`(excluded=1)：strict 得「峯」、lenient 得「峰」）。`+=` 保留先出現者、
     * `array_merge` 保留後出現者，兩者都會靜默丟掉一個，讓**通知與實際落庫的字形不一致**
     * ——而通知是使用者唯一能看見替換發生的管道。
     *
     * 衝突時值升級成 list；沒有衝突時維持純字串（絕大多數情況）。
     *
     * @param array<string,string|array<int,string>> $base
     * @param array<string,string|array<int,string>> $incoming
     * @return array<string,string|array<int,string>>
     */
    public static function mergeReplaced(array $base, array $incoming): array {
        foreach ($incoming as $variant => $reference) {
            foreach (is_array($reference) ? $reference : [$reference] as $one) {
                if (!array_key_exists($variant, $base)) {
                    $base[$variant] = $one;

                    continue;
                }

                $existing = is_array($base[$variant]) ? $base[$variant] : [$base[$variant]];
                if (!in_array($one, $existing, true)) {
                    $existing[] = $one;
                    $base[$variant] = $existing;
                }
            }
        }

        return $base;
    }

    /**
     * 把 replaced 攤平成 `[['from' => 變體, 'to' => 參考], …]`。
     *
     * 給需要**結構化 payload**（而非通知字串）的呼叫端用：那些地方不能收到
     * `string|array` 聯集，否則 JSON 化之後前端契約會壞
     * （例如批次匯入結果頁的 variant_replacements）。
     *
     * @param array<string,string|array<int,string>> $replaced
     * @return array<int,array{from: string, to: string}>
     */
    public static function flattenReplaced(array $replaced): array {
        $pairs = [];
        foreach ($replaced as $variant => $reference) {
            foreach (is_array($reference) ? $reference : [$reference] as $one) {
                $pairs[] = ['from' => (string) $variant, 'to' => (string) $one];
            }
        }

        return $pairs;
    }

    /**
     * 單值替換，模式仍由 VariantReplaceScope::modeFor() 決定。
     *
     * 很多掛鉤點手上**不是「以欄位名為鍵的整列」**：OfficeImportService 在
     * buildPinyin() 之前拿到的 $input 鍵是 name／name_alt／notes（欄位名只在
     * officeColumns() 的 return 才出現）、SocialInstituteImportService::resolveNameCode()
     * 拿到的是裸字串。對那些位置呼叫 replaceRow() 會靜默 no-op，必須用這個入口。
     *
     * @return array{text: string, replaced: array<string,string>}
     */
    public static function replaceFor(string $table, string $column, string $value): array {
        $mode = VariantReplaceScope::modeFor($table, $column);

        if ($mode === 'strict') {
            return self::replaceStrict($value);
        }

        if ($mode === 'lenient') {
            return self::replaceLenient($value);
        }

        return ['text' => $value, 'replaced' => []];
    }

    /**
     * char_variant_map 專用的結構驗證：單一 codepoint、不製造環。
     *
     * 為什麼不用 Eloquent observer：app/Models/ 下沒有 char_variant_map 的 model，
     * 它的每一條寫入都是 DB::table()，observer 攔不到；要攔就得把刻意 table-agnostic
     * 的泛用 CRUD（Codes UI 服務 80 張表）為這一張表特例化。所以改成共用 guard，
     * 由各寫入端呼叫。
     *
     * @param array<string,mixed> $row 可能是部分 payload（restoreUpdate 用歷史快照），
     *                                 會與現有列 merge 後再驗
     * @param int|null $excludeId 更新／還原既有列時要排除該列的舊邊，否則會誤報環：
     *                            表有 id=5 `乙→甲`、id=9 `甲→丙`，把 id=5 改成 `丙→乙`
     *                            是合法的 `甲→丙→乙`，但把舊邊算進去會看到 `乙→甲→丙→乙`
     *
     * @throws VariantMappingException 驗證不通過
     */
    public static function assertWritable(array $row, ?int $excludeId = null): void {
        $current = [];
        if ($excludeId !== null) {
            $existing = DB::table('char_variant_map')->where('id', $excludeId)->first();
            if ($existing !== null) {
                $current = [
                    'c_variant_char' => $existing->c_variant_char,
                    'c_reference_char' => $existing->c_reference_char,
                ];
            }
        }

        $variant = (string) ($row['c_variant_char'] ?? $current['c_variant_char'] ?? '');
        $reference = (string) ($row['c_reference_char'] ?? $current['c_reference_char'] ?? '');

        // 兩欄都沒被碰到（例如只改 c_notes）：不需驗證。
        if (!array_key_exists('c_variant_char', $row) && !array_key_exists('c_reference_char', $row)) {
            return;
        }

        // 只送了單邊字元欄、又沒有既有列可以 merge：這是呼叫端沒傳 id 的問題，
        // 報「必須是單一字元」會誤導（使用者根本沒動另一欄）。
        if ($variant === '' || $reference === '') {
            throw new VariantMappingException(__('variant.incomplete_payload'));
        }

        if (mb_strlen($variant) !== 1 || mb_strlen($reference) !== 1) {
            throw new VariantMappingException(__('variant.single_codepoint_required'));
        }

        if ($variant === $reference) {
            throw new VariantMappingException(__('variant.self_reference_not_allowed'));
        }

        // 把待寫入的邊放進現有邊集（排除被取代的舊邊），看會不會成環。
        $edges = DB::table('char_variant_map')
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->pluck('c_reference_char', 'c_variant_char')
            ->all();
        $edges[$variant] = $reference;

        $node = $variant;
        $seen = [];
        while (isset($edges[$node]) && !isset($seen[$node])) {
            $seen[$node] = true;
            $node = $edges[$node];
        }
        if (isset($edges[$node]) && isset($seen[$node])) {
            throw new VariantMappingException(__('variant.cycle_not_allowed', ['char' => $node]));
        }
    }

    /**
     * 清除靜態快取（測試用，比照 VariantCharNormalizer::reset() 慣例）。
     */
    public static function reset(): void {
        self::$lenientMap = null;
        self::$strictMap = null;
        self::$tableMissing = null;
    }

    /**
     * 把 replaceLenient()/replaceStrict() 回傳的 replaced 組成非阻塞通知文字（見
     * docs/CHAR_VARIANT_MAP_CALL_SITE_WIRING_PLAN.md 待決事項 5）。多個呼叫端
     * （BIOG_MAIN create／update、ALTNAME_DATA create／update 等）共用同一份措辭，
     * 避免各自組字造成用語不一致。
     *
     * @param array<string,string|array<int,string>> $replaced 異體字 => 參考字
     *        （同一變體在同一列被解析成多個參考字時，值會是陣列——見 replaceRow()）
     * @return array<int,string> 空陣列代表無需通知
     */
    public static function buildNotices(array $replaced): array {
        if ($replaced === []) {
            return [];
        }

        $pairs = [];
        foreach ($replaced as $variant => $reference) {
            // 同一個變體可能在同一列裡被解析成不同的參考字——strict 與 lenient 的閉包
            // 終點可以不同（`龴→峯`(excluded=0) + `峯→峰`(excluded=1)：strict 得「峯」、
            // lenient 得「峰」）。replaceRow() 遇到這種衝突會把值升級成陣列，
            // 這裡兩種形狀都要能渲染，否則通知會告訴使用者錯誤的字。
            $references = is_array($reference) ? $reference : [$reference];
            foreach ($references as $one) {
                $pairs[] = __('variant.notice_pair', ['variant' => $variant, 'reference' => (string) $one]);
            }
        }

        return [__('variant.notice', ['pairs' => implode(__('variant.notice_separator'), $pairs)])];
    }

    /**
     * 把 buildNotices() 的結果併入一個已組好的 JsonResponse（頂層新增可選的
     * notices 欄位）。給那些回應結構固定在共用抽象類別裡、子類只能事後
     * decode／重新包裝的呼叫端使用（例如 AltnameCreateHandler／
     * AltnameMutationHandler 繼承 AbstractPersonSubresource*Handler 的
     * handleDirect()／handleProposal()，回應在父類別內組好，子類無法在建構時
     * 插入額外欄位，只能對已完成的 JsonResponse 再加工）。
     *
     * 注意：這裡是重新組一個全新的 JsonResponse，不是複製／修改原始 `$response`，
     * 所以不會帶到原始回應上可能設定的自訂 header／cookie。目前所有呼叫端
     * （AbstractPersonSubresourceCreateHandler／AbstractPersonSubresourceMutationHandler
     * 的 handleDirect()／handleProposal()）都只用 response()->json([...]) 組出單純的
     * JSON 回應，沒有額外 header／cookie，故現階段無影響；若未來這些父類別新增了
     * header／cookie，需要重新評估這裡是否要改用 `$response->setData($data)` 保留原始
     * response 物件（含 header）而非重建。
     *
     * @param array<string,string|array<int,string>> $replaced 形狀同 buildNotices()
     */
    public static function withNotices(JsonResponse $response, array $replaced): JsonResponse {
        $notices = self::buildNotices($replaced);
        if ($notices === []) {
            return $response;
        }

        $data = $response->getData(true);
        $data['notices'] = $notices;

        return response()->json($data, $response->getStatusCode());
    }

    /**
     * @param array<string,string> $map
     * @return array{text: string, replaced: array<string,string>}
     */
    protected static function replaceUsing(string $text, array $map): array {
        // Unicode NFC：把相容表意文字折疊成統一表意文字（慎 U+FA87 → 慎 U+614E）。
        //
        // **必須在查對照表之前**：對照表的鍵全是統一表意文字，未正規化的相容碼位一個都
        // 對不上，會原樣落庫（而它在資料庫層是不同位元組 ⇒ 唯一鍵擋不住、搜尋互不可見）。
        //
        // **也必須在 empty($map) 早退之前**：NFC 與對照表無關，缺表／空表時照樣要做。
        //
        // 這不是「改寫使用者錄入的字」——canonical equivalence 下兩個碼位是同一個字，
        // 與異體字替換的性質不同，見 UnicodeNfc 類註的對照表。因此**不記進 $replaced**：
        // 通知欄會顯示成「慎 → 慎」兩個一模一樣的字形，對使用者只是雜訊。
        $text = UnicodeNfc::normalize($text);

        if ($text === '' || empty($map)) {
            return ['text' => $text, 'replaced' => []];
        }

        $replaced = [];
        foreach ($map as $variant => $reference) {
            // c_variant_char 只在 DB 層是 NOT NULL，不保證非空字串；空字串會讓
            // str_contains() 恆為 true、且讓 strtr() 對空字串 key 發出 PHP 警告。
            if ($variant !== '' && str_contains($text, $variant)) {
                $replaced[$variant] = $reference;
            }
        }

        if (empty($replaced)) {
            return ['text' => $text, 'replaced' => []];
        }

        return ['text' => strtr($text, $replaced), 'replaced' => $replaced];
    }

    /**
     * @return array<string,string>
     */
    protected static function lenientMap(): array {
        if (self::$lenientMap === null) {
            $edges = self::loadEdges(null);
            if ($edges === null) {
                return [];
            }
            self::$lenientMap = self::resolveMap($edges, 'lenient');
        }

        return self::$lenientMap;
    }

    /**
     * @return array<string,string>
     */
    protected static function strictMap(): array {
        if (self::$strictMap === null) {
            $edges = self::loadEdges(0);
            if ($edges === null) {
                return [];
            }
            self::$strictMap = self::resolveMap($edges, 'strict');
        }

        return self::$strictMap;
    }

    /**
     * 讀對照表的邊集。
     *
     * **只在「表不存在」時降級為不替換**（回 null，呼叫端不快取這個結果）。
     * 這一步從 S2 起變成必要：落地替換現在掛在 Codes UI 全部 5 條寫入路徑上，若在
     * 尚未 migrate／部分遷移的環境因為缺表而拋錯，**整個代碼表的寫入功能都會 500**
     * ——為了一個正規化的加值功能讓核心錄入功能停擺是不對的取捨。
     *
     * **其餘錯誤一律往上拋**（連線問題、欄位被改名、權限…）。早期版本對所有 Throwable
     * 都降級並把空 map 快取起來，後果是「一次瞬時錯誤就讓這個 worker 之後所有寫入都
     * 不再替換」，而且只留下一行 warning——那正是本階段最想避免的靜默失效。
     *
     * @param int|null $strictExcluded null = 不過濾（lenient）；0 = 只取可用於人名的（strict）
     * @return array<string,string>|null null = 表不存在，本次不替換（且不要快取）
     */
    protected static function loadEdges(?int $strictExcluded): ?array {
        if (self::$tableMissing === null) {
            self::$tableMissing = !Schema::hasTable('char_variant_map');
            if (self::$tableMissing) {
                Log::warning('char_variant_map 不存在，本次不做落地替換');
            }
        }

        if (self::$tableMissing) {
            return null;
        }

        $query = DB::table('char_variant_map');
        if ($strictExcluded !== null) {
            $query->where('c_strict_excluded', $strictExcluded);
        }

        return $query->pluck('c_reference_char', 'c_variant_char')->all();
    }

    /**
     * 把一份**已按模式過濾**的邊集，解析成可安全重複套用的對照表。
     *
     * 順序必須是「移除環上出邊 → 對剩餘無環圖算傳遞閉包」，不可顛倒——先算閉包會在
     * 環上無限迴圈（`A→B`、`B→A` 或自環 `A→A` 在環移除前閉包沒有定義）。
     *
     * 為什麼要傳遞閉包：`strtr()` 單次呼叫是同時替換，但**呼叫兩次不等於呼叫一次**。
     * 若表裡同時有 `A→B` 與 `B→C`，兩次套用得到 `C`、一次只得到 `B`，機制就不幂等。
     * 做完閉包後所有 key 都映射到終端節點，而終端節點沒有出邊故不是 key ⇒
     * key 集 ∩ value 集 = ∅ ⇒ 重複套用是不動點。
     *
     * 為什麼**按模式各自算**：兩張 map 必須各自對已過濾的邊集獨立計算。若先對全表算閉包
     * 再按 flag 過濾，`X→峯`(excluded=1 的 峯→峰) 會讓 strict map 得到 `X→峰`，等於透過
     * 傳遞把一條 strict-excluded 的邊套進人名欄，廢掉 c_strict_excluded 的唯一用途。
     * （今天的實作在 SQL 層就過濾，天然滿足；這裡的註解是擋住「共用 loader」那個重構。）
     *
     * @param array<string,string> $edges 異體字 => 參考字（已按模式過濾）
     * @return array<string,string>
     */
    protected static function resolveMap(array $edges, string $mode): array {
        // 只保留單一 codepoint 的邊：幂等論證只在單字元 key 下成立（多字元下
        // `甲乙→丙丁` + `丁→戊` 的閉包接不起來，第二趟會再改一次）。寫入端有 guard，
        // 這裡是對既有／繞過 guard 的資料做防禦。
        $clean = [];
        foreach ($edges as $variant => $reference) {
            $variant = (string) $variant;
            $reference = (string) $reference;
            if ($variant === '' || mb_strlen($variant) !== 1 || mb_strlen($reference) !== 1) {
                Log::error('char_variant_map: 略過非單一 codepoint 的對照', [
                    'mode' => $mode,
                    'variant' => $variant,
                    'reference' => $reference,
                ]);

                continue;
            }
            $clean[$variant] = $reference;
        }

        $clean = self::dropCycleEdges($clean, $mode);

        // 傳遞閉包：逐 key 走鏈到終端節點。此時圖已無環，走鏈必然終止。
        $resolved = [];
        foreach ($clean as $variant => $reference) {
            $target = $reference;
            while (isset($clean[$target])) {
                $target = $clean[$target];
            }
            if ($target !== $variant) {
                $resolved[$variant] = $target;
            }
        }

        return $resolved;
    }

    /**
     * 移除**構成環的邊**，其餘照常生效。
     *
     * 不拋錯也不回空 map：這兩個 map 方法是所有替換的唯一入口，在此 throw 會讓
     * Codes UI、所有 v2 mutate、批次匯入、眾包核准、提案核准一起爆（一筆 `峰→峯`
     * 或打錯字的 `A→A` 就夠）；回空 map 則等於全站靜默不替換。降級要局部且有聲音。
     *
     * `c_variant_char` 有唯一鍵 ⇒ 圖是 out-degree ≤ 1 的 functional graph，
     * 「環上節點」＝「從自己出發能走回自己」，逐 key 走鏈 + visited set 即可精確定位。
     * **「鏈進入環」（`A→B`、`B→C`、`C→B`）只丟環上節點（B、C）的出邊，`A→B` 要保留**。
     *
     * @param array<string,string> $edges
     * @return array<string,string>
     */
    protected static function dropCycleEdges(array $edges, string $mode): array {
        $onCycle = [];

        foreach (array_keys($edges) as $start) {
            $seen = [];
            $node = $start;
            while (isset($edges[$node]) && !isset($seen[$node])) {
                $seen[$node] = true;
                $node = $edges[$node];
            }
            // 走鏈停在一個已造訪過的節點 ⇒ 該節點在環上；沿環標記整圈。
            if (isset($edges[$node]) && isset($seen[$node])) {
                $cursor = $node;
                do {
                    $onCycle[$cursor] = true;
                    $cursor = $edges[$cursor];
                } while ($cursor !== $node);
            }
        }

        if ($onCycle === []) {
            return $edges;
        }

        Log::error('char_variant_map: 偵測到環，已丟棄環上的對照', [
            'mode' => $mode,
            'chars' => array_keys($onCycle),
        ]);

        return array_diff_key($edges, $onCycle);
    }
}
