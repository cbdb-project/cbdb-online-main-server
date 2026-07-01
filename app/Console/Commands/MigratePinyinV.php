<?php

namespace App\Console\Commands;

use App\Repositories\BiogMainRepository;
use App\Services\Pinyin\PinyinMigrationPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * 人名拼音 v→ü 遷移執行指令（預設 dry-run，只計算不寫入）。
 *
 * 機制見 docs/PINYIN_V_TO_UMLAUT_MIGRATION.md §D-2/§D-3/§D-5/§D-6（Sheet 權威優先 + 寫入前漂移檢查；
 * auto_pinyin 重生僅作 high/low 信心標記，不決定寫不寫）。
 * 權威來源為 Frank 的 Google Sheet（兩分頁 CSV）。本指令**只有加 --execute 才會寫入**，
 * 且寫入一律走受審計的 /api/v2/mutate（mode:direct），逐筆有 audit / operations 可回退。
 *
 * **Token 安全**：--execute 的 Bearer token 只從環境變數 `CBDB_MIGRATE_TOKEN` 讀取，
 * 絕不接受 CLI 參數、絕不輸出到畫面／log／檔案。
 */
class MigratePinyinV extends Command {
    /** 公開 Google Sheet（非機密）。 */
    private const SHEET_ID = '19SOyBtA8cKE9aq_hIkxRiT-e2i6f5bFDIY_TcNAn57I';
    private const GID_BIOG = '248977087';
    private const GID_ALTNAME = '1425535916';

