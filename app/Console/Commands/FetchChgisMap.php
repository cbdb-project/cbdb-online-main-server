<?php

namespace App\Console\Commands;

use App\Services\ChgisMapManager;
use Illuminate\Console\Command;

/**
 * 下載 CHGIS 底圖（chgis_map.mbtiles）
 *
 * 部署時呼叫；冪等：若本地已有合格檔案則跳過。失敗回非零碼但不丟例外，
 * 供 deploy.sh 以非致命方式處理（地圖功能會於首次存取時重試）。
 * 設計見 docs/CHGIS_MAP_PLACE_LINK.md §4.4。
 */
class FetchChgisMap extends Command {
    /**
     * @var string
     */
    protected $signature = 'cbdb:fetch-chgis-map
                            {--force : 強制重新下載，即使本地已有合格檔案}';

    /**
     * @var string
     */
    protected $description = '自 HuggingFace 下載 CHGIS 底圖 chgis_map.mbtiles（缺檔才下載）';

    public function handle(ChgisMapManager $manager): int {
        $path = $manager->path();

        if (!$this->option('force') && $manager->isReady()) {
            $this->info("CHGIS 底圖已存在，跳過下載：{$path}");

            return self::SUCCESS;
        }

        $this->info('開始下載 CHGIS 底圖，來源：' . $manager->sourceUrl());
        $this->line('目標路徑：' . $path);

        $start = microtime(true);

        try {
            $manager->download();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $seconds = round(microtime(true) - $start, 1);
        $sizeMb = round(filesize($path) / 1048576, 1);
        $this->info("下載完成：{$sizeMb} MB，耗時 {$seconds} 秒");

        return self::SUCCESS;
    }
}
