<?php

namespace App\Support\Oidc;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Bridge\AccessTokenRepository;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * 簽發 access token 之前先確認帳號仍是啟用的，而且**檢查與落庫在同一個交易、鎖住同一列 users**。
 *
 * 為什麼是這裡而不是只在 UserIdentityRepository：
 *  1. 那邊只在簽發 id_token 時被呼叫，而套件在 token 沒有 `openid` scope 時整段跳過；這裡不論 scope 都會跑。
 *     （實測過：沒有這一層時，`scope=profile` 的流程在帳號停用後仍拿到 access token。）
 *  2. 那邊是在 token **已經寫進資料庫之後**才拒絕，失敗的兌換會留下沒被撤銷的 token 列。這裡在落庫**之前**
 *     拒絕，不會留下任何記錄。
 *
 * 為什麼要鎖 users 列：停用帳號（ManagementController／cbdb:manage-user）是「先改 is_active、再撤銷 token」。
 * 若簽發端讀到 is_active=1 之後、落庫之前，管理員剛好停用並跑完撤銷，這張新 token 就沒人會撤它。
 * 簽發端在交易內鎖住 users 列，停用的 UPDATE 就必須等簽發交易結束，之後的撤銷一定看得到這張 token；
 * 反過來停用先提交，簽發端讀到的就是 is_active=0 而被拒絕。兩種順序都不會漏。
 * 與 AccountAccessRevoker::revokeOidcTokens() 用同一把鎖。（SQLite 的 lockForUpdate 是 no-op，測試只能驗證語意。）
 */
class ActiveUserAccessTokenRepository extends AccessTokenRepository {
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void {
        $userId = $accessTokenEntity->getUserIdentifier();

        // 沒有使用者的 token（client_credentials 等）不在這裡把關：那些 grant 由 RestrictOidcGrantTypes 擋掉。
        if ($userId === null || $userId === '') {
            parent::persistNewAccessToken($accessTokenEntity);

            return;
        }

        DB::transaction(function () use ($accessTokenEntity, $userId): void {
            $isActive = DB::table('users')->where('id', $userId)->lockForUpdate()->value('is_active');

            // 不存在的使用者也是 null，一併拒絕。
            if ($isActive === null || (int) $isActive !== User::STATUS_ACTIVE) {
                throw OAuthServerException::accessDenied('The user account is not available.');
            }

            parent::persistNewAccessToken($accessTokenEntity);
        });
    }
}
