<?php

namespace Tests\Feature;

use App\Jobs\FetchChgisMapJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use PDO;
use Tests\TestCase;

/**
 * ChgisMapController（tile / status）測試
 *
 * 對應 docs/CHGIS_MAP_PLACE_LINK.md §5.2。
 */
class ChgisMapControllerTest extends TestCase {
    private string $dir;
    private string $path;

    /** 兩塊內容不同的圖磚（用於驗證 TMS flip 方向與精確值）。 */
    private string $tileAtRow6 = "\x89PNG\r\n\x1a\nTILE-AT-TMS-ROW-6";
    private string $tileAtRow1 = "\x89PNG\r\n\x1a\nTILE-AT-TMS-ROW-1";

    protected function setUp(): void {
        parent::setUp();

        $this->dir = storage_path('framework/testing/chgis-ctrl-' . getmypid());
        $this->path = $this->dir . '/chgis_map.mbtiles';

        config([
            'chgis_map.source.path' => $this->path,
            'chgis_map.source.expected_min_bytes' => 16,
            'chgis_map.min_zoom' => 3,
            'chgis_map.max_zoom' => 8,
        ]);

        $this->cleanup();
    }

    protected function tearDown(): void {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        if (is_dir($this->dir)) {
            @rmdir($this->dir);
        }
    }

