<?php

namespace App\Support\Oidc;

use Laravel\Passport\Passport;

/**
 * id_token 簽章金鑰的公鑰資訊（供 `kid` 與健康檢查使用）。
 *
 * `kid` 用 RFC 7638 的 JWK 指紋（公鑰的 SHA-256），由公鑰本身決定、不必另外維護：換金鑰它就跟著變，
 * 而 id_token 頭部的 `kid` 與 JWKS 裡那把鑰的 `kid` 來自同一個函式，不會對不上。
 * RP 的函式庫收到不認得的 `kid` 會重抓 JWKS，這就是金鑰輪換時下游不需要人工介入的原因。
 *
 * 公鑰來源與套件的 JwksController 一致：先看 `passport.public_key`（環境變數 PASSPORT_PUBLIC_KEY，
 * 可含字面的 `\n`），沒有才讀 `Passport::keyPath('oauth-public.key')`。
 */
final class SigningKey {
    /** @var array<string, ?string> 以公鑰內容的 sha1 為鍵，避免每個請求都重算 */
    private static array $memo = [];

    public static function publicKeyPem(): string {
        $configured = str_replace('\\n', "\n", (string) config('passport.public_key'));

        if ($configured !== '') {
            return $configured;
        }

        $path = Passport::keyPath('oauth-public.key');

        return is_file($path) && is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * 私鑰（環境變數 PASSPORT_PRIVATE_KEY 或 oauth-private.key）。**只給健康檢查用來比對「公私鑰是不是同一對」，
     * 絕不可輸出到任何回應或日誌**；JWKS 與 kid 都只用公鑰。
     */
    public static function privateKeyPem(): string {
        $configured = str_replace('\\n', "\n", (string) config('passport.private_key'));

        if ($configured !== '') {
            return $configured;
        }

        $path = Passport::keyPath('oauth-private.key');

        return is_file($path) && is_readable($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * 公鑰與私鑰是不是同一對（RSA 的 modulus 與 exponent 相同）。任何一邊缺漏或不是 RSA 金鑰時回 false。
     *
     * 為什麼要檢查：不成對時 JWKS 公布的是 A 的公鑰、id_token 卻用 B 的私鑰簽，RP 永遠驗不過，
     * 而且兩個檔案單獨看都「存在、格式正確」。
     */
    public static function pairMatches(): bool {
        $private = @openssl_pkey_get_private(self::privateKeyPem());
        $public = @openssl_pkey_get_public(self::publicKeyPem());

        if ($private === false || $public === false) {
            return false;
        }

        $privateDetails = openssl_pkey_get_details($private);
        $publicDetails = openssl_pkey_get_details($public);

        if (!isset($privateDetails['rsa']['n'], $privateDetails['rsa']['e'], $publicDetails['rsa']['n'], $publicDetails['rsa']['e'])) {
            return false;
        }

        return hash_equals($privateDetails['rsa']['n'], $publicDetails['rsa']['n'])
            && hash_equals($privateDetails['rsa']['e'], $publicDetails['rsa']['e']);
    }

    /** 金鑰缺漏或不是 RSA 公鑰時回 null。 */
    public static function kid(): ?string {
        $pem = self::publicKeyPem();

        if ($pem === '') {
            return null;
        }

        $cacheKey = sha1($pem);

        if (array_key_exists($cacheKey, self::$memo)) {
            return self::$memo[$cacheKey];
        }

        $resource = @openssl_pkey_get_public($pem);
        $details = $resource === false ? false : openssl_pkey_get_details($resource);

        if ($details === false || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            return self::$memo[$cacheKey] = null;
        }

        // RFC 7638 §3：只取必要成員、依字典序、不含空白。
        $canonical = '{"e":"' . self::base64Url($details['rsa']['e']) . '","kty":"RSA","n":"' . self::base64Url($details['rsa']['n']) . '"}';

        return self::$memo[$cacheKey] = self::base64Url(hash('sha256', $canonical, true));
    }

    private static function base64Url(string $binary): string {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
