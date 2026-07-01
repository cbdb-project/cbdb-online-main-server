<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * cbdb:migrate-pinyin-v 指令的 dry-run 端對端（ALTNAME 側，不需拼音庫）。
 */
class MigratePinyinVCommandTest extends TestCase {
    private string $csv;
    private string $outDir;

    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        Schema::create('ALTNAME_DATA', function ($t) {
            $t->integer('c_personid');
            $t->string('c_alt_name_chn')->nullable();
            $t->integer('c_alt_name_type_code')->default(0);
            $t->string('c_alt_name')->nullable();
        });
        DB::table('ALTNAME_DATA')->insert([
            ['c_personid' => 10, 'c_alt_name_chn' => '呂胤', 'c_alt_name_type_code' => 4, 'c_alt_name' => 'Lv Yin'],
        ]);

        $this->csv = sys_get_temp_dir().'/pyv-alt-'.uniqid().'.csv';
        file_put_contents($this->csv, "table,field,id,wrong_pinyin,correct_pinyin\nALTNAME_DATA,c_alt_name,10,Lv Yin,Lü Yin\n");
        $this->outDir = sys_get_temp_dir().'/pyv-out-'.uniqid();
    }

    protected function tearDown(): void {
        @unlink($this->csv);
        foreach (glob($this->outDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->outDir);
        parent::tearDown();
    }

    #[Test]
    public function dry_run_plans_altname_without_writing(): void {
        $before = DB::table('ALTNAME_DATA')->get()->toArray();

        $this->artisan('cbdb:migrate-pinyin-v', [
            '--table' => 'altname',
            '--altname-csv' => $this->csv,
            '--out-dir' => $this->outDir,
        ])->assertSuccessful();

        // dry-run 不得寫入
        $this->assertEquals($before, DB::table('ALTNAME_DATA')->get()->toArray(), 'dry-run 不得修改資料');

        // 產物：預定變更含正確 3-key PK 與 changes
        $mutations = json_decode((string) file_get_contents($this->outDir.'/altname-mutations.json'), true);
        $this->assertCount(1, $mutations);
        $this->assertSame([
            'c_personid' => 10,
            'c_alt_name_chn' => '呂胤',
            'c_alt_name_type_code' => 4,
        ], $mutations[0]['pk']);
        $this->assertSame(['c_alt_name' => 'Lü Yin'], $mutations[0]['changes']);
    }

    #[Test]
    public function dry_run_rewrites_low_confidence_file_even_when_empty(): void {
        @mkdir($this->outDir, 0775, true);
        file_put_contents($this->outDir.'/altname-low-confidence.json', '[{"stale":true}]');

        $this->artisan('cbdb:migrate-pinyin-v', [
            '--table' => 'altname',
            '--altname-csv' => $this->csv,
            '--out-dir' => $this->outDir,
        ])->assertSuccessful();

        $low = json_decode((string) file_get_contents($this->outDir.'/altname-low-confidence.json'), true);
        $this->assertSame([], $low);
    }

    #[Test]
    public function it_tolerates_blank_and_short_csv_rows(): void {
        // Sheet 匯出常見的空白行/短行不得使指令崩潰（PHP 8 array_combine 會擲 ValueError）。
        file_put_contents(
            $this->csv,
            "table,field,id,wrong_pinyin,correct_pinyin\n"
            ."ALTNAME_DATA,c_alt_name,10,Lv Yin,Lü Yin\n"
            ."\n"                          // 空白行
            ."ALTNAME_DATA,c_alt_name\n"   // 短行（欄數不足）
            ."ALTNAME_DATA,c_alt_name,10,Lv Yin,Lü Yin,extra,cols\n"  // 長行（欄數過多）
        );

        $this->artisan('cbdb:migrate-pinyin-v', [
            '--table' => 'altname',
            '--altname-csv' => $this->csv,
            '--out-dir' => $this->outDir,
        ])->assertSuccessful();

        // 只有那筆合法列被規劃
        $mutations = json_decode((string) file_get_contents($this->outDir.'/altname-mutations.json'), true);
        $this->assertCount(1, $mutations);
    }

    #[Test]
    public function it_fails_when_csv_path_missing(): void {
        // 硬錯誤：指定的 CSV 路徑不存在 → 非零退出（供批次/CI）。
        $this->artisan('cbdb:migrate-pinyin-v', [
            '--table' => 'altname',
            '--altname-csv' => sys_get_temp_dir().'/does-not-exist-'.uniqid().'.csv',
            '--out-dir' => $this->outDir,
        ])->assertFailed();
    }

    #[Test]
    public function it_fails_when_no_csv_provided(): void {
        // 完全沒提供 CSV（也未 --fetch）→ 沒有可處理的資料 → 失敗。
        $this->artisan('cbdb:migrate-pinyin-v', [
            '--table' => 'altname',
            '--out-dir' => $this->outDir,
        ])->assertFailed();
    }

    #[Test]
    public function it_fails_when_biog_csv_missing_field_column(): void {
        // BIOG CSV 缺 field 欄（多半傳錯分頁）→ 載入階段早失敗，不進規劃器。
        $biogCsv = sys_get_temp_dir().'/pyv-biog-'.uniqid().'.csv';
        file_put_contents($biogCsv, "table,id,wrong_pinyin,correct_pinyin\nBIOG_MAIN,1,Lv,Lü\n");

        $this->artisan('cbdb:migrate-pinyin-v', [
            '--table' => 'biog',
            '--biog-csv' => $biogCsv,
            '--out-dir' => $this->outDir,
        ])->assertFailed();

        @unlink($biogCsv);
    }

    #[Test]
    public function it_filters_mutations_by_confidence(): void {
        $muts = [
            ['confidence' => 'high', 'pk' => 1],
            ['confidence' => 'low', 'pk' => 2],
            ['pk' => 3],   // 無信心（ALTNAME）
        ];
        $this->assertCount(3, \App\Console\Commands\MigratePinyinV::filterByConfidence($muts, 'all'));
        // high → high + 無信心，跳過 low
        $high = \App\Console\Commands\MigratePinyinV::filterByConfidence($muts, 'high');
        $this->assertSame([1, 3], array_column($high, 'pk'));
        // low → 僅 low
        $low = \App\Console\Commands\MigratePinyinV::filterByConfidence($muts, 'low');
        $this->assertSame([2], array_column($low, 'pk'));
    }

    #[Test]
    public function it_rejects_invalid_confidence(): void {
        $this->artisan('cbdb:migrate-pinyin-v', [
            '--table' => 'altname',
            '--altname-csv' => $this->csv,
            '--out-dir' => $this->outDir,
            '--confidence' => 'medium',
        ])->assertFailed();
    }

    #[Test]
    public function execute_without_token_env_fails(): void {
        // 安全：--execute 但未設 CBDB_MIGRATE_TOKEN → 直接失敗、不寫入。
        $prev = getenv('CBDB_MIGRATE_TOKEN');
        putenv('CBDB_MIGRATE_TOKEN');   // 清空

        $this->artisan('cbdb:migrate-pinyin-v', [
            '--table' => 'altname',
            '--altname-csv' => $this->csv,
            '--out-dir' => $this->outDir,
            '--execute' => true,
        ])->assertFailed();

        if ($prev !== false) {
            putenv('CBDB_MIGRATE_TOKEN='.$prev);
        }
    }

    #[Test]
    public function execute_payload_includes_person_id(): void {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['ok' => true], 200)]);
        $prev = getenv('CBDB_MIGRATE_TOKEN');
        putenv('CBDB_MIGRATE_TOKEN=test-token');

        $this->artisan('cbdb:migrate-pinyin-v', [
            '--table' => 'altname',
            '--altname-csv' => $this->csv,
            '--out-dir' => $this->outDir,
            '--execute' => true,
            '--base-url' => 'http://api.test',
        ])->assertSuccessful();

        putenv($prev === false ? 'CBDB_MIGRATE_TOKEN' : 'CBDB_MIGRATE_TOKEN='.$prev);

        // payload 必含頂層 person_id ＝ pk.c_personid（否則 /api/v2/mutate 回 422）
        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $d = $request->data();

            return $request->url() === 'http://api.test/api/v2/mutate'
                && ($d['resource'] ?? null) === 'altnames'
                && ($d['mode'] ?? null) === 'direct'
                && ($d['person_id'] ?? null) === 10
                && ($d['target']['pk']['c_personid'] ?? null) === 10;
        });
    }
}
