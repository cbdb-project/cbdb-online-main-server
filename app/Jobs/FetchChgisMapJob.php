<?php

namespace App\Jobs;

use App\Services\ChgisMapManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 背景下載 CHGIS 底圖
 *
 * 由 status 端點於底圖缺檔時觸發（dispatchAfterResponse，不阻塞回應）。
 * 以 cache lock 避免併發重複下載，並把進度狀態寫入 cache 供前端輪詢。
 * 設計見 docs/CHGIS_MAP_PLACE_LINK.md §4.6。
 */
class FetchChgisMapJob implements ShouldQueue {
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** 下載狀態 cache 鍵。 */
    public const STATE_KEY = 'chgis_map_download_state';

    /** 互斥鎖鍵。 */
    public const LOCK_KEY = 'chgis_map_download_lock';

    /**
     * 鎖／狀態的存活秒數。
     *
     * 必須嚴格大於下載逾時，否則長下載期間鎖會提前到期、引發重複下載；
     * 狀態 TTL 與鎖一致，確保程序死亡後狀態能自然過期讓 status 重新觸發。
     */
    public static function ttlSeconds(): int {
        return (int) config('chgis_map.source.timeout', 1800) + 600;
    }

    public function handle(ChgisMapManager $manager): void {
        try {
            if ($manager->isReady()) {
                Cache::forget(self::STATE_KEY);

                return;
            }

            // 互斥：同時只允許一個下載。搶不到鎖代表已有別的程序在下載。
            $lock = Cache::lock(self::LOCK_KEY, self::ttlSeconds());
            if (!$lock->get()) {
                return;
            }

            try {
                Cache::put(
                    self::STATE_KEY,
                    ['state' => 'downloading', 'started_at' => time()],
                    now()->addSeconds(self::ttlSeconds())
                );
                $manager->download();
                Cache::forget(self::STATE_KEY);
                Log::info('CHGIS 底圖下載完成');
            } finally {
                $lock->release();
            }
        } catch (\Throwable $e) {
            // 涵蓋 isReady／取鎖／下載各階段的例外，統一落為 failed，避免永久卡 downloading。
            Cache::put(
                self::STATE_KEY,
                ['state' => 'failed', 'message' => $e->getMessage()],
                now()->addMinutes(10)
            );
            Log::warning('CHGIS 底圖下載失敗：' . $e->getMessage());
        }
    }
}
