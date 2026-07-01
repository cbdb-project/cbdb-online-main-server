<?php

namespace App\Console\Commands;

use App\Support\PinyinUmlaut;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 只讀掃描拼音欄位中的 `v`，依《漢語拼音方案》音節規則分類，輸出 CSV 供人工複核。
 *
 * - **只讀不寫**，可安全在生產執行（見 PINYIN_V_TO_UMLAUT_MIGRATION §5.4 / §D）。
 * - 分類：
 *     `pinyin`  —— 含可轉音節（lv/lve/nv/nve），`PinyinUmlaut::normalize()` 會改變其值 → 應轉為 ü。
 *     `other-v` —— 含 `v` 但非可轉音節（如 Silva/Calvin/Vietnam）→ 規則不動它，僅列出供人工瞄一眼。
 * - Phase A 掃描人名欄（BIOG_MAIN.c_surname/c_mingzi、ALTNAME_DATA.c_alt_name）。
 *   `c_name` 為派生欄不掃（改姓/名後由系統重算，見 §D-3）。
 * - Phase B（其他 code 表拼音欄）屬之後的獨立 goal，屆時再擴充欄位清單。
 */
class ScanPinyinV extends Command {
    /** @var string */
    protected $signature = 'cbdb:scan-pinyin-v
                            {--phase=A : 掃描階段（目前僅實作 A＝人名欄）}
                            {--out= : CSV 輸出路徑（預設 storage/app/pinyin-scan-{phase}.csv）}
                            {--limit=0 : 每欄最多輸出列數（0＝不限；供抽樣）}';

    /** @var string */
    protected $description = '只讀掃描拼音欄位中的 v，分類 pinyin(可轉)/other-v(疑似西文)，輸出 CSV 供人工複核。只讀不寫。';

    /**
     * Phase A 掃描欄位；ids 為輸出用的定位欄（非必為完整 PK，僅供人工在 CSV 中定位）。
     *
     * @return array<int, array{table:string, column:string, ids:array<int,string>, kind:string}>
     */
    private function phaseAColumns(): array {
        return [
            ['table' => 'BIOG_MAIN', 'column' => 'c_surname', 'ids' => ['c_personid'], 'kind' => 'name'],
            ['table' => 'BIOG_MAIN', 'column' => 'c_mingzi', 'ids' => ['c_personid'], 'kind' => 'name'],
            // ALTNAME_DATA 定位需 3-key（c_personid, c_alt_name_chn, c_alt_name_type_code）供 M4 遷移解析
            ['table' => 'ALTNAME_DATA', 'column' => 'c_alt_name', 'ids' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'], 'kind' => 'name'],
        ];
    }

    public function handle(): int {
        $phase = strtoupper((string) $this->option('phase'));
        if ($phase !== 'A') {
            $this->error("目前僅實作 Phase A；Phase B 屬之後的獨立 goal。");

            return self::FAILURE;
        }
        $limit = max(0, (int) $this->option('limit'));
        $out = (string) ($this->option('out') ?: storage_path("app/pinyin-scan-{$phase}.csv"));

        $fh = @fopen($out, 'w');
        if ($fh === false) {
            $this->error("無法寫入輸出檔：{$out}");

            return self::FAILURE;
        }
        fputcsv($fh, ['table', 'column', 'ids', 'current', 'proposed', 'class']);

        $summary = [];
        $rowsWritten = 0;

        try {
            foreach ($this->phaseAColumns() as $spec) {
                [$table, $column, $ids] = [$spec['table'], $spec['column'], $spec['ids']];
                if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                    $this->warn("略過（表/欄不存在）：{$table}.{$column}");

                    continue;
                }
                $key = "{$table}.{$column}";
                $summary[$key] = ['pinyin' => 0, 'other-v' => 0];

                // 只讀：僅撈含 v 的列（LIKE 對 ASCII 大小寫不敏感，v 與 V 皆命中）。
                $select = array_values(array_unique([...$ids, $column]));
                $query = DB::table($table)->select($select)->where($column, 'like', '%v%');
                // 依完整定位鍵排序：ALTNAME_DATA 的 c_personid 非唯一，只排單欄時
                // each()（offset 分頁）可能在 chunk 邊界漏掉/重複同分列，掃描必須零遺漏。
                foreach ($ids as $idCol) {
                    $query->orderBy($idCol);
                }
                if ($limit > 0) {
                    $query->limit($limit);
                }

                $query->each(function ($row) use ($fh, $table, $column, $ids, &$summary, &$rowsWritten, $key) {
                    $current = (string) ($row->{$column} ?? '');
                    $proposed = PinyinUmlaut::normalize($current);
                    $class = $proposed !== $current ? 'pinyin' : 'other-v';
                    $summary[$key][$class]++;
                    $idParts = array_map(static fn ($c) => (string) ($row->{$c} ?? ''), $ids);
                    fputcsv($fh, [$table, $column, implode('|', $idParts), $current, $proposed, $class]);
                    $rowsWritten++;
                });
            }
        } finally {
            fclose($fh);
        }

        // 摘要
        $this->info("拼音 v 掃描（Phase {$phase}）——只讀");
        $this->table(['table.column', 'pinyin (可轉)', 'other-v (疑似西文)'], array_map(
            static fn ($k, $v) => [$k, $v['pinyin'], $v['other-v']],
            array_keys($summary),
            array_values($summary),
        ));
        $this->line("共輸出 {$rowsWritten} 列至：{$out}");
        $this->line('提醒：本表僅供人工複核；正式批量修正以人工複核清單（Google Sheet）為權威（見 §D-2）。');

        return self::SUCCESS;
    }
}
