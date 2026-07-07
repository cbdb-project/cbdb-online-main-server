<?php

namespace App\Console\Commands;

use App\Services\Pinyin\CodeTablePinyinScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Code 表拼音 v→ü 批次遷移（Phase B）。預設 dry-run，只掃描不寫入。
 *
 * 機制（見 docs/CODE_TABLE_MUTATION_API_PLAN.md §D-5/§D-6）：
 *   - 掃描每張表的 Tier 1／Tier 2 拼音欄（config/code_table_mutations.php），套確定性規則
 *     {@see \App\Support\PinyinUmlaut::normalize()}。
 *   - **Tier 1**（純拼音）：`--execute` 時直接寫入。
 *   - **Tier 2**（可能含西文，如 ADDR_CODES.c_name）：**預設不寫**——僅列出命中供人眼複核；
 *     確認無誤傷後再以 `--tier=tier2`（或 `--tier=all`）寫入。
 *   - `otherVs`（含 v 但規則未命中，如 Vietnam/Bavard）：一律輸出安全網清單、絕不改。
 *
 * 寫入一律走受審計 `/api/v2/mutate`（mode:direct、person_id:0），逐筆有 audit/operations 可回退。
 * Token 只從環境變數 `CBDB_MIGRATE_TOKEN` 讀，絕不接受 CLI 參數、絕不輸出。
 */
