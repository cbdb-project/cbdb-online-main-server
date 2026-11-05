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
 * ⚠️ 這裡的 isActive() 檢查**只在簽發 id_token 時**才會被呼叫，而套件在 token 沒有 `openid` scope 時整段跳過
 * （含這個查詢）。所以它能成為把關，靠的是兩個前提，缺一不可：
 *   1. `GET /oauth/authorize` 強制 `openid` scope（App\Http\Middleware\RequireOpenIdScope）；
 *   2. `POST /oauth/token` 只放行 authorization_code（App\Http\Middleware\RestrictOidcGrantTypes）。
 * 且 token 在這個查詢**之前**就已寫進資料庫，失敗的兌換不會把 token 交給 RP，但也**沒有被撤銷**。
 * 完整的撤銷與 refresh 把關屬計畫第 4 步，完成前總開關（config/oidc.php）不可在 prod 開啟。
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
