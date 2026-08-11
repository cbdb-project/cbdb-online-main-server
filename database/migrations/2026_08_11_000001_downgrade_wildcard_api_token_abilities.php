<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 把既有 token 的通配能力 `*` 降級為登記過的最小能力。
 *
 * `ApiTokenController::store()` 的預設值原本是 `['*']`，而兩個建立介面都不送 abilities，
 * 所以線上每一個 token 都是通配。改掉預設值只影響新 token，既有的仍然「自動擁有將來新增
 * 的每一種能力」——包含入侵期間可能留下的那些。
 *
 * 今天降級不會弄壞任何東西：全站只有 EnsureMcpAbility 會檢查 abilities（要求 mcp:read），
 * `/api/user` 與 `api/v2/*` 根本不看 abilities，所以把 `*` 換成 mcp:read 之後，
 * 現有客戶端的行為完全不變。
 *
 * ⚠️ 未來注意：線上在用的 token 有幾個名稱明顯是 REST/v2 客戶端（CBDBAPI、Python Scripts…）
 * 而非 MCP 客戶端。今天把它們標成 mcp:read 是行為中性的，但**等到 api/v2/* 也開始檢查
 * abilities 的那一天，這些 token 會因為只有 MCP 唯讀而失效**，屆時需要為它們定義並補上
 * 對應能力（而不是以為 mcp:read 就夠）。
 */
return new class () extends Migration {
    /**
     * 刻意寫死而不是引用 App\Support\ApiTokenAbilities。
     *
     * migration 應該是凍結的快照：若輸出取決於當下的 config／應用類別，`migrate:fresh`
     * 會在不同環境寫出不同資料，而且那個類別日後被改名或刪掉就會讓全新安裝 migrate 失敗。
     */
    private const ALLOWED = ['mcp:read'];

    public function up(): void {
        if (!Schema::hasTable('personal_access_tokens')) {
            return;
        }

        // 判準是「缺少任何一個合法能力」而不是「含有 `*`」：
        //  - 可重跑（當成修復工具用）；
        //  - 同時修好 abilities 為 NULL／不可解碼的孤兒列——那種列會讓 Sanctum 的 can()
        //    直接拋 TypeError（in_array 收到 null），只判 `*` 的話永遠修不到。
        // chunkById 而非 chunk：offset 分頁在有人同時刪 token 時會位移而靜默跳列，
        // 且日後若有人加上 where 條件優化，offset 版本會立刻出現經典的跳列 bug。
        DB::table('personal_access_tokens')
            ->select('id', 'abilities')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $abilities = json_decode((string) $row->abilities, true);

                    if (!is_array($abilities)) {
                        // NULL／壞 JSON：無從保留，補上最小能力讓它至少可用且不會讓 can() 拋錯。
                        $this->rewrite($row->id, self::ALLOWED);

                        continue;
                    }

                    // 抽掉 `*` 與任何未登記的字串，保留原有的合法能力。
                    $kept = array_values(array_unique(array_filter(
                        $abilities,
                        fn ($ability) => in_array($ability, self::ALLOWED, true)
                    )));

                    if ($kept === $abilities) {
                        // 已經只帶合法能力且無重複 → 不動（重跑時走這條，保證冪等）。
                        continue;
                    }

                    // 刻意**不** merge ALLOWED：若日後 ALLOWED 長成多個能力，原本刻意只帶
                    // 其中一項的 token 會被這個 merge 擴權成全部。只有「一個合法能力都不剩」
                    // 時才補最小能力，否則保留它自己原有的那組。
                    $this->rewrite($row->id, $kept === [] ? self::ALLOWED : $kept);
                }
            });
    }

    private function rewrite(int|string $id, array $abilities): void {
        DB::table('personal_access_tokens')
            ->where('id', $id)
            ->update(['abilities' => json_encode(array_values($abilities))]);
    }

    public function down(): void {
        // 不可逆：把 `*` 放回去等於把「自動獲得未來所有能力」這個問題放回去。
        // 原本哪些 token 是通配已無從得知（也不需要知道）。故意留空。
    }
};
