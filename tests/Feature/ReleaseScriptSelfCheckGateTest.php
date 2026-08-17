<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #1251：**真的執行** `scripts/export-daily-sqlite.sh`，證明產物自檢失敗時整份腳本會非零收尾、
 * 而且不會產生 metadata。
 *
 * 為什麼需要執行而不是比對文字：review 實測過，「自檢呼叫還在、但結束碼被吞掉」是整類繞道，
 * 而且每一種都能讓純文字比對的守衛全綠——
 *   `… | tee -a log`        → bash 的 `!` 否定的是 pipeline（tee 的 0）
 *   `… && false`            → if 條件恆假
 *   `… && [ -z "$SKIP" ]`   → 留一個環境變數開關，review 時看起來無害
 *   刪掉區塊裡的 `exit 1`   → 只印訊息，然後照樣往下產生 metadata
 * 這些都不是「文字不見了」，是「語意沒了」。唯一守得住的方式是跑一次，看結束碼與檔案。
 *
 * 做法：把一個假的 `php` 放到 PATH 最前面。它記下每次呼叫的引數，對 `db:export-to-sqlite`
 * 建出輸出檔並回 0，對 `cbdb:assert-sqlite-release-scope` 回 `STUB_ASSERT_EXIT`。
 * 這樣就能在不連任何資料庫的情況下驅動整條釋出流程。
 */
class ReleaseScriptSelfCheckGateTest extends TestCase {
    /**
     * 同一個 STUB_ASSERT_EXIT 的執行結果快取。
     *
     * 跑一次要驅動 77 圈迴圈（每圈一個 stub 行程），約 6 秒；三條測試都用「自檢通過」那次執行，
     * 沒必要重跑。快取的是結果快照（結束碼、輸出、呼叫記錄、產出的檔名清單）而不是目錄，
     * 因為 tearDown 會把工作目錄刪掉。
     *
     * @var array<int,array{code:int, output:string, log:string, metadata:array<int,string>, sqlite:array<int,string>}>
     */
    private static array $runs = [];

    private string $dir;

    protected function setUp(): void {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/cbdb-release-gate-' . getmypid();
        $this->removeDir($this->dir);
        mkdir($this->dir . '/bin', 0777, true);
        mkdir($this->dir . '/work', 0777, true);
    }

    protected function tearDown(): void {
        $this->removeDir($this->dir);

        parent::tearDown();
    }

    private function removeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    /** 可用的 bash（CI 是 ubuntu；Windows 開發機上 `bash` 會解析到不能用的 WSL stub）。 */
    private function findBash(): ?string {
        $candidates = ['bash'];
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates = [
                'C:/Program Files/Git/bin/bash.exe',
                'C:/Program Files/Git/usr/bin/bash.exe',
                'bash',
            ];
        }

        foreach ($candidates as $candidate) {
            $output = [];
            $code = 0;
            exec(escapeshellarg($candidate) . ' -c "echo ok" 2>&1', $output, $code);
            if ($code === 0 && ($output[0] ?? '') === 'ok') {
                return $candidate;
            }
        }

