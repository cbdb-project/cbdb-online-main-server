<?php

namespace Tests\Feature;

use App\Services\NameSearchIndexService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * 針對 2026_09_22_000000_strip_numeric_disambiguation_suffix_from_biog_main_names 的測試。
 *
 * 重點是**邊界**：該刪的刪（拼音／中文、一位／多位數字、全角形），不該刪的一格都不能動
 * （說明性括號、字串中段的括號、姓氏欄）。`migrate:fresh` 跑的是空表，跑不到任何分支，
 * 必須手動鋪資料。
 */
class StripNumericDisambiguationSuffixMigrationTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
            $table->string('c_surname')->nullable();
            $table->string('c_surname_chn')->nullable();
            $table->string('c_mingzi')->nullable();
            $table->string('c_mingzi_chn')->nullable();
            // 類註承諾「不碰 *_proper／*_rm」，補上欄位才測得到（migration 是硬編碼 4 欄）。
            $table->string('c_name_proper')->nullable();
            $table->string('c_name_rm')->nullable();
            $table->string('c_mingzi_proper')->nullable();
            $table->string('c_mingzi_rm')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('CBDB__NAME_FTS');
        Schema::dropIfExists('BIOG_MAIN');

        parent::tearDown();
    }

    /** 跑 migration 並回傳它 echo 出來的摘要（跳過的列是需要人看的資訊，要能斷言）。 */
    private function runMigration(): string {
        $migration = require database_path(
            'migrations/2026_09_22_000000_strip_numeric_disambiguation_suffix_from_biog_main_names.php'
        );
        $this->assertInstanceOf(Migration::class, $migration);

        ob_start();

        try {
            $migration->up();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function insert(int $id, array $attributes = []): void {
        DB::table('BIOG_MAIN')->insert($attributes + ['c_personid' => $id]);
    }

    private function row(int $id): object {
        return DB::table('BIOG_MAIN')->where('c_personid', $id)->first();
    }

    #[Test]
    public function it_strips_the_numeric_suffix_and_the_space_before_it(): void {
        $this->insert(1, [
            'c_name' => 'Jia Gongyan (2)',
            'c_mingzi' => 'Gongyan (2)',
            'c_name_chn' => '賈公彥',
            'c_mingzi_chn' => '公彥',
        ]);

        $this->runMigration();

        $row = $this->row(1);
        $this->assertSame('Jia Gongyan', $row->c_name, '後綴與它前面的空格都要刪掉');
        $this->assertSame('Gongyan', $row->c_mingzi);
    }

    #[Test]
    public function it_strips_the_suffix_whether_or_not_a_space_precedes_it(): void {
        $this->insert(1, ['c_name' => 'Jia Gongyan(2)', 'c_mingzi' => 'Gongyan(12)']);
        $this->insert(2, ['c_name' => 'Chen Ji  (3)', 'c_mingzi' => 'Ji  (3)']);

        $this->runMigration();

        $this->assertSame('Jia Gongyan', $this->row(1)->c_name, '無空格形也要處理');
        $this->assertSame('Gongyan', $this->row(1)->c_mingzi, '兩位數字也要處理');
        $this->assertSame('Chen Ji', $this->row(2)->c_name, '多個空格要一併吃掉');
        $this->assertSame('Ji', $this->row(2)->c_mingzi);
    }

    #[Test]
    public function it_strips_the_suffix_from_chinese_name_columns(): void {
        $this->insert(1, ['c_name_chn' => '許瑤(2)', 'c_mingzi_chn' => '瑤(2)']);
        $this->insert(2, ['c_name_chn' => '徐秉哲（2）', 'c_mingzi_chn' => '秉哲（２）']);

        $this->runMigration();

        $this->assertSame('許瑤', $this->row(1)->c_name_chn);
        $this->assertSame('瑤', $this->row(1)->c_mingzi_chn);
        $this->assertSame('徐秉哲', $this->row(2)->c_name_chn, '全角括號也要處理');
        $this->assertSame('秉哲', $this->row(2)->c_mingzi_chn, '全角數字也要處理');
    }

    #[Test]
    public function it_leaves_descriptive_brackets_untouched(): void {
        $this->insert(1, [
            'c_name' => 'Guo Shi (Wife of Zhao Zhen)',
            'c_mingzi' => 'Shi (Wife of Zhao Zhen)',
            'c_name_chn' => '郭氏(趙禎之妻)',
        ]);
        $this->insert(2, ['c_name' => 'Li Bai (zi)', 'c_mingzi' => 'Bai (zi)']);
        // 括號內混有數字但不只有數字：仍然是內容，不是序號。
        $this->insert(3, ['c_name' => 'Wang Wu (jinshi 1148)', 'c_mingzi' => 'Wu (2nd)']);

        $this->runMigration();

        $this->assertSame('Guo Shi (Wife of Zhao Zhen)', $this->row(1)->c_name);
        $this->assertSame('Shi (Wife of Zhao Zhen)', $this->row(1)->c_mingzi);
        $this->assertSame('郭氏(趙禎之妻)', $this->row(1)->c_name_chn);
        $this->assertSame('Li Bai (zi)', $this->row(2)->c_name);
        $this->assertSame('Bai (zi)', $this->row(2)->c_mingzi);
        $this->assertSame('Wang Wu (jinshi 1148)', $this->row(3)->c_name);
        $this->assertSame('Wu (2nd)', $this->row(3)->c_mingzi);
    }

    #[Test]
    public function it_leaves_mid_string_brackets_untouched(): void {
        // 已知的 4 列資料瑕疵（personid 23881／24163／26374／35836）：那裡的 (n) 是同音姓氏
        // 序號，真正的病是姓氏誤植進 c_mingzi，只刪括號會留下 'Fan Baizhi' 仍然是錯的。
        $this->insert(23881, [
            'c_name' => 'Fan Baizhi',
            'c_surname' => 'Fan',
            'c_mingzi' => 'Fan (1) Baizhi',
        ]);

        $this->runMigration();

        $row = $this->row(23881);
        $this->assertSame('Fan (1) Baizhi', $row->c_mingzi, '字串中段的括號一律不動（那 4 列人工修正）');
        $this->assertSame('Fan Baizhi', $row->c_name);
    }

    #[Test]
    public function it_leaves_surname_columns_untouched(): void {
        $this->insert(1, [
            'c_name' => 'Fan Baizhi (2)',
            'c_surname' => 'Fan (2)',
            'c_surname_chn' => '樊(2)',
            'c_mingzi' => 'Baizhi (2)',
        ]);

        $this->runMigration();

        $row = $this->row(1);
        $this->assertSame('Fan Baizhi', $row->c_name);
        $this->assertSame('Baizhi', $row->c_mingzi);
        $this->assertSame('Fan (2)', $row->c_surname, '姓氏欄不在處理範圍（全庫實測 0 列）');
        $this->assertSame('樊(2)', $row->c_surname_chn);
    }

    #[Test]
    public function it_never_empties_a_name(): void {
        $this->insert(1, ['c_name' => '(2)', 'c_mingzi' => ' (2) ', 'c_name_chn' => '（２）']);

        $output = $this->runMigration();

        $row = $this->row(1);
        $this->assertSame('(2)', $row->c_name, '整格只有後綴時寧可留著讓人看，不要清成空字串');
        $this->assertSame(' (2) ', $row->c_mingzi);
        $this->assertSame('（２）', $row->c_name_chn);
        // 「留著讓人看」必須真的講出來，否則等於靜默跳過。
        $this->assertStringContainsString('c_personid=1', $output);
        $this->assertStringContainsString('刪掉會變成沒有名字', $output);
        $this->assertSame(3, substr_count($output, '刪掉會變成沒有名字'), '三個欄位各報一次');
    }

    #[Test]
    public function it_leaves_clean_rows_and_nulls_alone(): void {
        $this->insert(1, [
            'c_name' => 'Wang Anshi',
            'c_mingzi' => 'Anshi',
            'c_name_chn' => '王安石',
            'c_mingzi_chn' => '安石',
        ]);
        $this->insert(2, ['c_name' => null, 'c_mingzi' => null, 'c_name_chn' => null, 'c_mingzi_chn' => null]);
        // 數字結尾但沒有括號：#154 驗收案例之一的形狀，不能誤刪。
        $this->insert(3, ['c_name' => 'Liu Xidian1', 'c_mingzi' => 'Xidian1']);

        $this->runMigration();

        $this->assertSame('Wang Anshi', $this->row(1)->c_name);
        $this->assertSame('安石', $this->row(1)->c_mingzi_chn);
        $this->assertNull($this->row(2)->c_name);
        $this->assertNull($this->row(2)->c_mingzi_chn);
        $this->assertSame('Liu Xidian1', $this->row(3)->c_name, '沒有括號就不是後綴');
        $this->assertSame('Xidian1', $this->row(3)->c_mingzi);
    }

    #[Test]
    public function it_leaves_three_digit_brackets_untouched_and_says_so(): void {
        // 合成輸入：庫裡實測 0 列（見 migration 類註）。釘的是不可逆操作下的防禦分支——
        // 3 位數在姓名欄裡更可能是年份之類的內容，寧可 echo 請人看也不要賭。
        $this->insert(1, ['c_name' => 'Wang Wu (1148)', 'c_mingzi' => 'Wu (1148)']);
        // 2 位與 3 位混在一起時，整條 pattern 都不該命中（3 位那組卡在字串尾端）。
        $this->insert(2, ['c_name' => 'Wang Liu (2) (1148)']);

        $output = $this->runMigration();

        $this->assertSame('Wang Wu (1148)', $this->row(1)->c_name, '3 位以上不視為序號');
        $this->assertSame('Wu (1148)', $this->row(1)->c_mingzi);
        $this->assertSame('Wang Liu (2) (1148)', $this->row(2)->c_name, '尾端是 3 位時整格不動');
        $this->assertStringContainsString('3 位以上數字', $output, '跳過了就要講出來');
        $this->assertSame(3, substr_count($output, '3 位以上數字'));
    }

    #[Test]
    public function it_strips_repeated_suffixes_in_one_pass(): void {
        // 只吃最後一組的話這支 migration 就不是幂等的（跑一次留 'Wang Anshi (2)'）。
        $this->insert(1, ['c_name' => 'Wang Anshi (2) (3)', 'c_mingzi' => 'Anshi(2)(3)']);

        $this->runMigration();

        $this->assertSame('Wang Anshi', $this->row(1)->c_name);
        $this->assertSame('Anshi', $this->row(1)->c_mingzi);
    }

    #[Test]
    public function it_leaves_proper_and_romanization_columns_untouched(): void {
        $this->insert(1, [
            'c_name' => 'Wang Anshi (2)',
            'c_name_proper' => 'Wang Anshi (2)',
            'c_name_rm' => 'Wang Anshi (2)',
            'c_mingzi_proper' => 'Anshi (2)',
            'c_mingzi_rm' => 'Anshi (2)',
        ]);

        $this->runMigration();

        $row = $this->row(1);
        $this->assertSame('Wang Anshi', $row->c_name);
        $this->assertSame('Wang Anshi (2)', $row->c_name_proper, '*_proper 不在處理範圍');
        $this->assertSame('Wang Anshi (2)', $row->c_name_rm, '*_rm 不在處理範圍');
        $this->assertSame('Anshi (2)', $row->c_mingzi_proper);
        $this->assertSame('Anshi (2)', $row->c_mingzi_rm);
    }

    /**
     * 回歸守衛：早期版本用 `chunk()` 邊掃邊改，而 WHERE 篩的欄位正是被 UPDATE 的欄位——
     * 列一被清乾淨就退出結果集，offset 分頁於是往前跳過等量的列，靜默漏掉約 9% 該清的資料。
     * 必須鋪出跨批（> CHUNK_SIZE）且「要清／不用清」交錯的資料才抓得到。
     */
    #[Test]
    public function it_processes_every_candidate_across_batches(): void {
        $chunkSize = $this->migrationConstant('CHUNK_SIZE');
        $total = $chunkSize * 3 + 7;

        $rows = [];
        $expectedStripped = 0;
        for ($i = 1; $i <= $total; $i++) {
            // 每 3 列有 2 列帶後綴（要清），1 列帶說明性括號（不清但仍是 LIKE 候選）。
            if ($i % 3 === 0) {
                $rows[] = ['c_personid' => $i, 'c_name' => "Ren Wu$i (Wife of Zhao Zhen)"];

                continue;
            }
            $rows[] = ['c_personid' => $i, 'c_name' => "Ren Wu$i (2)"];
            $expectedStripped++;
        }
        foreach (array_chunk($rows, 200) as $batch) {
            DB::table('BIOG_MAIN')->insert($batch);
        }

        $output = $this->runMigration();

        $leftover = DB::table('BIOG_MAIN')->where('c_name', 'like', '%(2)')->count();
        $this->assertSame(0, $leftover, '跨批時不可漏掉任何一列');
        $this->assertSame(
            $expectedStripped,
            DB::table('BIOG_MAIN')->where('c_name', 'not like', '%)')->count(),
            '該清的列數要完全相符'
        );
        $this->assertSame(
            (int) ($total / 3),
            DB::table('BIOG_MAIN')->where('c_name', 'like', '%(Wife of Zhao Zhen)')->count(),
            '說明性括號一列都不能動'
        );
        $this->assertStringContainsString((string) $expectedStripped.' 列', $output, 'echo 的列數不可少報');
    }

    /** 取 migration 的 private static 方法，用來直接釘住內部契約。 */
    private function migrationMethod(string $name): callable {
        $migration = require database_path(
            'migrations/2026_09_22_000000_strip_numeric_disambiguation_suffix_from_biog_main_names.php'
        );
        $method = (new \ReflectionClass($migration))->getMethod($name);
        $method->setAccessible(true);

        return fn (...$args) => $method->invoke(null, ...$args);
    }

    /** 取 migration 的常數原始值（不假設型別）。 */
    private function migrationRawConstant(string $name): mixed {
        $migration = require database_path(
            'migrations/2026_09_22_000000_strip_numeric_disambiguation_suffix_from_biog_main_names.php'
        );
        $reflection = new \ReflectionClass($migration);
        $this->assertTrue($reflection->hasConstant($name), $name . ' 常數不存在');

        return $reflection->getConstant($name);
    }

    /** 從 migration 讀出常數，避免測試與實作各寫一個數字而失效。 */
    private function migrationConstant(string $name): int {
        $migration = require database_path(
            'migrations/2026_09_22_000000_strip_numeric_disambiguation_suffix_from_biog_main_names.php'
        );
        $value = (new \ReflectionClass($migration))->getConstant($name);
        $this->assertIsInt($value, $name . ' 應是整數常數');
        $this->assertGreaterThan(0, $value);

        return $value;
    }

    #[Test]
    public function it_rebuilds_the_name_search_index_for_changed_chinese_names(): void {
        $this->createNameSearchIndexTable();
        // 索引裡的舊值是「剝掉括號、保留內容」的產物（見 NameSearchIndexService::normalizeName）。
        DB::table('CBDB__NAME_FTS')->insert([
            'c_personid' => 69848, 'name_type_code' => null, 'name_type_desc' => 'main_name',
            'name_type_desc_chn' => '本名', 'search_term' => '許瑤2', 'full_name' => '許瑤2',
            'source' => 'BIOG_MAIN', 'source_key' => 'biog_main:69848', 'is_simplified' => 0,
        ]);
        $this->insert(69848, ['c_name' => 'Xu Yao', 'c_name_chn' => '許瑤(2)', 'c_mingzi_chn' => '瑤(2)']);

        $output = $this->runMigration();

        $this->assertSame('許瑤', $this->row(69848)->c_name_chn);
        $terms = DB::table('CBDB__NAME_FTS')->where('c_personid', 69848)->pluck('search_term')->all();
        $this->assertNotContains('許瑤2', $terms, '舊索引列必須被刪掉，不是留下兩套');
        $this->assertContains('許瑤', $terms, '要重建成乾淨的名字');
        $this->assertSame(
            ['許瑤'],
            DB::table('CBDB__NAME_FTS')->where('c_personid', 69848)->pluck('full_name')->unique()->values()->all()
        );
        $this->assertStringContainsString('CBDB__NAME_FTS', $output);
    }

    #[Test]
    public function it_does_not_touch_the_index_when_only_pinyin_changes(): void {
        $this->createNameSearchIndexTable();
        DB::table('CBDB__NAME_FTS')->insert([
            'c_personid' => 26, 'name_type_code' => null, 'name_type_desc' => 'main_name',
            'name_type_desc_chn' => '本名', 'search_term' => '陳機', 'full_name' => '陳機',
            'source' => 'BIOG_MAIN', 'source_key' => 'biog_main:26', 'is_simplified' => 0,
        ]);
        // 只有拼音欄帶後綴——中文名沒變，索引不該被動到（那 5,185／5,138 列與 FTS 無關）。
        $this->insert(26, ['c_name' => 'Chen Ji (2)', 'c_mingzi' => 'Ji (2)', 'c_name_chn' => '陳機']);

        $output = $this->runMigration();

        $this->assertSame('Chen Ji', $this->row(26)->c_name);
        $this->assertSame(1, DB::table('CBDB__NAME_FTS')->where('c_personid', 26)->count());
        $this->assertStringNotContainsString('CBDB__NAME_FTS', $output);
    }

    #[Test]
    public function it_runs_when_the_name_search_index_table_is_absent(): void {
        // 測試環境（與極舊 schema）可能沒有這張表，migration 仍必須跑完。
        $this->assertFalse(Schema::hasTable('CBDB__NAME_FTS'));
        $this->insert(1, ['c_name' => 'Xu Yao (2)', 'c_name_chn' => '許瑤(2)']);

        $this->runMigration();

        $this->assertSame('許瑤', $this->row(1)->c_name_chn);
    }

    /** 建出 migration 會去重建的索引表（欄位對齊 2025_11_13 的 create migration）。 */
    private function createNameSearchIndexTable(): void {
        Schema::create('CBDB__NAME_FTS', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('c_personid');
            $table->unsignedSmallInteger('name_type_code')->nullable();
            $table->string('name_type_desc', 32);
            $table->string('name_type_desc_chn', 32);
            $table->string('search_term', 100);
            $table->string('full_name', 100);
            $table->string('source', 32);
            $table->string('source_key')->nullable();
            $table->boolean('is_simplified')->default(false);
            $table->timestamps();
        });
    }

    #[Test]
    public function it_reports_an_out_of_range_group_that_only_surfaces_after_stripping(): void {
        // 尾端是合法的 1–2 位後綴，剝掉它才會讓 (1148) 浮到字串尾端。這一列**會**被改動，
        // 所以殘留的可疑括號一定要進人工清單——不會有「下一次 migration」來補報。
        $this->insert(1, ['c_name' => 'Wang Liu (1148) (2)']);

        $output = $this->runMigration();

        $this->assertSame('Wang Liu (1148)', $this->row(1)->c_name, '只該剝掉合法的那一組');
        $this->assertStringContainsString('3 位以上數字', $output, '改動過就更要回報');
        $this->assertStringContainsString('c_personid=1', $output);
    }

    #[Test]
    public function it_leaves_a_numeric_suffix_followed_by_another_bracket_untouched(): void {
        // 「只認字串尾端」的必然結果：比誤刪安全。實測庫裡 0 列。
        $this->insert(1, ['c_name' => 'Wang Wu (2) (zi)', 'c_mingzi' => 'Wu (2) (zi)']);

        $this->runMigration();

        $this->assertSame('Wang Wu (2) (zi)', $this->row(1)->c_name);
        $this->assertSame('Wu (2) (zi)', $this->row(1)->c_mingzi);
    }

    #[Test]
    public function it_keeps_a_descriptive_bracket_before_a_numeric_suffix(): void {
        $this->insert(1, ['c_name' => 'Guo Shi (Wife of Zhao Zhen) (2)']);

        $this->runMigration();

        $this->assertSame('Guo Shi (Wife of Zhao Zhen)', $this->row(1)->c_name, '只吃數字那組');
    }

    #[Test]
    public function it_skips_and_reports_invalid_utf8(): void {
        // /u 之下任何非法 UTF-8 都會讓 preg_replace 回 null。主檔有 latin1 時代的歷史，
        // 不能假設不會發生；重點是「不要把 null 當成清空寫回去」，而且要講出來。
        DB::statement("INSERT INTO BIOG_MAIN (c_personid, c_name) VALUES (1, CAST(x'57616e6720ff2028322900' AS TEXT))");
        $before = DB::table('BIOG_MAIN')->where('c_personid', 1)->value('c_name');

        $output = $this->runMigration();

        $this->assertSame(
            $before,
            DB::table('BIOG_MAIN')->where('c_personid', 1)->value('c_name'),
            '無從判斷就一格都不能碰'
        );
        $this->assertStringContainsString('不是合法的 UTF-8', $output);
    }

    #[Test]
    public function it_caps_the_per_row_skip_report(): void {
        $limit = $this->migrationConstant('REPORT_LIMIT');
        $rows = [];
        for ($i = 1; $i <= $limit + 5; $i++) {
            $rows[] = ['c_personid' => $i, 'c_name' => '(2)'];
        }
        DB::table('BIOG_MAIN')->insert($rows);

        $output = $this->runMigration();

        // 數 `c_personid=` 而不是數理由字串——總結那行也會複述同一個理由。
        $this->assertSame($limit, substr_count($output, 'c_personid='), '逐列列出的筆數要收在上限內');
        $this->assertStringContainsString('刪掉會變成沒有名字', $output);
        $this->assertStringContainsString('另有 5 格', $output, '其餘要報總數，不可靜默丟掉');
    }

    #[Test]
    public function it_rolls_back_the_name_when_reindexing_fails(): void {
        // 姓名更新與索引重建必須同進同退：若讓 UPDATE 先提交、重建才失敗，重跑時
        // 「許瑤」已不含 '('、不再是候選，那筆索引就會永久留著舊值而且沒有任何提示。
        $this->createNameSearchIndexTable();
        DB::table('CBDB__NAME_FTS')->insert([
            'c_personid' => 69848, 'name_type_code' => null, 'name_type_desc' => 'main_name',
            'name_type_desc_chn' => '本名', 'search_term' => '許瑤2', 'full_name' => '許瑤2',
            'source' => 'BIOG_MAIN', 'source_key' => 'biog_main:69848', 'is_simplified' => 0,
        ]);
        $this->insert(69848, ['c_name' => 'Xu Yao', 'c_name_chn' => '許瑤(2)']);

        // 讓重建必定失敗：把 full_name 收窄到塞不下，insert 就會炸。
        $this->app->bind(NameSearchIndexService::class, fn () => new class () extends NameSearchIndexService {
            public function indexPerson($person) {
                throw new RuntimeException('模擬索引重建失敗');
            }
        });

        try {
            $this->runMigration();
            $this->fail('重建失敗應該讓 migration 拋出，而不是靜默吞掉');
        } catch (RuntimeException $e) {
            $this->assertSame('模擬索引重建失敗', $e->getMessage());
        }

        $this->assertSame(
            '許瑤(2)',
            $this->row(69848)->c_name_chn,
            '重建失敗時姓名要一起回滾，重跑才處理得到這一列'
        );
        $this->assertSame(
            ['許瑤2'],
            DB::table('CBDB__NAME_FTS')->where('c_personid', 69848)->pluck('search_term')->all(),
            '索引也不可以被改到一半'
        );
    }

    /**
     * `stripSuffix()` 把**狀態與值分開**回傳，而不是用哨兵字串或 `null` 兼任狀態。
     *
     * 這條走 Reflection 直接打那個方法，不經資料庫：舊設計的哨兵含 NUL 位元組，而帶 NUL 的值
     * 在 SQLite 的 `LIKE` 下根本進不了候選集，從資料層構造不出碰撞——但契約本身仍然值得釘住，
     * 否則下一個人很容易「簡化」成回傳 `null` 或哨兵字串，把狀態與資料重新混為一談。
     */
    #[Test]
    public function strip_suffix_reports_status_separately_from_the_value(): void {
        $strip = $this->migrationMethod('stripSuffix');
        $constant = fn (string $name): string => (string) $this->migrationRawConstant($name);

        $this->assertSame(
            [$constant('STATUS_OK'), 'Jia Gongyan'],
            $strip('Jia Gongyan (2)'),
            '正常剝除'
        );
        $this->assertSame(
            [$constant('STATUS_OK'), 'Jia Gongyan'],
            $strip('Jia Gongyan'),
            '沒有後綴就原值回傳，而不是另一種狀態'
        );
        $this->assertSame(
            [$constant('STATUS_WOULD_EMPTY'), '(2)'],
            $strip('(2)'),
            '會清成空字串時要回原值，呼叫端才不會拿哨兵去寫庫'
        );
        $this->assertSame(
            [$constant('STATUS_PREG_FAILED'), "Wang \xFF (2)"],
            $strip("Wang \xFF (2)"),
            '非法 UTF-8 要能與「會清空」區分，兩者的人工處置不同'
        );
        // 關鍵：值長得像狀態也不會被誤判——狀態根本不在同一個位置上。
        $this->assertSame(
            [$constant('STATUS_OK'), 'preg_failed'],
            $strip('preg_failed (2)')
        );
        $this->assertSame(
            [$constant('STATUS_OK'), 'would_empty'],
            $strip('would_empty (2)')
        );
    }

    #[Test]
    public function it_strips_non_ascii_whitespace_before_the_suffix(): void {
        // PHP 的 /u 會一併開 PCRE2_UCP，所以 \s 涵蓋 NBSP／U+2002／U+3000。
        // 這個依賴值得釘住：少了它，這些列會留下一個看不見的尾巴空白。
        $this->insert(1, ['c_name' => "Jia Gongyan\u{00A0}(2)"]);
        $this->insert(2, ['c_name' => "Jia Gongyan\u{2002}(2)"]);
        $this->insert(3, ['c_name' => "Jia Gongyan\u{3000}(2)"]);
        $this->insert(4, ['c_name' => "Jia Gongyan (2)\u{00A0}"]);

        $this->runMigration();

        foreach ([1, 2, 3, 4] as $id) {
            $this->assertSame('Jia Gongyan', $this->row($id)->c_name, "第 $id 列的空白沒清乾淨");
        }
    }

    #[Test]
    public function it_is_idempotent(): void {
        $this->insert(1, ['c_name' => 'Jia Gongyan (2)', 'c_mingzi' => 'Gongyan (2)']);

        $this->runMigration();
        $this->runMigration();

        $this->assertSame('Jia Gongyan', $this->row(1)->c_name);
        $this->assertSame('Gongyan', $this->row(1)->c_mingzi);
    }

    #[Test]
    public function it_no_ops_when_the_table_is_absent(): void {
        Schema::dropIfExists('BIOG_MAIN');

        $this->runMigration();

        $this->assertFalse(Schema::hasTable('BIOG_MAIN'));
    }

    #[Test]
    public function down_is_a_no_op(): void {
        $this->insert(1, ['c_name' => 'Jia Gongyan (2)']);

        $migration = require database_path(
            'migrations/2026_09_22_000000_strip_numeric_disambiguation_suffix_from_biog_main_names.php'
        );
        $migration->down();

        $this->assertSame('Jia Gongyan (2)', $this->row(1)->c_name, 'down() 刻意不還原');
    }
}
