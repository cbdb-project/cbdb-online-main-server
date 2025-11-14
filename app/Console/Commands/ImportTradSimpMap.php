<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ImportTradSimpMap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cbdb:import-trad-simp-map
                            {--url=https://raw.githubusercontent.com/BYVoid/OpenCC/refs/heads/master/data/dictionary/TSCharacters.txt : Source URL for OpenCC Traditional→Simplified mappings}
                            {--truncate : Truncate CBDB__TRAD_SIMP_MAP before inserting}
                            {--skip-non-bmp : Skip mappings whose trad/simp characters fall outside BMP (legacy behavior)}
                            {--batch=1000 : Number of records to insert per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '下載 OpenCC t2s 對照並匯入 CBDB__TRAD_SIMP_MAP';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!Schema::hasTable('CBDB__TRAD_SIMP_MAP')) {
            $this->error('資料表 CBDB__TRAD_SIMP_MAP 不存在，請先執行 migrations。');
            return 1;
        }

        $url = $this->option('url');
        $skipNonBmp = (bool) $this->option('skip-non-bmp');
        $this->logInfo(sprintf(
            "Starting import; downloading mapping file from %s (skip non-BMP = %s)",
            $url,
            $skipNonBmp ? 'true' : 'false'
        ));

        $tempPath = tempnam(sys_get_temp_dir(), 'opencc_');

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                ],
            ]);
            $contents = @file_get_contents($url, false, $context);
        } catch (\Throwable $e) {
            @unlink($tempPath);
            $this->logError('下載失敗：' . $e->getMessage());
            return 1;
        }

        if ($contents === false) {
            @unlink($tempPath);
            $this->logError('無法下載 OpenCC 對照檔。');
            return 1;
        }

        file_put_contents($tempPath, $contents);

        $this->logInfo('Parsing mapping file…');
        $handle = fopen($tempPath, 'rb');
        if (!$handle) {
            @unlink($tempPath);
            $this->logError('無法讀取暫存檔案。');
            return 1;
        }

        $records = [];
        $nonBmpSeen = 0;
        $skippedNonBmp = 0;
        $skippedInvalid = 0;
        $lineNumber = 0;

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 2) {
                continue;
            }

            $trad = $this->normalizeChar(array_shift($parts));
            $simp = $this->normalizeChar(implode('', $parts));

            if ($trad === null || $simp === null) {
                $skippedInvalid++;
                if ($skippedInvalid <= 5) {
                    $this->logWarn(sprintf(
                        'Skipped invalid mapping at line %d (raw: %s)',
                        $lineNumber,
                        $line
                    ));
                }
                continue;
            }

            $isNonBmpPair = $this->isNonBmp($trad) || $this->isNonBmp($simp);
            if ($isNonBmpPair) {
                $nonBmpSeen++;
                if ($skipNonBmp) {
                    $skippedNonBmp++;
                    if ($skippedNonBmp <= 5) {
                        $this->logWarn(sprintf(
                            'Skipped non-BMP mapping at line %d (%s -> %s)',
                            $lineNumber,
                            $this->describeChar($trad),
                            $this->describeChar($simp)
                        ));
                    }
                    continue;
                }
                if ($nonBmpSeen <= 5) {
                    $this->logWarn(sprintf(
                        'Including non-BMP mapping at line %d (%s -> %s); ensure utf8mb4 is configured end-to-end.',
                        $lineNumber,
                        $this->describeChar($trad),
                        $this->describeChar($simp)
                    ));
                }
            }

            $records[$trad] = $simp;
        }
        fclose($handle);
        @unlink($tempPath);

        if (empty($records)) {
            $this->logError('未解析到任何對照資料。');
            return 1;
        }

        $this->logInfo(sprintf(
            'Parsed %d mappings (skipped %d invalid, non-BMP seen %d, skipped %d); preparing database writes…',
            count($records),
            $skippedInvalid,
            $nonBmpSeen,
            $skippedNonBmp
        ));

        $batchSize = max(1, (int) $this->option('batch'));
        $total = count($records);

        DB::connection()->transaction(function () use ($records, $batchSize, $total) {
            if ($this->option('truncate')) {
                $this->logInfo('Truncating CBDB__TRAD_SIMP_MAP before import.');
                DB::table('CBDB__TRAD_SIMP_MAP')->truncate();
            }

            $this->logInfo(sprintf('Starting batch insert (batch size: %d)…', $batchSize));

            // 轉換為批次陣列
            $batch = [];
            $inserted = 0;
            $batchCount = 0;

            foreach ($records as $trad => $simp) {
                $batch[] = [
                    'trad_char' => $trad,
                    'simp_char' => $simp,
                ];

                // 當批次達到指定大小時插入
                if (count($batch) >= $batchSize) {
                    try {
                        DB::table('CBDB__TRAD_SIMP_MAP')->insert($batch);
                        $inserted += count($batch);
                        $batchCount++;

                        if ($batchCount % 5 === 0) {
                            $this->logInfo(sprintf(
                                'Inserted %d / %d mappings (%d batches)…',
                                $inserted,
                                $total,
                                $batchCount
                            ));
                        }

                        $batch = [];
                    } catch (\Throwable $e) {
                        $this->logError(sprintf(
                            "Failed to insert batch #%d (size: %d): %s",
                            $batchCount + 1,
                            count($batch),
                            $e->getMessage()
                        ));
                        throw $e;
                    }
                }
            }

            // 插入最後一批
            if (!empty($batch)) {
                try {
                    DB::table('CBDB__TRAD_SIMP_MAP')->insert($batch);
                    $inserted += count($batch);
                    $batchCount++;
                } catch (\Throwable $e) {
                    $this->logError(sprintf(
                        "Failed to insert final batch #%d (size: %d): %s",
                        $batchCount,
                        count($batch),
                        $e->getMessage()
                    ));
                    throw $e;
                }
            }

            $this->logInfo(sprintf(
                'Batch insert completed: %d mappings in %d batches.',
                $inserted,
                $batchCount
            ));
        });

        $this->logInfo(sprintf('Imported %d mappings into CBDB__TRAD_SIMP_MAP.', count($records)));
        if ($skippedInvalid > 0 || $skippedNonBmp > 0 || $nonBmpSeen > 0) {
            $this->logWarn(sprintf(
                'Skipped %d invalid entries, saw %d non-BMP characters (skipped %d).',
                $skippedInvalid,
                $nonBmpSeen,
                $skippedNonBmp
            ));
        }

        return 0;
    }

    protected function normalizeChar($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $char = mb_substr($value, 0, 1, 'UTF-8');

        if ($char === '' || !preg_match('//u', $char)) {
            return null;
        }

        return $char;
    }

    protected function isNonBmp(string $char): bool
    {
        return strlen(mb_convert_encoding($char, 'UTF-8')) > 3;
    }

    protected function describeChar(string $char): string
    {
        $hex = strtoupper(bin2hex($char));
        return sprintf("%s (U+%s)", $char, $hex);
    }

    protected function logInfo(string $message): void
    {
        Log::info('[cbdb:import-trad-simp-map] ' . $message);
        $this->info($message);
    }

    protected function logWarn(string $message): void
    {
        Log::warning('[cbdb:import-trad-simp-map] ' . $message);
        $this->warn($message);
    }

    protected function logError(string $message): void
    {
        Log::error('[cbdb:import-trad-simp-map] ' . $message);
        $this->error($message);
    }
}
