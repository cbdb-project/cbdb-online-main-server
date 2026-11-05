<?php

namespace App\Support\Oidc;

use Laravel\Passport\Bridge\RefreshTokenRepository;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;

/**
 * 不簽發 refresh token（docs/OIDC_PROVIDER_PLAN.md §8 第 4 步）。
 *
 * 設計決議：下游只需要在**登入那一刻**拿到 id_token 確認身分；沒有單點登出，下游自己管工作階段。
 * 在這個前提下 refresh token 對我們沒有任何用處，卻是風險最大的一塊——它是長壽憑證，必須在每次使用時
 * 重查帳號狀態、處理輪換與重用偵測、在停用帳號時撤銷。與其把這些都做對，不如**根本不簽發**：
 * 攻擊面整個消失。
 *
 * league 的 `issueRefreshToken()` 在 `getNewRefreshToken()` 回 null 時不簽發、不落庫，token 回應也不會有
 * `refresh_token` 欄位。`refresh_token` grant 另外由 RestrictOidcGrantTypes 拒絕。
 *
 * 若日後下游真的需要長工作階段，要有意地改回來：恢復這個類別、把 refresh_token 加進
 * RestrictOidcGrantTypes::ALLOWED，並補「refresh 時重查 isActive()、輪換、停用時撤銷」的測試。
 */
class NoRefreshTokenRepository extends RefreshTokenRepository {
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
        return null;
    }
}
