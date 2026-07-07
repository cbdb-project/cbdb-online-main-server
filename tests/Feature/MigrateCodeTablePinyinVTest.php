<?php

namespace Tests\Feature;

use App\Services\Pinyin\CodeTablePinyinScanner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Code 表拼音 v→ü 批次遷移的掃描器與 dry-run 指令（Phase B）。
 */
class MigrateCodeTablePinyinVTest extends TestCase {
    private string $outDir;

    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('GANZHI_CODES', function (Blueprint $table) {
            $table->integer('c_ganzhi_code')->primary();
            $table->string('c_ganzhi_chn')->nullable();
            $table->string('c_ganzhi_py')->nullable();
        });
        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
        });
        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->string('c_office_pinyin')->nullable();
            $table->string('c_office_pinyin_alt')->nullable();
            $table->string('c_office_trans')->nullable();
        });
        Schema::create('TEXT_INSTANCE_DATA', function (Blueprint $table) {
            $table->integer('c_textid');
            $table->integer('c_text_edition_id');
            $table->integer('c_text_instance_id');
            $table->string('c_instance_title')->nullable();
            $table->primary(['c_textid', 'c_text_edition_id', 'c_text_instance_id']);
        });

        $this->outDir = storage_path('framework/testing/code-pinyin-'.uniqid());
    }

    protected function tearDown(): void {
        Schema::dropIfExists('GANZHI_CODES');
        Schema::dropIfExists('ADDR_CODES');
        Schema::dropIfExists('OFFICE_CODES');
        Schema::dropIfExists('TEXT_INSTANCE_DATA');
        if (is_dir($this->outDir)) {
            File::deleteDirectory($this->outDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function testScannerClassifiesMutationsAndOtherVs(): void {
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 1, 'c_name' => 'Lvchuan'],          // 命中 → Lüchuan
            ['c_addr_id' => 2, 'c_name' => 'Vietnam'],          // 含 v、規則未命中 → otherV
            ['c_addr_id' => 3, 'c_name' => 'Soviet Far East'],  // 含 v、規則未命中 → otherV
            ['c_addr_id' => 4, 'c_name' => 'Beijing'],          // 無 v → 不掃
            ['c_addr_id' => 5, 'c_name' => 'Yanlv'],            // 命中 → Yanlü
        ]);

        $scanner = new CodeTablePinyinScanner();
        $result = $scanner->scan('ADDR_CODES', ['c_addr_id'], ['c_name']);

        $muts = collect($result['mutations']);
        $this->assertCount(2, $muts);
        $this->assertEqualsCanonicalizing(
            [['from' => 'Lvchuan', 'to' => 'Lüchuan'], ['from' => 'Yanlv', 'to' => 'Yanlü']],
            $muts->map(fn ($m) => ['from' => $m['from'], 'to' => $m['to']])->all()
        );
        $this->assertSame(['c_addr_id' => 1], $muts->firstWhere('from', 'Lvchuan')['pk']);

        $otherVals = collect($result['otherVs'])->pluck('value')->all();
        $this->assertEqualsCanonicalizing(['Vietnam', 'Soviet Far East'], $otherVals);
    }

    #[Test]
    public function testScannerReturnsEmptyForNoColumns(): void {
        $result = (new CodeTablePinyinScanner())->scan('ADDR_CODES', ['c_addr_id'], []);
        $this->assertSame(['mutations' => [], 'otherVs' => [], 'scannedRows' => 0], $result);
    }

    #[Test]
    public function testDryRunTier1WritesReportsAndDoesNotTouchDb(): void {
        DB::table('GANZHI_CODES')->insert(['c_ganzhi_code' => 1, 'c_ganzhi_chn' => '呂', 'c_ganzhi_py' => 'lv']);

        $this->artisan('cbdb:migrate-code-pinyin-v', [
            '--tables' => 'ganzhi_codes',
            '--tier' => 'tier1',
            '--out-dir' => $this->outDir,
        ])->assertExitCode(0);

        // dry-run 不改資料
        $this->assertDatabaseHas('GANZHI_CODES', ['c_ganzhi_code' => 1, 'c_ganzhi_py' => 'lv']);

        // 產物 JSON：mutations 含 lv→lü
        $mutations = json_decode(File::get($this->outDir.'/ganzhi_codes-mutations.json'), true);
        $this->assertCount(1, $mutations);
        $this->assertSame('lv', $mutations[0]['from']);
        $this->assertSame('lü', $mutations[0]['to']);
    }

    #[Test]
    public function testTier1DoesNotScanTier2Columns(): void {
        // ADDR_CODES.c_name 為 Tier 2；--tier=tier1 時該表無 Tier 1 欄 → 略過、不產出 mutations 檔
        DB::table('ADDR_CODES')->insert(['c_addr_id' => 1, 'c_name' => 'Lvchuan']);

        $this->artisan('cbdb:migrate-code-pinyin-v', [
            '--tables' => 'addr_codes',
            '--tier' => 'tier1',
            '--out-dir' => $this->outDir,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($this->outDir.'/addr_codes-mutations.json');
    }

    #[Test]
    public function testTier2ScanReportsMixedColumnHitsAndOtherV(): void {
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 1, 'c_name' => 'Lvchuan'],
            ['c_addr_id' => 2, 'c_name' => 'Vietnam'],
        ]);

        $this->artisan('cbdb:migrate-code-pinyin-v', [
            '--tables' => 'addr_codes',
            '--tier' => 'tier2',
            '--out-dir' => $this->outDir,
        ])->assertExitCode(0);

        $mutations = json_decode(File::get($this->outDir.'/addr_codes-mutations.json'), true);
        $otherVs = json_decode(File::get($this->outDir.'/addr_codes-otherVs.json'), true);
        $this->assertSame('Lüchuan', $mutations[0]['to']);
        $this->assertSame('Vietnam', $otherVs[0]['value']);
    }

    #[Test]
    public function testScannerHandlesCompositeKey(): void {
        DB::table('TEXT_INSTANCE_DATA')->insert([
            'c_textid' => 100, 'c_text_edition_id' => 2, 'c_text_instance_id' => 3, 'c_instance_title' => 'Lvshi Chunqiu',
        ]);
        $result = (new CodeTablePinyinScanner())->scan('TEXT_INSTANCE_DATA', ['c_textid', 'c_text_edition_id', 'c_text_instance_id'], ['c_instance_title']);

        $this->assertCount(1, $result['mutations']);
        $this->assertSame(['c_textid' => 100, 'c_text_edition_id' => 2, 'c_text_instance_id' => 3], $result['mutations'][0]['pk']);
        $this->assertSame('Lüshi Chunqiu', $result['mutations'][0]['to']);
    }

    #[Test]
    public function testDryRunSendsNoHttp(): void {
        Http::fake();
        DB::table('GANZHI_CODES')->insert(['c_ganzhi_code' => 1, 'c_ganzhi_py' => 'lv']);

        $this->artisan('cbdb:migrate-code-pinyin-v', [
            '--tables' => 'ganzhi_codes',
            '--out-dir' => $this->outDir,
        ])->assertExitCode(0);

        Http::assertNothingSent();
    }

    #[Test]
    public function testExecuteGroupsMultiColumnAndSendsBearerTokenNoLeak(): void {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        // 同一列兩個 Tier 1 欄皆命中 → 應合併為一次請求
        DB::table('OFFICE_CODES')->insert(['c_office_id' => 10, 'c_office_pinyin' => 'Lv Bu', 'c_office_pinyin_alt' => 'Nvguan', 'c_office_trans' => 'x']);

        $prev = getenv('CBDB_MIGRATE_TOKEN');
        putenv('CBDB_MIGRATE_TOKEN=SECRET-TOKEN-XYZ');

        try {
            $this->artisan('cbdb:migrate-code-pinyin-v', [
                '--tables' => 'office_codes',
                '--tier' => 'tier1',
                '--execute' => true,
                '--base-url' => 'http://localhost',
                '--out-dir' => $this->outDir,
            ])->assertExitCode(0);
        } finally {
            putenv($prev === false ? 'CBDB_MIGRATE_TOKEN' : 'CBDB_MIGRATE_TOKEN='.$prev);
        }

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/api/v2/mutate')
                && $request->hasHeader('Authorization', 'Bearer SECRET-TOKEN-XYZ')
                && $body['resource'] === 'office_codes'
                && $body['person_id'] === 0
                && $body['target']['pk'] === ['c_office_id' => 10]
                // 兩欄合併為一次 changes
                && $body['changes'] === ['c_office_pinyin' => 'Lü Bu', 'c_office_pinyin_alt' => 'Nüguan'];
        });
    }

    #[Test]
    public function testExecuteFailureWritesFailuresJsonWithoutToken(): void {
        // 模擬「反射端點」：把 Authorization 明文回填進 body，驗證落檔前一律塗掉 token。
        Http::fake(['*' => Http::response(['ok' => false, 'echo' => 'got header Bearer SECRET-TOKEN-XYZ'], 422)]);
        DB::table('GANZHI_CODES')->insert(['c_ganzhi_code' => 1, 'c_ganzhi_py' => 'lv']);

        $prev = getenv('CBDB_MIGRATE_TOKEN');
        putenv('CBDB_MIGRATE_TOKEN=SECRET-TOKEN-XYZ');

        try {
            $this->artisan('cbdb:migrate-code-pinyin-v', [
                '--tables' => 'ganzhi_codes',
                '--tier' => 'tier1',
                '--execute' => true,
                '--out-dir' => $this->outDir,
            ])->assertExitCode(1); // 有寫入失敗
        } finally {
            putenv($prev === false ? 'CBDB_MIGRATE_TOKEN' : 'CBDB_MIGRATE_TOKEN='.$prev);
        }

        $failFile = $this->outDir.'/ganzhi_codes-apply-failures.json';
        $this->assertFileExists($failFile);
        $raw = File::get($failFile);
        $this->assertStringContainsString('"status": 422', $raw);
        // 即使端點把 token 反射進 body，落檔前也已塗掉：token 絕不落檔、且已 [REDACTED]。
        $this->assertStringNotContainsString('SECRET-TOKEN-XYZ', $raw);
        $this->assertStringContainsString('[REDACTED]', $raw);
    }

    #[Test]
    public function testUnknownResourceFails(): void {
        $this->artisan('cbdb:migrate-code-pinyin-v', [
            '--tables' => 'not_a_table',
            '--out-dir' => $this->outDir,
        ])->assertExitCode(1);
    }

    #[Test]
    public function testExecuteWithoutTokenFails(): void {
        $prev = getenv('CBDB_MIGRATE_TOKEN');
        putenv('CBDB_MIGRATE_TOKEN');  // 清空

        try {
            $this->artisan('cbdb:migrate-code-pinyin-v', [
                '--tables' => 'ganzhi_codes',
                '--execute' => true,
                '--out-dir' => $this->outDir,
            ])->assertExitCode(1);
        } finally {
            if ($prev !== false) {
                putenv('CBDB_MIGRATE_TOKEN='.$prev);
            }
        }
    }
}
