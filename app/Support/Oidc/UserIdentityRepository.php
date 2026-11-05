<?php

namespace App\Support\Oidc;

use App\Models\User;
use League\OAuth2\Server\Exception\OAuthServerException;
use OpenIDConnect\Interfaces\IdentityEntityInterface;
use OpenIDConnect\Interfaces\IdentityRepositoryInterface;

/**
 * id_token 的身分來源（config/openid.php 的 repositories.identity）。
 *
 * 取代套件預設的 IdentityRepository——那個會回傳寫死的演示身分（Jon Snow，`email_verified => true`）。
 *
 * 規則（docs/OIDC_PROVIDER_PLAN.md §6.1）：
 *  - `sub` 是內部穩定鍵 users.id，**明確轉成字串**（OIDC 規定 sub 是 JSON string，users.id 是整數）；
 *  - claims 走**顯式白名單**：name、email、email_verified。不序列化整個 User——它有 is_admin、
 *    is_active、settings、confirmation_token（長期眾包憑證）等不能外流的欄位；
 *  - email 沒有所有權驗證流程，而且使用者可自行修改，所以 `email_verified` 恆為 false，
 *    RP 不可把 email 當身份鍵；
 *  - 帳號不存在或未啟用一律拒絕。
 *
 * 這裡的 isActive() 檢查是**最後一道**（縱深防禦），不是唯一一道，而且它**只在簽發 id_token 時**才會被呼叫
 * （套件在 token 沒有 `openid` scope 時整段跳過）。真正擋住停用帳號的是更前面的幾層：
 *   1. EnsureOidcUserIsActive：授權請求時把已停用帳號當成未登入；
 *   2. ActiveUserAccessTokenRepository：簽發 access token **之前**檢查，與落庫同一個交易、鎖同一列 users，
 *      失敗時不留下任何 token 記錄，且不論 scope；
 *   3. AccountAccessRevoker::revokeOidcTokens()：停用帳號時撤銷已簽出的 access token 與尚未兌換的授權碼；
 *   4. 不簽發 refresh token（NoRefreshTokenRepository）。
 * 這裡只剩「簽發 id_token 的那一刻」的最後確認，以及白名單 claims 的組裝。
 */
final class UserIdentityRepository implements IdentityRepositoryInterface {
    public function getByIdentifier(string $identifier): IdentityEntityInterface {
        $user = ctype_digit($identifier) ? User::query()->find((int) $identifier) : null;

        if ($user === null || !$user->isActive()) {
            throw OAuthServerException::accessDenied('The user account is not available.');
        }

        $entity = new UserIdentityEntity([
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'email_verified' => false,
        ]);
        $entity->setIdentifier((string) $user->getKey());

        return $entity;
    }
}
