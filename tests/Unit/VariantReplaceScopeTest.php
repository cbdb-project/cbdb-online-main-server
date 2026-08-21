<?php

namespace Tests\Unit;

use App\Support\CompositePrimaryKey;
use App\Support\VariantReplaceScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VariantReplaceScope 測試（設計見 docs/CHAR_VARIANT_MAP_TEXT_COLUMN_ROLLOUT_PLAN.md）。
 *
 * 手動建合成表（比照 CharVariantMapServiceTest 慣例）。有些型別（date／decimal／blob）
 * 全庫實際不存在，只能用合成表斷言。
 */
class VariantReplaceScopeTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        VariantReplaceScope::reset();

        // BIOG_MAIN 是已知表（在 config/codes.php 與 CompositePrimaryKey 裡），
        // 這裡建一個涵蓋各種型別的簡化版。
        Schema::dropIfExists('BIOG_MAIN');
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->string('c_name_chn', 255)->nullable();
            $table->string('c_surname_chn', 255)->nullable();
            $table->string('c_mingzi_chn', 255)->nullable();
            $table->string('c_name', 255)->nullable();
            $table->string('c_surname', 255)->nullable();
            $table->string('c_mingzi', 255)->nullable();
            $table->string('c_name_proper', 255)->nullable();
            $table->string('c_name_rm', 255)->nullable();
            $table->string('c_index_year_type_code', 255)->nullable();
            $table->string('c_tribe', 255)->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_modified_by', 255)->nullable();
            $table->integer('c_index_year')->nullable();
            $table->date('c_some_date')->nullable();
            $table->decimal('c_some_decimal', 8, 2)->nullable();
            $table->binary('c_some_blob')->nullable();
            $table->char('c_some_char', 1)->nullable();
        });

        Schema::dropIfExists('ALTNAME_DATA');
        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->string('c_alt_name_chn', 255)->nullable();
            $table->string('c_alt_name', 255)->nullable();
            $table->string('c_alt_name_pinyin', 255)->nullable();
            $table->text('c_notes')->nullable();
        });

        Schema::dropIfExists('EVENT_CODES');
        Schema::create('EVENT_CODES', function (Blueprint $table) {
            $table->integer('c_event_code');
            $table->string('c_event_name_chn', 255)->nullable();
        });

        Schema::dropIfExists('char_variant_map');
        Schema::create('char_variant_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_variant_char', 10);
            $table->string('c_reference_char', 10);
            $table->string('c_notes', 255)->nullable();
        });

        Schema::dropIfExists('pinyin');
        Schema::create('pinyin', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('c_chn', 10);
            $table->string('c_pinyin', 255)->nullable();
        });

        Schema::dropIfExists('ENTRY_TYPES');
        Schema::create('ENTRY_TYPES', function (Blueprint $table) {
            $table->string('c_entry_type', 255);
            $table->string('c_entry_type_parent_id', 255)->nullable();
            $table->string('c_entry_type_desc_chn', 255)->nullable();
        });

        Schema::dropIfExists('STATUS_TYPES');
        Schema::create('STATUS_TYPES', function (Blueprint $table) {
            $table->string('c_status_type_code', 255);
            $table->string('c_status_type_parent_code', 255)->nullable();
            $table->string('c_status_type_chn', 255)->nullable();
        });

        VariantReplaceScope::reset();
    }

    // ─────────────────────────── 型別判定 ───────────────────────────

    #[Test]
    public function testTextTypesAreInScopeAndNonTextTypesAreNot(): void {
        // varchar / char / text 類 → 在範圍內
        $this->assertSame('lenient', VariantReplaceScope::modeFor('BIOG_MAIN', 'c_tribe'), 'varchar 應在範圍內');
        $this->assertSame('lenient', VariantReplaceScope::modeFor('BIOG_MAIN', 'c_notes'), 'text 應在範圍內');
        $this->assertSame('lenient', VariantReplaceScope::modeFor('BIOG_MAIN', 'c_some_char'), 'char 應在範圍內');

        // 非文本型別 → 不替換
        $this->assertNull(VariantReplaceScope::modeFor('BIOG_MAIN', 'c_index_year'), 'integer 不該在範圍內');
        $this->assertNull(VariantReplaceScope::modeFor('BIOG_MAIN', 'c_some_date'), 'date 不該在範圍內');
        $this->assertNull(VariantReplaceScope::modeFor('BIOG_MAIN', 'c_some_decimal'), 'decimal 不該在範圍內');
        $this->assertNull(VariantReplaceScope::modeFor('BIOG_MAIN', 'c_some_blob'), 'blob 不該在範圍內');
    }

    #[Test]
    public function testUnknownColumnIsNotInScope(): void {
        $this->assertNull(VariantReplaceScope::modeFor('BIOG_MAIN', 'c_does_not_exist'));
    }

    /**
     * TEXT_TYPES 對**合成表**的型別涵蓋（本檔不跑 migration，只看 setUp() 建的那幾張）。
     *
     * 真正的漂移守衛在 `Tests\Feature\VariantReplaceRegistryDriftTest`（掛 RefreshDatabase、
     * 對真實 schema 執行）——放在這裡會是假綠，因為這裡只有合成表。
     * enum／json 的缺口另由 testEveryNonTextColumnTypeInMigrationsIsClassified() 掃原始碼補上。
     */
    #[Test]
    public function testTextTypesCoversEveryObservedTextType(): void {
        $observed = [];
        foreach (['BIOG_MAIN', 'ALTNAME_DATA', 'EVENT_CODES', 'ENTRY_TYPES'] as $table) {
            foreach (Schema::getColumns($table) as $column) {
                $observed[strtolower((string) $column['type_name'])] = true;
            }
        }

        $textLike = array_filter(array_keys($observed), function (string $type): bool {
            return str_contains($type, 'char') || str_contains($type, 'text');
        });

        foreach ($textLike as $type) {
            $this->assertContains(
                $type,
                VariantReplaceScope::TEXT_TYPES,
                "型別 {$type} 看起來是文本型別但不在 TEXT_TYPES 內，會靜默逃出替換範圍"
            );
        }
    }

    /**
     * 漂移守衛（原始碼側）：migrations 出現 enum／json／set 欄位時，必須是**已分類**的。
     *
     * 這條補上 SQLite 抓不到的缺口——`->enum()`／`->json()` 在 SQLite 被編成 varchar／text，
     * 型別守衛會全綠，而 MariaDB 端該欄真的是 enum／json、真的逃出 TEXT_TYPES 的判定。
     *
     * 判準是「有沒有被分類」而不是「有沒有出現」：既有的 audit_log json 欄是合法的
     * （整表已排除），新增的則必須先決定它的歸屬才能過這關。
     */
    #[Test]
    public function testEveryNonTextColumnTypeInMigrationsIsClassified(): void {
        // 已分類的既有案例：migration 檔名 => 理由
        $classified = [
            '2026_02_08_000000_create_audit_log_table.php'
                => 'audit_log 整表在 EXCLUDED_TABLES（紀錄類資料，改寫等於偽造紀錄）',
        ];

        $files = glob(database_path('migrations/*.php')) ?: [];
        $unclassified = [];

        foreach ($files as $file) {
            $name = basename($file);
            $source = (string) file_get_contents($file);

            $hits = [];
            foreach (['->enum(', '->json(', '->jsonb(', '->set('] as $needle) {
                if (str_contains($source, $needle)) {
                    $hits[] = $needle;
                }
            }

            if ($hits === [] || array_key_exists($name, $classified)) {
                continue;
            }

            $unclassified[] = $name.' 使用了 '.implode('／', $hits);
        }

        $this->assertSame(
            [],
            $unclassified,
            "新增 enum／json／set 欄位時，必須先決定它在 VariantReplaceScope 的分類"
                ."（SQLite 會把它們編成 varchar／text，型別守衛抓不到，該欄會靜默逃出範圍）。
"
                ."確認歸屬後，把 migration 檔名加進本測試的 \$classified 並寫理由：
"
                .implode("
", $unclassified)
        );
    }

    // ─────────────────────── 預設方向與 strict 例外 ───────────────────────

    /**
     * D4 的核心：**預設是 lenient（全量）**，不是 strict。
     *
     * 寫反會造成「全庫靜默少替換」這種極難察覺的退化——每個欄位看起來都有在替換，
     * 只是少了幾筆規則，不會有任何報錯。
     */
    #[Test]
    public function testDefaultModeIsLenientNotStrict(): void {
        $this->assertSame(
            'lenient',
            VariantReplaceScope::modeFor('EVENT_CODES', 'c_event_name_chn'),
            '未登記在 STRICT_COLUMNS 的文本欄必須拿到 lenient'
        );
    }

    #[Test]
    public function testStrictAndLenientAreDecidedPerColumnNotPerTable(): void {
        // 同一列 BIOG_MAIN：姓名欄 strict、c_notes lenient
        $this->assertSame('strict', VariantReplaceScope::modeFor('BIOG_MAIN', 'c_surname_chn'));
        $this->assertSame('lenient', VariantReplaceScope::modeFor('BIOG_MAIN', 'c_notes'));

        // 同一列 ALTNAME_DATA：別名欄 strict、c_notes lenient
        $this->assertSame('strict', VariantReplaceScope::modeFor('ALTNAME_DATA', 'c_alt_name_chn'));
        $this->assertSame('lenient', VariantReplaceScope::modeFor('ALTNAME_DATA', 'c_notes'));
    }

    /** 登記完整性：抽驗擋不住漏登，必須逐欄斷言。 */
    #[Test]
    public function testEveryStrictColumnIsRegistered(): void {
        foreach (['c_name_chn', 'c_surname_chn', 'c_mingzi_chn'] as $column) {
            $this->assertSame('strict', VariantReplaceScope::modeFor('BIOG_MAIN', $column), "BIOG_MAIN.{$column} 應為 strict");
        }
        $this->assertSame('strict', VariantReplaceScope::modeFor('ALTNAME_DATA', 'c_alt_name_chn'));
    }

    /** 13 個拉丁人名欄逐欄斷言為排除（同理，抽驗擋不住漏排）。 */
    #[Test]
    public function testEveryLatinNameColumnIsExcluded(): void {
        $biogMain = [
            'c_surname', 'c_mingzi', 'c_name',
            'c_surname_proper', 'c_mingzi_proper', 'c_name_proper',
            'c_surname_rm', 'c_mingzi_rm', 'c_name_rm',
        ];
        foreach ($biogMain as $column) {
            $this->assertContains(
                $column,
                VariantReplaceScope::EXCLUDED_COLUMNS['BIOG_MAIN'],
                "BIOG_MAIN.{$column} 必須登記為排除"
            );
        }

        foreach (['c_alt_name', 'c_alt_name_pinyin', 'c_alt_name_pinyin2', 'c_alt_name_pinyin3'] as $column) {
            $this->assertContains(
                $column,
                VariantReplaceScope::EXCLUDED_COLUMNS['ALTNAME_DATA'],
                "ALTNAME_DATA.{$column} 必須登記為排除"
            );
        }

        // 存在於合成表的那幾個，實際查一次 modeFor()
        foreach (['c_name', 'c_surname', 'c_mingzi', 'c_name_proper', 'c_name_rm'] as $column) {
            $this->assertNull(VariantReplaceScope::modeFor('BIOG_MAIN', $column), "BIOG_MAIN.{$column} 不該被替換");
        }
        foreach (['c_alt_name', 'c_alt_name_pinyin'] as $column) {
            $this->assertNull(VariantReplaceScope::modeFor('ALTNAME_DATA', $column), "ALTNAME_DATA.{$column} 不該被替換");
        }
    }

    // ─────────────────────── 大小寫與 fail-closed ───────────────────────

    /**
     * 大小寫不敏感。這不是假想：config/codes.php 用小寫 char_variant_map／pinyin，
     * CBDB 表用全大寫，客戶端還可能傳任意大小寫。漏中 strict 清單 ⇒ 靜默降級成 lenient
     * ⇒ 人名裡的「峯」被改寫，正是 D4 明令禁止的事。
     */
    #[Test]
    public function testTableAndColumnMatchingIsCaseInsensitive(): void {
        $this->assertSame('strict', VariantReplaceScope::modeFor('biog_main', 'C_SURNAME_CHN'));
        $this->assertSame('strict', VariantReplaceScope::modeFor('BiOg_MaIn', 'c_Surname_Chn'));
        $this->assertNull(VariantReplaceScope::modeFor('PINYIN', 'C_CHN'), 'pinyin.c_chn 大小寫變體也必須排除');
    }

    /** D2 fail-closed：未知表一律不替換。 */
    #[Test]
    public function testUnknownTableIsFailClosed(): void {
        $this->assertFalse(VariantReplaceScope::isKnownDataTable('personal_access_tokens'));
        $this->assertFalse(VariantReplaceScope::isKnownDataTable('DROP TABLE students;--'));
        $this->assertNull(VariantReplaceScope::modeFor('personal_access_tokens', 'abilities'));
        $this->assertNull(VariantReplaceScope::modeFor('totally_made_up', 'c_name_chn'));
    }

    /** 已知表判定必須涵蓋四份 registry（尤其形狀不同的 code_table_mutations）。 */
    #[Test]
    public function testKnownDataTableCoversAllFourRegistries(): void {
        // codes.php
        $this->assertTrue(VariantReplaceScope::isKnownDataTable('BIOG_MAIN'));
        // CompositePrimaryKey::SCHEMAS
        $this->assertTrue(VariantReplaceScope::isKnownDataTable('ALTNAME_DATA'));
        // code_table_writes.php
        $this->assertTrue(VariantReplaceScope::isKnownDataTable('TEXT_CODES'));
        // code_table_mutations.php（list of maps，表名在 'table' 值）
        $this->assertTrue(VariantReplaceScope::isKnownDataTable('NIAN_HAO'));
        $this->assertTrue(VariantReplaceScope::isKnownDataTable('GANZHI_CODES'));
    }

    /**
     * code_table_mutations 的形狀陷阱：它是 list of maps、表名在 'table' 值。
     * 誤用 array_keys() 會得到 "0".."13" 這些假表名並漏掉 14 張真表——今天恰好被掩蓋
     * （那 14 張也都在 codes.php），純屬巧合，所以要顯式斷言。
     */
    #[Test]
    public function testCodeTableMutationsRegistryIsExtractedByTableValueNotKey(): void {
        $definitions = (array) config('code_table_mutations.tables', []);

        $this->assertNotEmpty($definitions);
        $this->assertArrayHasKey(0, $definitions, 'code_table_mutations.tables 應為 list（數字鍵）');

        $names = array_column($definitions, 'table');
        $this->assertCount(count($definitions), $names, '每筆定義都必須有 table 鍵');
        $this->assertContains('NIAN_HAO', $names);

        foreach ($names as $name) {
            $this->assertTrue(
                VariantReplaceScope::isKnownDataTable($name),
                "code_table_mutations 登記的 {$name} 必須被視為已知表"
            );
        }
    }

    // ─────────────────────── 排除清單逐筆 ───────────────────────

    #[Test]
    public function testExcludedTargetsAreNotReplaced(): void {
        // 對照表自身（整表排除）——替換會自我吞噬
        $this->assertNull(VariantReplaceScope::modeFor('char_variant_map', 'c_variant_char'));
        $this->assertNull(VariantReplaceScope::modeFor('char_variant_map', 'c_notes'));

        // 拼音字典的鍵——第一階段設計「異體字各自有讀音」
        $this->assertNull(VariantReplaceScope::modeFor('pinyin', 'c_chn'));

        // 稽核欄（任何表都排除）
        $this->assertNull(VariantReplaceScope::modeFor('BIOG_MAIN', 'c_modified_by'));

        // 跨表／樹狀代碼鍵
        $this->assertNull(VariantReplaceScope::modeFor('BIOG_MAIN', 'c_index_year_type_code'));
        $this->assertNull(VariantReplaceScope::modeFor('ENTRY_TYPES', 'c_entry_type'));
        $this->assertNull(VariantReplaceScope::modeFor('ENTRY_TYPES', 'c_entry_type_parent_id'));
        // 後綴是 _parent_code 而非 _parent_id——只按 *_parent_id 掃會漏掉
        $this->assertNull(VariantReplaceScope::modeFor('STATUS_TYPES', 'c_status_type_parent_code'));

        // 但同表的內容欄仍要替換
        $this->assertSame('lenient', VariantReplaceScope::modeFor('ENTRY_TYPES', 'c_entry_type_desc_chn'));
        $this->assertSame('lenient', VariantReplaceScope::modeFor('STATUS_TYPES', 'c_status_type_chn'));
    }

    /**
     * 反例守衛：這三個是 varchar PK 成員但語義是**內容**（別名／書名／頁碼），
     * 必須替換。判準是「值是否用來跟別表對上」，不是「是否為 varchar PK 成員」——
     * 照後者的字面實作會直接抵銷整個計畫的重點。
     */
    #[Test]
    public function testTextPkMembersThatAreContentAreStillReplaced(): void {
        $this->assertSame('strict', VariantReplaceScope::modeFor('ALTNAME_DATA', 'c_alt_name_chn'));

        foreach (['ASSOC_DATA' => 'c_text_title', 'BIOG_SOURCE_DATA' => 'c_pages'] as $table => $column) {
            $this->assertNotContains(
                $column,
                VariantReplaceScope::EXCLUDED_COLUMNS[$table] ?? [],
                "{$table}.{$column} 是內容欄（PK 成員但語義是書名／頁碼），不可列入排除"
            );
        }
    }

    /** 對照／映射表全部要排除——CompositePrimaryKey 有登記者不可遺漏。 */
    #[Test]
    public function testMappingTablesAreExcludedAsWhole(): void {
        $this->assertContains('char_variant_map', VariantReplaceScope::EXCLUDED_TABLES);
        $this->assertContains('CBDB__NAME_FTS', VariantReplaceScope::EXCLUDED_TABLES);
    }

    // ─────────────────────────── 快取 ───────────────────────────

    #[Test]
    public function testResetClearsColumnTypeCache(): void {
        $this->assertSame('lenient', VariantReplaceScope::modeFor('EVENT_CODES', 'c_event_name_chn'));

        Schema::drop('EVENT_CODES');
        Schema::create('EVENT_CODES', function (Blueprint $table) {
            $table->integer('c_event_code');
            $table->integer('c_event_name_chn'); // 型別換成 integer
        });

        // 快取未清 ⇒ 仍看到舊型別
        $this->assertSame('lenient', VariantReplaceScope::modeFor('EVENT_CODES', 'c_event_name_chn'));

        VariantReplaceScope::reset();

        // 清掉後看到新型別
        $this->assertNull(VariantReplaceScope::modeFor('EVENT_CODES', 'c_event_name_chn'));
    }

    #[Test]
    public function testCompositePrimaryKeySchemasAreAllKnown(): void {
        foreach (array_keys(CompositePrimaryKey::SCHEMAS) as $table) {
            $this->assertTrue(
                VariantReplaceScope::isKnownDataTable($table),
                "CompositePrimaryKey 登記的 {$table} 必須被視為已知表"
            );
        }
    }
}
