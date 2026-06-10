<?php

namespace Tests\Feature;

use App\Services\PersonMapPointsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 人物地圖點位（personPoints 端點與 PersonMapPointsService）測試
 *
 * 對應 docs/CHGIS_MAP_PLACE_LINK.md §5.2、§5.3。
 */
class PersonMapPointsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // 固定座標判定範圍，避免受 .env 影響
        config([
            'chgis_map.epsilon' => 1e-7,
            'chgis_map.mercator_lat_limit' => 85.0511,
            'chgis_map.bounds' => ['west' => 58.5372, 'south' => -62.6348, 'east' => 152.24, 'north' => 82.7288],
            'chgis_map.sane_bounds' => ['enabled' => true, 'west' => 70.0, 'south' => 15.0, 'east' => 140.0, 'north' => 55.0],
        ]);

        $this->createSchema();
        $this->seedData();
    }

    protected function tearDown(): void {
        foreach (['BIOG_MAIN', 'BIOG_ADDR_DATA', 'ADDR_CODES', 'POSTED_TO_OFFICE_DATA', 'POSTED_TO_ADDR_DATA', 'OFFICE_CODES'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function createSchema(): void {
        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name')->nullable();
        });

        Schema::create('BIOG_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_addr_id');
            $table->integer('c_addr_type');
            $table->integer('c_sequence');
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_lastyear')->nullable();
        });

        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
            $table->double('x_coord')->nullable();
            $table->double('y_coord')->nullable();
        });

        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_office_id');
            $table->integer('c_posting_id');
            $table->integer('c_sequence')->nullable();
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_lastyear')->nullable();
        });

        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_office_id');
            $table->integer('c_posting_id');
            $table->integer('c_addr_id');
        });

        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->string('c_office_chn')->nullable();
            $table->string('c_office_trans')->nullable();
        });
    }

    private function seedData(): void {
        DB::table('BIOG_MAIN')->insert(['c_personid' => 1001, 'c_name_chn' => '蘇軾', 'c_name' => 'Su Shi']);

        // 地址：100 有效；101 為 0,0 無效；102 超界無效
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 100, 'c_name_chn' => '開封', 'c_name' => 'Kaifeng', 'x_coord' => 114.3, 'y_coord' => 34.8],
            ['c_addr_id' => 101, 'c_name_chn' => '無座標地', 'c_name' => 'NoCoord', 'x_coord' => 0, 'y_coord' => 0],
            ['c_addr_id' => 102, 'c_name_chn' => '超界地', 'c_name' => 'OutOfBounds', 'x_coord' => 200, 'y_coord' => 10],
            // 官職地點：200 有效；201 為 0,0 無效
            ['c_addr_id' => 200, 'c_name_chn' => '密州', 'c_name' => 'Mizhou', 'x_coord' => 119.4, 'y_coord' => 36.7],
            ['c_addr_id' => 201, 'c_name_chn' => '無座標官地', 'c_name' => 'NoCoordOffice', 'x_coord' => 0, 'y_coord' => 0],
        ]);

        DB::table('BIOG_ADDR_DATA')->insert([
            ['c_personid' => 1001, 'c_addr_id' => 100, 'c_addr_type' => 1, 'c_sequence' => 1, 'c_firstyear' => 1037, 'c_lastyear' => 1101],
            ['c_personid' => 1001, 'c_addr_id' => 101, 'c_addr_type' => 1, 'c_sequence' => 2, 'c_firstyear' => null, 'c_lastyear' => null],
            ['c_personid' => 1001, 'c_addr_id' => 102, 'c_addr_type' => 2, 'c_sequence' => 3, 'c_firstyear' => null, 'c_lastyear' => null],
        ]);

        DB::table('OFFICE_CODES')->insert([
            ['c_office_id' => 9001, 'c_office_chn' => '知州'],
            ['c_office_id' => 9002, 'c_office_chn' => '通判'],
        ]);

        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            ['c_personid' => 1001, 'c_office_id' => 9001, 'c_posting_id' => 5001, 'c_sequence' => 1, 'c_firstyear' => 1074, 'c_lastyear' => 1076],
            ['c_personid' => 1001, 'c_office_id' => 9002, 'c_posting_id' => 5002, 'c_sequence' => 2, 'c_firstyear' => 1071, 'c_lastyear' => 1074],
        ]);

        DB::table('POSTED_TO_ADDR_DATA')->insert([
            ['c_personid' => 1001, 'c_office_id' => 9001, 'c_posting_id' => 5001, 'c_addr_id' => 200],
            ['c_personid' => 1001, 'c_office_id' => 9002, 'c_posting_id' => 5002, 'c_addr_id' => 201],
        ]);
    }

    // ---- endpoint ----

    public function testEndpointReturnsOnlyValidPoints(): void {
        $response = $this->getJson('/basicinformation/1001/map-points');

        $response->assertOk();
        $points = $response->json('points');

        // 只有 1 個有效地址（100）+ 1 個有效官職地點（200）= 2
        $this->assertCount(2, $points);

        $byKey = collect($points)->keyBy('key');
        $this->assertTrue($byKey->has('addr:100:1:1'));
        // 點位 key 含 c_office_id，避免不同官職同 posting_id 碰撞
        $this->assertTrue($byKey->has('office:9001:5001:200'));

        $addr = $byKey['addr:100:1:1'];
        $this->assertSame('address', $addr['source']);
        $this->assertSame(100, $addr['addr_id']);
        $this->assertEqualsWithDelta(114.3, $addr['lon'], 0.0001);
        $this->assertEqualsWithDelta(34.8, $addr['lat'], 0.0001);

        $office = $byKey['office:9001:5001:200'];
        $this->assertSame('office', $office['source']);
        $this->assertSame('知州 · 密州', $office['label']);
    }

    public function testEndpointExcludesInvalidCoordinates(): void {
        $points = $this->getJson('/basicinformation/1001/map-points')->json('points');
        $addrIds = collect($points)->pluck('addr_id')->all();

        // 0,0 與超界座標不出現
        $this->assertNotContains(101, $addrIds);
        $this->assertNotContains(102, $addrIds);
        $this->assertNotContains(201, $addrIds);
    }

    public function testEndpointReturnsEmptyForUnknownPerson(): void {
        $this->getJson('/basicinformation/999999/map-points')
            ->assertOk()
            ->assertJson(['points' => []]);
    }

    // ---- service ----

    public function testAddressEntriesFlagsLinkability(): void {
        $entries = app(PersonMapPointsService::class)->addressEntries(1001);

        $this->assertCount(3, $entries);
        $byId = collect($entries)->keyBy('addr_id');
        $this->assertTrue($byId[100]['linkable']);
        $this->assertFalse($byId[101]['linkable']);
        $this->assertFalse($byId[102]['linkable']);
        // 無效座標不輸出 lon/lat
        $this->assertNull($byId[101]['lon']);
        $this->assertNull($byId[102]['lat']);
    }

    public function testOfficePlacesByPostingGroupsAndFlags(): void {
        $byPosting = app(PersonMapPointsService::class)->officePlacesByPosting(1001);

        // 分組鍵為 "{office_id}:{posting_id}"
        $this->assertArrayHasKey('9001:5001', $byPosting);
        $this->assertArrayHasKey('9002:5002', $byPosting);

        $this->assertTrue($byPosting['9001:5001'][0]['linkable']);
        $this->assertSame('知州 · 密州', $byPosting['9001:5001'][0]['label']);

        $this->assertFalse($byPosting['9002:5002'][0]['linkable']);
        $this->assertNull($byPosting['9002:5002'][0]['lon']);
    }

    public function testOrphanAddressDoesNotErrorAndIsNotLinkable(): void {
        // BIOG_ADDR_DATA 指向不存在的 ADDR_CODES（孤兒外鍵）
        DB::table('BIOG_ADDR_DATA')->insert([
            'c_personid' => 1001, 'c_addr_id' => 88888, 'c_addr_type' => 9, 'c_sequence' => 9,
            'c_firstyear' => null, 'c_lastyear' => null,
        ]);

        $entries = app(PersonMapPointsService::class)->addressEntries(1001);
        $orphan = collect($entries)->firstWhere('addr_id', 88888);

        $this->assertNotNull($orphan);
        $this->assertFalse($orphan['linkable']);
        $this->assertNull($orphan['name_chn']);
        $this->assertSame('', $orphan['label']);
        // 不應出現在地圖點位中
        $points = app(PersonMapPointsService::class)->points(1001);
        $this->assertNotContains(88888, collect($points)->pluck('addr_id')->all());
    }

    public function testAddressLabelFallsBackFromEmptyChineseToEnglish(): void {
        DB::table('ADDR_CODES')->where('c_addr_id', 100)->update([
            'c_name_chn' => '',
            'c_name' => 'Kaifeng',
        ]);

        $entry = collect(app(PersonMapPointsService::class)->addressEntries(1001))
            ->firstWhere('addr_id', 100);

        $this->assertNotNull($entry);
        $this->assertSame('Kaifeng', $entry['label']);
    }

    public function testOfficeIdMinusOnePlaceholderIsExcluded(): void {
        // c_office_id = -1 為「無地點」佔位，應被 offices_addr 關聯排除
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 1001, 'c_office_id' => -1, 'c_posting_id' => 5003, 'c_addr_id' => 200,
        ]);

        $byPosting = app(PersonMapPointsService::class)->officePlacesByPosting(1001);
        $this->assertArrayNotHasKey('-1:5003', $byPosting);
    }

    public function testSamePostingIdDifferentOfficeDoesNotMisassignName(): void {
        // 同 posting_id=5001 但不同 office（9003 縣令，地點 addr 100），不可取到 9001(知州) 的官名
        DB::table('OFFICE_CODES')->insert(['c_office_id' => 9003, 'c_office_chn' => '縣令']);
        DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 1001, 'c_office_id' => 9003, 'c_posting_id' => 5001, 'c_sequence' => 3, 'c_firstyear' => 1080, 'c_lastyear' => 1082,
        ]);
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 1001, 'c_office_id' => 9003, 'c_posting_id' => 5001, 'c_addr_id' => 100,
        ]);

        $byPosting = app(PersonMapPointsService::class)->officePlacesByPosting(1001);

        // 兩個任命各自獨立分組，官名不互相覆蓋
        $this->assertSame('知州 · 密州', $byPosting['9001:5001'][0]['label']);
        $this->assertSame('縣令 · 開封', $byPosting['9003:5001'][0]['label']);
    }

    public function testMultipleAddressesUnderSamePostingAreGroupedAndSorted(): void {
        // 同一任命（9001:5001）多個地點，應分在同組並依 addr_id 排序
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 1001, 'c_office_id' => 9001, 'c_posting_id' => 5001, 'c_addr_id' => 100,
        ]);

        $places = app(PersonMapPointsService::class)->officePlacesByPosting(1001)['9001:5001'];

        $this->assertCount(2, $places);
        $this->assertSame(100, $places[0]['addr_id']);
        $this->assertSame(200, $places[1]['addr_id']);
    }

    public function testOfficeOrphanAddressDoesNotErrorAndIsNotLinkable(): void {
        DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 1001, 'c_office_id' => 9001, 'c_posting_id' => 5001, 'c_addr_id' => 88888,
        ]);

        $response = $this->getJson('/basicinformation/1001/map-points');
        $response->assertOk();

        $places = app(PersonMapPointsService::class)->officePlacesByPosting(1001)['9001:5001'];
        $orphan = collect($places)->firstWhere('addr_id', 88888);

        $this->assertNotNull($orphan);
        $this->assertSame('office:9001:5001:88888', $orphan['key']);
        $this->assertFalse($orphan['linkable']);
        $this->assertNull($orphan['name_chn']);
        $this->assertNull($orphan['name']);
        $this->assertNull($orphan['lon']);
        $this->assertNull($orphan['lat']);
        $this->assertSame('知州 · addr_id:88888', $orphan['label']);
        $this->assertNotContains(88888, collect($response->json('points'))->pluck('addr_id')->all());
    }

    public function testOfficeLabelFallsBackToEnglishAndAddrId(): void {
        DB::table('OFFICE_CODES')->where('c_office_id', 9001)->update([
            'c_office_chn' => null,
            'c_office_trans' => 'Prefect',
        ]);
        DB::table('ADDR_CODES')->where('c_addr_id', 200)->update([
            'c_name_chn' => null,
            'c_name' => null,
        ]);

        $place = app(PersonMapPointsService::class)->officePlacesByPosting(1001)['9001:5001'][0];

        $this->assertSame('Prefect · addr_id:200', $place['label']);
    }
}
