<?php

namespace App\Console\Commands;

use App\Support\Oidc\SigningKey;
use Illuminate\Console\Command;
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

        if ((string) config('app.key') === '') {
            $errors[] = 'APP_KEY 未設定：授權碼以它加密。';
        }

        $this->checkKeys($errors, $warnings);
        $this->checkIssuer($errors, $warnings);
        $this->checkScopes($errors);

        if (in_array((string) config('cache.default'), ['array', 'file'], true)) {
            $warnings[] = "限流計數存在 cache store「" . config('cache.default') . '」。單節點沒問題；多節點部署必須改用共享 store（redis／database），否則實際上限是「節點數 × 上限」。';
        }

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

        if (!str_starts_with($issuer, 'https://') && !app()->environment(['local', 'testing'])) {
            $errors[] = "issuer 必須是 https：{$issuer}";
        }

        if (str_ends_with($issuer, '/')) {
            $warnings[] = "issuer 結尾有斜線（{$issuer}）：它是 id_token 的 iss 與 discovery 的 issuer，RP 會逐字比對，請確認下游設定完全相同。";
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
