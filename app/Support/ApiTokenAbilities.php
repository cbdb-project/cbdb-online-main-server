<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * API token 能力（Sanctum abilities）的單一權威來源。
 *
 * 背景：`ApiTokenController::store()` 原本的預設值是 `['*']`——Sanctum 的通配能力，
 * `$token->can(任何字串)` 都會通過。目前全站只有一處消費 abilities
 * （EnsureMcpAbility，要求 `mcp:read`），所以通配值今天沒有多給任何權限；但它的意思是
 * 「這個 token 自動擁有將來新增的每一種能力」——只要有人日後加一個 ability-gated 的
 * 寫入端點，所有既存 token 就立刻獲得授權。這是上了膛的槍，先卸彈。
 */
class ApiTokenAbilities {
    /** Sanctum 的通配能力值；本專案一律不接受。 */
    public const WILDCARD = '*';

    /**
     * 允許簽發的能力，寫成字面值清單。
     *
     * 刻意**不**從 `config('mcp.cbdb.required_ability')` 推導。曾經那樣寫，有兩個真實後果：
     *
     *  1. `MCP_REQUIRED_ABILITY=*` 會讓 allowed() 回傳 `['*']`，於是驗證放行通配、
     *     降級 migration 也把 `*` 原封不動寫回去——一個環境變數就能無聲地把整個 P1-4 還原，
     *     而程式碼給出的 422 訊息還在說「通配能力已停用」。
     *  2. 把 env 改成別的字串（例如 mcp:readonly）會讓所有既存 token 立刻失去 MCP 權限，
     *     而且使用者連「重新簽一個能用的」都做不到，因為 allowed() 已經不含舊字串——
     *     只能手動改 SQL 救。在 token 還是 `['*']` 的年代改這個 env 是無害的，收斂之後不是。
     *
     * 新增能力時在這裡登記；**淘汰的名稱也要留著**，否則帶舊名稱的既存 token 會變成
     * 無法修復的孤兒。
     *
     * @return list<string>
     */
    public const ISSUABLE = [
        'mcp:read',
    ];

    /**
     * @return list<string>
     */
    public static function allowed(): array {
        // 防禦性過濾：就算有人把 WILDCARD 加進 ISSUABLE，也不會真的簽出通配 token。
        return array_values(array_filter(
            self::ISSUABLE,
            fn ($ability) => !self::isWildcard($ability)
        ));
    }

    /**
     * 未指定 abilities 時的預設值。
     *
     * 兩個建立 token 的介面（React TokenManager 與舊 Blade profile 頁）都不送 abilities，
     * 所以這個預設值就是實務上絕大多數 token 拿到的能力。取「能用的最小權限」＝MCP 唯讀，
     * 而不是空陣列：空陣列會讓從 UI 建的 token 連唯一的既有用途（MCP）都用不了。
     *
     * @return list<string>
     */
    public static function default(): array {
        return self::allowed();
    }

    public static function isWildcard(mixed $ability): bool {
        return is_string($ability) && trim($ability) === self::WILDCARD;
    }

    /**
     * 已回報過的設定漂移值，避免每次建立 token 都寫一筆 log 把檔案洗爆。
     *
     * @var array<string, true>
     */
    private static array $reportedDrift = [];

    /**
     * MCP 要求的能力是否真的簽得出來。
     *
     * 上面刻意與 config 解耦，代價是兩者可能漂移：若營運把 `MCP_REQUIRED_ABILITY` 改成
     * ISSUABLE 之外的字串（或清空），任何新簽的 token 都進不了 MCP，而且畫面上不會有任何跡象。
     * 這是設定錯誤而不是使用者錯誤，所以在這裡記一筆讓它可被發現。
     *
     * 刻意**不** fail closed（不拒絕簽發）：`mcp:read` 對 `/api/user` 與 `api/v2/*` 一樣有用
     * （那些端點根本不檢查 abilities），線上多數 token 其實是 REST 客戶端。因為 MCP 的設定
     * 打錯就讓整個 token 簽發停擺，是自找的服務中斷，比「簽出一個進不了 MCP 但仍可用的 token」
     * 更糟。
     */
    public static function assertMcpAbilityIsIssuable(): void {
        $required = trim((string) config('mcp.cbdb.required_ability', 'mcp:read'));

        if ($required !== '' && in_array($required, self::allowed(), true)) {
            return;
        }

        // 每個 process 對同一個漂移值只記一次。
        $key = $required === '' ? '<empty>' : $required;
        if (isset(self::$reportedDrift[$key])) {
            return;
        }
        self::$reportedDrift[$key] = true;

        Log::error(
            'MCP_REQUIRED_ABILITY 設為不可簽發的能力，任何新 token 都無法通過 MCP 准入。',
            [
                'required_ability' => $key,
                'issuable' => self::allowed(),
                'hint' => '請把該值加進 App\Support\ApiTokenAbilities::ISSUABLE，或改回可簽發的能力。',
            ]
        );
    }

    /** 測試用：清掉「只記一次」的去重狀態。 */
    public static function forgetReportedDrift(): void {
        self::$reportedDrift = [];
    }
}
