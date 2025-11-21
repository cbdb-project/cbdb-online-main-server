<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 地址层级关系重建命令 - 基于 Michael Fuller 教授的 VB 代码逻辑
 *
 * 处理时间段分割和多级归属关系，保留数据间隙以讲述最连续的故事
 */
class RegenerateAddresses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cbdb:regenerate-addresses-table
                            {--verify : 验证特定示例案例（如 Jiangle 100149）}
                            {--dry-run : 仅模拟运行，不实际修改数据}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重新生成 ADDRESSES 表数据（基于 ADDR_CODES 和 ADDR_BELONGS_DATA）';

    /**
     * 统计信息
     */
    protected $stats = [
        'cleaned_belongs' => 0,
        'invalid_belongs' => 0,
        'time_segments' => 0,
        'final_addresses' => 0,
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('==========================================================');
        $this->info('开始重建地址层级关系（保留间隙）...');
        $this->info('==========================================================');
        $this->newLine();

        try {
            // 1. 清理归属数据
            $this->info('[步骤 1/3] 清理归属数据...');
            $this->cleanBelongsData();
            $this->newLine();

            // 2. 构建时间段（包含间隙填充）
            $this->info('[步骤 2/3] 构建时间段（填充间隙）...');
            $this->buildTimeSegmentsWithGaps();
            $this->newLine();

            // 3. 生成最终 ADDRESSES 表
            if (!$this->option('dry-run')) {
                $this->info('[步骤 3/3] 生成最终 ADDRESSES 表...');
                $this->buildFinalAddressesTable();
                $this->newLine();
            } else {
                $this->warn('[步骤 3/3] 跳过（dry-run 模式）');
                $this->newLine();
            }

            // 4. 显示统计信息
            $this->displayStats();

            // 5. 验证示例案例（如果需要）
            if ($this->option('verify')) {
                $this->newLine();
                $this->verifyExampleCases();
            }

            $this->info('==========================================================');
            $this->info('重建完成！');
            $this->info('==========================================================');

            return 0;
        } catch (\Exception $e) {
            $this->error('重建过程中发生错误: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }

    /**
     * 清理 ADDR_BELONGS_DATA 中的无效数据
     * 这是 Michael 代码中的关键步骤
     */
    protected function cleanBelongsData()
    {
        // 创建临时表存储清理后的数据
        DB::statement('DROP TEMPORARY TABLE IF EXISTS CLEANED_BELONGS_DATA');
        DB::statement('
            CREATE TEMPORARY TABLE CLEANED_BELONGS_DATA (
                c_addr_id INT,
                c_belongs_to INT,
                c_firstyear SMALLINT,
                c_lastyear SMALLINT,
                INDEX idx_addr (c_addr_id),
                INDEX idx_belongs (c_belongs_to)
            )
        ');

        // 获取所有归属关系
        $belongsData = DB::select('
            SELECT abd.*,
                   ac1.c_firstyear as addr_first,
                   ac1.c_lastyear as addr_last,
                   ac2.c_firstyear as belongs_first,
                   ac2.c_lastyear as belongs_last
            FROM ADDR_BELONGS_DATA abd
            JOIN ADDR_CODES ac1 ON abd.c_addr_id = ac1.c_addr_id
            LEFT JOIN ADDR_CODES ac2 ON abd.c_belongs_to = ac2.c_addr_id
        ');

        $validCount = 0;
        $invalidCount = 0;

        $bar = $this->output->createProgressBar(count($belongsData));
        $bar->start();

        foreach ($belongsData as $row) {
            // 规则 1: 排除 Unknown (c_belongs_to = 0 或 NULL)
            if (empty($row->c_belongs_to) || $row->c_belongs_to == 0) {
                $invalidCount++;
                $bar->advance();
                continue;
            }

            // 规则 2: belongs_to 单位必须存在
            if ($row->belongs_first === null || $row->belongs_last === null) {
                $this->warn("\n  警告: belongs_to 单位 {$row->c_belongs_to} 不存在");
                $invalidCount++;
                $bar->advance();
                continue;
            }

            // 获取时间值，处理 NULL 情况
            $abd_first = $row->c_firstyear ?? $row->addr_first;
            $abd_last = $row->c_lastyear ?? $row->addr_last;

            // 计算有效时间范围
            $effective_first = $this->safeMax($abd_first, $row->addr_first, $row->belongs_first);
            $effective_last = $this->safeMin($abd_last, $row->addr_last, $row->belongs_last);

            if ($effective_first === null || $effective_last === null) {
                $this->warn("\n  警告: 时间范围包含 NULL: {$row->c_addr_id} -> {$row->c_belongs_to}");
                $invalidCount++;
                $bar->advance();
                continue;
            }

            if ($effective_first > $effective_last) {
                $this->warn("\n  警告: 无效时间范围: {$row->c_addr_id} -> {$row->c_belongs_to} ({$effective_first} > {$effective_last})");
                $invalidCount++;
                $bar->advance();
                continue;
            }

            // 插入清理后的数据
            DB::table('CLEANED_BELONGS_DATA')->insert([
                'c_addr_id' => $row->c_addr_id,
                'c_belongs_to' => $row->c_belongs_to,
                'c_firstyear' => $effective_first,
                'c_lastyear' => $effective_last,
            ]);
            $validCount++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->stats['cleaned_belongs'] = $validCount;
        $this->stats['invalid_belongs'] = $invalidCount;

        $this->info("  ✓ 有效记录: {$validCount}");
        $this->info("  ✓ 无效记录: {$invalidCount}");
    }

    /**
     * 构建时间段（包含间隙）
     * 这保留了数据中的间隙，讲述最连续的故事
     */
    protected function buildTimeSegmentsWithGaps()
    {
        // 创建结果表
        DB::statement('DROP TEMPORARY TABLE IF EXISTS TIME_SEGMENTS');
        DB::statement('
            CREATE TEMPORARY TABLE TIME_SEGMENTS (
                c_addr_id INT,
                segment_start SMALLINT,
                segment_end SMALLINT,
                belongs_chain TEXT,
                level1_id INT,
                level1_start SMALLINT,
                level1_end SMALLINT,
                level2_id INT,
                level2_start SMALLINT,
                level2_end SMALLINT,
                level3_id INT,
                level3_start SMALLINT,
                level3_end SMALLINT,
                level4_id INT,
                level4_start SMALLINT,
                level4_end SMALLINT,
                level5_id INT,
                level5_start SMALLINT,
                level5_end SMALLINT,
                INDEX idx_addr (c_addr_id)
            )
        ');

        // 获取所有有有效年份数据的地址
        $addresses = DB::select('
            SELECT c_addr_id, c_firstyear, c_lastyear
            FROM ADDR_CODES
            WHERE c_firstyear IS NOT NULL AND c_lastyear IS NOT NULL
        ');

        $this->info("  处理 " . count($addresses) . " 个有有效年份数据的地址...");

        $bar = $this->output->createProgressBar(count($addresses));
        $bar->start();

        foreach ($addresses as $addrRow) {
            $addrId = $addrRow->c_addr_id;
            $addrFirst = $addrRow->c_firstyear;
            $addrLast = $addrRow->c_lastyear;

            // 跳过无效年份
            if ($addrFirst === null || $addrLast === null || $addrFirst > $addrLast) {
                $bar->advance();
                continue;
            }

            // 获取此地址的所有 level 1 归属关系
            $level1Belongs = DB::select('
                SELECT DISTINCT c_belongs_to, c_firstyear, c_lastyear
                FROM CLEANED_BELONGS_DATA
                WHERE c_addr_id = ?
                ORDER BY c_firstyear
            ', [$addrId]);

            if (empty($level1Belongs)) {
                // 整个期间没有归属关系
                $this->insertSegment($addrId, $addrFirst, $addrLast, []);
            } else {
                // 处理每个 L1 关系并填充间隙
                $currentYear = $addrFirst;

                foreach ($level1Belongs as $l1) {
                    $l1Start = $l1->c_firstyear;
                    $l1End = $l1->c_lastyear;
                    $l1Id = $l1->c_belongs_to;

                    // 如果此 L1 关系之前有间隙
                    if ($currentYear < $l1Start) {
                        // 插入间隙记录（仅 L1，无更深层级）
                        $gapChain = [
                            'level1' => [
                                'id' => $l1Id,
                                'start' => $currentYear,
                                'end' => $l1Start - 1
                            ]
                        ];
                        $this->insertSegment($addrId, $currentYear, $l1Start - 1, $gapChain);
                    }

                    // 处理实际的 L1 期间及其嵌套关系
                    $this->processLevel1WithGaps($addrId, $l1Id, $l1Start, $l1End);

                    $currentYear = $l1End + 1;
                }

                // 填充末尾的间隙（如果需要）
                if ($addrLast !== null && $currentYear <= $addrLast) {
                    // 使用最后一个 L1 归属填充间隙
                    if (!empty($level1Belongs)) {
                        $lastL1 = end($level1Belongs);
                        $gapChain = [
                            'level1' => [
                                'id' => $lastL1->c_belongs_to,
                                'start' => $currentYear,
                                'end' => $addrLast
                            ]
                        ];
                        $this->insertSegment($addrId, $currentYear, $addrLast, $gapChain);
                    }
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $count = DB::table('TIME_SEGMENTS')->count();
        $this->stats['time_segments'] = $count;
        $this->info("  ✓ 生成 {$count} 个时间段");
    }

    /**
     * 处理 Level 1 归属期间，填充 Level 2+ 关系中的间隙
     */
    protected function processLevel1WithGaps($addrId, $l1Id, $l1Start, $l1End)
    {
        if ($l1Start === null || $l1End === null) {
            return;
        }

        // 获取此 L1 的 Level 2 关系
        $level2Belongs = DB::select('
            SELECT DISTINCT c_belongs_to, c_firstyear, c_lastyear
            FROM CLEANED_BELONGS_DATA
            WHERE c_addr_id = ?
              AND c_firstyear <= ?
              AND c_lastyear >= ?
            ORDER BY c_firstyear
        ', [$l1Id, $l1End, $l1Start]);

        if (empty($level2Belongs)) {
            // 整个 L1 期间没有 Level 2
            $chain = [
                'level1' => ['id' => $l1Id, 'start' => $l1Start, 'end' => $l1End]
            ];
            $this->insertSegment($addrId, $l1Start, $l1End, $chain);
        } else {
            // 处理 L2 关系并填充间隙
            $currentYear = $l1Start;

            foreach ($level2Belongs as $l2) {
                // 计算与 L1 期间的交集
                $l2EffectiveStart = max($l2->c_firstyear, $l1Start);
                $l2EffectiveEnd = min($l2->c_lastyear, $l1End);

                if ($l2EffectiveStart > $l2EffectiveEnd) {
                    continue;
                }

                // 填充此 L2 之前的间隙（如果需要）
                if ($currentYear < $l2EffectiveStart) {
                    $gapChain = [
                        'level1' => ['id' => $l1Id, 'start' => $currentYear, 'end' => $l2EffectiveStart - 1]
                    ];
                    $this->insertSegment($addrId, $currentYear, $l2EffectiveStart - 1, $gapChain);
                }

                // 处理实际的 L2 期间及更深层级
                $this->processLevel2WithGaps($addrId, $l1Id, $l1Start, $l1End,
                    $l2->c_belongs_to, $l2EffectiveStart, $l2EffectiveEnd);

                $currentYear = $l2EffectiveEnd + 1;
            }

            // 填充 L1 期间末尾的间隙（如果需要）
            if ($currentYear <= $l1End) {
                $gapChain = [
                    'level1' => ['id' => $l1Id, 'start' => $currentYear, 'end' => $l1End]
                ];
                $this->insertSegment($addrId, $currentYear, $l1End, $gapChain);
            }
        }
    }

    /**
     * 处理 Level 2 及更深层级，继续填充间隙
     */
    protected function processLevel2WithGaps($addrId, $l1Id, $l1Start, $l1End, $l2Id, $l2Start, $l2End)
    {
        if ($l2Start === null || $l2End === null) {
            return;
        }

        // 获取 Level 3 关系
        $level3Belongs = DB::select('
            SELECT DISTINCT c_belongs_to, c_firstyear, c_lastyear
            FROM CLEANED_BELONGS_DATA
            WHERE c_addr_id = ?
              AND c_firstyear <= ?
              AND c_lastyear >= ?
            ORDER BY c_firstyear
        ', [$l2Id, $l2End, $l2Start]);

        if (empty($level3Belongs)) {
            // 整个 L2 期间没有 Level 3
            $chain = [
                'level1' => ['id' => $l1Id, 'start' => $l1Start, 'end' => $l1End],
                'level2' => ['id' => $l2Id, 'start' => $l2Start, 'end' => $l2End]
            ];
            $this->insertSegment($addrId, $l2Start, $l2End, $chain);
        } else {
            // 处理 L3 关系并填充间隙
            $currentYear = $l2Start;

            foreach ($level3Belongs as $l3) {
                // 计算交集
                $l3EffectiveStart = max($l3->c_firstyear, $l2Start);
                $l3EffectiveEnd = min($l3->c_lastyear, $l2End);

                if ($l3EffectiveStart > $l3EffectiveEnd) {
                    continue;
                }

                // 填充此 L3 之前的间隙
                if ($currentYear < $l3EffectiveStart) {
                    $gapChain = [
                        'level1' => ['id' => $l1Id, 'start' => $l1Start, 'end' => $l1End],
                        'level2' => ['id' => $l2Id, 'start' => $currentYear, 'end' => $l3EffectiveStart - 1]
                    ];
                    $this->insertSegment($addrId, $currentYear, $l3EffectiveStart - 1, $gapChain);
                }

                // 创建包含 L3 的段
                $chain = [
                    'level1' => ['id' => $l1Id, 'start' => $l1Start, 'end' => $l1End],
                    'level2' => ['id' => $l2Id, 'start' => $l2Start, 'end' => $l2End],
                    'level3' => ['id' => $l3->c_belongs_to, 'start' => $l3EffectiveStart, 'end' => $l3EffectiveEnd]
                ];

                // 继续到 L4 和 L5（如果需要）
                $this->processDeeperLevels($addrId, $chain, $l3->c_belongs_to,
                    $l3EffectiveStart, $l3EffectiveEnd, 3);

                $currentYear = $l3EffectiveEnd + 1;
            }

            // 填充 L2 期间末尾的间隙
            if ($currentYear <= $l2End) {
                $gapChain = [
                    'level1' => ['id' => $l1Id, 'start' => $l1Start, 'end' => $l1End],
                    'level2' => ['id' => $l2Id, 'start' => $currentYear, 'end' => $l2End]
                ];
                $this->insertSegment($addrId, $currentYear, $l2End, $gapChain);
            }
        }
    }

    /**
     * 通用处理器，用于 level 4 和 5
     */
    protected function processDeeperLevels($addrId, $chain, $parentId, $start, $end, $currentLevel)
    {
        if ($start === null || $end === null) {
            return;
        }

        if ($currentLevel >= 5) {
            // 已达到最大深度，保存段
            $this->insertSegment($addrId, $start, $end, $chain);
            return;
        }

        $nextLevel = $currentLevel + 1;

        // 获取下一级关系
        $nextBelongs = DB::select('
            SELECT DISTINCT c_belongs_to, c_firstyear, c_lastyear
            FROM CLEANED_BELONGS_DATA
            WHERE c_addr_id = ?
              AND c_firstyear <= ?
              AND c_lastyear >= ?
            ORDER BY c_firstyear
        ', [$parentId, $end, $start]);

        if (empty($nextBelongs)) {
            // 没有更深层级，保存当前链
            $this->insertSegment($addrId, $start, $end, $chain);
        } else {
            // 处理间隙
            $currentYear = $start;

            foreach ($nextBelongs as $nb) {
                $nbStart = max($nb->c_firstyear, $start);
                $nbEnd = min($nb->c_lastyear, $end);

                if ($nbStart > $nbEnd) {
                    continue;
                }

                // 填充之前的间隙
                if ($currentYear < $nbStart) {
                    $this->insertSegment($addrId, $currentYear, $nbStart - 1, $chain);
                }

                // 创建包含下一级的新链
                $newChain = $chain;
                $newChain["level{$nextLevel}"] = [
                    'id' => $nb->c_belongs_to,
                    'start' => $nbStart,
                    'end' => $nbEnd
                ];

                // 继续更深
                $this->processDeeperLevels($addrId, $newChain, $nb->c_belongs_to,
                    $nbStart, $nbEnd, $nextLevel);

                $currentYear = $nbEnd + 1;
            }

            // 填充末尾的间隙
            if ($currentYear <= $end) {
                $this->insertSegment($addrId, $currentYear, $end, $chain);
            }
        }
    }

    /**
     * 插入时间段记录
     */
    protected function insertSegment($addrId, $start, $end, $chain)
    {
        if ($start === null || $end === null) {
            return;
        }

        $values = [
            'c_addr_id' => $addrId,
            'segment_start' => $start,
            'segment_end' => $end,
            'belongs_chain' => json_encode($chain),
        ];

        // 添加层级信息
        for ($i = 1; $i <= 5; $i++) {
            if (isset($chain["level{$i}"])) {
                $values["level{$i}_id"] = $chain["level{$i}"]['id'];
                $values["level{$i}_start"] = $chain["level{$i}"]['start'] ?? $start;
                $values["level{$i}_end"] = $chain["level{$i}"]['end'] ?? $end;
            } else {
                $values["level{$i}_id"] = null;
                $values["level{$i}_start"] = null;
                $values["level{$i}_end"] = null;
            }
        }

        DB::table('TIME_SEGMENTS')->insert($values);
    }

    /**
     * 构建最终 ADDRESSES 表
     */
    protected function buildFinalAddressesTable()
    {
        // 清空现有数据
        DB::table('ADDRESSES')->truncate();

        // 從 TIME_SEGMENTS 構建最終資料，對應 Python 版本的輸出
        DB::statement('
            INSERT INTO ADDRESSES (
                c_addr_id,
                c_addr_cbd,
                c_name,
                c_name_chn,
                c_admin_type,
                c_firstyear,
                c_lastyear,
                c_belongs_firstyear,
                c_belongs_lastyear,
                x_coord,
                y_coord,
                belongs1_ID,
                belongs1_Name,
                belongs1_Name_chn,
                belongs2_ID,
                belongs2_Name,
                belongs2_Name_chn,
                belongs3_ID,
                belongs3_Name,
                belongs3_Name_chn,
                belongs4_ID,
                belongs4_Name,
                belongs4_Name_chn,
                belongs5_ID,
                belongs5_Name,
                belongs5_Name_chn
            )
            SELECT
                ts.c_addr_id,
                NULL AS c_addr_cbd,
                ac.c_name,
                ac.c_name_chn,
                ac.c_admin_type,
                ac.c_firstyear,
                ac.c_lastyear,
                ts.segment_start,
                ts.segment_end,
                ac.x_coord,
                ac.y_coord,
                ts.level1_id,
                a1.c_name_chn,
                a1.c_name_chn,
                ts.level2_id,
                a2.c_name_chn,
                a2.c_name_chn,
                ts.level3_id,
                a3.c_name_chn,
                a3.c_name_chn,
                ts.level4_id,
                a4.c_name_chn,
                a4.c_name_chn,
                ts.level5_id,
                a5.c_name_chn,
                a5.c_name_chn
            FROM TIME_SEGMENTS ts
            JOIN ADDR_CODES ac ON ts.c_addr_id = ac.c_addr_id
            LEFT JOIN ADDR_CODES a1 ON ts.level1_id = a1.c_addr_id
            LEFT JOIN ADDR_CODES a2 ON ts.level2_id = a2.c_addr_id
            LEFT JOIN ADDR_CODES a3 ON ts.level3_id = a3.c_addr_id
            LEFT JOIN ADDR_CODES a4 ON ts.level4_id = a4.c_addr_id
            LEFT JOIN ADDR_CODES a5 ON ts.level5_id = a5.c_addr_id
            ORDER BY ts.c_addr_id, ts.segment_start
        ');

        $count = DB::table('ADDRESSES')->count();
        $this->stats['final_addresses'] = $count;
        $this->info("  ✓ ADDRESSES 表已创建，共 {$count} 条记录");
    }

    /**
     * 验证特定示例案例
     */
    protected function verifyExampleCases()
    {
        $this->info('验证示例案例...');
        $this->newLine();

        // 检查 Jiangle (100149)
        $this->info('验证 Jiangle (100149)...');
        $results = DB::select('
            SELECT c_belongs_firstyear, c_belongs_lastyear,
                   belongs1_Name_chn, belongs2_Name_chn, belongs3_Name_chn
            FROM ADDRESSES
            WHERE c_addr_id = 100149
            ORDER BY c_firstyear
            LIMIT 10
        ');

        if (!empty($results)) {
            $this->info("  Jiangle 有 " . count($results) . " 条记录（显示前 10 条）:");
            foreach ($results as $row) {
                $this->line(sprintf(
                    "    %s-%s: %s -> %s -> %s",
                    $row->c_belongs_firstyear ?? 'NULL',
                    $row->c_belongs_lastyear ?? 'NULL',
                    $row->belongs1_Name_chn ?? '',
                    $row->belongs2_Name_chn ?? '',
                    $row->belongs3_Name_chn ?? ''
                ));
            }
        } else {
            $this->warn('  未找到 Jiangle (100149) 的记录');
        }

        $this->newLine();

        // 检查 Jun county (4524)（如果存在）
        $results = DB::select('
            SELECT c_belongs_firstyear, c_belongs_lastyear,
                   belongs1_Name_chn, belongs2_Name_chn, belongs3_Name_chn, belongs4_Name_chn
            FROM ADDRESSES
            WHERE c_addr_id = 4524
            ORDER BY c_firstyear
            LIMIT 10
        ');

        if (!empty($results)) {
            $this->info("Jun county (4524) 有记录（显示前 10 条）:");
            foreach ($results as $row) {
                $this->line(sprintf(
                    "    %s-%s: %s -> %s -> %s -> %s",
                    $row->c_belongs_firstyear ?? 'NULL',
                    $row->c_belongs_lastyear ?? 'NULL',
                    $row->belongs1_Name_chn ?? '',
                    $row->belongs2_Name_chn ?? '',
                    $row->belongs3_Name_chn ?? '',
                    $row->belongs4_Name_chn ?? ''
                ));
            }
        }
    }

    /**
     * 显示统计信息
     */
    protected function displayStats()
    {
        $this->info('=== 统计信息 ===');
        $this->info(sprintf('  ✓ 有效归属记录: %s', number_format($this->stats['cleaned_belongs'])));
        $this->info(sprintf('  ✓ 无效归属记录: %s', number_format($this->stats['invalid_belongs'])));
        $this->info(sprintf('  ✓ 生成时间段: %s', number_format($this->stats['time_segments'])));

        if (!$this->option('dry-run')) {
            $this->info(sprintf('  ✓ 最终 ADDRESSES 记录: %s', number_format($this->stats['final_addresses'])));
        }
    }

    /**
     * 安全的 min 函数，忽略 NULL 值
     */
    protected function safeMin(...$values)
    {
        $validValues = array_filter($values, function ($v) {
            return $v !== null;
        });

        return !empty($validValues) ? min($validValues) : null;
    }

    /**
     * 安全的 max 函数，忽略 NULL 值
     */
    protected function safeMax(...$values)
    {
        $validValues = array_filter($values, function ($v) {
            return $v !== null;
        });

        return !empty($validValues) ? max($validValues) : null;
    }

    /**
     * Laravel 6 尚未提供 Command::newLine()，自行代理到輸出層
     */
    protected function newLine($count = 1)
    {
        $this->output->newLine($count);
    }
}