        return null;
    }

    private function posix(string $path): string {
        return str_replace('\\', '/', $path);
    }

    /**
     * 用假的 `php` 跑一次釋出腳本（同一個 $assertExit 只跑一次，之後回快取）。
     *
     * @return array{code:int, output:string, log:string, metadata:array<int,string>, sqlite:array<int,string>}
     */
    private function runReleaseScript(int $assertExit): array {
        if (isset(self::$runs[$assertExit])) {
            return self::$runs[$assertExit];
        }

        $bash = $this->findBash();
        if ($bash === null) {
            $this->markTestSkipped('找不到可用的 bash，無法執行釋出腳本（CI 上為 ubuntu，這裡會實跑）');
        }

        $binDir = $this->posix($this->dir . '/bin');
        $workDir = $this->posix($this->dir . '/work');
        $logFile = $this->posix($this->dir . '/php-calls.log');

        file_put_contents($binDir . '/php', implode("\n", [
            '#!/bin/bash',
            'printf "%s\n" "$*" >> "$STUB_LOG"',
            'out=""',
            'for arg in "$@"; do',
            '  case "$arg" in',
            '    --output=*) out="${arg#--output=}" ;;',
            '  esac',
            'done',
            'case "$*" in',
            '  *cbdb:assert-sqlite-release-scope*)',
            // 自檢的目標檔必須已經存在。真實命令對不存在的檔案是 fail-closed 的，若 stub 一律回 0，
            // 「把自檢搬到匯出迴圈之前」這種改動會在測試裡假綠（codex 覆核時指出）。
            '    target=""',
            '    for arg in "$@"; do',
            '      case "$arg" in',
            '        -*) ;;',
            '        artisan) ;;',
            '        cbdb:*|db:*) ;;',
            '        *) target="$arg" ;;',
            '      esac',
            '    done',
            '    if [ -z "$target" ] || [ ! -f "$target" ]; then',
            '      echo "stub: 自檢目標檔不存在: $target" >&2',
            '      exit 3',
            '    fi',
            '    exit "${STUB_ASSERT_EXIT:-0}" ;;',
            '  *db:export-to-sqlite*)',
            '    if [ -n "$out" ]; then printf "x" > "$out"; fi',
            '    exit 0 ;;',
            'esac',
            'exit 0',
            '',
        ]));
        chmod($binDir . '/php', 0755);

        $driver = $this->dir . '/driver.sh';
        file_put_contents($driver, implode("\n", [
            '#!/bin/bash',
            'STUB_DIR="' . $binDir . '"',
            'if command -v cygpath >/dev/null 2>&1; then STUB_DIR="$(cygpath -u "$STUB_DIR")"; fi',
            'export PATH="$STUB_DIR:$PATH"',
            'export STUB_LOG="' . $logFile . '"',
            'export STUB_ASSERT_EXIT=' . $assertExit,
            'export OUTPUT_DIR="' . $workDir . '"',
            'cd "' . $this->posix(base_path()) . '"',
            'exec bash scripts/export-daily-sqlite.sh',
            '',
        ]));

        $output = [];
        $code = 0;
        exec(
            escapeshellarg($bash) . ' ' . escapeshellarg($this->posix($driver)) . ' 2>&1',
            $output,
            $code
        );

        return self::$runs[$assertExit] = [
            'code' => $code,
            'output' => implode("\n", $output),
            'log' => (string) @file_get_contents($this->dir . '/php-calls.log'),
            'metadata' => array_map([$this, 'posix'], glob($workDir . '/*.json') ?: []),
            'sqlite' => array_map([$this, 'posix'], glob($workDir . '/*.sqlite3') ?: []),
        ];
    }

    #[Test]
    public function a_failing_self_check_aborts_the_script_and_produces_no_metadata(): void {
        $result = $this->runReleaseScript(1);

        $this->assertStringContainsString(
            'cbdb:assert-sqlite-release-scope',
            $result['log'],
            '釋出腳本沒有呼叫產物自檢——這次的非零結束碼可能來自別的地方，測試不成立'
        );
        $this->assertNotSame(
            0,
            $result['code'],
            '產物自檢失敗時釋出腳本必須以非零結束碼收尾（weekly-sqlite-sync.sh 的 set -e 才會擋下 '
                . "hf upload）。腳本輸出：\n" . $result['output']
        );
        $this->assertSame(
            [],
            $result['metadata'],
            '產物自檢失敗時不得產生 metadata'
        );
    }

    #[Test]
    public function a_passing_self_check_lets_the_script_finish(): void {
        // 反向對照：沒有這條，上面那條可以被「腳本永遠失敗」滿足。
        $result = $this->runReleaseScript(0);

        $this->assertSame(
            0,
            $result['code'],
            "自檢通過時釋出腳本應該正常收尾。腳本輸出：\n" . $result['output']
        );
        $this->assertNotSame(
            [],
            $result['metadata'],
            '自檢通過時應該產生 metadata'
        );
    }

    #[Test]
    public function the_self_check_runs_against_the_file_that_gets_packaged(): void {
        // 實測過的繞道：在自檢前把 OUTPUT_FILE 換成一個新建的空檔，靜態斷言只釘「字面 $OUTPUT_FILE」
        // 而不管那個變數當下指向誰。這裡比對「自檢拿到的路徑」與「實際產生的 .sqlite3」。
        $result = $this->runReleaseScript(0);

        $this->assertCount(1, $result['sqlite'], '釋出腳本應該產生恰好一個 .sqlite3');
        $expectedPath = $result['sqlite'][0];

        $assertCalls = array_values(array_filter(
            explode("\n", $result['log']),
            fn ($line) => str_contains($line, 'cbdb:assert-sqlite-release-scope')
        ));
        $this->assertCount(1, $assertCalls, '產物自檢應該被呼叫恰好一次');
        $this->assertStringContainsString(
            $expectedPath,
            $assertCalls[0],
            '產物自檢必須檢查實際產生（並會被打包上傳）的那個檔案，不是別的路徑'
        );

        // 而且要在**所有**匯出之後：搬到匯出迴圈之前的話，它檢查的是還沒寫完的檔案。
        $lines = explode("\n", trim($result['log']));
        $lastExport = null;
        $assertAt = null;
        foreach ($lines as $index => $line) {
            if (str_contains($line, 'db:export-to-sqlite')) {
                $lastExport = $index;
            }
            if (str_contains($line, 'cbdb:assert-sqlite-release-scope')) {
                $assertAt = $index;
            }
        }
        $this->assertNotNull($lastExport, '記錄裡找不到匯出呼叫');
        $this->assertNotNull($assertAt, '記錄裡找不到自檢呼叫');
        $this->assertGreaterThan(
            $lastExport,
            $assertAt,
            '產物自檢必須排在最後一次匯出之後，否則檢查的不是完整的產物'
        );
    }

    #[Test]
    public function the_self_check_receives_the_allowlist_length(): void {
        // --min-tables 是「產物被截斷／是空檔」唯一會被抓到的地方，而它的值必須來自 allowlist 長度。
        $result = $this->runReleaseScript(0);

        $assertCall = '';
        foreach (explode("\n", $result['log']) as $line) {
            if (str_contains($line, 'cbdb:assert-sqlite-release-scope')) {
                $assertCall = $line;

                break;
            }
        }

        $this->assertMatchesRegularExpression(
            '/--min-tables=(\d+)/',
            $assertCall,
            '產物自檢必須帶 --min-tables'
        );
        preg_match('/--min-tables=(\d+)/', $assertCall, $matches);
        $this->assertGreaterThanOrEqual(
            70,
            (int) $matches[1],
            '--min-tables 應該等於 allowlist 的長度（目前 77 張）；數字掉到很小表示 allowlist 被繞開了'
        );
    }
}
