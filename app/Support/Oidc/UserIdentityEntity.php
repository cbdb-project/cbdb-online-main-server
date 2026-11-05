<?php

namespace App\Support\Oidc;

use League\OAuth2\Server\Entities\Traits\EntityTrait;
use OpenIDConnect\Interfaces\IdentityEntityInterface;

/**
 * id_token 的身分實體：只承載 UserIdentityRepository 已白名單化的 claims。
 * scope 到 claim 的過濾由套件的 ClaimExtractor 負責（profile→name、email→email／email_verified）。
 */
final class UserIdentityEntity implements IdentityEntityInterface {
    use EntityTrait;

    /** @param array<string, string|bool> $claims */
    public function __construct(private readonly array $claims) {
    }

    /**
     * @param string[] $scopes
     *
     * @return array<string, string|bool>
     */
    public function getClaims(array $scopes = []): array {
        return $this->claims;
    }
}
