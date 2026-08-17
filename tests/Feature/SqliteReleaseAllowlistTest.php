<?php

namespace Tests\Feature;

use App\Console\Commands\ExportMysqlToSqlite;
use App\Support\SqliteReleaseTables;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #1251：對外釋出的 SQLite 由 `scripts/export-daily-sqlite.sh` 的 `TABLES=(...)` allowlist 決定，
 * 這份清單**不得含任何帳號／憑證表或 Laravel／應用自己的營運表**。
 *
 * 注意這是**靜態契約測試**：它檢查的是腳本文字，不是產出的 `.sqlite3`。另外兩個檔案負責真正執行：
 *  - [AssertSqliteReleaseScopeTest](AssertSqliteReleaseScopeTest.php)：產物自檢命令本身的行為。
 *  - [ReleaseScriptSelfCheckGateTest](ReleaseScriptSelfCheckGateTest.php)：自檢失敗時整份釋出腳本
 *    真的會非零收尾且不產生 metadata。**「呼叫還在」是文字比對守不住的語意**（`| tee`、`&& false`、
 *    漏掉的 `exit 1` 都能讓呼叫留著而結束碼被吞），所以那條測試是真的跑 bash。
 *
 * 為什麼還要有這條靜態測試：釋出範圍的第一層就是那份硬編碼清單，沒有任何機制阻止日後有人加一張表、
 * 或把腳本改成「動態取全部表」（那會讓釋出範圍變成由來源資料庫決定）。命令層雖然也會跳過憑證表的
 * 資料列，但那是為了保護「開發者裸跑」，**不是**釋出範圍的依據——釋出以 allowlist 為準。
 *
 * 契約敘述見 docs/SQLITE_DATA_RELEASE.md。
 */
class SqliteReleaseAllowlistTest extends TestCase {
    private const SCRIPT = 'scripts/export-daily-sqlite.sh';

    /** 公開 CBDB 資料表／代碼表的名稱形狀（與 AssertSqliteReleaseScope 同一判準）。 */
    private const PUBLIC_TABLE_PATTERN = '/^[A-Z][A-Z0-9_]*$/';

    /** 這份腳本是 CRLF；比對「`; then` 後接換行」之類的形狀前先正規化行尾。 */
    private function releaseScriptSource(): string {
        $path = base_path(self::SCRIPT);
        $this->assertFileExists($path, self::SCRIPT . ' 不存在——釋出流程改動時請同步更新本測試');

        return str_replace("\r\n", "\n", file_get_contents($path));
    }

    /** `TABLES=(` 與收尾 `)` 之間的原文。 */
    private function releaseAllowlistBlock(): string {
        $source = $this->releaseScriptSource();

        $this->assertSame(
            1,
            preg_match('/^TABLES=\((.*?)^\)/ms', $source, $matches),
            self::SCRIPT . ' 內找不到單一處的 TABLES=(...) 字面宣告。若釋出範圍改用別的機制'
                . '（例如動態取表），請先確認新機制如何保證不外流帳號／憑證表，再更新本測試。'
        );

        return $matches[1];
    }

