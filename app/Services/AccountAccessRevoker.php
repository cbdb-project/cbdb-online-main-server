<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    /**
     * 撤銷帳號的所有 OIDC（Passport）憑證：access token、授權碼，以及（若存在）refresh token。
     *
     * 與 revokeApiTokens() 是同一個道理：`is_active` 複查只是擋下請求，資料庫裡的憑證列還是有效的。
     * Sanctum 與 Passport 是兩套互不相干的表，停用帳號只撤銷前者的話，已簽出的 OIDC access token 會活到自己過期，
     * 尚未兌換的授權碼也還能換出新 token（兌換時的 isActive() 檢查是縱深防禦，不是唯一防線）。
     *
     * **撤銷而非刪除**：把 `revoked` 設為 true、保留列，供事後追溯；過期與已撤銷的列由 `passport:purge` 清理。
     *
     * 鎖 `users` 列後才讀寫，與 App\Support\Oidc\ActiveUserAccessTokenRepository::persistNewAccessToken() 用**同一把鎖**：
     * 簽發端在交易內「檢查 is_active → 落庫」，這裡的撤銷必須等它結束才看得到那張新 token，
     * 兩種先後順序都不會留下沒人撤銷的 token。
     *
     * 表不存在時為 no-op（兼容未建這些表的環境與測試）。
     *
     * @param  string  $context  觸發來源（'management_ui'、'cli'），寫進 audit 供追溯
     * @return int 實際被撤銷的列數（access token＋授權碼＋refresh token）
     */
    public function revokeOidcTokens(User $user, string $context): int {
        foreach (['oauth_access_tokens', 'oauth_auth_codes', 'oauth_refresh_tokens'] as $table) {
            if (!Schema::hasTable($table)) {
                return 0;
            }
        }

        $summary = DB::transaction(function () use ($user): array {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

            // refresh token 沒有 user_id，只能經由 access_token_id 找到。先取清單再更新（不論 access token 本身
            // 是否已被撤銷，其 refresh token 都要撤）。分批以避開 SQLite 的繫結變數上限。
            $accessIds = DB::table('oauth_access_tokens')->where('user_id', $user->id)->pluck('id')->all();

            $refresh = 0;
            foreach (array_chunk($accessIds, 500) as $chunk) {
                $refresh += DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $chunk)->where('revoked', false)->update(['revoked' => true]);
            }

            return [
                'access_tokens' => DB::table('oauth_access_tokens')->where('user_id', $user->id)->where('revoked', false)->update(['revoked' => true]),
                'auth_codes' => DB::table('oauth_auth_codes')->where('user_id', $user->id)->where('revoked', false)->update(['revoked' => true]),
                'refresh_tokens' => $refresh,
            ];
        });

        $total = array_sum($summary);

        if ($total === 0) {
            return 0;
        }

        // 審計失敗絕不讓撤銷失敗（見 SecurityAuditLogger），所以放在交易外。
        $this->securityAudit->record(
            table: 'oauth_access_tokens',
            operation: 'UPDATE',
            rowPk: ['user_id' => $user->id],
            event: 'oidc_tokens_revoked_on_deactivation',
            before: ['context' => $context, 'reason' => 'account_deactivated'],
            after: $summary,
            channel: str_starts_with($context, 'cli')
                ? SecurityAuditLogger::CHANNEL_CLI
                : SecurityAuditLogger::CHANNEL_HTTP
        );

        return $total;
    }
}