    /** @var string */
    protected $signature = 'cbdb:migrate-pinyin-v
                            {--table=both : both|biog|altname}
                            {--biog-csv= : BIOG_MAIN CSV 路徑（省略且 --fetch 時自 Sheet 下載）}
                            {--altname-csv= : ALTNAME CSV 路徑}
                            {--fetch : 自公開 Google Sheet 下載 CSV（需網路）}
                            {--surname= : 僅處理某姓氏拼音（BIOG_MAIN，一姓一批）}
                            {--limit=0 : 最多處理筆數（抽樣，0＝不限）}
                            {--out-dir= : 產物輸出目錄（預設 storage/app/pinyin-migration）}
                            {--execute : 實際寫入（走 /api/v2/mutate）；省略＝dry-run 只計算}
                            {--confidence=all : 寫入時只送指定信心（all|high|low）；high 另含無信心的 ALTNAME、跳過 BIOG low，low 僅送 BIOG low}
                            {--base-url=http://localhost : --execute 時的 API base URL}';

    /** @var string */
    protected $description = '人名拼音 v→ü 遷移：Sheet 權威、寫入前漂移檢查、重生僅作信心標記，預設 dry-run。--execute 才寫入（走審計 API）。';

    public function handle(): int {
        $table = (string) $this->option('table');
        if (!in_array($table, ['both', 'biog', 'altname'], true)) {
            $this->error('--table 只能是 both|biog|altname');

            return self::FAILURE;
        }
        $confidence = (string) $this->option('confidence');
        if (!in_array($confidence, ['all', 'high', 'low'], true)) {
            $this->error('--confidence 只能是 all|high|low');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $limit = max(0, (int) $this->option('limit'));
        $outDir = (string) ($this->option('out-dir') ?: storage_path('app/pinyin-migration'));
        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            $this->error("無法建立輸出目錄：{$outDir}");

            return self::FAILURE;
        }

        $token = null;
        if ($execute) {
            $token = getenv('CBDB_MIGRATE_TOKEN') ?: null;
            if (!$token) {
                $this->error('--execute 需要環境變數 CBDB_MIGRATE_TOKEN（Bearer token）；為安全，token 不接受 CLI 參數。');

                return self::FAILURE;
            }
        }

        $repo = app(BiogMainRepository::class);
        $regenerator = function (string $chn) use ($repo): array {
            $d = $repo->auto_pinyin(['c_name_chn' => $chn]);

            return [
                'c_surname' => (string) ($d['c_surname'] ?? ''),
                'c_mingzi' => (string) ($d['c_mingzi'] ?? ''),
                'c_name' => (string) ($d['c_name'] ?? ''),
            ];
        };
        $planner = new PinyinMigrationPlanner($regenerator);

        $mode = $execute ? '**寫入模式（--execute）**' : 'dry-run（只計算、不寫入）';
        $this->info("人名拼音 v→ü 遷移 — {$mode}");

        $totalMutations = 0;
        $totalExceptions = 0;
        $applyFailures = 0;
        $hadLoadError = false;
        $processedAny = false;

        foreach (['biog', 'altname'] as $side) {
            if ($table !== 'both' && $table !== $side) {
                continue;
            }
            $rows = $this->loadRows($side, $limit);
            if ($rows === null) {
                // 硬錯誤（下載失敗／路徑錯／缺欄位）——已在 loadRows 內報錯。
                $hadLoadError = true;

                continue;
            }
            if ($rows === []) {
                continue;   // 該側未提供 CSV 或空表：略過（非錯誤）。
            }
            $processedAny = true;
            if ($side === 'biog' && $this->option('surname')) {
                $sn = (string) $this->option('surname');
                $rows = array_values(array_filter($rows, static fn ($r) => str_starts_with($r['wrong_pinyin'], $sn) || str_starts_with($r['correct_pinyin'], $sn)));
            }

            $plan = $side === 'biog' ? $planner->planBiogMain($rows) : $planner->planAltname($rows);
            $this->reportPlan($side, $plan, $outDir);
            $totalMutations += count($plan['mutations']);
            $totalExceptions += count($plan['exceptions']);

            if ($execute) {
                $toApply = $this->filterByConfidence($plan['mutations'], $confidence);
                if ($confidence !== 'all') {
                    $this->line("  依 --confidence={$confidence} 篩選：本批送出 ".count($toApply).' / 共 '.count($plan['mutations']).' 筆');
                }
                if ($toApply !== []) {
                    $applyFailures += $this->applyMutations($toApply, (string) $this->option('base-url'), (string) $token, $outDir, $side);
                }
            }
        }

        $this->newLine();
        $this->line("合計預定變更 {$totalMutations} 筆、例外 {$totalExceptions} 筆。產物於：{$outDir}");
        if (!$execute) {
            $this->line('這是 dry-run；加 --execute 並設 CBDB_MIGRATE_TOKEN 才會實際寫入。');
        }

        // 退出碼語義（供批次/CI）：任何載入硬錯誤、寫入失敗、或完全沒處理到任何側 → 失敗。
        if ($hadLoadError) {
            $this->error('有 CSV 載入失敗，未完整執行。');

            return self::FAILURE;
        }
        if (!$processedAny) {
            $this->error('沒有可處理的資料（請提供 --biog-csv/--altname-csv 或 --fetch）。');

            return self::FAILURE;
        }
        if ($applyFailures > 0) {
            $this->error("有 {$applyFailures} 筆寫入失敗（詳見 *-apply-failures.json）。");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * 載入某一側的 Sheet 列（CSV）。
     *
     * @return array<int, array<string, string>>|null
     */
    private function loadRows(string $side, int $limit): ?array {
        $path = (string) ($side === 'biog' ? $this->option('biog-csv') : $this->option('altname-csv'));
        $fetchedTemp = null;

        if ($path === '' && $this->option('fetch')) {
            $gid = $side === 'biog' ? self::GID_BIOG : self::GID_ALTNAME;
            $url = 'https://docs.google.com/spreadsheets/d/'.self::SHEET_ID."/export?format=csv&gid={$gid}";
            $resp = Http::get($url);
            if (!$resp->successful()) {
                $this->error("下載 Sheet 失敗（{$side}）：HTTP {$resp->status()}");

                return null;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'pyv');
            file_put_contents($tmp, $resp->body());
            $path = $tmp;
            $fetchedTemp = $tmp;
        }

        if ($path === '') {
            // 未提供 CSV（且未 --fetch）：軟略過，非錯誤。
            $this->warn("略過 {$side}：未提供 CSV（--{$side}-csv= 或 --fetch）。");

            return [];
        }
        if (!is_file($path)) {
            $this->error("CSV 路徑不存在（{$side}）：{$path}");

            return null;
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            $this->error("無法讀取 CSV：{$path}");

            return null;
        }
        $header = fgetcsv($fh, null, ',', '"', '');
        if ($header === false) {
            fclose($fh);
            if ($fetchedTemp !== null) {
                @unlink($fetchedTemp);
            }

            return [];
        }
        $header = array_map(static fn ($h) => trim((string) $h), $header);
        // BIOG 需要 field 欄（決定改 c_surname/c_mingzi/c_name）；缺欄多半是傳錯分頁，早失敗以免後段不透明。
        if ($side === 'biog' && !in_array('field', $header, true)) {
            fclose($fh);
            if ($fetchedTemp !== null) {
                @unlink($fetchedTemp);
            }
            $this->error("BIOG CSV 缺少 field 欄（可能傳錯分頁）：{$path}");

            return null;
        }
        $validFields = ['c_surname', 'c_mingzi', 'c_name'];
        $rows = [];
        while (($data = fgetcsv($fh, null, ',', '"', '')) !== false) {
            $data = array_map(static fn ($v) => (string) $v, $data);
            // 空白/短列（Sheet 匯出常見）：欄數不符就跳過——array_combine 在 PHP 8 會擲 ValueError，不可依賴回傳 false。
            if (count($header) !== count($data)) {
                continue;
            }
            $assoc = array_combine($header, $data);
            if (!isset($assoc['id'], $assoc['wrong_pinyin'], $assoc['correct_pinyin'])) {
                continue;
            }
            // BIOG：field 必須是已知欄位，否則跳過（避免規劃器讀到未預期欄名）。
            if ($side === 'biog' && !in_array($assoc['field'] ?? '', $validFields, true)) {
                continue;
            }
            $rows[] = $assoc;
            if ($limit > 0 && count($rows) >= $limit) {
                break;
            }
        }
        fclose($fh);
        if ($fetchedTemp !== null) {
            @unlink($fetchedTemp);
        }

        return $rows;
    }

    /**
     * 依 --confidence 篩選要送出的 mutation。
     * - all：全部；high：high + 無信心欄位（ALTNAME），跳過 BIOG low；low：僅 BIOG low。
     *
     * @param  array<int, array>  $mutations
     * @return array<int, array>
     */
    public static function filterByConfidence(array $mutations, string $confidence): array {
        if ($confidence === 'all') {
            return array_values($mutations);
        }
        if ($confidence === 'high') {
            // high 或無信心欄位（ALTNAME）；明確排除 low 及未來可能新增的其它信心值。
            return array_values(array_filter($mutations, static fn ($m) => !array_key_exists('confidence', $m) || $m['confidence'] === 'high'));
        }

        return array_values(array_filter($mutations, static fn ($m) => ($m['confidence'] ?? null) === 'low'));
    }

    /** @param array{mutations:array,exceptions:array,skipped:array,alreadyDone:array} $plan */
    private function reportPlan(string $side, array $plan, string $outDir): void {
        $this->newLine();
        $hi = count(array_filter($plan['mutations'], static fn ($m) => ($m['confidence'] ?? null) === 'high'));
        $lo = count(array_filter($plan['mutations'], static fn ($m) => ($m['confidence'] ?? null) === 'low'));
        $conf = ($hi + $lo) > 0 ? "（信心 high {$hi}／low {$lo}）" : '';
        $this->info(strtoupper($side).'：預定變更 '.count($plan['mutations']).$conf
            .'、例外 '.count($plan['exceptions'])
            .'、跳過(漂移/落空) '.count($plan['skipped'])
            .'、已遷移 '.count($plan['alreadyDone']));

        // 抽樣預覽（前 5 筆變更）
        $samples = array_slice($plan['mutations'], 0, 5);
        if ($samples !== []) {
            $this->table(['pk', 'from', 'to'], array_map(static fn ($m) => [
                json_encode($m['pk'], JSON_UNESCAPED_UNICODE),
                (string) ($m['preview']['from'] ?? ''),
                (string) ($m['preview']['to'] ?? ''),
            ], $samples));
        }

        // 產物：把 mutations / exceptions / skipped 各寫一份 JSON 供人工複核。
        foreach (['mutations', 'exceptions', 'skipped', 'alreadyDone'] as $bucket) {
            $file = $outDir.DIRECTORY_SEPARATOR."{$side}-{$bucket}.json";
            file_put_contents($file, json_encode($plan[$bucket], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        // 另出一份 low-confidence（重生對不上 Sheet）供寫入前人工抽查（如生僻字/多音字/「之妻」英譯）。
        // 一律重寫（空則寫 []），避免重用 out-dir 時殘留上一輪過期清單而誤判。
        $low = array_values(array_filter($plan['mutations'], static fn ($m) => ($m['confidence'] ?? null) === 'low'));
        file_put_contents(
            $outDir.DIRECTORY_SEPARATOR."{$side}-low-confidence.json",
            json_encode($low, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 實際送出變更（走 /api/v2/mutate，mode:direct）。Token 僅用於 header，不輸出。
     *
     * @param  array<int, array>  $mutations
     * @return int  失敗筆數（供退出碼判斷）
     */
    private function applyMutations(array $mutations, string $baseUrl, string $token, string $outDir, string $side): int {
        $endpoint = rtrim($baseUrl, '/').'/api/v2/mutate';
        $ok = 0;
        $fail = 0;
        $failures = [];

        foreach ($mutations as $m) {
            $payload = [
                'resource' => $m['resource'],
                'mode' => 'direct',
                // person 子資源（basicinformation／altnames）API 要求頂層 person_id ＝ 該人 c_personid。
                'person_id' => $m['pk']['c_personid'] ?? 0,
                'target' => ['pk' => $m['pk']],
                'changes' => $m['changes'],
            ];
            $resp = Http::withToken($token)->acceptJson()->post($endpoint, $payload);
            if ($resp->successful()) {
                $ok++;
            } else {
                $fail++;
                // 只記錄 pk 與狀態/訊息，絕不含 token。
                $failures[] = ['pk' => $m['pk'], 'status' => $resp->status(), 'body' => $resp->json() ?? $resp->body()];
            }
        }

        $this->line("  送出 {$side}：成功 {$ok}、失敗 {$fail}");
        if ($failures !== []) {
            file_put_contents(
                $outDir.DIRECTORY_SEPARATOR."{$side}-apply-failures.json",
                json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }

        return $fail;
    }
}
