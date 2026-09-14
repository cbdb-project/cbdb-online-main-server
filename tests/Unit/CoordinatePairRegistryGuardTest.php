<?php

namespace Tests\Unit;

use App\Support\CoordinatePairNormalizer;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 機械把關：`CoordinatePairNormalizer::PAIRS` 不得與各表的寫入白名單／實際 schema 漂移。
 *
 * 為什麼需要：正規化會在「經緯度必須成對」時**補寫呼叫端沒送的那一欄**，而各 handler
 * 的 `allowed_fields` 收窄發生在掛鉤**之前**——補寫的欄位不會再過白名單。於是兩份清單
 * 一旦不一致，後果分兩種、都沒有人會發出聲音：
 *
 *  1. 座標欄不在 `allowed_fields` 裡（例如哪天把 `ADDR_CODES` 的白名單縮回去，像
 *     2026-09 之前那樣只開一欄）：補寫的欄位會繞過白名單直接進 `->update()`，
 *     而呼叫端自己送同一欄卻會收到 422「包含不允許更新的欄位」。同一個欄位兩套規則。
 *  2. 登記的欄名根本不存在於資料表（打錯字、或欄位改名）：`normalizeRow()` 會在
 *     `$data` 裡塞一個不存在的欄，落庫時變成資料庫層的 1054 例外（500），而不是
 *     一個誠實的 422。
 *
 * 這支測試的取向與 `VariantReplaceJoinKeyGuardTest`／`VariantReplaceHookCoverageTest`
 * 相同：讓漂移在 CI 就紅，而不是在生產環境變成一個 500。
 */
class CoordinatePairRegistryGuardTest extends TestCase {
    /**
     * 各表的寫入白名單來源。兩份 config 都要查：`code_table_mutations` 是 update 端、
     * `code_table_writes` 是 create 端，而 create／update 的掛鉤點是對稱的。
     *
     * @return array<string, array<int, string>> 表名（大寫）→ allowed_fields
     */
    private function allowedFieldsByConfig(): array {
        $result = [];

        foreach ((array) config('code_table_mutations.tables', []) as $def) {
            $table = strtoupper((string) ($def['table'] ?? ''));
            if ($table !== '') {
                $result['code_table_mutations (update)'][$table]
                    = array_merge($result['code_table_mutations (update)'][$table] ?? [], (array) ($def['allowed_fields'] ?? []));
            }
        }

        foreach ((array) config('code_table_writes.tables', []) as $key => $def) {
            $table = strtoupper((string) ($def['table'] ?? $key));
            if ($table !== '') {
                $result['code_table_writes (create)'][$table]
                    = array_merge($result['code_table_writes (create)'][$table] ?? [], (array) ($def['allowed_fields'] ?? []));
            }
        }

        return $result;
    }

    #[Test]
    public function testEveryRegisteredCoordinateColumnIsWritableInEveryConfigThatCoversItsTable(): void {
        // **逐份 config 檢查，不可以先合併。** 合併之後，只從 `code_table_mutations`
        // （update 端）拿掉 `y_coord` 而 `code_table_writes`（create 端）還留著，
        // 這支守衛就看不見了——而那正是「同一個欄位兩套規則」真正會發生的形狀：
        // update 端補寫的伙伴欄繞過白名單進 `->update()`，呼叫端自己送同一欄卻被 422 擋下。
        $byConfig = $this->allowedFieldsByConfig();
        $this->assertNotEmpty($byConfig, '兩份 config 都讀不到，這支守衛已經失效。');

        $checked = 0;
        foreach ($byConfig as $configLabel => $allowedByTable) {
            foreach (CoordinatePairNormalizer::registeredTables() as $table) {
                if (!isset($allowedByTable[$table])) {
                    // 這份 config 沒有覆蓋這張表就跳過（`ADDRESSES` 只有 Codes UI 那條路，
                    // 兩份 config 都沒有它；那條路徑送整列表單、不做欄位白名單）。
                    continue;
                }

                $allowedLower = array_map('strtolower', $allowedByTable[$table]);

                foreach (CoordinatePairNormalizer::pairsFor($table) as $pair) {
                    foreach ($pair as $column) {
                        $this->assertContains(
                            strtolower($column),
                            $allowedLower,
                            $table.'.'.$column.' 登記在 CoordinatePairNormalizer::PAIRS，但不在 '
                            .$configLabel.' 的 allowed_fields 裡。正規化會補寫這一欄而且繞過白名單，'
                            .'呼叫端自己送卻會被 422 擋下——同一個欄位兩套規則。'
                            .'請把它加進該份白名單，或把這一對從 PAIRS 移除。'
                        );
                        ++$checked;
                    }
                }
            }
        }

        // ADDR_CODES 在兩份 config 裡各有 2 欄，所以至少要檢查到 4 次。寫死這個下限是為了
        // 讓「config 結構改了、迴圈靜默一次都沒跑」變成紅燈而不是綠燈。
        $this->assertGreaterThanOrEqual(
            4,
            $checked,
            '檢查次數低於預期，表示 PAIRS 與兩份 config 的對應關係斷了——這支守衛已經失效。'
        );
    }

