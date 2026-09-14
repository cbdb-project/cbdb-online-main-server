<?php

namespace Tests\Feature;

use App\Services\CoordinateZeroCleanupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 事後清掃：`cbdb:normalize-addr-coords` 與共用它的一次性 data migration。
 *
 * 為什麼需要這道地板：寫入端守衛只管**新的寫入**。生產環境那 316 列 `0,0` 幾乎確定是原始
 * 匯入帶進來的，而任何上游重灌、或任何匯入舊 dump 的新部署，都會再帶一批。
 */
class NormalizeAddrCoordsCommandTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('app.env', 'testing');
        $this->app['env'] = 'testing';
        config()->set('prometheus.enabled', false);
        config()->set('prometheus.storage_adapter', 'memory');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
            $table->smallInteger('c_admin_cat_code')->default(0);
            $table->double('x_coord')->nullable();
            $table->double('y_coord')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('ADDR_CODES');
        parent::tearDown();
    }

    private function seedRows(): void {
        // 逐列 insert：SQLite 的多列 VALUES 要求每一列欄位數相同，而這裡刻意讓其中
        // 一列帶稽核欄、其他列不帶。
        $rows = [
            // 兩軸皆零——經典的那 316 列
            ['c_addr_id' => 1, 'c_name' => 'ZeroZero', 'x_coord' => 0, 'y_coord' => 0],
            // 半截：一軸有真值、一軸為零
            ['c_addr_id' => 2, 'c_name' => 'HalfZero', 'x_coord' => 113.5, 'y_coord' => 0],
            // 半截：一軸有真值、一軸 NULL
            ['c_addr_id' => 3, 'c_name' => 'HalfNull', 'x_coord' => 105.36354, 'y_coord' => null],
            // 正常列——絕不可以被動到
            ['c_addr_id' => 4, 'c_name' => 'Good', 'x_coord' => 113.11134338, 'y_coord' => 40.37184906],
            // 兩軸皆 NULL——已經是對的，不該被算成「清理了一列」
            ['c_addr_id' => 5, 'c_name' => 'BothNull', 'x_coord' => null, 'y_coord' => null],
            // 稽核欄有值的零座標列，用來驗證清理不會蓋掉稽核欄
            ['c_addr_id' => 6, 'c_name' => 'Stamped', 'x_coord' => 0, 'y_coord' => 0,
                'c_modified_by' => '某位編輯者', 'c_modified_date' => '2020-01-01 00:00:00'],
        ];
        foreach ($rows as $row) {
            DB::table('ADDR_CODES')->insert($row);
        }
    }

    private function row(int $id): object {
        return DB::table('ADDR_CODES')->where('c_addr_id', $id)->first();
    }

    #[Test]
    public function testItClearsZeroAndHalfPairsAndLeavesGoodRowsAlone(): void {
        $this->seedRows();

        $this->artisan('cbdb:normalize-addr-coords')->assertExitCode(0);

        foreach ([1, 2, 3, 6] as $id) {
            $this->assertNull($this->row($id)->x_coord, "id $id 的 x_coord 應該被清空");
            $this->assertNull($this->row($id)->y_coord, "id $id 的 y_coord 應該被清空");
        }

        // 正常列毫髮無傷
        $good = $this->row(4);
        $this->assertSame(113.11134338, (float) $good->x_coord);
        $this->assertSame(40.37184906, (float) $good->y_coord);
    }

    #[Test]
    public function testItDoesNotStampTheAuditColumns(): void {
        // 這是資料清理、不是 316 次編輯行為。把最後修改者改成執行清理的人，會抹掉
        // 「這列上次真的被誰改過」這個更有價值的事實（AGENTS.md §1.2）。
        $this->seedRows();

        $this->artisan('cbdb:normalize-addr-coords')->assertExitCode(0);

        $stamped = $this->row(6);
        $this->assertNull($stamped->x_coord, '座標應該被清了');
        $this->assertSame('某位編輯者', $stamped->c_modified_by, 'c_modified_by 不可以被清理蓋掉');
        $this->assertStringStartsWith('2020-01-01', (string) $stamped->c_modified_date);
    }

    #[Test]
    public function testItIsIdempotent(): void {
        // 幂等是這支能放進部署腳本／cron 的前提。
        $this->seedRows();

        $this->artisan('cbdb:normalize-addr-coords')->assertExitCode(0);
        $first = DB::table('ADDR_CODES')->orderBy('c_addr_id')->get()->toArray();

        $result = app(CoordinateZeroCleanupService::class)->cleanTable('ADDR_CODES');
        $this->assertSame(0, $result['cleared'], '第二次跑不該再改動任何一列');

        $second = DB::table('ADDR_CODES')->orderBy('c_addr_id')->get()->toArray();
        $this->assertEquals($first, $second);
    }

    #[Test]
    public function testDryRunChangesNothing(): void {
        $this->seedRows();

        $this->artisan('cbdb:normalize-addr-coords', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0.0, (float) $this->row(1)->x_coord, '--dry-run 不可以寫入');
        $this->assertSame(113.5, (float) $this->row(2)->x_coord);
    }

    #[Test]
    public function testItReportsHowManyRowsItWouldClear(): void {
        $this->seedRows();

        $result = app(CoordinateZeroCleanupService::class)->cleanTable('ADDR_CODES', true);

        // id 1、2、3、6 需要處理；4 正常、5 已是兩軸 NULL。
        $this->assertSame(4, $result['cleared']);
        $this->assertSame([], $result['skipped_non_numeric']);
    }

    #[Test]
    public function testItSkipsAndReportsARowWhoseCoordinateIsNotANumber(): void {
        // 資料庫裡存著一個不是數的座標（理論上進不來，但舊資料什麼都可能有）。
        // 不猜、不清——列出來讓人看。
        DB::table('ADDR_CODES')->insert([
            'c_addr_id' => 7, 'c_name' => 'Garbage', 'x_coord' => 0, 'y_coord' => 0,
        ]);
        DB::statement("UPDATE ADDR_CODES SET y_coord = 'east' WHERE c_addr_id = 7");

        $result = app(CoordinateZeroCleanupService::class)->cleanTable('ADDR_CODES');

        $this->assertSame(0, $result['cleared']);
        $this->assertSame(
            [['c_addr_id' => 7, 'columns' => ['y_coord']]],
            $result['skipped_non_numeric']
        );
        // 那一列一個字都沒被動到。
        $row = $this->row(7);
        $this->assertSame('east', (string) $row->y_coord);
    }

    #[Test]
    public function testAnUnregisteredTableIsRefusedRatherThanSilentlyIgnored(): void {
        $this->artisan('cbdb:normalize-addr-coords', ['--table' => 'BIOG_MAIN'])
            ->assertExitCode(1);
    }

    #[Test]
    public function testAddressesIsRefusedBecauseItIsADerivedCache(): void {
        // `ADDRESSES` 有登記座標欄，但沒有可逐列定位的主鍵，而且它是由 ADDR_CODES
        // 重建的派生快取——在派生物上逐列改只會與源頭不一致。
        $this->artisan('cbdb:normalize-addr-coords', ['--table' => 'ADDRESSES'])
            ->assertExitCode(1);
    }

    #[Test]
    public function testTheTableNameIsNormalisedToTheRegisteredSpelling(): void {
        // `pairsFor()`／`keyColumnFor()` 是大小寫不敏感地比對，但 `DB::table()` 用的是原樣
        // 字串——於是 `--table=addr_codes` 會通過兩道閘門，然後在 case-sensitive 的 MySQL
        //（Linux 上的預設）拋未捕捉的 QueryException。
        //
        // **這裡刻意斷言「機制」而不是「結果」**：SQLite 的表名大小寫不敏感，所以「小寫
        // 表名也能清到資料」在測試環境裡**加不加歸一都會綠**（已用 mutation 確認）。
        // 回傳的 `table` 欄位是歸一後的值，那在兩種引擎上都一樣可觀察，拿掉歸一就會紅。
        $this->seedRows();

        $result = app(CoordinateZeroCleanupService::class)->cleanTable('addr_codes');

        $this->assertSame(
            'ADDR_CODES',
            $result['table'],
            '表名必須被歸一成登記的拼法，否則 DB::table() 會拿到原樣字串'
        );
        // 順帶確認它真的做了事（不是因為表名不對而空轉）。
        $this->assertSame(4, $result['cleared']);
    }
}
