<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
     * 清除靜態快取（測試用，比照 VariantCharNormalizer::reset() 慣例）。
     */
    public static function reset(): void {
        self::$lenientMap = null;
        self::$strictMap = null;
    }

    /**
     * 把 replaceLenient()/replaceStrict() 回傳的 replaced 組成非阻塞通知文字（見
     * docs/CHAR_VARIANT_MAP_CALL_SITE_WIRING_PLAN.md 待決事項 5）。多個呼叫端
     * （BIOG_MAIN create／update、ALTNAME_DATA create／update 等）共用同一份措辭，
     * 避免各自組字造成用語不一致。
     *
     * @param array<string,string> $replaced 異體字 => 參考字
     * @return array<int,string> 空陣列代表無需通知
     */
    public static function buildNotices(array $replaced): array {
        if ($replaced === []) {
            return [];
        }

        $pairs = [];
        foreach ($replaced as $variant => $reference) {
            $pairs[] = "「{$variant}」已正規化為「{$reference}」";
        }

        return ['異體字：'.implode('、', $pairs)];
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
     * @param array<string,string> $replaced
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
            self::$lenientMap = DB::table('char_variant_map')
                ->pluck('c_reference_char', 'c_variant_char')
                ->all();
        }

        return self::$lenientMap;
    }

    /**
     * @return array<string,string>
     */
    protected static function strictMap(): array {
        if (self::$strictMap === null) {
            self::$strictMap = DB::table('char_variant_map')
                ->where('c_strict_excluded', 0)
                ->pluck('c_reference_char', 'c_variant_char')
                ->all();
        }

        return self::$strictMap;
    }
}
