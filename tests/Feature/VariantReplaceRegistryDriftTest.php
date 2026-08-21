<?php

namespace Tests\Feature;

use App\Support\VariantReplaceScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 已知表 registry 的漂移守衛（計畫 D2／S1）。
 *
 * **為什麼要獨立一個 Feature 測試而不是放在 VariantReplaceScopeTest 裡**：那支測試
 * 沒有 RefreshDatabase，`CreatesApplication` 給的是全新的 :memory: SQLite、從不跑
 * migration，所以 `Schema::getTables()` 只會回傳測試自己在 setUp() 建的那幾張合成表
 * ——而它們當然全是已知表 ⇒ 守衛永遠綠、永遠抓不到「新 migration 建了 CBDB 表卻忘了
 * 登記進四份 registry」。這正是本檔要修的假綠。
 *
 * 掛 RefreshDatabase 讓 schema 真的來自 database/migrations，守衛才有意義。
 *
 * 邊界：它抓的是「新 migration 忘了登記」，**抓不到只存在於 prod 的表**
 * （D2 的 caveat；prod-only 欄位另見 S0 的 prod schema 比對）。
 */
class VariantReplaceRegistryDriftTest extends TestCase {
    use RefreshDatabase;

    /**
     * 明文的「非 CBDB 資料表」清單：框架表與紀錄／帳號表。
     *
     * 落在這裡的表**不需要**登記進四份 registry，因為它們本來就不該被落地替換
     * （紀錄的語義是「當時實際發生了什麼」，改寫等於偽造紀錄）。
     *
     * @var array<int,string>
     */
    private const NON_CBDB_TABLES = [
        // Laravel 框架／基礎設施
        'migrations',
        'password_resets',
        'personal_access_tokens',
        // 帳號與紀錄類（同時也在 VariantReplaceScope::EXCLUDED_TABLES）
        'users',
        'audit_log',
        'operations',
        'nl_query_logs',
        'ai_fill_logs',
        // 專案自建的派生索引（sidecar）：內容由來源表重算，不是錄入端
        'person_change_index',
        // Access 時代遺留的 metadata 表：描述「schema 長什麼樣」而非 CBDB 學術資料，
        // 全庫零引用（計畫「不在本階段範圍內」已記錄）
        'copytables',
        'copytablesdefault',
        'copymissingtables',
        'tablesfields',
        'tablesfieldschanges',
        'foreignkeys',
        // 簡繁標籤對照表（對照／映射性質；全庫零引用，不可達）
        'formlabels',
    ];

    /**
     * live schema 的每一張表，要嘛在已知 CBDB 表聯集內、要嘛在明文的非 CBDB 清單內。
     *
     * 漏登記的後果是**靜默**的：D2 是 fail-closed，未知表一律不替換，所以新增一張
     * CBDB 表卻忘了登記，它的所有文本欄就會安靜地跳過落地替換，沒有任何錯誤。
     */
    #[Test]
    public function testEveryMigrationTableIsEitherKnownOrExplicitlyNonCbdb(): void {
        $tables = $this->liveTableNames();

        // 先確認這支測試真的看到了 migration 建出來的 schema，而不是幾張合成表。
        // 沒有這個下限斷言，本測試會退化成上一版那種假綠。
        $this->assertGreaterThan(
            50,
            count($tables),
            'live schema 只有 '.count($tables).' 張表，看起來 migration 沒有跑；'
                .'本守衛必須在真實 schema 上執行才有意義'
        );

        $nonCbdb = array_map('strtolower', self::NON_CBDB_TABLES);
        $unclassified = [];

        foreach ($tables as $name) {
            if (in_array($name, $nonCbdb, true)) {
                continue;
            }
            if (VariantReplaceScope::isKnownDataTable($name)) {
                continue;
            }
            $unclassified[] = $name;
        }

        sort($unclassified);

        $this->assertSame(
            [],
            $unclassified,
            "下列表既不在已知 CBDB 表聯集（config/codes.php ∪ CompositePrimaryKey::SCHEMAS "
                ."∪ config/code_table_writes.php ∪ config/code_table_mutations.php）內、"
                ."也不在本測試的非 CBDB 白名單內。\n"
                ."因為 VariantReplaceScope 是 fail-closed，它們的文本欄會**靜默**跳過落地替換：\n"
                .implode("\n", $unclassified)
        );
    }