    #[Test]
    public function testEveryRegisteredTableAndColumnActuallyExists(): void {
        foreach (CoordinatePairNormalizer::registeredTables() as $table) {
            if (!Schema::hasTable($table)) {
                // 測試環境（SQLite）只建立各測試自己需要的表，所以「表不存在」在這裡
                // 不是失敗。真正要擋的是「表在、但登記的欄名不在」那種打錯字。
                continue;
            }

            foreach (CoordinatePairNormalizer::pairsFor($table) as $pair) {
                foreach ($pair as $column) {
                    $this->assertTrue(
                        Schema::hasColumn($table, $column),
                        $table.'.'.$column.' 登記在 CoordinatePairNormalizer::PAIRS，但這張表沒有這一欄。'
                        .'正規化會在待寫入的列裡塞一個不存在的欄位，落庫時是 1054（500）而不是 422。'
                    );
                }
            }
        }

        $this->assertNotEmpty(CoordinatePairNormalizer::registeredTables());
    }

    #[Test]
    public function testAddrCodesIsStillRegistered(): void {
        // 這張表就是這整條機制存在的原因（316 列 0,0）。把它從 PAIRS 拿掉等於關掉功能，
        // 所以釘死它，不要讓「清理 registry」順手移除。
        $this->assertContains('ADDR_CODES', CoordinatePairNormalizer::registeredTables());
        $this->assertSame([['x_coord', 'y_coord']], CoordinatePairNormalizer::pairsFor('ADDR_CODES'));
    }

    #[Test]
    public function testNoOtherTableInTheSchemaCarriesCoordinateColumnsUnregistered(): void {
        // 反向把關：新增一張帶經緯度的表卻忘了登記，這裡要紅。
        // 不查 information_schema（那會綁死 MySQL，而測試跑 SQLite），改掃 migration 原始碼
        // ——這條規則要擋的正是「新 migration 加了 x_coord 卻沒動 PAIRS」。
        //
        // **四種寫法都要掃到**（raw SQL 帶／不帶反引號、`Schema::create`、`Schema::table`）。
        // 第一版只認 `CREATE TABLE \`X\` (` 這一種，於是有兩個盲點，
        // 而且都不是假設：
        //  (a) 本庫有 17 處 `Schema::create()` 與多處 `Schema::table()`（後者是「給既有表
        //      **加**座標欄」的寫法，至少和新建一張表一樣可能發生），那是新 migration 的
        //      正常寫法，舊版一個都不匹配
        //      ——`2025_11_17_100000_drop_place_codes_table.php` 的 down() 裡就有
        //      `$table->double('x_coord')`，舊版守衛完全看不見；
        //  (b) 有 8 處 raw `CREATE TABLE` 不帶反引號（`CREATE TABLE CBDB__TRAD_SIMP_MAP (`、
        //      `CREATE TABLE EVENTS_DATA_new (` 等），同樣漏掉。
        $registered = array_map('strtolower', CoordinatePairNormalizer::registeredTables());

        // 已移除的表：CREATE TABLE 仍留在原始匯入檔與 down() 裡，但表本身不存在。
        $dropped = ['place_codes'];

        $found = [];
        foreach (glob(database_path('migrations').'/*.php') as $file) {
            $source = file_get_contents($file);

            // (1) raw SQL，反引號可有可無
            preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\((.*?)\n\s*\)/s', $source, $raw, PREG_SET_ORDER);
            foreach ($raw as $m) {
                $found[$m[1]] = ($found[$m[1]] ?? '').$m[2];
            }

            // (2) Schema::create('X', ...) 與 Schema::table('X', ...)：抓表名後把該檔剩餘
            //     內容一起看。`Schema::table()` 是「給既有表**加**座標欄」的寫法，至少和
            //     新建一張表一樣可能發生，第一版漏掉它。
            //     刻意不精確切出 closure 主體——寧可**過度**歸因（把整個檔案的內容算給這張表）
            //     也不要漏掉：這支守衛寧可誤報一次讓人來看，也不要靜默放過一張新表。
            preg_match_all("/Schema::(?:create|table)\(\s*['\"]([A-Za-z0-9_]+)['\"]/", $source, $schema, PREG_SET_ORDER);
            foreach ($schema as $m) {
                $found[$m[1]] = ($found[$m[1]] ?? '').$source;
            }
        }

        $this->assertNotEmpty($found, 'migration 一張表都沒掃到，這支守衛已經失效。');

        $unregistered = [];
        foreach ($found as $table => $body) {
            if (stripos($body, 'x_coord') === false && stripos($body, 'y_coord') === false) {
                continue;
            }
            $lower = strtolower($table);
            if (in_array($lower, $registered, true) || in_array($lower, $dropped, true)) {
                continue;
            }
            $unregistered[] = $table;
        }
        sort($unregistered);

        $this->assertSame(
            [],
            $unregistered,
            '這些資料表看起來有經緯度欄但沒登記在 CoordinatePairNormalizer::PAIRS：'
            .implode('、', $unregistered)
            .'。帶經緯度的新表必須同步登記，否則它的寫入端不受歸零守衛保護。'
            .'（若這是誤報——例如 Schema::create 的歸因把同檔其他表的欄位算進來了——'
            .'請縮小這支測試的歸因範圍，不要直接把表加進排除清單。）'
        );

        // 正向敏感度：這支測試真的看到了那兩張已登記的座標表，而不是掃了一堆卻什麼都沒認出來。
        $coordinateBearing = [];
        foreach ($found as $table => $body) {
            if (stripos($body, 'x_coord') !== false) {
                $coordinateBearing[] = strtolower($table);
            }
        }
        $this->assertContains('addr_codes', $coordinateBearing);
        $this->assertContains('addresses', $coordinateBearing);
    }
}