    /**
     * 從發布腳本解析出 `TABLES=(...)` 內的**所有** token。
     *
     * 刻意不用 `/"([^"]+)"/` 只抓雙引號項：review 實測過，在清單裡加一行單引號 `'audit_log'`
     * 或裸字 `audit_log`，bash 都會正常展開匯出，而只看雙引號的解析器完全看不到它們。
     * 這裡改成剝註解 → 依空白切 token → 去掉外層引號，任何寫法都會進到下面的形狀檢查。
     *
     * @return array<int,string>
     */
    private function releaseAllowlist(): array {
        $block = $this->releaseAllowlistBlock();
        $block = preg_replace('/#[^\n]*/', '', $block);

        $tables = [];
        foreach (preg_split('/\s+/', (string) $block, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $tables[] = trim($token, "\"'");
        }

        $this->assertNotEmpty($tables, 'TABLES=(...) 解析結果為空');

        return $tables;
    }

    /**
     * 腳本內所有 `--tables=` 的引數原文。
     *
     * @return array<int,string>
     */
    private function tablesArguments(): array {
        preg_match_all('/--tables=(\S+)/', $this->releaseScriptSource(), $matches);

        return $matches[1] ?? [];
    }

    #[Test]
    public function the_release_allowlist_contains_no_credential_tables(): void {
        $allowlist = array_map('strtolower', $this->releaseAllowlist());

        foreach (array_keys(ExportMysqlToSqlite::CREDENTIAL_TABLES) as $credentialTable) {
            $this->assertNotContains(
                $credentialTable,
                $allowlist,
                "釋出 allowlist 不得含帳號／憑證表 {$credentialTable}（見 docs/SQLITE_DATA_RELEASE.md）"
            );
        }

        // 也不得從 --tables 引數夾帶：`--tables="$TABLE,audit_log"` 這種寫法不會出現在 allowlist 裡。
        // （下面 the_release_script_exports_table_by_table... 會要求每個 --tables 恰好是 "$TABLE"，
        //  這裡再從「憑證表名」的角度掃一次，兩個方向都釘住。）
        foreach ($this->tablesArguments() as $argument) {
            foreach (array_keys(ExportMysqlToSqlite::CREDENTIAL_TABLES) as $credentialTable) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b' . preg_quote($credentialTable, '/') . '\b/i',
                    $argument,
                    "--tables 引數不得提到帳號／憑證表 {$credentialTable}：{$argument}"
                );
            }
        }
    }

    #[Test]
    public function the_release_script_allowlist_matches_the_release_table_constant(): void {
        // 腳本的 TABLES=(...) 驅動匯出（意圖），SqliteReleaseTables::PUBLIC_TABLES 是產物自檢的
        // oracle（事實）。兩份清單必須逐項相同，否則其中一邊會變成沒人看的裝飾品：
        //  - 只加進腳本 → 產物自檢會擋下來（好，但錯誤訊息會出現在每週釋出的凌晨）
        //  - 只加進常數 → 自檢放行了一張其實沒匯出的表，範圍契約就鬆掉了
        $this->assertSame(
            SqliteReleaseTables::PUBLIC_TABLES,
            $this->releaseAllowlist(),
            'scripts/export-daily-sqlite.sh 的 TABLES 與 App\Support\SqliteReleaseTables::PUBLIC_TABLES '
                . '必須完全一致（含順序）。新增 CBDB 表時兩邊都要加。'
        );
    }

    #[Test]
    public function every_allowlisted_table_is_a_public_cbdb_table(): void {
        // 比枚舉營運表更硬的判準：CBDB 資料表／代碼表一律大寫（含底線與數字），而 Laravel 與
        // 應用自己的表（users、operations、audit_log、nl_query_logs、pinyin、char_variant_map…）
        // 一律小寫。用形狀斷言就不必維護一份會過期的營運表清單。
        //
        // 額外排除 CBDB__ 前綴：那些是應用自建的索引表（FTS、繁簡對照），形狀上是大寫但不對外，
        // 而且帶 `--tables` 時 ExportMysqlToSqlite 的 CBDB__ 過濾會被 early return 跳過，
        // 所以真的會被匯出。
        foreach ($this->releaseAllowlist() as $table) {
            $this->assertMatchesRegularExpression(
                self::PUBLIC_TABLE_PATTERN,
                $table,
                "釋出 allowlist 只能含大寫的 CBDB 資料表；{$table} 看起來是 Laravel／應用自己的表"
                    . '（見 docs/SQLITE_DATA_RELEASE.md）'
            );
            $this->assertStringStartsNotWith(
                'CBDB__',
                $table,
                "CBDB__ 是應用自建的內部索引表，不對外釋出：{$table}"
            );
        }
    }

    #[Test]
    public function the_release_script_self_checks_the_produced_artifact(): void {
        // 這是唯一繞不過去的防線：靜態檢查（本檔其餘測試）看的是腳本文字，任何一層間接
        // （TABLES+=／換個變數名餵 for 迴圈／source 外部檔／eval）都可能讓實際匯出範圍與 allowlist
        // 不一致。產物自檢直接讀要上傳的那個 .sqlite3。
        //
        // 這裡只釘「呼叫的形狀」；它真的會中止腳本這件事由 ReleaseScriptSelfCheckGateTest 執行驗證。
        $source = $this->releaseScriptSource();

        $this->assertMatchesRegularExpression(
            '/^set -e$/m',
            $source,
            '釋出腳本必須有 set -e：產物自檢是裸呼叫，靠 set -e 讓非零結束碼中止整份腳本'
        );

        // 必須是「裸呼叫」：行首就是 php，且整行沒有 |、&、; 這些能吞掉或改寫結束碼的位置。
        // 實測過的繞道（都在舊寫法 `if ! php artisan …; then … exit 1; fi` 的空隙裡）：
        //   `… | tee -a log`           → ! 否定的是 pipeline（tee 的 0），artisan 的碼被丟掉
        //   `… && false`               → 條件恆假，永不 abort
        //   `… && [ -z "$SKIP" ]`      → 留一個環境變數開關給 cron，review 時看起來無害
        //   刪掉區塊裡的 `exit 1`      → 只印訊息然後照樣往下產生 metadata
        $this->assertSame(
            1,
            preg_match_all(
                '/^php artisan cbdb:assert-sqlite-release-scope "\$OUTPUT_FILE"[^\n|&;]*$/m',
                $source
            ),
            '產物自檢必須是單一處的裸呼叫 `php artisan cbdb:assert-sqlite-release-scope "$OUTPUT_FILE" …`：'
                . '不得放進 if／pipeline／&& ||，那些位置都能讓結束碼被吞掉而呼叫看起來還在'
        );

        // 自檢必須把 allowlist 的長度傳下去，否則「匯出中途失敗、產物少了幾十張表」會靜默通過。
        $this->assertMatchesRegularExpression(
            '/cbdb:assert-sqlite-release-scope "\$OUTPUT_FILE" --min-tables="\$\{#TABLES\[@\]\}"/',
            $source,
            '產物自檢必須帶 --min-tables="${#TABLES[@]}"：把「產物表數」與 allowlist 長度綁在一起'
        );

        // 自檢必須在 metadata 產生之前。取位置時用上面那個「實際呼叫」的 offset，不要用
        // strpos(命令名)——腳本裡的註解也含命令名，那會讓「把呼叫搬到 metadata 之後、註解留在原處」
        // 這種改動照樣通過（實測過）。
        $this->assertSame(
            1,
            preg_match(
                '/^php artisan cbdb:assert-sqlite-release-scope /m',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE
            )
        );
        $metadataAt = strpos($source, 'OUTPUT_META_FILE" <<EOF');
        $this->assertNotFalse($metadataAt, '找不到 metadata 產生區塊——釋出流程改動時請同步更新本測試');
        $this->assertLessThan(
            $metadataAt,
            $matches[0][1],
            '產物自檢必須在產生 metadata 之前執行'
        );
    }

    #[Test]
    public function the_release_script_exports_table_by_table_with_an_explicit_allowlist(): void {
        // 釋出範圍的保證來自「逐表帶 --tables」這個形狀。若哪天改成不帶 --tables（全匯）或
        // 動態取表，上面幾條斷言就形同虛設——所以這裡把形狀本身釘住。
        $source = $this->releaseScriptSource();

        // 迴圈必須真的迭代 TABLES。實測過的繞道：把 `for TABLE in "${TABLES[@]}"` 換成
        // `for TABLE in $(cat scripts/release-tables.txt)`，或另開一個 EXTRA=(...) 併進去——
        // 兩者都讓 TABLES=(...) 變成沒人讀的裝飾品，而其餘斷言全綠。
        $this->assertSame(
            1,
            preg_match_all('/^for TABLE in "\$\{TABLES\[@\]\}"; do$/m', $source),
            '匯出迴圈必須恰好迭代 "${TABLES[@]}"：換成外部檔或動態取表就等於 allowlist 沒人讀'
        );

        // 每個 --tables 引數都必須恰好是 "$TABLE"。實測過的繞道：改成 --tables="$TABLE,audit_log"
        // 就能在不新增匯出呼叫的情況下夾帶一張表。
        $arguments = $this->tablesArguments();
        $this->assertNotEmpty($arguments, '釋出腳本必須逐表帶 --tables；不得改為不帶 --tables 的全量匯出');
        foreach ($arguments as $argument) {
            $this->assertSame(
                '"$TABLE"',
                $argument,
                '--tables 只能是 "$TABLE"（逐表匯出迴圈變數），不得夾帶其他表名或改成動態值'
            );
        }

        // 「存在」不等於「唯一」：只斷言上面那個形狀存在，仍可在別處另加一次匯出呼叫。
        // 目前腳本恰好兩處（第一張建檔、其餘 --append），數字變動就必須重新審視釋出範圍。
        //
        // 比對 'db:export' 而非完整命令名：實測過 `db:export"-to-sqlite"` 這種寫法 bash 會併回
        // 原命令，卻能讓「完整命令名」的計數維持 2。
        $this->assertSame(
            2,
            substr_count($source, 'db:export'),
            '釋出腳本應該只有兩處 db:export-to-sqlite 呼叫（建檔 + append）；'
                . '多出來的呼叫可能繞過 allowlist（例如自檢通過後再補一次全量匯出），請重新審視'
        );
        $this->assertSame(
            substr_count($source, 'db:export'),
            count($arguments),
            '每一次匯出呼叫都必須帶 --tables：兩者數量不相等表示有一次呼叫是全量匯出'
        );

        // allowlist 必須是**單一處、字面**宣告。codex 覆核時實測：加一行
        // `TABLES+=($(printf '\165\163\145\162\163'))` 可以在不出現 "users" 字樣的情況下把它塞進
        // 匯出範圍，而上面幾條斷言全綠。所以這裡禁止追加與動態展開。
        // 對 TABLES 的**所有**寫入形式只能有一處，就是那份字面清單。
        //
        // 錨定行首並要求變數名恰好是 TABLES：腳本內另有 FAILED_TABLES+=("$TABLE")（記錄失敗的表），
        // 不加錨定會誤命中它。
        $this->assertSame(
            1,
            preg_match_all('/^\s*TABLES(?:\+?=|\[[^\]]*\]\s*\+?=)/m', $source),
            '對 TABLES 的賦值只能有一處（那份字面 allowlist）。若這裡不是 1，代表有人以 '
                . 'TABLES+=／TABLES[i]= 之類的方式追加表名，實際匯出範圍就不再等於那份清單'
        );
        // 先取出區塊再檢查，不要用 [^)]* 之類的負向字元類——`$(cat x)` 自己就含 `)`，
        // 會讓比對提前停住而漏抓（實測過）。
        $this->assertDoesNotMatchRegularExpression(
            '/\$\(|`|\$\{/',
            $this->releaseAllowlistBlock(),
            '不得在 TABLES=(...) 內使用指令替換或變數展開：allowlist 必須是字面清單，'
                . '否則實際匯出範圍由執行環境決定，本測試也就守不住任何東西'
        );

        $this->assertStringNotContainsString(
            '--with-credentials',
            $source,
            '釋出腳本不得使用 --with-credentials'
        );
        $this->assertStringNotContainsString(
            '--with-internal',
            $source,
            '釋出腳本不得使用 --with-internal（CBDB__ 內部表不對外）'
        );
    }

    #[Test]
    public function the_upload_script_re_checks_the_artifact_before_packaging(): void {
        // 上傳邊界再驗一次：export-daily-sqlite.sh 的自檢與 hf upload 之間隔了一整個腳本。
        $path = base_path('scripts/weekly-sqlite-sync.sh');
        $this->assertFileExists($path);
        $source = str_replace("\r\n", "\n", file_get_contents($path));

        $this->assertMatchesRegularExpression(
            '/^set -e$/m',
            $source,
            'weekly-sqlite-sync.sh 必須有 set -e，否則匯出腳本的非零結束碼不會擋下上傳'
        );

        $this->assertSame(
            1,
            preg_match(
                '/^php artisan cbdb:assert-sqlite-release-scope "\$SQLITE_FILE"[^\n|&;]*$/m',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE
            ),
            'weekly-sqlite-sync.sh 必須在打包前對 $SQLITE_FILE 再跑一次產物自檢（裸呼叫）'
        );

        $zipAt = strpos($source, 'zip -j -9');
        $this->assertNotFalse($zipAt, '找不到 zip 步驟——釋出流程改動時請同步更新本測試');
        $this->assertLessThan(
            $zipAt,
            $matches[0][1],
            '上傳邊界的自檢必須在 zip 之前，否則檢查的不是實際會被上傳的內容'
        );
    }
}
