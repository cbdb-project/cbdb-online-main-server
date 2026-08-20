<?php

namespace App\Console\Commands;

use App\Support\SqliteReleaseTables;
use Illuminate\Console\Command;
use PDO;

/**
 * 釋出產物自檢（#1251）：確認一個 SQLite 檔的內容**就是對外釋出契約允許的範圍**。
 *
 * 為什麼需要這個命令，而不是只靠 `scripts/export-daily-sqlite.sh` 的 `TABLES=(...)` allowlist：
 * 那份清單是「靜態意圖」，而守它的 `SqliteReleaseAllowlistTest` 檢查的也只是腳本文字。任何一層
 * 間接（`TABLES+=`、換一個變數名餵 for 迴圈、`source` 外部檔、`eval`）都可能讓實際匯出範圍與清單
 * 不一致，靜態檢查追不完。這個命令檢查的是**真正要上傳的那個檔案**，所以繞不過去。
 *
 * 檢查四件事（任何一件不過就以非零結束碼收尾）：
 *  1. 不含帳號／憑證表（名單取自 ExportMysqlToSqlite::CREDENTIAL_TABLES，維持單一真源）。
 *     單獨列出只為了給出明確的錯誤訊息——它們本來也不在下面那份 allowlist 裡。
 *  2. 每個表／檢視名都必須出現在 SqliteReleaseTables::PUBLIC_TABLES 裡。這一條才是擋住 `audit_log`
 *     （email＋登入 IP／UA）、`operations`、`nl_query_logs`、`ai_fill_logs` 這類營運／個資表的東西
 *     ——它們不在憑證名單裡，只靠第 1 條會整批漏掉。
 *     刻意用**精確集合**而不是「全大寫就算公開」的形狀判準：形狀判準會放行任何日後新增的大寫表，
 *     而 `AUDIT_LOG_ARCHIVE`、`USER_LOGIN_EVENTS` 這種名字一樣全大寫、卻是個資。
 *  3. 不含檢視（view）。檢視也會被下面的表數算進去，若不擋掉，78 個「與 allowlist 同名的空檢視」
 *     就能冒充一份完整產物。
 *  4. 表數不得少於 `--min-tables`（預設就是 allowlist 的長度）。空檔與被截斷的產物都是**真實存在
 *     的合法 SQLite**（0 張表），光看「有沒有壞表名」會直接放行。2＋3＋4 合起來等於
 *     「產物的表集合恰好是那 78 張」。
 *
 * 一律 fail-closed：檔案不存在、不是普通檔案、開不起來、讀不到表清單、`--min-tables` 不是數字，
 * 全部視為失敗。特別注意「檔案不存在」——`new PDO("sqlite:/no/such/file")` 會**建立**一個空資料庫
 * 然後順利回報「0 張表、沒問題」，那正是最危險的假通過。
 */
class AssertSqliteReleaseScope extends Command {
    protected $signature = 'cbdb:assert-sqlite-release-scope
                            {file : 要檢查的 SQLite 產物路徑}
                            {--min-tables= : 產物至少要有幾張表（預設＝allowlist 長度）}';

    protected $description = '檢查 SQLite 產物只含公開 CBDB 資料表（釋出前自檢，見 #1251）';

    /** SQLite 自己的內部物件（sqlite_sequence 等），不屬於釋出範圍也不計入表數。 */
    private const INTERNAL_PREFIX = 'sqlite_';

    public function handle(): int {
        $file = (string) $this->argument('file');

        // 預設就是「完整的 78 張」：漏傳這個選項不該讓下界掉到 1。上傳邊界那次呼叫（
        // weekly-sqlite-sync.sh）就沒傳，若預設是 1，把產物換成只含一張表的檔案也會通過。
        $minTables = $this->option('min-tables');
        if ($minTables === null) {
            $minTables = count(SqliteReleaseTables::PUBLIC_TABLES);
        } else {
            if (preg_match('/^\d+$/', (string) $minTables) !== 1) {
                $this->error(sprintf('✗ --min-tables 必須是非負整數，收到: %s', $minTables));

                return self::FAILURE;
            }
            $minTables = (int) $minTables;
        }

        // fail-closed：不存在就直接失敗，**絕不**讓 PDO 順手建一個空檔然後假通過。
        if (!is_file($file)) {
            $this->error(sprintf('✗ 找不到檔案或不是普通檔案: %s', $file));

            return self::FAILURE;
        }

        try {
            $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $objects = $pdo->query("SELECT name, type FROM sqlite_master WHERE type IN ('table','view')")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $this->error(sprintf('✗ 無法讀取 SQLite 檔（%s）: %s', $file, $e->getMessage()));

            return self::FAILURE;
        }

        $objects = array_values(array_filter(
            array_map(
                fn ($row) => ['name' => (string) $row['name'], 'type' => (string) $row['type']],
                $objects
            ),
            fn ($object) => !str_starts_with(strtolower($object['name']), self::INTERNAL_PREFIX)
        ));

        $names = array_map(fn ($object) => $object['name'], $objects);

        $credential = array_keys(ExportMysqlToSqlite::CREDENTIAL_TABLES);
        $credentialHits = array_values(array_filter(
            $names,
            fn ($name) => in_array(strtolower($name), $credential, true)
        ));

        if ($credentialHits !== []) {
            $this->error('✗ 產物含帳號／憑證表: ' . implode(', ', $credentialHits));
            $this->error('  釋出檔不得包含這些表，請檢查 scripts/export-daily-sqlite.sh 的 TABLES allowlist');
            $this->error('  釋出契約見 docs/SQLITE_DATA_RELEASE.md');

            return self::FAILURE;
        }

        $notPublic = array_values(array_filter(
            $names,
            fn ($name) => !in_array($name, SqliteReleaseTables::PUBLIC_TABLES, true)
        ));

        if ($notPublic !== []) {
            $this->error('✗ 產物含 allowlist 以外的表: ' . implode(', ', $notPublic));
            $this->error('  釋出檔只能有 App\Support\SqliteReleaseTables::PUBLIC_TABLES 列出的 CBDB 表；'
                . 'Laravel 與應用自己的表（audit_log、operations、nl_query_logs、migrations…）'
                . '含個人資料或與公開資料集無關');
            $this->error('  若這是刻意擴大釋出範圍，請同時更新那份常數與 scripts/export-daily-sqlite.sh 的 TABLES');
            $this->error('  釋出契約見 docs/SQLITE_DATA_RELEASE.md');

            return self::FAILURE;
        }

        // 釋出檔只該有資料表。檢視同樣會被 --min-tables 算進去，若不擋掉，78 個「與 allowlist 同名
        // 的空檢視」就能冒充一份完整產物（codex 覆核時指出）。
        $views = array_values(array_map(
            fn ($object) => $object['name'],
            array_filter($objects, fn ($object) => strtolower($object['type']) === 'view')
        ));

        if ($views !== []) {
            $this->error('✗ 產物含檢視（view）: ' . implode(', ', $views));
            $this->error('  釋出檔只能有資料表；檢視即使名稱在 allowlist 內也不算釋出內容');

            return self::FAILURE;
        }

        if (count($names) < $minTables) {
            $this->error(sprintf(
                '✗ 產物只有 %d 張表，少於預期的 %d 張——匯出可能中途失敗或產物是空檔',
                count($names),
                $minTables
            ));

            return self::FAILURE;
        }

        $this->info(sprintf('✓ 已檢查 %d 張表，全部在釋出 allowlist 內', count($names)));

        return self::SUCCESS;
    }
}