    /**
     * 建立 mbtiles fixture，於指定 (zoom, column, tile_row) 寫入圖磚。
     *
     * 注意：tile_row 直接以 TMS 列號寫入（不經 XYZ 公式換算），讓測試獨立於
     * controller 的 flip 公式，避免「寫入與讀取共用同一公式」的套套邏輯。
     *
     * @param array<int, array{z:int, col:int, row:int, data:string}> $tiles
     */
    private function makeFixture(array $tiles): void {
        @mkdir($this->dir, 0775, true);

        $pdo = new PDO('sqlite:' . $this->path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE metadata (name TEXT, value TEXT)');
        $pdo->exec('CREATE TABLE tiles (zoom_level INTEGER, tile_column INTEGER, tile_row INTEGER, tile_data BLOB)');
        $pdo->prepare('INSERT INTO metadata(name, value) VALUES (?, ?)')->execute(['format', 'png']);

        $stmt = $pdo->prepare('INSERT INTO tiles(zoom_level, tile_column, tile_row, tile_data) VALUES (?, ?, ?, ?)');
        foreach ($tiles as $t) {
            $stmt->bindValue(1, $t['z'], PDO::PARAM_INT);
            $stmt->bindValue(2, $t['col'], PDO::PARAM_INT);
            $stmt->bindValue(3, $t['row'], PDO::PARAM_INT);
            $stmt->bindValue(4, $t['data'], PDO::PARAM_LOB);
            $stmt->execute();
        }
        $pdo = null;
    }

    /**
     * 預設 fixture：z=3, col=6，於 TMS row 6 與 row 1 放不同內容，
     * 用於驗證 XYZ↔TMS 的 flip 方向與精確值。
     */
    private function makeDirectionalFixture(): void {
        $this->makeFixture([
            ['z' => 3, 'col' => 6, 'row' => 6, 'data' => $this->tileAtRow6],
            ['z' => 3, 'col' => 6, 'row' => 1, 'data' => $this->tileAtRow1],
        ]);
    }

    /**
     * 於既有 mbtiles 追加一塊圖磚（tile_row 直接以 TMS 列號寫入）。
     */
    private function insertTile(int $z, int $col, int $row, string $data): void {
        $pdo = new PDO('sqlite:' . $this->path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare('INSERT INTO tiles(zoom_level, tile_column, tile_row, tile_data) VALUES (?, ?, ?, ?)');
        $stmt->bindValue(1, $z, PDO::PARAM_INT);
        $stmt->bindValue(2, $col, PDO::PARAM_INT);
        $stmt->bindValue(3, $row, PDO::PARAM_INT);
        $stmt->bindValue(4, $data, PDO::PARAM_LOB);
        $stmt->execute();
        $pdo = null;
    }

    // ---- tile ----

    public function testTileHitUsesCorrectTmsFlip(): void {
        // MBTiles 規格：XYZ (z=3, y=1) → TMS tile_row = 2^3 - 1 - 1 = 6（獨立硬值）
        // 故請求 /3/6/1 必須命中 TMS row 6 的內容，而非 row 1。
        $this->makeDirectionalFixture();

        $response = $this->get('/chgis-map/tiles/3/6/1');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertSame($this->tileAtRow6, $response->getContent());
        $this->assertNotSame($this->tileAtRow1, $response->getContent());
    }

    public function testTileReturnsStableEtagAndSupportsNotModified(): void {
        $this->makeDirectionalFixture();

        $first = $this->get('/chgis-map/tiles/3/6/1');
        $first->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->withHeader('If-None-Match', $etag)
            ->get('/chgis-map/tiles/3/6/1')
            ->assertStatus(304);
    }

    public function testTileEtagChangesWhenMbtilesFileMtimeChanges(): void {
        $this->makeDirectionalFixture();

        $first = $this->get('/chgis-map/tiles/3/6/1');
        $first->assertOk();
        $firstEtag = $first->headers->get('ETag');

        clearstatcache(true, $this->path);
        touch($this->path, filemtime($this->path) + 2);
        clearstatcache(true, $this->path);

        $second = $this->get('/chgis-map/tiles/3/6/1');
        $second->assertOk();

        $this->assertNotSame($firstEtag, $second->headers->get('ETag'));
    }

    public function testTileSendsRevalidatingCacheControl(): void {
        $this->makeDirectionalFixture();

        $response = $this->get('/chgis-map/tiles/3/6/1');

        $response->assertOk();
        // 必須每次帶 ETag 重新驗證（no-cache）；不可有長 fresh 期，
        // 否則底圖更新後瀏覽器於 fresh 期內不驗證，會繼續顯示舊磚（舊河流色）。
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringNotContainsString('max-age', $cacheControl);
    }

    public function testTransparentTileSendsRevalidatingCacheControl(): void {
        $this->makeDirectionalFixture();

        // miss → 透明磚
        $response = $this->get('/chgis-map/tiles/3/0/0');

        $response->assertOk();
        $this->assertLessThan(200, strlen($response->getContent()));
        // 透明磚同樣需 no-cache：避免該格由「透明」轉為「底圖已覆蓋」後仍顯示快取空白磚。
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringNotContainsString('max-age', $cacheControl);
    }

    public function testTransparentTileSupportsNotModified(): void {
        $this->makeDirectionalFixture();

        $first = $this->get('/chgis-map/tiles/3/0/0');
        $first->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->withHeader('If-None-Match', $etag)
            ->get('/chgis-map/tiles/3/0/0')
            ->assertStatus(304);
    }

    public function testTransparentTileEtagChangesWhenMbtilesFileMtimeChanges(): void {
        $this->makeDirectionalFixture();

        $first = $this->get('/chgis-map/tiles/3/0/0');
        $first->assertOk();
        $firstEtag = $first->headers->get('ETag');

        clearstatcache(true, $this->path);
        touch($this->path, filemtime($this->path) + 2);
        clearstatcache(true, $this->path);

        $second = $this->get('/chgis-map/tiles/3/0/0');
        $second->assertOk();

        $this->assertNotSame($firstEtag, $second->headers->get('ETag'));
    }

    public function testTransparentTileBecomingRealReturns200NotStale304(): void {
        // 本次修復的核心情境：某格先是 miss（透明磚），底圖更新後該格已有實磚，
        // 帶舊透明磚 ETag 重抓時必須回 200＋實磚內容，而非沿用快取的 304 空白磚。
        $this->makeDirectionalFixture();

        // 先 miss → 透明磚，取得其 ETag（XYZ 3/0/0 未在 fixture 中）
        $first = $this->get('/chgis-map/tiles/3/0/0');
        $first->assertOk();
        $this->assertLessThan(200, strlen($first->getContent()));
        $transparentEtag = $first->headers->get('ETag');
        $this->assertNotNull($transparentEtag);

        // 底圖更新：於該 z/x/y 寫入實磚（XYZ 3/0/0 → TMS row = 2^3-1-0 = 7）並推進 mtime
        $realTile = "\x89PNG\r\n\x1a\nREAL-TILE-AT-3-0-0";
        $this->insertTile(3, 0, 7, $realTile);
        clearstatcache(true, $this->path);
        touch($this->path, filemtime($this->path) + 2);
        clearstatcache(true, $this->path);

        // 帶舊透明磚 ETag 重抓 → 必須 200＋實磚內容，不可回 304
        $second = $this->withHeader('If-None-Match', $transparentEtag)
            ->get('/chgis-map/tiles/3/0/0');
        $second->assertOk();
        $this->assertSame($realTile, $second->getContent());
    }

    public function testTileFlipDirectionIsConsistent(): void {
        // 反向：XYZ (z=3, y=6) → TMS row = 2^3 - 1 - 6 = 1，應命中 row 1 內容。
        $this->makeDirectionalFixture();

        $response = $this->get('/chgis-map/tiles/3/6/6');

        $response->assertOk();
        $this->assertSame($this->tileAtRow1, $response->getContent());
    }

    public function testTileMissReturnsTransparentPng(): void {
        $this->makeDirectionalFixture();

        // 不存在的圖磚
        $response = $this->get('/chgis-map/tiles/3/0/0');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertLessThan(200, strlen($response->getContent()));
    }

    public function testTileOutOfZoomReturnsTransparent(): void {
        $this->makeDirectionalFixture();

        // z=2 低於 min_zoom(3)
        $response = $this->get('/chgis-map/tiles/2/1/1');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertLessThan(200, strlen($response->getContent()));
    }

    public function testTileOutOfRangeReturnsTransparent(): void {
        $this->makeDirectionalFixture();

        $response = $this->get('/chgis-map/tiles/3/8/1');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertLessThan(200, strlen($response->getContent()));
    }

    public function testTileWhenBasemapMissingReturns503(): void {
        // 不建立 fixture → isReady false
        $this->get('/chgis-map/tiles/3/6/1')->assertStatus(503);
    }

    // ---- status ----

    public function testStatusReadyWhenFilePresent(): void {
        $this->makeDirectionalFixture();

        $this->get('/chgis-map/status')
            ->assertOk()
            ->assertJson(['ready' => true, 'state' => 'ready']);
    }

    public function testStatusTriggersDownloadWhenMissing(): void {
        Bus::fake();

        $response = $this->get('/chgis-map/status');

        $response
            ->assertOk()
            ->assertJson(['ready' => false, 'state' => 'downloading']);

        Bus::assertDispatchedAfterResponse(FetchChgisMapJob::class);
        $state = Cache::get(FetchChgisMapJob::STATE_KEY);
        $this->assertSame('downloading', $state['state'] ?? null);
        $this->assertSame($state['started_at'] ?? null, $response->json('started_at'));
    }

    public function testStatusReturnsDownloadingWithoutRedispatch(): void {
        Bus::fake();
        Cache::put(
            FetchChgisMapJob::STATE_KEY,
            ['state' => 'downloading', 'started_at' => time()],
            now()->addMinutes(10)
        );

        $this->get('/chgis-map/status')
            ->assertOk()
            ->assertJson([
                'ready' => false,
                'state' => 'downloading',
                'started_at' => Cache::get(FetchChgisMapJob::STATE_KEY)['started_at'],
            ]);

        Bus::assertNothingDispatched();
    }

    public function testStatusRedispatchesWhenDownloadingIsStale(): void {
        Bus::fake();
        // started_at 遠早於 ttl，視為下載程序已死亡 → 應重新觸發，避免永久卡 downloading
        Cache::put(
            FetchChgisMapJob::STATE_KEY,
            ['state' => 'downloading', 'started_at' => time() - FetchChgisMapJob::ttlSeconds() - 10],
            now()->addMinutes(60)
        );

        $response = $this->get('/chgis-map/status');

        $response
            ->assertOk()
            ->assertJson(['ready' => false, 'state' => 'downloading']);

        Bus::assertDispatchedAfterResponse(FetchChgisMapJob::class);
        $this->assertSame(
            Cache::get(FetchChgisMapJob::STATE_KEY)['started_at'] ?? null,
            $response->json('started_at')
        );
    }

    public function testStatusReturnsFailedWithoutRetry(): void {
        Bus::fake();
        Cache::put(FetchChgisMapJob::STATE_KEY, ['state' => 'failed', 'message' => '網路錯誤'], now()->addMinutes(10));

        $this->get('/chgis-map/status')
            ->assertOk()
            ->assertJson(['ready' => false, 'state' => 'failed', 'message' => '網路錯誤']);

        Bus::assertNothingDispatched();
    }

    public function testStatusRetryRedispatchesAfterFailure(): void {
        Bus::fake();
        Cache::put(FetchChgisMapJob::STATE_KEY, ['state' => 'failed', 'message' => '網路錯誤'], now()->addMinutes(10));

        $response = $this->get('/chgis-map/status?retry=1');

        $response
            ->assertOk()
            ->assertJson(['ready' => false, 'state' => 'downloading']);

        Bus::assertDispatchedAfterResponse(FetchChgisMapJob::class);
        $this->assertNotNull($response->json('started_at'));
    }
}
