<?php

namespace Tests\Support;

use Laravel\Passport\Passport;
use phpseclib4\Crypt\RSA;

/**
 * OIDC 測試用的 RSA 金鑰。
 *
 * - 用 Passport 自己的做法（phpseclib）生成：本機 PHP 的 openssl 沒有設定檔，`openssl_pkey_new` 不能用。
 * - 整個行程只生成一次（純 PHP 生成 2048 位元很慢）。
 * - **必須在 app 啟動之前呼叫**（放在 bootConfigOverrides() 裡）：OidcServiceProvider 在 boot 時由公鑰算 `kid`，
 *   金鑰路徑要先設好，否則 id_token 頭部與 JWKS 會沒有 kid。
 */
final class OidcTestKeys {
    private static ?string $directory = null;

    /** 把 Passport 的金鑰目錄指到測試金鑰，回傳該目錄。 */
    public static function install(): string {
        if (self::$directory === null) {
            $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oidc-test-keys-' . bin2hex(random_bytes(4));
            mkdir($directory, 0700, true);

            $key = RSA::createKey(2048);
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'oauth-public.key', (string) $key->getPublicKey());
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'oauth-private.key', (string) $key);

            if (!windows_os()) {
                chmod($directory . DIRECTORY_SEPARATOR . 'oauth-public.key', 0660);
                chmod($directory . DIRECTORY_SEPARATOR . 'oauth-private.key', 0600);
            }

            self::$directory = $directory;
        }

        Passport::loadKeysFrom(self::$directory);

        return self::$directory;
    }

    public static function publicKeyPem(): string {
        return (string) file_get_contents(self::install() . DIRECTORY_SEPARATOR . 'oauth-public.key');
    }

    public static function privateKeyPem(): string {
        return (string) file_get_contents(self::install() . DIRECTORY_SEPARATOR . 'oauth-private.key');
    }
}
