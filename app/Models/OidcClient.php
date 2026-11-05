<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;

/**
 * OIDC Provider 的 client 模型（OidcServiceProvider 以 Passport::useClientModel 掛上）。
 *
 * 唯一的差別：first-party client 跳過同意頁。本專案的 client 一律由管理員手動建立（沒有 client 自助註冊，
 * 也沒有開 Passport 的 `/oauth/clients` JSON API），都是 first-party——沒有擁有者（owner）的才算。
 * 有擁有者的 client 不會被自動核准，`prompt=consent` 同理，兩者都回 `consent_required`
 * （見 App\Http\Responses\OidcConsentRequiredResponse，本專案沒有同意頁）。
 */
class OidcClient extends Client {
    /**
     * @param \Laravel\Passport\Scope[] $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool {
        return $this->firstParty();
    }
}