class MigrateCodeTablePinyinV extends Command {
    /** @var string */
    protected $signature = 'cbdb:migrate-code-pinyin-v
                            {--tables=all : all｜逗號分隔的 resource（如 office_codes,ganzhi_codes）}
                            {--tier=tier1 : 處理哪層欄位 tier1｜tier2｜all（tier2 含西文，需人工複核後才寫）}
                            {--out-dir= : 產物輸出目錄（預設 storage/app/code-pinyin-migration）}
                            {--execute : 實際寫入（走 /api/v2/mutate）；省略＝dry-run 只掃描}
                            {--base-url=http://localhost : --execute 時的 API base URL}';

    /** @var string */
    protected $description = 'Code 表拼音 v→ü 批次遷移：掃描 Tier 1/2 拼音欄、確定性規則，預設 dry-run。Tier 1 可 --execute 寫入（審計 API）；Tier 2 需人工複核。';

    public function handle(CodeTablePinyinScanner $scanner): int {
        $tier = (string) $this->option('tier');
        if (!in_array($tier, ['tier1', 'tier2', 'all'], true)) {
            $this->error('--tier 只能是 tier1｜tier2｜all');

            return self::FAILURE;
        }
        $execute = (bool) $this->option('execute');
        $outDir = (string) ($this->option('out-dir') ?: storage_path('app/code-pinyin-migration'));
        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            $this->error("無法建立輸出目錄：{$outDir}");

            return self::FAILURE;
        }

        $token = null;
        if ($execute) {
            $token = getenv('CBDB_MIGRATE_TOKEN') ?: null;
            if (!$token) {
                $this->error('--execute 需要環境變數 CBDB_MIGRATE_TOKEN（Bearer token）；token 不接受 CLI 參數。');

                return self::FAILURE;
            }
        }

        $definitions = $this->selectedDefinitions((string) $this->option('tables'));
        if ($definitions === null) {
            return self::FAILURE;
        }

        $this->info('Code 表拼音 v→ü 遷移 — '.($execute ? '**寫入模式（--execute）**' : 'dry-run（只掃描、不寫入）')."；tier={$tier}");

        $totalMutations = 0;
        $totalOtherV = 0;
        $applyFailures = 0;

        foreach ($definitions as $def) {
            $columns = $this->columnsForTier($def, $tier);
            if ($columns === []) {
                continue;
            }
            // 掃描須與 API 寫入同一連線（基底 handler 用預設連線 DB::table），故用預設連線（null）。
            $result = $scanner->scan($def['table'], $def['key_columns'], $columns, null);
            $this->reportTable($def, $columns, $result, $outDir);
            $totalMutations += count($result['mutations']);
            $totalOtherV += count($result['otherVs']);

            if ($execute && $result['mutations'] !== []) {
                $applyFailures += $this->applyMutations($def, $result['mutations'], (string) $this->option('base-url'), (string) $token, $outDir);
            }
        }

        $this->newLine();
        $this->line("合計預定變更 {$totalMutations} 筆、[OTHER-v] 安全網 {$totalOtherV} 筆。產物於：{$outDir}");
        if (!$execute) {
            $this->line('這是 dry-run；加 --execute 並設 CBDB_MIGRATE_TOKEN 才會實際寫入。Tier 2 需 --tier=tier2/all 且已人工複核 [OTHER-v] 清單。');
        }

        if ($applyFailures > 0) {
            $this->error("有 {$applyFailures} 筆寫入失敗（詳見 *-apply-failures.json）。");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * 依 --tables 選出要處理的 config 定義。
     *
     * @return list<array<string,mixed>>|null  null＝參數錯誤
     */
    private function selectedDefinitions(string $tablesOpt): ?array {
        $all = config('code_table_mutations.tables', []);
        if ($tablesOpt === 'all') {
            return array_values($all);
        }
        $wanted = array_filter(array_map('trim', explode(',', $tablesOpt)));
        $byResource = [];
        foreach ($all as $def) {
            $byResource[$def['resource']] = $def;
        }
        $selected = [];
        foreach ($wanted as $r) {
            if (!isset($byResource[$r])) {
                $this->error("未知 resource：{$r}（見 config/code_table_mutations.php）");

                return null;
            }
            $selected[] = $byResource[$r];
        }

        return $selected;
    }

    /** @param array<string,mixed> $def @return list<string> */
    private function columnsForTier(array $def, string $tier): array {
        $t1 = $def['tier1_fields'] ?? [];
        $t2 = $def['tier2_fields'] ?? [];

        return match ($tier) {
            'tier1' => array_values($t1),
            'tier2' => array_values($t2),
            default => array_values(array_merge($t1, $t2)),
        };
    }

    /**
     * @param array<string,mixed> $def
     * @param array{mutations:array,otherVs:array,scannedRows:int} $result
     * @param list<string> $columns
     */
    private function reportTable(array $def, array $columns, array $result, string $outDir): void {
        $this->newLine();
        $this->info(sprintf(
            '%s（%s）：掃描 %d 列、命中(可轉) %d、[OTHER-v] %d',
            $def['table'],
            implode(',', $columns),
            $result['scannedRows'],
            count($result['mutations']),
            count($result['otherVs'])
        ));

        $samples = array_slice($result['mutations'], 0, 8);
        if ($samples !== []) {
            $this->table(['pk', 'column', 'from', 'to'], array_map(static fn ($m) => [
                json_encode($m['pk'], JSON_UNESCAPED_UNICODE),
                $m['column'],
                $m['from'],
                $m['to'],
            ], $samples));
        }

        foreach (['mutations', 'otherVs'] as $bucket) {
            file_put_contents(
                $outDir.DIRECTORY_SEPARATOR.$def['resource'].'-'.$bucket.'.json',
                json_encode($result[$bucket], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * 送出變更（走 /api/v2/mutate，mode:direct）。同一列多欄命中合併為一次請求。Token 僅用於 header。
     *
     * @param array<string,mixed> $def
     * @param list<array{pk:array,column:string,from:string,to:string}> $mutations
     * @return int 失敗筆數
     */
    private function applyMutations(array $def, array $mutations, string $baseUrl, string $token, string $outDir): int {
        $endpoint = rtrim($baseUrl, '/').'/api/v2/mutate';

        // 依 pk 合併同列的多欄變更
        $byPk = [];
        foreach ($mutations as $m) {
            $key = json_encode($m['pk'], JSON_UNESCAPED_UNICODE);
            if (!isset($byPk[$key])) {
                $byPk[$key] = ['pk' => $m['pk'], 'changes' => []];
            }
            $byPk[$key]['changes'][$m['column']] = $m['to'];
        }

        $ok = 0;
        $fail = 0;
        $failures = [];
        foreach ($byPk as $entry) {
            $resp = Http::withToken($token)->acceptJson()->post($endpoint, [
                'resource' => $def['resource'],
                'mode' => 'direct',
                'person_id' => 0,
                'target' => ['pk' => $entry['pk']],
                'changes' => $entry['changes'],
            ]);
            if ($resp->successful()) {
                $ok++;
            } else {
                $fail++;
                // 防禦性：--base-url 為呼叫端可控，惡意/誤設端點可能把 Authorization 反射進 body。
                // 落檔前把 token 字串一律塗掉，確保 token 絕不寫入磁碟（即使被反射）。
                $failures[] = [
                    'pk' => $entry['pk'],
                    'status' => $resp->status(),
                    'body' => $this->redactToken($resp->json() ?? $resp->body(), $token),
                ];
            }
        }

        $this->line("  送出 {$def['table']}：成功 {$ok}、失敗 {$fail}");
        if ($failures !== []) {
            file_put_contents(
                $outDir.DIRECTORY_SEPARATOR.$def['resource'].'-apply-failures.json',
                json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }

        return $fail;
    }

    /**
     * 遞迴把 token 明文從（字串／陣列）回應內容中塗掉，確保 token 不隨失敗紀錄落檔。
     */
    private function redactToken(mixed $data, string $token): mixed {
        if ($token === '') {
            return $data;
        }
        if (is_string($data)) {
            return str_replace($token, '[REDACTED]', $data);
        }
        if (is_array($data)) {
            $out = [];
            foreach ($data as $key => $value) {
                // 連 key 也塗（惡意端點若把 token 反射成 JSON key）
                $safeKey = is_string($key) ? str_replace($token, '[REDACTED]', $key) : $key;
                $out[$safeKey] = $this->redactToken($value, $token);
            }

            return $out;
        }

        return $data;
    }
}
