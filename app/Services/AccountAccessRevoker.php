<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountAccessRevoker {
    public function __construct(private SecurityAuditLogger $securityAudit) {
    }

    /**
     * 撤銷帳號的所有 Sanctum API token，並把撤銷事實寫進 audit_log。
     *
     * 為什麼要有這一步：`is_active` 複查（見 App\Http\Middleware\Authenticate）只是擋下請求，
     * 資料庫裡那些 token 列還是有效憑證。只要哪天複查被繞過、或帳號被重新啟用，這些
     * 舊 token 就直接復活。所以「停用」動作本身必須連帶把憑證真的刪掉。
     *
     * @param  string  $context  觸發來源（例如 'management_ui'、'cli'），寫進 audit 供追溯
     * @return int 實際刪除的 token 筆數
     */
    public function revokeApiTokens(User $user, string $context): int {
        // 讀清單與刪除放在同一把 users 列鎖下，與 ApiTokenController::store() 序列化：
        // 沒有這把鎖時兩者可能交錯成「這裡讀到舊清單 → 對方寫入新 token → 撤銷完成但仍留
        // 一個有效憑證」。刻意只鎖住「鎖＋讀＋刪」，審計留在交易外——審計失敗不得回退撤銷。
        $tokens = DB::transaction(function () use ($user) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

            $rows = $user->tokens()->get(['id', 'name', 'abilities', 'last_used_at']);
            if ($rows->isNotEmpty()) {
                $user->tokens()->delete();
            }

            return $rows;
        });

        if ($tokens->isEmpty()) {
            return 0;
        }

        // DELETE 的語義：被銷毀的狀態記在 old_data。連 reason／context 一起放，
        // 日後查「這批 token 是誰在什麼情境下撤掉的」才有線索。
        //
        // 走 SecurityAuditLogger 而不是自己組請求脈絡：它已經處理好 CLI／HTTP 的差異
        // （CLI 下 ip/UA 寫 null 而不是 Laravel 造的假 127.0.0.1 / Symfony）、actor 型別，
        // 以及「審計失敗絕不讓撤銷失敗」——token 已經刪掉了，此時拋例外只會讓管理員看到
        // 500、誤以為停用沒生效而重試。
        $this->securityAudit->record(
            table: 'personal_access_tokens',
            operation: 'DELETE',
            rowPk: ['tokenable_id' => $user->id],
            event: 'api_tokens_revoked_on_deactivation',
            before: [
                'context' => $context,
                'reason' => 'account_deactivated',
                'tokens' => $tokens->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'last_used_at' => optional($token->last_used_at)->toIso8601String(),
                ])->all(),
            ],
            // 呼叫端已在 $context 標明來源（'cli' / 'management_ui'…），據此決定要不要記 IP／UA：
            // CLI 情境的 ip/UA 是 Laravel 造的假值，寫進審計會誤導調查。
            channel: str_starts_with($context, 'cli')
                ? SecurityAuditLogger::CHANNEL_CLI
                : SecurityAuditLogger::CHANNEL_HTTP
        );

        return $tokens->count();
    }
}
