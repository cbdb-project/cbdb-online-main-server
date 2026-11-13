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

    // ---- 圖磚路由無狀態（下游站跨站載入；routes/web.php 的註解、CHGIS_MAP_PLACE_LINK §5.1.1） ----

    /**
     * 模擬下游站的瀏覽器跨站載入圖磚：帶 Origin／Referer、不帶本站 cookie、Accept 是圖片。
     *
     * 下游的 <img> 預設是 no-cors 請求，瀏覽器不會送 Origin；這裡加上它只是讓情境更嚴苛，
     * 確認不論有無 Origin，回應都不帶 cookie。
     */
    private function getTileCrossSite(string $uri, array $headers = []): \Illuminate\Testing\TestResponse {
        return $this->withHeaders(array_merge([
            'Origin' => 'https://qa.example.org',
            'Referer' => 'https://qa.example.org/',
            'Accept' => 'image/avif,image/webp,image/png,image/*;q=0.8,*/*;q=0.5',
            'Sec-Fetch-Site' => 'cross-site',
        ], $headers))->get($uri);
    }

    /**
     * 回應不帶任何 cookie，且這次請求沒有啟動 session（file driver 下就不會落 session 檔）。
     */
    private function assertStateless(\Illuminate\Testing\TestResponse $response): void {
        $this->assertSame([], $response->headers->getCookies(), '圖磚回應不可帶 Set-Cookie');
        $this->assertFalse($response->headers->has('Set-Cookie'), '圖磚回應不可帶 Set-Cookie');
        // 不可用 session store 的 isStarted()：StartSession 在回應後 save()，save() 會把 started
        // 設回 false，那個斷言恆真。StartSession 會把 session 掛到請求上，看請求才看得到。
        $this->assertFalse(
            $this->app['request']->hasSession(),
            '圖磚請求不可啟動 session（file driver 下每次都會寫一個 session 檔）'
        );
        // 不可擋跨站載入：CORP 若是 same-origin／same-site，下游的 <img> 會被瀏覽器拒絕。
        $this->assertFalse(
            $response->headers->has('Cross-Origin-Resource-Policy'),
            '圖磚回應不可帶擋跨站的 Cross-Origin-Resource-Policy'
        );
    }

    public function testTileHitSetsNoCookie(): void {
        $this->makeDirectionalFixture();

        $response = $this->getTileCrossSite('/chgis-map/tiles/3/6/1');

        $response->assertOk();
        $this->assertSame($this->tileAtRow6, $response->getContent());
        $this->assertStateless($response);
    }

    public function testTileNotModifiedSetsNoCookie(): void {
        $this->makeDirectionalFixture();

        $etag = $this->getTileCrossSite('/chgis-map/tiles/3/6/1')->headers->get('ETag');
        $this->assertNotNull($etag);

        $response = $this->getTileCrossSite('/chgis-map/tiles/3/6/1', ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        $this->assertStateless($response);
    }

    public function testTransparentTileSetsNoCookie(): void {
        $this->makeDirectionalFixture();

        // miss、超出 zoom、超出座標範圍：三條透明磚路徑
        foreach (['/chgis-map/tiles/3/0/0', '/chgis-map/tiles/2/1/1', '/chgis-map/tiles/3/8/1'] as $uri) {
            $response = $this->getTileCrossSite($uri);

            $response->assertOk();
            $this->assertLessThan(200, strlen($response->getContent()), $uri);
            $this->assertStateless($response);
        }
    }

    public function testTileWhenBasemapMissingSetsNoCookie(): void {
        // 503 走例外處理器渲染錯誤頁：沒有 session 也必須渲染得出來（不可變成 500），且不帶 cookie。
        // 用 text/html 的 Accept 走 HTML 錯誤頁那條渲染路徑。
        $response = $this->getTileCrossSite('/chgis-map/tiles/3/6/1', ['Accept' => 'text/html']);

        $response->assertStatus(503);
        $this->assertStateless($response);
    }

    public function testTileWithStaleSessionCookieDoesNotStartSession(): void {
        // 同站（本站自己的地圖 modal）的請求會帶著本站的 session cookie；圖磚路由也不該讀它、
        // 不該續期它（續期＝回 Set-Cookie）。
        $this->makeDirectionalFixture();

        $response = $this->withUnencryptedCookie(config('session.cookie'), str_repeat('a', 40))
            ->withHeader('Accept', 'image/png')
            ->get('/chgis-map/tiles/3/6/1');

        $response->assertOk();
        $this->assertStateless($response);
    }

    public function testWebGroupRouteStillSetsCookies(): void {
        // 對照組：證明上面的斷言在這個測試環境裡抓得到 cookie（否則「沒有 cookie」可能只是
        // 測試環境本來就不發 cookie）。status 刻意留在 web 群組。
        $this->makeDirectionalFixture();

        $response = $this->get('/chgis-map/status');

        $response->assertOk();
        $this->assertNotSame([], $response->headers->getCookies());
        $this->assertTrue($this->app['request']->hasSession());
    }

    public function testTileWritesNoSessionFileUnderFileDriver(): void {
        // prod 的實際失敗模式：SESSION_DRIVER=file 時每個跨站圖磚請求都落一個 session 檔。
        // 這裡切到 file driver、指向空目錄，數檔案；status 是對照組（同一個設定下會寫檔）。
        $this->makeDirectionalFixture();

        $sessionDir = $this->dir . '/sessions';
        @mkdir($sessionDir, 0775, true);
        config(['session.driver' => 'file', 'session.files' => $sessionDir]);
        $this->app['session']->forgetDrivers();

        try {
            for ($i = 0; $i < 3; $i++) {
                $this->getTileCrossSite('/chgis-map/tiles/3/6/1')->assertOk();
            }
            $this->assertSame([], glob($sessionDir . '/*') ?: [], '圖磚請求不可寫 session 檔');

            $this->get('/chgis-map/status')->assertOk();
            $this->assertNotSame([], glob($sessionDir . '/*') ?: [], '對照組（web 群組）應寫出 session 檔');
        } finally {
            foreach (glob($sessionDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($sessionDir);
        }
    }

    public function testTileWithOversizedCoordinatesReturns404Not500(): void {
        // 超出 PHP int 範圍的數字字串轉成 int 參數會 TypeError（500）；路由的位數上限讓它們回 404。
        $this->makeDirectionalFixture();

        foreach ([
            '/chgis-map/tiles/3/99999999999999999999/1',
            '/chgis-map/tiles/3/1/99999999999999999999',
            '/chgis-map/tiles/999/1/1',
        ] as $uri) {
            $this->getTileCrossSite($uri)->assertNotFound();
        }

        // 位數上限之內的大數仍走控制器（超出 2^z−1 → 透明磚），不是 404。
        $this->getTileCrossSite('/chgis-map/tiles/3/9999999999/1')->assertOk();
        $this->getTileCrossSite('/chgis-map/tiles/99/1/1')->assertOk();
    }

    public function testTileRouteRunsNoWebGroupMiddleware(): void {
        // 機制層的護欄：直接看路由實際會跑的 middleware，不依賴測試環境的 session driver。
        // 在 route:cache 之下 excluded_middleware 一樣會序列化進快取，這個斷言對 prod 有效。
        $route = app('router')->getRoutes()->getByName('chgis-map.tile');
        $this->assertNotNull($route);

        $middleware = app('router')->gatherRouteMiddleware($route);

        foreach ([
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\SetLocaleMiddleware::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ] as $class) {
            $this->assertNotContains($class, $middleware, $class . ' 不可出現在圖磚路由上');
        }

        // 對照：同一個檔案裡的 status 路由仍有 StartSession，證明 gatherRouteMiddleware 有展開群組。
        $status = app('router')->getRoutes()->getByName('chgis-map.status');
        $this->assertContains(
            \Illuminate\Session\Middleware\StartSession::class,
            app('router')->gatherRouteMiddleware($status)
        );
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
