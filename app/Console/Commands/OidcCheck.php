<?php

namespace App\Console\Commands;

use App\Support\Oidc\SigningKey;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Laravel\Passport\Passport;

/**
 * OIDC Provider 的上線前檢查（docs/OIDC_PROVIDER_PLAN.md §8 第 3 步）。
 *
 * 開啟 `OIDC_PROVIDER_ENABLED` 之前、以及每次部署後跑一次。總開關關閉時直接成功（沒有東西要檢查）。
 * 有 **錯誤** 回傳非 0；**警告** 只提醒、不影響結束碼。
 *
 * 只檢查「靠人手維護、容易在新環境漏掉」的前提：這些問題都不會讓應用啟動失敗，而是在第一個下游登入時
 * 才以 500 或「id_token 驗不過」的形式冒出來。
 */
class OidcCheck extends Command {
    protected $signature = 'oidc:check';

    protected $description = '檢查 OIDC Provider 的上線前提（金鑰、sodium、issuer、scope）；總開關關閉時直接成功';

    public function handle(): int {
        if (!config('oidc.enabled')) {
            $this->components->info('OIDC Provider 已關閉（OIDC_PROVIDER_ENABLED 不是 true），沒有需要檢查的項目。');

            return self::SUCCESS;
        }

        $errors = [];
        $warnings = [];

        if (!extension_loaded('sodium')) {
            $errors[] = 'PHP 沒有載入 sodium 擴充（lcobucci/jwt 需要）。請安裝並啟用（Debian／Ubuntu：apt install php8.4-sodium，或在 php.ini 開啟 extension=sodium）。';
        }

        $this->checkAppKey($errors);

        $this->checkKeys($errors, $warnings);
        $this->checkIssuer($errors, $warnings);
        $this->checkScopes($errors);

        // 多節點部署的兩個前提。命令只看得到本機，沒辦法跨節點驗證，所以只能提醒：
        //  1. 限流計數存在預設 cache store，file／array 時每個節點各算各的（只有這種情況才警告）；
        //  2. **所有節點必須用同一對簽章金鑰**（相同的 PASSPORT_*_KEY 環境變數，或共享掛載的金鑰目錄）：
        //     JWKS 只公布一把鑰，每個節點若各用自己的 storage/oauth-*.key，一個節點簽的 id_token
        //     會在另一個節點的 JWKS 下驗不過，登入成不成功取決於請求落在哪個節點。
        //     這與 cache store 無關（用 redis 的多節點部署一樣會中），所以**無條件**提醒，不是只在 file／array 時。
        if (in_array((string) config('cache.default'), ['array', 'file'], true)) {
            $warnings[] = "限流計數存在 cache store「" . config('cache.default') . '」。單節點沒問題；多節點部署必須改用共享 store（redis／database），否則實際上限是「節點數 × 上限」。';
        }

        $warnings[] = '多節點部署時，所有節點必須使用**同一對**簽章金鑰（相同的 PASSPORT_PUBLIC_KEY／PASSPORT_PRIVATE_KEY，或共享掛載的金鑰目錄）。本命令只能檢查本機，無法驗證其他節點。';

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        foreach ($errors as $error) {
            $this->components->error($error);
        }

        if ($errors !== []) {
            $this->newLine();
            $this->components->error(count($errors) . ' 個錯誤：不可開啟 OIDC Provider，或請先修正。');

            return self::FAILURE;
        }

        $this->components->info('OIDC Provider 上線前提檢查通過（kid: ' . SigningKey::kid() . '）。');

        return self::SUCCESS;
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function checkKeys(array &$errors, array &$warnings): void {
        // 公鑰與私鑰**各自獨立**檢查：來源可以是環境變數或檔案，而且兩邊可以不同。
        // （曾經只要任一邊來自環境變數就整段跳過，於是「私鑰在環境變數、公鑰根本不存在」也顯示通過，
        // 實際上 /oauth/jwks 會 500。）
        $keys = [
            '公鑰' => ['env' => 'PASSPORT_PUBLIC_KEY', 'configured' => (string) config('passport.public_key'), 'path' => Passport::keyPath('oauth-public.key'), 'pem' => SigningKey::publicKeyPem()],
            '私鑰' => ['env' => 'PASSPORT_PRIVATE_KEY', 'configured' => (string) config('passport.private_key'), 'path' => Passport::keyPath('oauth-private.key'), 'pem' => SigningKey::privateKeyPem()],
        ];

        foreach ($keys as $label => $key) {
            if ($key['pem'] !== '') {
                continue;
            }

            $errors[] = $key['configured'] !== ''
                ? "{$label}（{$key['env']}）是空的。"
                : "找不到{$label}：{$key['path']}（也沒有設定 {$key['env']}）。第一次部署請執行 `php artisan passport:keys`（只做一次；之後不要加 --force，那會換掉簽章金鑰）。";
        }

        // 私鑰不可被「其他人」讀取（只有它是檔案時才檢查）。Windows 的檔案權限模型不同，不檢查。
        $privatePath = $keys['私鑰']['path'];
        if ($keys['私鑰']['configured'] === '' && !windows_os() && is_file($privatePath) && (fileperms($privatePath) & 0007) !== 0) {
            $errors[] = "私鑰 {$privatePath} 可被其他使用者讀取（權限應為 0600）。請 chmod 600，並確認只有網頁伺服器的使用者能讀。";
        }

        if ($keys['公鑰']['pem'] === '' || $keys['私鑰']['pem'] === '') {
            return; // 上面已經報過缺哪一把
        }

        if (SigningKey::kid() === null) {
            $errors[] = '公鑰不是有效的 RSA 公鑰，無法計算 kid／JWKS。';

            return;
        }

        // RSA 小於 2048 位元可被分解，攻擊者就能偽造 RP 會接受的 id_token。
        $bits = SigningKey::publicKeyBits();
        if ($bits !== null && $bits < 2048) {
            $errors[] = "簽章金鑰只有 {$bits} 位元，必須至少 2048 位元（`php artisan passport:keys` 預設是 4096）。";
        }

        if (!SigningKey::pairMatches()) {
            $errors[] = '公鑰與私鑰不是同一對（或私鑰不是有效的 RSA 私鑰）：JWKS 公布的公鑰驗不了 id_token，RP 會永遠驗不過。請重新生成成對的金鑰，不要只換其中一把。';
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function checkIssuer(array &$errors, array &$warnings): void {
        $issuer = (string) config('openid.issuedBy');

        if ($issuer === '' || $issuer === 'laravel') {
            $errors[] = '沒有明確的 issuer：請設定 OIDC_ISSUER（或 APP_URL）。未設定時套件會從請求推導 scheme＋host，放在反向代理後面容易推出內網位址，而 iss 發出去就是 RP 驗證的依據。';

            return;
        }

        // 不只比對前綴：`https://` 這種看似 https 的殘缺值，RP 一樣無法使用。
        $parts = parse_url($issuer);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';

        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
            $errors[] = "issuer 不是有效的 URL（需要 scheme 與主機）：{$issuer}";

            return;
        }

        // 帳號與密碼不屬於 issuer：它會原樣出現在 id_token 的 iss 與 discovery 文件裡。
        if (isset($parts['user']) || isset($parts['pass'])) {
            $errors[] = "issuer 不可含使用者資訊（user:pass@）：{$issuer}";

            return;
        }

        // discovery 只在**主機根路徑**（/.well-known/openid-configuration）提供。issuer 若帶路徑，OIDC Discovery
        // 規定 RP 要去抓 `{issuer}/.well-known/openid-configuration`，那個網址在這裡是 404。
        $path = (string) ($parts['path'] ?? '');
        if (($path !== '' && $path !== '/') || isset($parts['query']) || isset($parts['fragment'])) {
            $errors[] = "issuer 不可帶路徑、查詢字串或片段（本專案的 discovery 只在主機根路徑提供）：{$issuer}";

            return;
        }

        if ($scheme !== 'https' && !app()->environment(['local', 'testing'])) {
            $errors[] = "issuer 必須是 https：{$issuer}";
        }

        if (str_ends_with($issuer, '/')) {
            $warnings[] = "issuer 結尾有斜線（{$issuer}）：它是 id_token 的 iss 與 discovery 的 issuer，RP 會逐字比對，請確認下游設定完全相同。";
        }
    }

    /**
     * APP_KEY 不只要非空：它要能被 Laravel 的 encrypter 接受（長度須符合 cipher），否則授權碼加密時才丟例外，
     * 而那已經是第一個下游登入的當下。
     *
     * @param list<string> $errors
     */
    private function checkAppKey(array &$errors): void {
        $key = (string) config('app.key');

        if ($key === '') {
            $errors[] = 'APP_KEY 未設定：授權碼以它加密。';

            return;
        }

        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if ($raw === false || !Encrypter::supported($raw, (string) config('app.cipher'))) {
            $errors[] = 'APP_KEY 格式錯誤或長度與 cipher（' . config('app.cipher') . '）不符：Laravel 的 encrypter 會拒絕它，授權碼無法加密。請以 `php artisan key:generate` 重新生成。';
        }
    }

    /**
     * @param list<string> $errors
     */
    private function checkScopes(array &$errors): void {
        $expected = array_keys((array) config('openid.passport.tokens_can'));
        $actual = array_keys(Passport::$scopes);

        if ($actual !== $expected) {
            $errors[] = 'Passport 的 scope 與設定不一致（多了：' . implode(', ', array_diff($actual, $expected))
                . '）。常見原因是 laravel/mcp 在 booted() 併入的 mcp:use 沒被蓋掉，RP 因此能申請到無關的 scope。';
        }
    }
}
