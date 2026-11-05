<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;

/**
 * Passport 在「需要同意頁」時會呼叫的視圖回應——本專案沒有同意頁，所以這裡改成回 OIDC 錯誤。
 *
 * 會走到這裡的情況只有兩種（OidcClient::skipsAuthorization 讓 first-party client 預設免同意）：
 *  1. RP 帶了 `prompt=consent`——OIDC Core 規定伺服器無法取得同意時必須回錯誤（規範寫「通常是
 *     consent_required」），不可靜默當成已同意；
 *  2. client 有擁有者（非 first-party），本專案不核發這種 client 的授權。
 *
 * 此時 league 已經驗證過 client 與 redirect_uri（validateAuthorizationRequest 在前），所以往
 * redirect_uri 帶錯誤回去是安全的。docs/OIDC_PROVIDER_PLAN.md §6.4。
 */
class OidcConsentRequiredResponse implements AuthorizationViewResponse {
    /** @var array<string, mixed> */
    private array $parameters = [];

    public function withParameters(array $parameters = []): static {
        $this->parameters = $parameters;

        return $this;
    }

    /**
     * @param \Illuminate\Http\Request $request
     */
    public function toResponse($request): RedirectResponse {
        /** @var Client $client */
        $client = $this->parameters['client'];

        $redirectUri = (string) ($request->query('redirect_uri') ?: ($client->redirect_uris[0] ?? ''));

        $query = array_filter([
            'error' => 'consent_required',
            'error_description' => 'The authorization server does not provide a consent screen.',
            'state' => $request->query('state'),
        ], static fn ($value) => $value !== null && $value !== '');

        return redirect()->away($redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($query));
    }
}
