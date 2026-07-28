<?php

namespace App\Console\Commands;

use App\Support\TradSimpMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 更新 vendored 的 third_party/opencc/TSCharacters.txt（OpenCC 原始字典檔）。
 *
 * 這個指令**只做「下載最新版本、覆蓋 vendored 檔案」這一件事**，不解析、不產生任何衍生
 * 檔案——App\Support\TradSimpMap 會在讀取當下直接解析這份原始檔，覆蓋後不需要額外的
 * 「重新產生」步驟。這是開發環境／CI 執行的操作，不在生產環境執行：覆蓋後用
 * `git diff third_party/opencc/TSCharacters.txt` 審查變化，提交後隨一般部署流程上線。
 */
class SyncOpenccTradSimpSource extends Command {
    /**
     * @var string
     */
    protected $signature = 'cbdb:sync-opencc-trad-simp
                            {--url=https://raw.githubusercontent.com/BYVoid/OpenCC/refs/heads/master/data/dictionary/TSCharacters.txt : Source URL for the OpenCC dictionary file}
                            {--output= : Override output file path (defaults to third_party/opencc/TSCharacters.txt)}';

    /**
     * @var string
     */
    protected $description = '下載最新 OpenCC TSCharacters.txt，覆蓋 third_party/opencc/TSCharacters.txt';

    public function handle(): int {
        $url = $this->option('url');
        $outputPath = $this->option('output') ?: TradSimpMap::sourcePath();

        $this->logInfo(sprintf('Downloading %s ...', $url));

        $context = stream_context_create(['http' => ['timeout' => 30]]);
        $contents = @file_get_contents($url, false, $context);
        if ($contents === false) {
            $this->logError('無法下載 OpenCC 對照檔。');

            return 1;
        }

        file_put_contents($outputPath, $contents);

        $mappings = TradSimpMap::parseFile($outputPath);
        if (empty($mappings)) {
            $this->logError(sprintf('寫入 %s 後解析不到任何對照資料，來源檔案格式可能有誤，請檢查。', $outputPath));

            return 1;
        }

        $this->logInfo(sprintf(
            'Wrote %s (%d bytes). Parses to %d trad->simp mappings (identity mappings excluded).',
            $outputPath,
            strlen($contents),
            count($mappings)
        ));
        $this->logInfo('請用 git diff 審查變化並提交，不需要任何額外的「重新產生」步驟。');

        return 0;
    }

    protected function logInfo(string $message): void {
        Log::info('[cbdb:sync-opencc-trad-simp] ' . $message);
        $this->info($message);
    }

    protected function logError(string $message): void {
        Log::error('[cbdb:sync-opencc-trad-simp] ' . $message);
        $this->error($message);
    }
}
