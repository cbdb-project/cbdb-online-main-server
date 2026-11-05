<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 週六釋出腳本順便清理 OIDC 過期 token（docs/OIDC_PROVIDER_PLAN.md §8.1 第 8 項）。
 *
 * 兩個要守住的事：清理失敗不能把「SQLite 釋出」的結束碼弄成非零；舊檔名的轉接不能消失
 * （prod 的 crontab 在改指新檔名之前還指著舊路徑，消失了每週釋出會無聲地停掉）。
 */
class WeeklyScriptOidcPurgeStepTest extends TestCase {
    private function read(string $rel): string {
        $path = base_path($rel);
        $this->assertFileExists($path);

        return str_replace("\r\n", "\n", file_get_contents($path));
    }

    #[Test]
    public function the_purge_step_runs_after_the_upload_and_cannot_fail_the_release(): void {
        $source = $this->read('scripts/weekly-sqlite-sync-and-oidc-purge.sh');

        $this->assertSame(
            1,
            preg_match('/^php artisan passport:purge --expired --hours=24 [^\n]*\|\| echo [^\n]*$/m', $source, $m, PREG_OFFSET_CAPTURE),
            '清理必須是裸呼叫後接 `|| echo`（set -e 下否則會蓋掉釋出結果），且帶 --expired'
        );

        $uploadAt = strpos($source, 'hf upload');
        $this->assertNotFalse($uploadAt);
        $this->assertGreaterThan($uploadAt, $m[0][1], '清理必須排在上傳之後，不能讓它先擋下釋出');
    }

    #[Test]
    public function the_old_script_name_still_forwards_to_the_new_one(): void {
        $source = $this->read('scripts/weekly-sqlite-sync.sh');

        $this->assertMatchesRegularExpression(
            '/^exec "\$\(dirname "\$0"\)\/weekly-sqlite-sync-and-oidc-purge\.sh" "\$@"$/m',
            $source,
            'crontab 改指新檔名之前，舊路徑必須繼續轉接到新腳本'
        );
    }
}
