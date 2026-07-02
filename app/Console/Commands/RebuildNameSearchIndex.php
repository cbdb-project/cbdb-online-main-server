<?php

namespace App\Console\Commands;

use App\Services\NameFtsProgressService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RebuildNameSearchIndex extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cbdb:rebuild-name-search
                            {--truncate : Truncate CBDB__NAME_FTS before rebuilding}
                            {--batch=500 : Number of records to insert per batch}
                            {--commit-interval=5000 : Number of records to commit per transaction}
                            {--id-from= : Start from this c_personid (inclusive)}
                            {--id-to= : End at this c_personid (inclusive)}
                            {--task-id= : 內部使用：前端輪詢的進度任務編號}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重建姓名搜尋倒排索引表';

    /**
     * 繁簡映射緩存
     *
     * @var array
     */
    protected $tradSimpMap = [];

    /**
     * 目前進行中的進度任務
     *
     * @var string|null
     */
    protected $taskId;

    /**
     * 上一次回報進度時已處理的姓名數
     *
     * @var int
     */
    protected $lastProgressCount = 0;

    /**
     * 回報進度的最小間隔
     *
     * @var int
     */
    protected $progressReportInterval = 1000;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle() {
        if (!Schema::hasTable('CBDB__NAME_FTS')) {
            $this->error('資料表 CBDB__NAME_FTS 不存在，請先執行 migrations。');

            return 1;
        }

        $this->taskId = $this->option('task-id') ? (string) $this->option('task-id') : null;
        $this->lastProgressCount = 0;
        if ($this->taskId) {
            NameFtsProgressService::update($this->taskId, 12, '正在初始化索引重建作業…', 'running');
        }

        $batchSize = max(1, (int) $this->option('batch'));
        $commitInterval = max(100, (int) $this->option('commit-interval'));
        $idFrom = $this->option('id-from') ? (int) $this->option('id-from') : null;
        $idTo = $this->option('id-to') ? (int) $this->option('id-to') : null;

        $this->info('開始重建姓名搜尋倒排索引...');
        $this->info(sprintf(
            '批次大小：%d，事務提交間隔：%d 條記錄',
            $batchSize,
            $commitInterval
        ));

        if ($idFrom !== null || $idTo !== null) {
            $this->info(sprintf(
                'ID 範圍：%s 到 %s',
                $idFrom ?? '開始',
                $idTo ?? '結束'
            ));
        }

        // 載入繁簡映射表
        $this->loadTradSimpMap();

        if ($this->option('truncate')) {
            $this->info('清空現有索引資料...');
            DB::table('CBDB__NAME_FTS')->delete();
        }

        // 流式處理：邊收集邊生成邊插入
        $this->info('流式處理姓名資料...');
        $this->streamProcessNames($batchSize, $commitInterval, $idFrom, $idTo, $this->taskId);

        $this->info('索引重建完成！');
        $this->displayStatistics();

        return 0;
    }

    /**
     * 流式處理姓名（邊收集邊生成邊插入，降低記憶體佔用）
     *
     * @param int $batchSize
     * @param int $commitInterval
     * @param int|null $idFrom
     * @param int|null $idTo
     */
    protected function streamProcessNames(int $batchSize, int $commitInterval, ?int $idFrom = null, ?int $idTo = null, ?string $taskId = null) {
        $recordBuffer = [];
        $totalInserted = 0;

        $this->streamProcessNamesInTransaction($batchSize, $recordBuffer, $totalInserted, $commitInterval, $idFrom, $idTo, $taskId);

        $this->info(sprintf('成功插入 %s 條倒排記錄', number_format($totalInserted)));
    }

    /**
     * 流式處理姓名（分段事務）
     *
     * @param int $batchSize
     * @param array $recordBuffer
     * @param int $totalInserted
     * @param int $commitInterval
     * @param int|null $idFrom
     * @param int|null $idTo
     */
    protected function streamProcessNamesInTransaction(int $batchSize, array &$recordBuffer, int &$totalInserted, int $commitInterval, ?int $idFrom = null, ?int $idTo = null, ?string $taskId = null) {
        // 禁用查詢日誌以避免記憶體累積
        DB::connection()->disableQueryLog();

        // 統計總數用於進度條
        $mainQuery = DB::table('BIOG_MAIN')
            ->where('c_personid', '>', 0)
            ->whereNotNull('c_name_chn')
            ->where('c_name_chn', '!=', '');
        if ($idFrom !== null) {
            $mainQuery->where('c_personid', '>=', $idFrom);
        }
        if ($idTo !== null) {
            $mainQuery->where('c_personid', '<=', $idTo);
        }
        $totalMainNames = $mainQuery->count();

        $altQuery = DB::table('ALTNAME_DATA')
            ->where('c_personid', '>', 0)
            ->whereNotNull('c_alt_name_chn')
            ->where('c_alt_name_chn', '!=', '');
        if ($idFrom !== null) {
            $altQuery->where('c_personid', '>=', $idFrom);
        }
        if ($idTo !== null) {
            $altQuery->where('c_personid', '<=', $idTo);
        }
        $totalAltNames = $altQuery->count();
        $totalNames = $totalMainNames + $totalAltNames;

        $bar = $this->output->createProgressBar($totalNames ?: 1);
        $bar->start();

        $lastCommitCount = 0; // 上次提交時的記錄數
        $timestamp = now(); // 快取時間戳，避免每次都創建新物件
        $processedNames = 0;

        // 開始第一個事務
        DB::beginTransaction();

        // 1. 處理本名（流式，使用 chunkById 避免 OFFSET 造成的記憶體累積）
        $mainProcessQuery = DB::table('BIOG_MAIN')
            ->select('c_personid', 'c_name_chn')
            ->where('c_personid', '>', 0)
            ->whereNotNull('c_name_chn')
            ->where('c_name_chn', '!=', '');
        if ($idFrom !== null) {
            $mainProcessQuery->where('c_personid', '>=', $idFrom);
        }
        if ($idTo !== null) {
            $mainProcessQuery->where('c_personid', '<=', $idTo);
        }
        $mainProcessQuery
            ->orderBy('c_personid')
            ->chunkById(1000, function ($rows) use (&$recordBuffer, &$totalInserted, &$lastCommitCount, $batchSize, $commitInterval, &$bar, $totalNames, $timestamp, &$processedNames, $taskId) {
                foreach ($rows as $row) {
                    $fullName = $this->normalizeName($row->c_name_chn);
                    $processedNames++;
                    if (!$fullName) {
                        $bar->advance();
                        $this->reportProgressIfNeeded($taskId, $processedNames, $totalNames, '正在處理本名資料…');

                        continue;
                    }

                    // 生成倒排記錄
                    $records = $this->generateRecordsForName([
                        'c_personid' => $row->c_personid,
                        'name_type_code' => null,
                        'name_type_desc' => 'main_name',
                        'name_type_desc_chn' => '本名',
                        'full_name' => $fullName,
                        'source' => 'BIOG_MAIN',
                        'source_key' => 'biog_main:' . $row->c_personid,
                    ], $timestamp);

                    // 使用 array_push 展開，避免 array_merge 的記憶體複製
                    array_push($recordBuffer, ...$records);

                    // 當緩衝區達到批次大小時，插入資料庫
                    if (count($recordBuffer) >= $batchSize) {
                        DB::table('CBDB__NAME_FTS')->insert($recordBuffer);
                        $totalInserted += count($recordBuffer);
                        $recordBuffer = [];

                        // 分段提交：每插入 commitInterval 條記錄就提交並開啟新事務
                        if ($totalInserted - $lastCommitCount >= $commitInterval) {
                            DB::commit();
                            $this->line('');
                            $this->info(sprintf(
                                '已提交 %s 條記錄（內存：%s MB）',
                                number_format($totalInserted),
                                number_format(memory_get_usage(true) / 1024 / 1024, 2)
                            ));
                            DB::beginTransaction();
                            $lastCommitCount = $totalInserted;
                            gc_collect_cycles(); // 清理內存
                        }
                    }

                    $bar->advance();
                    $this->reportProgressIfNeeded($taskId, $processedNames, $totalNames, '正在處理本名資料…');
                }
            }, 'c_personid');

        // 2. 處理別名（流式）
        $altProcessQuery = DB::table('ALTNAME_DATA as ad')
            ->join('ALTNAME_CODES as codes', 'codes.c_name_type_code', '=', 'ad.c_alt_name_type_code')
            ->select(
                'ad.c_personid',
                'ad.c_alt_name_type_code',
                'codes.c_name_type_desc',
                'codes.c_name_type_desc_chn',
                'ad.c_alt_name_chn'
            )
            ->where('ad.c_personid', '>', 0)
            ->whereNotNull('ad.c_alt_name_chn')
            ->where('ad.c_alt_name_chn', '!=', '');
        if ($idFrom !== null) {
            $altProcessQuery->where('ad.c_personid', '>=', $idFrom);
        }
        if ($idTo !== null) {
            $altProcessQuery->where('ad.c_personid', '<=', $idTo);
        }
        $altProcessQuery
            ->orderBy('ad.c_personid')
            ->chunk(1000, function ($rows) use (&$recordBuffer, &$totalInserted, &$lastCommitCount, $batchSize, $commitInterval, &$bar, $totalNames, $timestamp, &$processedNames, $taskId) {
                foreach ($rows as $row) {
                    $fullName = $this->normalizeName($row->c_alt_name_chn);
                    $processedNames++;
                    if (!$fullName) {
                        $bar->advance();
                        $this->reportProgressIfNeeded($taskId, $processedNames, $totalNames, '正在處理別名資料…');

                        continue;
                    }

                    // 生成倒排記錄
                    $records = $this->generateRecordsForName([
                        'c_personid' => $row->c_personid,
                        'name_type_code' => $row->c_alt_name_type_code,
                        'name_type_desc' => $row->c_name_type_desc ?: 'altname',
                        'name_type_desc_chn' => $row->c_name_type_desc_chn ?: '別名',
                        'full_name' => $fullName,
                        'source' => 'ALTNAME_DATA',
                        'source_key' => sprintf(
                            'altname:%d-%d-%s',
                            $row->c_personid,
                            $row->c_alt_name_type_code,
                            $row->c_alt_name_chn
                        ),
                    ], $timestamp);

                    // 使用 array_push 展開，避免 array_merge 的記憶體複製
                    array_push($recordBuffer, ...$records);

                    // 當緩衝區達到批次大小時，插入資料庫
                    if (count($recordBuffer) >= $batchSize) {
                        DB::table('CBDB__NAME_FTS')->insert($recordBuffer);
                        $totalInserted += count($recordBuffer);
                        $recordBuffer = [];

                        // 分段提交：每插入 commitInterval 條記錄就提交並開啟新事務
                        if ($totalInserted - $lastCommitCount >= $commitInterval) {
                            DB::commit();
                            $this->line('');
                            $this->info(sprintf(
                                '已提交 %s 條記錄（內存：%s MB）',
                                number_format($totalInserted),
                                number_format(memory_get_usage(true) / 1024 / 1024, 2)
                            ));
                            DB::beginTransaction();
                            $lastCommitCount = $totalInserted;
                            gc_collect_cycles(); // 清理內存
                        }
                    }

                    $bar->advance();
                    $this->reportProgressIfNeeded($taskId, $processedNames, $totalNames, '正在處理別名資料…');
                }
            });

        // 插入剩餘的記錄
        if (!empty($recordBuffer)) {
            DB::table('CBDB__NAME_FTS')->insert($recordBuffer);
            $totalInserted += count($recordBuffer);
        }

        // 最後提交剩餘的事務
        DB::commit();

        $bar->finish();
        $this->line('');

        $this->reportProgressIfNeeded($taskId, $processedNames, $totalNames, '姓名資料已全部處理，正在整理索引結果…');
    }

    protected function reportProgressIfNeeded(?string $taskId, int $processed, int $total, string $message = null): void {
        if (!$taskId || $total <= 0) {
            return;
        }

        $shouldReport = $processed === $total || ($processed - $this->lastProgressCount) >= $this->progressReportInterval;
        if (!$shouldReport) {
            return;
        }

        $percent = (int) floor(($processed / $total) * 75) + 15;
        $percent = max(12, min(90, $percent));
        $message = $message ?? sprintf(
            '已處理 %s / %s 筆姓名…',
            number_format($processed),
            number_format($total)
        );

        NameFtsProgressService::update($taskId, $percent, $message, 'running');
        $this->lastProgressCount = $processed;
    }

    /**
     * 為單個姓名生成倒排記錄（包含繁簡體）
     *
     * @param array $nameInfo
     * @param \Illuminate\Support\Carbon|null $timestamp
     * @return array
     */
    protected function generateRecordsForName(array $nameInfo, $timestamp = null): array {
        $records = [];
        $insertedTerms = []; // 記錄已插入的 search_term，避免重複

        // 使用傳入的時間戳或創建新的（避免在循環中重複創建）
        if ($timestamp === null) {
            $timestamp = now();
        }

        // 生成繁體版本的後綴
        $tradSuffixes = $this->generateSuffixes($nameInfo['full_name']);
        foreach ($tradSuffixes as $suffix) {
            if ($this->isValidSearchTerm($suffix)) {
                $records[] = array_merge($nameInfo, [
                    'search_term' => $suffix,
                    'is_simplified' => 0,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
                $insertedTerms[$suffix] = true; // 標記已插入
            }
        }

        // 生成簡體版本的後綴（只插入與繁體不同的）
        $simplifiedName = $this->convertToSimplified($nameInfo['full_name']);
        if ($simplifiedName && $simplifiedName !== $nameInfo['full_name']) {
            $simpSuffixes = $this->generateSuffixes($simplifiedName);
            foreach ($simpSuffixes as $suffix) {
                // 只有當這個後綴尚未插入過（繁簡不同）時才插入
                if (!isset($insertedTerms[$suffix]) && $this->isValidSearchTerm($suffix)) {
                    $records[] = array_merge($nameInfo, [
                        'search_term' => $suffix,
                        'is_simplified' => 1,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                }
            }
        }

        return $records;
    }

    /**
     * 載入繁簡映射表
     */
    protected function loadTradSimpMap() {
        if (!Schema::hasTable('CBDB__TRAD_SIMP_MAP')) {
            $this->warn('CBDB__TRAD_SIMP_MAP 表不存在，將跳過繁簡轉換');

            return;
        }

        $this->info('載入繁簡映射表...');
        $rows = DB::table('CBDB__TRAD_SIMP_MAP')->get();

        foreach ($rows as $row) {
            $this->tradSimpMap[$row->trad_char] = $row->simp_char;
        }

        $this->info(sprintf('載入 %d 個繁簡映射', count($this->tradSimpMap)));
    }

    /**
     * 規範化姓名（去除括號註釋）
     *
     * @param string $name
     * @return string|null
     */
    protected function normalizeName(string $name): ?string {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        // 移除括號符號本身，並移除所有空白（含全形／不斷行空白），但保留內容以便搜尋。
        // 例如："宗氏（李白妻）" → "宗氏李白妻"、"李白 (青蓮)" → "李白青蓮"。
        // 中文姓名不含有意義的空白；殘留空白會破壞後綴索引與搜尋比對，
        // 也會導致全量重建時覆蓋掉增量流程（NameSearchIndexService）已修正的結果。
        $name = preg_replace('/[()（）\s\p{Zs}]/u', '', $name);
        $name = trim($name);

        return $name ?: null;
    }

    /**
     * 將繁體字轉換為簡體字
     *
     * @param string $text
     * @return string
     */
    protected function convertToSimplified(string $text): string {
        if (empty($this->tradSimpMap)) {
            return $text;
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $result = '';

        foreach ($chars as $char) {
            $result .= $this->tradSimpMap[$char] ?? $char;
        }

        return $result;
    }

    /**
     * 生成字串的所有後綴
     *
     * @param string $text
     * @return array
     */
    protected function generateSuffixes(string $text): array {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $suffixes = [];

        // 完整名稱
        $suffixes[] = $text;

        // 生成所有後綴（從第2個字開始）
        for ($i = 1; $i < count($chars); $i++) {
            $suffixes[] = implode('', array_slice($chars, $i));
        }

        return $suffixes;
    }

    /**
     * 檢查搜尋詞是否有效
     *
     * @param string $term
     * @return bool
     */
    protected function isValidSearchTerm(string $term): bool {
        $term = trim($term);

        if ($term === '') {
            return false;
        }

        // 排除以括號開頭的詞
        $firstChar = mb_substr($term, 0, 1, 'UTF-8');
        if (in_array($firstChar, ['(', ')', '（', '）'])) {
            return false;
        }

        return true;
    }

    /**
     * 顯示統計資訊
     */
    protected function displayStatistics() {
        $stats = DB::table('CBDB__NAME_FTS')
            ->selectRaw('
                COALESCE(name_type_desc_chn, "本名") as label,
                is_simplified,
                COUNT(*) as count
            ')
            ->groupBy('label', 'is_simplified')
            ->get();

        $this->info('');
        $this->info('=== 索引統計 ===');

        $rows = [];
        foreach ($stats as $stat) {
            $variant = $stat->is_simplified ? '簡體' : '繁體';
            $rows[] = [
                $stat->label,
                $variant,
                number_format($stat->count),
            ];
        }

        $this->table(['類型', '字體', '記錄數'], $rows);

        $total = DB::table('CBDB__NAME_FTS')->count();
        $uniquePersons = DB::table('CBDB__NAME_FTS')
            ->distinct('c_personid')
            ->count('c_personid');

        $this->info(sprintf('總計：%s 條倒排記錄', number_format($total)));
        $this->info(sprintf('涵蓋：%s 個人物', number_format($uniquePersons)));
    }
}
