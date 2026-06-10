<?php

namespace App\Http\Controllers;

use App\Jobs\FetchChgisMapJob;
use App\Services\ChgisMapManager;
use App\Services\MbtilesReader;
use App\Services\PersonMapPointsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CHGIS 地圖：底圖 tile 服務與下載狀態
 *
 * 設計見 docs/CHGIS_MAP_PLACE_LINK.md §5.2。
 */
class ChgisMapController extends Controller {
    /** 1×1 透明 PNG（圖磚未命中時回傳，避免 Leaflet 報錯）。 */
    private const TRANSPARENT_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function __construct(private readonly ChgisMapManager $manager) {
    }

    /**
     * 取出底圖圖磚（z/x/y，XYZ 慣例）。
     */
    public function tile(Request $request, int $z, int $x, int $y): Response {
        if (!$this->manager->isReady()) {
            abort(503, 'CHGIS 底圖尚未就緒');
        }

        $minZoom = (int) config('chgis_map.min_zoom', 3);
        $maxZoom = (int) config('chgis_map.max_zoom', 8);

        // 超出原生 zoom 或座標範圍 → 回透明磚（前端 overzoom 時亦安全）
        if ($z < $minZoom || $z > $maxZoom || !$this->withinTileRange($z, $x, $y)) {
            return $this->transparentTile();
        }

        try {
            $data = (new MbtilesReader($this->manager->path()))->tile($z, $x, $y);
        } catch (\Throwable $e) {
            // 底圖暫時不可用（如下載 rename 競態）時降級為透明磚，避免 Leaflet 整面報錯
            Log::warning('CHGIS 圖磚讀取失敗：' . $e->getMessage());

            return $this->transparentTile();
        }

        if ($data === null) {
            return $this->transparentTile();
        }

        // ETag 納入底圖檔 mtime，底圖更新後同 z/x/y 也會失效，避免回舊磚
        $version = @filemtime($this->manager->path()) ?: 0;

        $response = response($data, 200)
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'public, max-age=2592000')
            ->setEtag(md5($z . '/' . $x . '/' . $y . '/' . $version))
            ->setLastModified((new \DateTimeImmutable())->setTimestamp($version));

        $response->isNotModified($request);

        return $response;
    }

    /**
     * 底圖狀態；缺檔時於回應後背景觸發下載。
     */
    public function status(Request $request): JsonResponse {
        if ($this->manager->isReady()) {
            return response()->json(['ready' => true, 'state' => 'ready']);
        }

        $state = Cache::get(FetchChgisMapJob::STATE_KEY);
        $current = $state['state'] ?? null;

        // 進行中且未逾時 → 回 downloading；逾時（程序疑似死亡）則視為 stale 往下重新觸發
        if ($current === 'downloading' && !$this->isStale($state)) {
            return response()->json([
                'ready' => false,
                'state' => 'downloading',
                'started_at' => $state['started_at'] ?? null,
            ]);
        }

        // 失敗狀態：除非前端要求重試，否則直接回報失敗
        if ($current === 'failed' && !$request->boolean('retry')) {
            return response()->json([
                'ready' => false,
                'state' => 'failed',
                'message' => $state['message'] ?? null,
            ]);
        }

        // 觸發下載（回應送出後才執行，不阻塞此請求）
        $startedAt = time();

        Cache::put(
            FetchChgisMapJob::STATE_KEY,
            ['state' => 'downloading', 'started_at' => $startedAt],
            now()->addSeconds(FetchChgisMapJob::ttlSeconds())
        );
        FetchChgisMapJob::dispatchAfterResponse();

        return response()->json([
            'ready' => false,
            'state' => 'downloading',
            'started_at' => $startedAt,
        ]);
    }

    /**
     * 某人物所有「有效座標」的地點（addresses + offices），供地圖 modal 繪製。
     */
    public function personPoints(PersonMapPointsService $service, int $id): JsonResponse {
        return response()->json(['points' => $service->points($id)]);
    }

    /**
     * downloading 狀態是否已逾時（疑似下載程序死亡）。
     */
    private function isStale(?array $state): bool {
        $startedAt = $state['started_at'] ?? 0;

        return (time() - (int) $startedAt) >= FetchChgisMapJob::ttlSeconds();
    }

    /**
     * 回傳 1×1 透明 PNG。
     */
    private function transparentTile(): Response {
        return response(base64_decode(self::TRANSPARENT_PNG), 200)
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * x/y 是否落在該 zoom 的合法瓦片索引範圍 [0, 2^z - 1]。
     */
    private function withinTileRange(int $z, int $x, int $y): bool {
        $max = (1 << $z) - 1;

        return $x >= 0 && $x <= $max && $y >= 0 && $y <= $max;
    }
}
