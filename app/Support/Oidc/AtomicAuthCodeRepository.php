<?php

namespace App\Support\Oidc;

use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;

/**
 * 授權碼只能被兌換一次，而且「檢查是否已用過」與「標記為已用」是**同一個原子操作**。
 *
 * league/oauth2-server 的順序是：validateAuthorizationCode() 先呼叫 isAuthCodeRevoked() 檢查，簽發 token 之後
 * 才呼叫 revokeAuthCode()。兩個並發請求可以都在任何一方撤銷之前讀到「尚未撤銷」，於是同一張授權碼換出兩組 token。
 *
 * 這裡把 isAuthCodeRevoked() 改成「消耗」：一條 `UPDATE ... SET revoked = 1 WHERE id = ? AND revoked = 0`，
 * 影響 0 列就代表「已被用過（或不存在）」。資料庫保證同一列的條件式 UPDATE 只有一個請求會得到 1 列，
 * 其餘都拿到 0 列而被拒絕。之後 league 呼叫的 revokeAuthCode() 只是冪等的重複標記。
 *
 * 取捨：消耗發生在 PKCE 驗證**之前**（isAuthCodeRevoked 在 validateAuthorizationCode 內，code_verifier 驗證在其後），
 * 所以一次失敗的兌換（例如 code_verifier 不對）也會燒掉這張碼——使用者重新登入即可。這比「失敗不燒碼」嚴格，
 * 而且符合 RFC 6749 §4.1.2「授權碼被重複使用時伺服器應撤銷由它簽發的 token」的精神：持碼者沒有驗證器就不該有第二次機會。
 * 代價是持有碼的攻擊者可以燒掉別人的碼（拒絕服務一次登入），但他得先拿到碼。
 */
class AtomicAuthCodeRepository extends AuthCodeRepository {
    public function isAuthCodeRevoked(string $codeId): bool {
        $consumed = Passport::authCode()->newQuery()
            ->whereKey($codeId)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        return $consumed === 0;
    }
}
