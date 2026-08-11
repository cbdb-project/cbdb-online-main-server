<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class AccountAccessRevoker {
    public function __construct(private AuditLogService $auditLog) {
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
        $tokens = $user->tokens()->get(['id', 'name', 'abilities', 'last_used_at']);

        if ($tokens->isEmpty()) {
            return 0;
        }

        $user->tokens()->delete();

        // DELETE 的語義：被銷毀的狀態記在 old_data，new_data 為 null。連 reason／context
        // 一起放進 old_data，日後查「這批 token 是誰在什麼情境下撤掉的」才有線索。
        //
        // 審計失敗絕不可讓撤銷失敗：token 已經刪掉了，此時拋例外只會讓管理員看到 500、
        // 誤以為停用沒生效而重試（與 ManagementController::auditUserChange 同一取捨——
        // DB trigger 才是權威 tripwire，這裡是帶操作者的補充佐證）。
        try {
            $this->auditLog->write(
                table: 'personal_access_tokens',
                operation: 'DELETE',
                rowPk: ['tokenable_id' => $user->id],
                oldData: [
                    'context' => $context,
                    'reason' => 'account_deactivated',
                    'tokens' => $tokens->map(fn ($token) => [
                        'id' => $token->id,
                        'name' => $token->name,
                        'abilities' => $token->abilities,
                        'last_used_at' => optional($token->last_used_at)->toIso8601String(),
                    ])->all(),
                ],
                newData: null
            );
        } catch (\Throwable $e) {
            Log::warning('API token 撤銷的審計寫入失敗（撤銷本身已完成）: '.$e->getMessage());
        }

        return $tokens->count();
    }
}