    /**
     * 反向守衛：非 CBDB 白名單不該累積已經不存在的表名（否則它會慢慢變成一份
     * 「什麼都豁免」的清單，讓上面那條斷言失去效力）。
     */
    #[Test]
    public function testNonCbdbWhitelistHasNoStaleEntries(): void {
        $tables = $this->liveTableNames();
        $stale = [];

        foreach (self::NON_CBDB_TABLES as $name) {
            if (!in_array(strtolower($name), $tables, true)) {
                $stale[] = $name;
            }
        }

        $this->assertSame(
            [],
            $stale,
            '非 CBDB 白名單含有 live schema 裡不存在的表，請移除：'.implode(', ', $stale)
        );
    }

    /**
     * 白名單與「已知表」必須互斥。
     *
     * 迴圈是**先**比對白名單才問 isKnownDataTable()，所以清單裡的表若日後被登記進四份
     * registry，它就會靜默進入替換範圍、而守衛照樣全綠。`formlabels` 正是最可能發生的
     * 那個（簡繁標籤對照表，目前完全靠這份白名單擋著）。
     *
     * 這條斷言把兩者釘成互斥：白名單項一旦被登記成已知表，就必須同時在
     * `VariantReplaceScope::EXCLUDED_TABLES` 內，否則紅。
     */
    #[Test]
    public function testWhitelistedTablesAreEitherUnknownOrExplicitlyExcluded(): void {
        $excluded = array_map('strtolower', VariantReplaceScope::EXCLUDED_TABLES);
        $leaking = [];

        foreach (self::NON_CBDB_TABLES as $name) {
            if (!VariantReplaceScope::isKnownDataTable($name)) {
                continue;
            }
            if (in_array(strtolower($name), $excluded, true)) {
                continue;
            }
            $leaking[] = $name;
        }

        $this->assertSame(
            [],
            $leaking,
            '下列表同時出現在本測試的非 CBDB 白名單與已知 CBDB 表聯集裡，'
                .'卻沒有登記在 VariantReplaceScope::EXCLUDED_TABLES ⇒ '
                .'它們的文本欄會被落地替換，而本守衛卻因白名單而放過：'.implode(', ', $leaking)
        );
    }

    /**
     * TEXT_TYPES 漂移守衛（真實 schema 版）：live schema 裡每一個看起來是文本型別的
     * `type_name` 都必須在 TEXT_TYPES 內，否則該欄會靜默逃出替換範圍。
     *
     * ⚠️ 這條在 SQLite 上抓不到 enum／json（Laravel 把它們編成 varchar／text），
     * 那個缺口由 VariantReplaceScopeTest 的 migration 原始碼掃描補上。
     */
    #[Test]
    public function testTextTypesCoversEveryTextTypeInLiveSchema(): void {
        $observed = [];

        foreach ($this->liveTableNames() as $table) {
            try {
                $columns = Schema::getColumns($table);
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($columns as $column) {
                $type = strtolower((string) ($column['type_name'] ?? ''));
                if ($type !== '') {
                    $observed[$type] = true;
                }
            }
        }

        $this->assertNotEmpty($observed, 'live schema 應該要有欄位型別');

        $textLike = array_filter(array_keys($observed), function (string $type): bool {
            return str_contains($type, 'char') || str_contains($type, 'text');
        });

        foreach ($textLike as $type) {
            $this->assertContains(
                $type,
                VariantReplaceScope::TEXT_TYPES,
                "型別 {$type} 看起來是文本型別但不在 VariantReplaceScope::TEXT_TYPES 內，"
                    .'該型別的欄位會靜默逃出落地替換範圍'
            );
        }
    }

    /** @return array<int,string> 小寫表名 */
    private function liveTableNames(): array {
        $names = [];

        foreach (Schema::getTables() as $table) {
            $name = strtolower((string) ($table['name'] ?? ''));
            if ($name !== '' && !str_starts_with($name, 'sqlite_')) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
