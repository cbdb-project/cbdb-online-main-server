<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * cbdb:scan-pinyin-v 只讀掃描命令（Phase A：人名欄）。
 */
class ScanPinyinVTest extends TestCase {
    private string $out;

    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        Schema::create('BIOG_MAIN', function ($t) {
            $t->integer('c_personid')->primary();
            $t->string('c_surname')->nullable();
            $t->string('c_mingzi')->nullable();
        });
        Schema::create('ALTNAME_DATA', function ($t) {
            $t->integer('c_personid');
            $t->string('c_alt_name_chn')->nullable();
            $t->integer('c_alt_name_type_code')->default(0);
            $t->string('c_alt_name')->nullable();
        });

        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => 1, 'c_surname' => 'Lv', 'c_mingzi' => 'Mengzheng'],   // pinyin surname
            ['c_personid' => 2, 'c_surname' => 'Wang', 'c_mingzi' => 'Anshi'],      // clean, no v
            ['c_personid' => 3, 'c_surname' => 'Silva', 'c_mingzi' => 'Bing'],      // western v (other-v)
            ['c_personid' => 4, 'c_surname' => 'Yelv', 'c_mingzi' => 'Chucai'],     // joined pinyin
            ['c_personid' => 5, 'c_surname' => 'Wang', 'c_mingzi' => 'Lvbu'],       // pinyin in c_mingzi (可轉)
            ['c_personid' => 6, 'c_surname' => 'LV', 'c_mingzi' => 'Meng'],         // 大寫 V，LIKE 須同命中
        ]);
        DB::table('ALTNAME_DATA')->insert([
            ['c_personid' => 1, 'c_alt_name_chn' => '呂胤', 'c_alt_name_type_code' => 4, 'c_alt_name' => 'Lv Yin'],  // pinyin
            ['c_personid' => 9, 'c_alt_name_chn' => '席爾瓦', 'c_alt_name_type_code' => 4, 'c_alt_name' => 'Silva'], // other-v
        ]);

        $this->out = sys_get_temp_dir() . '/cbdb-scan-pinyin-' . uniqid() . '.csv';
    }

    protected function tearDown(): void {
        @unlink($this->out);
        parent::tearDown();
    }

    /** @return array<int, array<string, string>> */
    private function readCsv(): array {
        $rows = array_map('str_getcsv', file($this->out, FILE_IGNORE_NEW_LINES));
        $header = array_shift($rows);

        return array_map(static fn ($r) => array_combine($header, $r), $rows);
    }

    #[Test]
    public function it_classifies_pinyin_vs_other_v_and_writes_csv(): void {
        $this->artisan('cbdb:scan-pinyin-v', ['--out' => $this->out])->assertSuccessful();

        $rows = $this->readCsv();
        $index = [];
        foreach ($rows as $r) {
            $index["{$r['table']}.{$r['column']}|{$r['current']}"] = $r;
        }

        // 可轉音節：proposed 為 ü、class=pinyin
        $this->assertSame('Lü', $index['BIOG_MAIN.c_surname|Lv']['proposed']);
        $this->assertSame('pinyin', $index['BIOG_MAIN.c_surname|Lv']['class']);
        $this->assertSame('Yelü', $index['BIOG_MAIN.c_surname|Yelv']['proposed']);
        $this->assertSame('pinyin', $index['BIOG_MAIN.c_surname|Yelv']['class']);
        $this->assertSame('Lü Yin', $index['ALTNAME_DATA.c_alt_name|Lv Yin']['proposed']);
        $this->assertSame('pinyin', $index['ALTNAME_DATA.c_alt_name|Lv Yin']['class']);

        // 西文名：不轉、class=other-v，proposed==current
        $this->assertSame('other-v', $index['BIOG_MAIN.c_surname|Silva']['class']);
        $this->assertSame('Silva', $index['BIOG_MAIN.c_surname|Silva']['proposed']);
        $this->assertSame('other-v', $index['ALTNAME_DATA.c_alt_name|Silva']['class']);

        // 無 v 的列不應出現（Wang / Anshi）
        $this->assertArrayNotHasKey('BIOG_MAIN.c_surname|Wang', $index);

        // ALTNAME 定位欄（3-key）帶出
        $this->assertSame('1|呂胤|4', $index['ALTNAME_DATA.c_alt_name|Lv Yin']['ids']);

        // c_mingzi 欄同樣被掃描與分類（非僅 c_surname）
        $this->assertSame('Lübu', $index['BIOG_MAIN.c_mingzi|Lvbu']['proposed']);
        $this->assertSame('pinyin', $index['BIOG_MAIN.c_mingzi|Lvbu']['class']);
        $this->assertSame('5', $index['BIOG_MAIN.c_mingzi|Lvbu']['ids']);

        // 大寫 V 也須命中並轉為 Ü（LIKE 對大小寫不敏感）
        $this->assertSame('LÜ', $index['BIOG_MAIN.c_surname|LV']['proposed']);
        $this->assertSame('pinyin', $index['BIOG_MAIN.c_surname|LV']['class']);
    }

    #[Test]
    public function it_honors_limit_per_column(): void {
        $this->artisan('cbdb:scan-pinyin-v', ['--out' => $this->out, '--limit' => 1])->assertSuccessful();

        $rows = $this->readCsv();
        // 每欄最多 1 列；共 3 欄（c_surname/c_mingzi/c_alt_name）→ 至多 3 列
        $this->assertLessThanOrEqual(3, count($rows));

        $perColumn = [];
        foreach ($rows as $r) {
            $perColumn["{$r['table']}.{$r['column']}"] = ($perColumn["{$r['table']}.{$r['column']}"] ?? 0) + 1;
        }
        foreach ($perColumn as $col => $n) {
            $this->assertLessThanOrEqual(1, $n, "{$col} 超過 --limit=1");
        }
        // 依 c_personid 排序，c_surname 首列應為 person 1 的 Lv
        $surnameRows = array_values(array_filter($rows, static fn ($r) => $r['column'] === 'c_surname'));
        $this->assertSame('Lv', $surnameRows[0]['current']);
    }

    #[Test]
    public function it_rejects_non_phase_a(): void {
        $this->artisan('cbdb:scan-pinyin-v', ['--phase' => 'B', '--out' => $this->out])
            ->assertFailed();
    }

    #[Test]
    public function it_is_read_only(): void {
        $biogBefore = DB::table('BIOG_MAIN')->get()->toArray();
        $altBefore = DB::table('ALTNAME_DATA')->get()->toArray();
        $this->artisan('cbdb:scan-pinyin-v', ['--out' => $this->out])->assertSuccessful();
        $this->assertEquals($biogBefore, DB::table('BIOG_MAIN')->get()->toArray(), '掃描命令不得修改 BIOG_MAIN');
        $this->assertEquals($altBefore, DB::table('ALTNAME_DATA')->get()->toArray(), '掃描命令不得修改 ALTNAME_DATA');
    }
}
