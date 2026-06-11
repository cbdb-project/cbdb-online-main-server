<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * cbdb:fetch-chgis-map 指令測試
 *
 * 對應 docs/CHGIS_MAP_PLACE_LINK.md §4.4。
 */
class FetchChgisMapTest extends TestCase {
    private string $dir;
    private string $path;

    /** 產生真正的 SQLite/mbtiles 測試 fixture，避免只模擬檔頭。 */
    private function fakeMbtiles(int $minSize = 2048): string {
        $tmp = tempnam(sys_get_temp_dir(), 'chgis-mbtiles-');
        if ($tmp === false) {
            throw new \RuntimeException('無法建立暫存 SQLite fixture');
        }

        try {
            $pdo = new PDO('sqlite:' . $tmp);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE metadata (name TEXT PRIMARY KEY, value TEXT)');
            $pdo->exec('CREATE TABLE tiles (zoom_level INTEGER, tile_column INTEGER, tile_row INTEGER, tile_data BLOB)');
            $pdo->prepare('INSERT INTO metadata(name, value) VALUES (?, ?)')
                ->execute(['format', 'pbf']);

            while (filesize($tmp) < $minSize) {
                $pdo->prepare('INSERT INTO tiles(zoom_level, tile_column, tile_row, tile_data) VALUES (?, ?, ?, ?)')
                    ->execute([0, random_int(0, PHP_INT_MAX), 0, random_bytes(512)]);
            }

            $pdo = null;

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    protected function setUp(): void {
        parent::setUp();

        $this->dir = storage_path('framework/testing/chgis-' . getmypid());
        $this->path = $this->dir . '/chgis_map.mbtiles';

        config([
            'chgis_map.source.url' => 'https://huggingface.test/chgis_map.mbtiles',
            'chgis_map.source.path' => $this->path,
            'chgis_map.source.expected_min_bytes' => 16,
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

    public function testDownloadsWhenMissing(): void {
        $body = $this->fakeMbtiles();
        Http::fake([
            '*' => Http::response($body, 200),
        ]);

        $this->artisan('cbdb:fetch-chgis-map')
            ->assertExitCode(0);

        $this->assertFileExists($this->path);
        $this->assertSame($body, file_get_contents($this->path));
        $this->assertCount(0, glob($this->dir . '/*.part*') ?: []);
        $this->assertCount(0, glob($this->dir . '/*.bak.*') ?: []);
    }

    public function testSkipsWhenAlreadyPresent(): void {
        @mkdir($this->dir, 0775, true);
        file_put_contents($this->path, $this->fakeMbtiles());

        $etag = '"test-etag-v1"';
        file_put_contents($this->dir . '/chgis_map.version', $etag);

        Http::fake(['*' => Http::response('', 200, ['ETag' => $etag])]);

        $this->artisan('cbdb:fetch-chgis-map')
            ->expectsOutputToContain('已是最新')
            ->assertExitCode(0);

        // 只有一次 HEAD（版本比對），沒有 GET 下載
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => $req->method() === 'HEAD');
    }

    public function testDetectsUpdateAndRedownloads(): void {
        @mkdir($this->dir, 0775, true);
        file_put_contents($this->path, $this->fakeMbtiles(64));
        file_put_contents($this->dir . '/chgis_map.version', '"old-etag"');

        $newBody = $this->fakeMbtiles(128);
        $newEtag = '"new-etag-v2"';

        Http::fake(['*' => Http::response($newBody, 200, ['ETag' => $newEtag])]);

        $this->artisan('cbdb:fetch-chgis-map')
            ->expectsOutputToContain('新版本')
            ->assertExitCode(0);

        $this->assertSame($newBody, file_get_contents($this->path));
        $this->assertSame($newEtag, file_get_contents($this->dir . '/chgis_map.version'));
    }

    public function testRedownloadsWhenExistingFileIsCorrupt(): void {
        // 既有檔體積達標但非 SQLite（壞檔）→ isReady 應回 false 而重新下載
        @mkdir($this->dir, 0775, true);
        file_put_contents($this->path, str_repeat('X', 2048));

        $body = $this->fakeMbtiles();
        Http::fake(['*' => Http::response($body, 200)]);

        $this->artisan('cbdb:fetch-chgis-map')
            ->assertExitCode(0);

        $this->assertSame($body, file_get_contents($this->path));
        $this->assertCount(0, glob($this->dir . '/*.bak.*') ?: []);
    }

    public function testForceRedownloadsEvenIfPresent(): void {
        @mkdir($this->dir, 0775, true);
        file_put_contents($this->path, $this->fakeMbtiles(64));

        $body = $this->fakeMbtiles(128);
        Http::fake(['*' => Http::response($body, 200)]);

        $this->artisan('cbdb:fetch-chgis-map', ['--force' => true])
            ->assertExitCode(0);

        $this->assertSame($body, file_get_contents($this->path));
        $this->assertCount(0, glob($this->dir . '/*.bak.*') ?: []);
    }

    public function testFailsOnHttpError(): void {
        Http::fake(['*' => Http::response('not found', 404)]);

        $this->artisan('cbdb:fetch-chgis-map')
            ->expectsOutputToContain('HTTP 狀態碼')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->path);
        $this->assertCount(0, glob($this->dir . '/*.part*') ?: []);
        $this->assertCount(0, glob($this->dir . '/*.bak.*') ?: []);
    }

    public function testFailsOnIncompleteDownload(): void {
        // 回傳體積低於 expected_min_bytes(16) 的內容
        Http::fake(['*' => Http::response('tiny', 200)]);

        $this->artisan('cbdb:fetch-chgis-map')
            ->expectsOutputToContain('不完整')
            ->assertExitCode(1);

        // 半截檔不應留下、也不應被視為就緒
        $this->assertFileDoesNotExist($this->path);
        $this->assertCount(0, glob($this->dir . '/*.part*') ?: []);
        $this->assertCount(0, glob($this->dir . '/*.bak.*') ?: []);
    }

    public function testFailsOnNonMbtilesContent(): void {
        // 體積達標(>=16)但非 SQLite 格式（例如 HTML 錯誤頁）→ 應被魔術位元組擋下
        Http::fake(['*' => Http::response(str_repeat('<html>error</html>', 8), 200)]);

        $this->artisan('cbdb:fetch-chgis-map')
            ->expectsOutputToContain('非 mbtiles')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->path);
        $this->assertCount(0, glob($this->dir . '/*.part*') ?: []);
        $this->assertCount(0, glob($this->dir . '/*.bak.*') ?: []);
    }
}
