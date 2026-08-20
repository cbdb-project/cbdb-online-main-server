<?php

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Services\Import\TextImportService;
use App\Services\PinyinDictionary;
use App\Services\VariantCharNormalizer;
use App\Support\PinyinUmlaut;
use App\Support\SimplifiedOnlyChars;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AdminBatchLoadBookTitlesController extends Controller {
    /**
     * @var OperationRepository
     */
    protected $operationRepository;

    /**
     * @var ToolsRepository
     */
    protected $toolsRepository;

    public function __construct(
        OperationRepository $operationRepository,
        ToolsRepository $toolsRepository,
        protected TextImportService $textImportService
    ) {
        $this->operationRepository = $operationRepository;
        $this->toolsRepository = $toolsRepository;
    }

    /**
     * Render the batch upload form.
     */
    public function showForm() {
        $this->ensureAdmin();

        return view('admin.batch_load_book_titles', [
            'page_title' => __('admin.batch_load_books'),
            'page_title_key' => '批次匯入書稿資料',
            'page_description' => __('admin.batch_load_books_desc'),
            'page_url' => route('admin.batch-load-book-titles'),
            'input' => old('entries', ''),
            'results' => session('batch_results', []),
            'batchErrors' => session('batch_errors', []),
            'batchId' => session('batch_id'),
            'toast' => session('toast'),
        ]);
    }

    /**
     * Inertia + React 版：批次匯入書稿表單頁。讀取與 Blade 相同的 session 結果。
     */
    public function appShowForm(Request $request) {
        $this->ensureAdmin();

        return Inertia::render('Admin/BatchLoadBookTitles/Index', [
            'input' => (string) old('entries', ''),
            'results' => session('batch_results', []),
            'batch_errors' => array_values(session('batch_errors', [])),
            'batch_id' => session('batch_id'),
            'toast' => session('toast'),
            'urls' => [
                'store' => route('app.admin.batch-load-book-titles.store', [], false),
                'undo' => route('app.admin.batch-load-book-titles.undo', [], false),
                'reset' => route('app.admin.batch-load-book-titles', [], false),
                'update_pinyin' => route('app.admin.batch-load-book-titles.update-pinyin', [], false),
                'check_rare_chars' => route('app.admin.batch-load-book-titles.check-rare-chars', [], false),
            ],
            'page_translations' => [
                'admin' => is_array($t = trans('admin')) ? $t : [],
            ],
        ]);
    }

    /** 依目前請求路徑決定 store/undo 完成後的列表路由（Blade vs Inertia 共用 store/undo）。 */
    protected function listRouteName(Request $request): string {
        return $request->is('app/*') ? 'app.admin.batch-load-book-titles' : 'admin.batch-load-book-titles';
    }

    /**
     * Handle the batch upload submission.
     */
    public function store(Request $request) {
        $this->ensureAdmin();

        $data = $request->validate([
            'entries' => 'required|string',
            'force' => 'nullable|in:1',
        ]);

        $skipPinyinCheck = ($data['force'] ?? null) === '1';

        [$rows, $errors] = $this->parseEntries($data['entries']);

        if (empty($errors)) {
            $errors = $this->validateRows($rows, $skipPinyinCheck);
        }

        if (!empty($errors)) {
            return redirect()
                ->route($this->listRouteName($request))
                ->withInput()
                ->with('batch_errors', $errors);
        }

        $batchId = $this->generateBatchId();
        $results = [];

        // 存儲過程（配號、書名標準化、拼音派生、稽核）已抽到 TextImportService 聚合根
        // （docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6 step 2），與 mutation API（resource=
        // text-entity）共用同一實作；本控制器保留批次語義（解析、批前校驗、batch 標記、撤回）。
        DB::transaction(function () use (&$results, $rows, $batchId) {
            foreach ($rows as $row) {
                $dynasty = $this->lookupDynasty($row['author_id']);

                $created = $this->textImportService->create([
                    'title' => $row['title'],
                    'type_id' => '01',
                    'dynasty_code' => $dynasty !== null ? (int) $dynasty : null,
                    'source_id' => (int) $row['source'],
                    'notes' => '[' . $batchId . ']',
                ]);

                $results[] = [
                    'line' => $row['line'],
                    'author_id' => $row['author_id'],
                    'title' => $created['title'],
                    'title_pinyin' => $created['title_pinyin'],
                    'source' => $row['source'],
                    'dynasty' => $dynasty,
                    'text_type' => '01',
                    'notes' => '[' . $batchId . ']',
                    'created_by' => $created['row']['c_created_by'] ?? null,
                    'created_date' => $created['row']['c_created_date'] ?? null,
                    'c_textid' => $created['textid'],
                    'variant_replacements' => $row['variant_replacements'] ?? [],
                ];
            }
        });

        return redirect()
            ->route($this->listRouteName($request))
            ->with('batch_results', $results)
            ->with('batch_errors', [])
            ->with('batch_id', $batchId)
            ->with('toast', ['msg' => '已新增 '.count($results).' 筆資料。', 'type' => 'success']);
    }

    /**
     * Undo a previous batch by deleting every TEXT_CODES row whose c_notes
     * matches the supplied batch id, plus the matching operations log entries.
     * Identifying the batch by the c_notes sentinel keeps this idempotent and
     * cheap (no need to track which row IDs were created in this session).
     */
    public function undo(Request $request) {
        $this->ensureAdmin();

        $data = $request->validate([
            'batch_id' => 'required|string|regex:/^[0-9]{14}-[0-9A-F]{6}$/',
        ]);

        $marker = '['.$data['batch_id'].']';

        try {
            $deleted = DB::transaction(function () use ($marker) {
                $textIds = DB::table('TEXT_CODES')
                    ->where('c_notes', $marker)
                    ->pluck('c_textid')
                    ->map(fn ($v) => (string) $v)
                    ->all();

                if (empty($textIds)) {
                    return 0;
                }

                DB::table('operations')
                    ->where('resource', 'TEXT_CODES')
                    ->whereIn('resource_id', $textIds)
                    ->delete();

                return DB::table('TEXT_CODES')->where('c_notes', $marker)->delete();
            });
        } catch (QueryException $e) {
            // TEXT_CODES 入邊外鍵已翻成 ON DELETE RESTRICT（去級聯 Phase 1 批次 2）：
            // 批內書目若已被其他資料引用（出處/著述/別名來源等），DELETE 會被 DB 以 1451 擋下、
            // 整批交易回滾（含 operations 清理）。fail-closed、零資料損失，這裡轉為友好訊息。
            if (($e->errorInfo[1] ?? null) !== 1451) {
                throw $e;
            }

            return redirect()
                ->route($this->listRouteName($request))
                ->with('toast', [
                    'msg' => "批次 {$data['batch_id']} 中已有書目被其他資料引用，整批撤回已取消（未刪除任何資料）。請先移除引用，或改由管理員單筆處理。",
                    'type' => 'error',
                ]);
        }

        return redirect()
            ->route($this->listRouteName($request))
            ->with('toast', $deleted > 0
                ? ['msg' => "已撤回批次 {$data['batch_id']}，共刪除 {$deleted} 筆。", 'type' => 'success']
                : ['msg' => "找不到對應批次 {$data['batch_id']} 的資料，可能已被撤回或不存在。", 'type' => 'warning']);
    }

    /**
     * Update the c_title (pinyin) of a single TEXT_CODES row created by a
     * specific batch. Scoped to rows whose c_notes still equals "[$batchId]"
     * so this endpoint cannot be used to mutate unrelated TEXT_CODES rows.
     * After the UPDATE we re-SELECT the row and return the actual stored
     * value so the UI can display exactly what landed in the database.
     */
    public function updatePinyin(Request $request): JsonResponse {
        $this->ensureAdmin();

        $data = $request->validate([
            'c_textid' => 'required|integer',
            'batch_id' => 'required|string|regex:/^[0-9]{14}-[0-9A-F]{6}$/',
            'pinyin' => 'required|string|max:255',
        ]);

        $marker = '['.$data['batch_id'].']';
        $textId = (int) $data['c_textid'];

        $original = DB::table('TEXT_CODES')->where('c_textid', $textId)->first();
        if (!$original) {
            return response()->json(['ok' => false, 'message' => '找不到該筆 TEXT_CODES。'], 404);
        }
        if ((string) $original->c_notes !== $marker) {
            // Refuse edits on rows that were not created by the supplied batch.
            // Keeps this endpoint scoped to the just-imported result table only.
            return response()->json(['ok' => false, 'message' => '此筆資料不屬於指定批次，無法編輯。'], 422);
        }

        // §D-6 保存止血：書名拼音 c_title（TEXT_CODES）為 Tier 1，靜默套 v→ü（與批次 buildPinyin 一致，
        // 修正 inline 編輯先前未歸一化的缺口）。
        $newPinyin = PinyinUmlaut::normalize($this->normalizePinyinInput($data['pinyin']));
        if ($newPinyin === '') {
            return response()->json(['ok' => false, 'message' => '拼音內容不可為空。'], 422);
        }

        $stored = DB::transaction(function () use ($original, $textId, $newPinyin) {
            $payload = $this->toolsRepository->timestamp([
                'c_title' => $newPinyin,
            ], false);

            DB::table('TEXT_CODES')->where('c_textid', $textId)->update($payload);

            // SELECT the row back so we return whatever the database actually stored
            // (after any column-level coercion such as truncation or charset folding).
            $fresh = DB::table('TEXT_CODES')->where('c_textid', $textId)->first();

            // resource_data and resource_original must describe the SAME column
            // set, otherwise OperationRepository::getArrDiff() walks resource_data
            // keys and reports any unmatched key (c_textid, c_title_chn …) as a
            // false "field changed" entry on the comparison modal. Likewise,
            // restoreUpdate filters resource_original through filterColumns and
            // UPDATEs whatever survives, so we must capture every column this
            // endpoint actually mutates: c_title plus c_modified_by/c_modified_date
            // (set by timestamp()). c_textid is recoverable from resource_id via
            // CompositePrimaryKey::parseStoredResourceId('TEXT_CODES'), so omit it
            // here. c_title_chn is never written by this endpoint — keep it out.
            $resourceData = $payload;
            $resourceOriginal = [
                'c_title' => $original->c_title,
                'c_modified_by' => $original->c_modified_by,
                'c_modified_date' => $original->c_modified_date,
            ];

            $this->operationRepository->store(
                Auth::id(),
                '',
                Operation::TYPE_UPDATE,
                'TEXT_CODES',
                $textId,
                $resourceData,
                $resourceOriginal
            );

            return $fresh;
        });

        return response()->json([
            'ok' => true,
            'c_textid' => (int) $stored->c_textid,
            'c_title' => (string) $stored->c_title,
            'c_title_chn' => (string) $stored->c_title_chn,
            'modified_by' => $stored->c_modified_by,
            'modified_date' => (string) $stored->c_modified_date,
        ]);
    }

    /**
     * 罕見字檢測：逐行檢查書名中的漢字是否「直接存在於人工策展的 pinyin 表」，
     * 把查不到的字連同行號列出，供管理員在正式匯入前先補齊表資料或人工確認。
     *
     * 與 store() 的匯入前檢查（collectUnpinyinableHan）刻意不同：
     *   - 匯入檢查用 PinyinDictionary::getPinyin()，會退回 opencc-pinyin 靜態字典，
     *     只有連 opencc 都查不到（極生僻字/非漢字）才擋。
     *   - 本檢測用 PinyinDictionary::isInTable()，「只看 pinyin 表」，不吃 opencc 退回，
     *     因此只要不在權威表內（即使 opencc 補得出讀音）都會被列為罕見字。
     *
     * 檢測範圍與匯入實際寫入 c_title 的拼音對齊：先 stripVolumeInfo() 去掉冒號後的
     * 卷冊註記（那段不會進拼音），但不套 VariantCharNormalizer——異體字歸一化屬於
     * 另一層退回機制，這裡同樣要如實回報「表未收此字形」。書名本身的字形標準化
     * （峯→峰，TITLE_VARIANT_MAP）已在 parseEntries 完成，故檢測看到的是標準化後的書名。
     */
    public function checkRareChars(Request $request): JsonResponse {
        $this->ensureAdmin();

        $data = $request->validate([
            'entries' => 'required|string',
        ]);

        [$rows, $parseErrors] = $this->parseEntries($data['entries']);

        $missing = [];
        $uniqueChars = [];

        foreach ($rows as $row) {
            $rareChars = $this->collectCharsMissingFromPinyinTable((string) $row['title']);
            if (empty($rareChars)) {
                continue;
            }

            $chars = [];
            foreach ($rareChars as $ch) {
                $chars[] = ['char' => $ch, 'codepoint' => sprintf('U+%04X', mb_ord($ch, 'UTF-8'))];
                $uniqueChars[$ch] = true;
            }

            $missing[] = [
                'line' => $row['line'],
                'title' => $row['title'],
                'chars' => $chars,
            ];
        }

        return response()->json([
            'ok' => true,
            'checked' => count($rows),
            'parse_errors' => array_values($parseErrors),
            'missing' => $missing,
            'unique_char_count' => count($uniqueChars),
        ]);
    }

    /**
     * 回傳書名中「不在 pinyin 表」的漢字（去重，保留出現順序）。
     * 檢測步驟與 buildPinyin 對齊（去卷冊註記後逐字檢查），但改用 isInTable()
     * 只查權威表、不吃 opencc 退回，也不套 VariantCharNormalizer 異體字歸一化。
     *
     * @return array<int,string>
     */
    protected function collectCharsMissingFromPinyinTable(string $title): array {
        $titleWithoutVolume = $this->stripVolumeInfo($title);
        $chars = preg_split('//u', $titleWithoutVolume, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $missing = [];
        foreach ($chars as $ch) {
            if (!preg_match('/^\p{Han}$/u', $ch)) {
                continue;
            }
            if (!PinyinDictionary::isInTable($ch)) {
                $missing[$ch] = true;
            }
        }

        return array_keys($missing);
    }

    /**
     * Trim, collapse whitespace and lowercase the user-edited pinyin string.
     * The admin is the authority on what the pinyin should be, so we do NOT
     * re-run the Pinyin dictionary here — that would silently overwrite their
     * fix. We only normalise whitespace/case so the stored value is consistent
     * with the rest of c_title in TEXT_CODES.
     */
    protected function normalizePinyinInput(string $value): string {
        $value = preg_replace('/\s+/u', ' ', $value);

        return strtolower(trim((string) $value));
    }

    /**
     * Ensure the current user is an active admin.
     */
    protected function ensureAdmin(): void {
        if (!Auth::check() || !Auth::user()->canRunBatchImport()) {
            abort(403);
        }
    }

    /**
     * Parse the submitted tab-separated lines.
     *
     * @param string $input
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,string>}
     */
    protected function parseEntries(string $input): array {
        $lines = preg_split('/\r\n|\n|\r/', $input);
        $rows = [];
        $errors = [];

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $parts = preg_split("/\t+/", $line);

            if (!$parts || count($parts) < 3) {
                $errors[] = "第 {$lineNumber} 行未找到三欄資料（作者 ID、書名、來源 TEXT_ID）";

                continue;
            }

            $authorId = trim($parts[0]);
            $title = trim($parts[1]);
            $source = trim($parts[2]);

            if ($authorId === '') {
                $errors[] = "第 {$lineNumber} 行作者 ID 為空";

                continue;
            }

            if (!ctype_digit($authorId)) {
                $errors[] = "第 {$lineNumber} 行作者 ID 必須為整數";

                continue;
            }

            if ($title === '') {
                $errors[] = "第 {$lineNumber} 行書名為空";

                continue;
            }

            if ($source === '') {
                $errors[] = "第 {$lineNumber} 行來源 TEXT_ID 為空";

                continue;
            }

            $standardized = $this->standardizeTitleVariants($title);

            $rows[] = [
                'line' => $lineNumber,
                'author_id' => (int) $authorId,
                // 在書名「誕生處」一次性標準化字形（峯→峰），讓後續所有
                // 消費端（c_title_chn、拼音、無拼音檢查）都只看到標準化後的書名，
                // 避免任一路徑漏做而三者不一致。
                'title' => $standardized['title'],
                'source' => $source,
                'variant_replacements' => $standardized['variant_replacements'],
            ];
        }

        if (empty($rows) && empty($errors)) {
            $errors[] = '沒有有效資料，請確認輸入格式。';
        }

        return [$rows, $errors];
    }

    /**
     * Generate a batch identifier for audit trail.
     */
    protected function generateBatchId(): string {
        // Second-level timestamp + 6 hex chars of randomness so two imports inside the
        // same second cannot share a marker (which would make undo() ambiguous).
        return now()->format('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * Validate every parsed row. Author and source ID checks are mandatory.
     * The pinyin-coverage check is skipped when $skipPinyinCheck is true,
     * letting an admin force-import titles with rare characters that are
     * known-correct but not yet in the Pinyin dict.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,string>
     */
    protected function validateRows(array $rows, bool $skipPinyinCheck = false): array {
        $errors = [];

        $authorIds = array_values(array_unique(array_map(static fn ($r) => (int) $r['author_id'], $rows)));
        $sourceIds = [];
        foreach ($rows as $r) {
            if (ctype_digit((string) $r['source'])) {
                $sourceIds[] = (int) $r['source'];
            }
        }
        $sourceIds = array_values(array_unique($sourceIds));

        $existingAuthors = $authorIds
            ? DB::table('BIOG_MAIN')->whereIn('c_personid', $authorIds)->pluck('c_personid')->map(fn ($v) => (int) $v)->all()
            : [];
        $existingAuthors = array_flip($existingAuthors);

        $existingSources = $sourceIds
            ? DB::table('TEXT_CODES')->whereIn('c_textid', $sourceIds)->pluck('c_textid')->map(fn ($v) => (int) $v)->all()
            : [];
        $existingSources = array_flip($existingSources);

        foreach ($rows as $row) {
            $line = $row['line'];

            if (!isset($existingAuthors[(int) $row['author_id']])) {
                $errors[] = "第 {$line} 行作者 ID {$row['author_id']} 不存在於 BIOG_MAIN";
            }

            $sourceRaw = (string) $row['source'];
            if (!ctype_digit($sourceRaw)) {
                $errors[] = "第 {$line} 行來源 TEXT_ID 必須為整數（目前為「{$sourceRaw}」）";
            } elseif (!isset($existingSources[(int) $sourceRaw])) {
                $errors[] = "第 {$line} 行來源 TEXT_ID {$sourceRaw} 不存在於 TEXT_CODES";
            }

            if (!$skipPinyinCheck) {
                $unpinyinable = $this->collectUnpinyinableHan((string) $row['title']);
                if (!empty($unpinyinable)) {
                    $display = implode(' ', array_map(static function ($ch) {
                        return sprintf('「%s」(U+%04X)', $ch, mb_ord($ch, 'UTF-8'));
                    }, $unpinyinable));
                    $errors[] = "第 {$line} 行書名含有無拼音對應的漢字（將造成 c_title 內仍含中文）：{$display}";
                }

                // 簡體字形嫌疑（僅簡體字集，見 SimplifiedOnlyChars）：多為現代轉錄混入，
                // 但這些字形在古籍中也可能是俗字／古字，故採警告＋強制放行語義，不硬性拒絕。
                $simplified = SimplifiedOnlyChars::findIn((string) $row['title']);
                if (!empty($simplified)) {
                    $display = implode(' ', array_map(static function ($ch) {
                        return sprintf('「%s」(U+%04X)', $ch, mb_ord($ch, 'UTF-8'));
                    }, $simplified));
                    $errors[] = "第 {$line} 行書名含簡體字形：{$display}；若文獻原貌即為此字形（俗字），請使用強制匯入放行";
                }
            }
        }

        return $errors;
    }

    /**
     * Return Han characters in the title that the Pinyin dictionary cannot translate.
     * Mirrors the steps buildPinyin() takes (drop volume info, normalize variants),
     * so the result reflects exactly which characters would survive untranslated in
     * c_title. PinyinDictionary::getPinyin() returns the original character on lookup miss.
     *
     * @return array<int,string>
     */
    protected function collectUnpinyinableHan(string $title): array {
        $titleWithoutVolume = $this->stripVolumeInfo($title);
        $normalized = VariantCharNormalizer::normalize($titleWithoutVolume);
        $chars = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $unmapped = [];
        foreach ($chars as $ch) {
            if (!preg_match('/^\p{Han}$/u', $ch)) {
                continue;
            }
            $pinyin = trim((string) PinyinDictionary::getPinyin($ch));
            if ($pinyin === '' || $pinyin === $ch) {
                $unmapped[$ch] = true;
            }
        }

        return array_keys($unmapped);
    }

    /**
     * Standardize variant glyphs in the title (峯→峰) — delegates to the aggregate root
     * (see TextImportService::standardizeTitleVariants for the semantics). Applied ONCE in
     * parseEntries() when the row's title is first built, so every downstream consumer —
     * c_title_chn, the pinyin (c_title) and the unpinyinable check — receives an already
     * standardized title and the three can never disagree on which character it contains.
     * （service create 內會再跑一次；對已標準化書名為冪等 no-op。）
     *
     * @return array{title: string, variant_replacements: array<int,array{from:string,to:string}>}
     */
    protected function standardizeTitleVariants(string $title): array {
        return $this->textImportService->standardizeTitleVariants($title);
    }

    /**
     * Remove volume/annotation info trailing after colon characters（委派聚合根，語義單源）。
     */
    protected function stripVolumeInfo(string $title): string {
        return $this->textImportService->stripVolumeInfo($title);
    }

    /**
     * Look up dynasty (c_dy) for the given person ID.
     */
    protected function lookupDynasty(int $personId): ?string {
        try {
            $value = DB::table('BIOG_MAIN')->where('c_personid', $personId)->value('c_dy');
        } catch (\Throwable $e) {
            return null;
        }

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}
