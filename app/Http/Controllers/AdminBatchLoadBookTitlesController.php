<?php

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Models\Pinyin;
use App\Models\TextCode;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Services\VariantCharNormalizer;
use App\Support\PinyinUmlaut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AdminBatchLoadBookTitlesController extends Controller {
    /**
     * 書名字形標準化對照表（異體字 → 標準字）。
     *
     * 與 VariantCharNormalizer 的差異：此對照表會「改寫存入 TEXT_CODES.c_title_chn
     * 的書名本身」，而非僅用於拼音轉換。只放確定要把原始書名一併標準化的字形，
     * 例如「峯」一律正規化為「峰」（兩者皆為繁體，屬同字異形，非繁簡轉換）。
     */
    private const TITLE_VARIANT_MAP = [
        '峯' => '峰',
        '靑' => '青',
        '頴' => '穎',
    ];

    /**
     * @var OperationRepository
     */
    protected $operationRepository;

    /**
     * @var ToolsRepository
     */
    protected $toolsRepository;

    public function __construct(OperationRepository $operationRepository, ToolsRepository $toolsRepository) {
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

        DB::transaction(function () use (&$results, $rows, $batchId) {
            $nextId = (int) DB::table('TEXT_CODES')->max('c_textid');
            if ($nextId < 0) {
                $nextId = 0;
            }

            foreach ($rows as $row) {
                $nextId++;

                $normalizedTitle = $this->normalizeTitle($row['title']);
                $titleWithoutVolume = $this->stripVolumeInfo($row['title']);
                $pinyinTitle = $this->buildPinyin($titleWithoutVolume);
                $dynasty = $this->lookupDynasty($row['author_id']);

                $payload = [
                    'c_textid' => $nextId,
                    'c_title_chn' => $normalizedTitle,
                    'c_title' => $pinyinTitle,
                    'c_text_type_id' => '01',
                    'c_text_dy' => $dynasty,
                    'c_source' => $row['source'],
                    'c_notes' => '[' . $batchId . ']',
                ];

                $payload = $this->toolsRepository->timestamp($payload, true);

                TextCode::create($payload);

                $this->operationRepository->store(
                    Auth::id(),
                    '',
                    1,
                    'TEXT_CODES',
                    $nextId,
                    $payload
                );

                $results[] = [
                    'line' => $row['line'],
                    'author_id' => $row['author_id'],
                    'title' => $normalizedTitle,
                    'title_pinyin' => $pinyinTitle,
                    'source' => $row['source'],
                    'dynasty' => $dynasty,
                    'text_type' => $payload['c_text_type_id'],
                    'notes' => $payload['c_notes'],
                    'created_by' => $payload['c_created_by'] ?? null,
                    'created_date' => $payload['c_created_date'] ?? null,
                    'c_textid' => $nextId,
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

        $newPinyin = $this->normalizePinyinInput($data['pinyin']);
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

            $rows[] = [
                'line' => $lineNumber,
                'author_id' => (int) $authorId,
                // 在書名「誕生處」一次性標準化字形（峯→峰），讓後續所有
                // 消費端（c_title_chn、拼音、無拼音檢查）都只看到標準化後的書名，
                // 避免任一路徑漏做而三者不一致。
                'title' => $this->standardizeTitleVariants($title),
                'source' => $source,
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
            }
        }

        return $errors;
    }

    /**
     * Return Han characters in the title that the Pinyin dictionary cannot translate.
     * Mirrors the steps buildPinyin() takes (drop volume info, normalize variants),
     * so the result reflects exactly which characters would survive untranslated in
     * c_title. Pinyin::getPinyin() returns the original character on lookup miss.
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
            $pinyin = trim((string) Pinyin::getPinyin($ch));
            if ($pinyin === '' || $pinyin === $ch) {
                $unmapped[$ch] = true;
            }
        }

        return array_keys($unmapped);
    }

    /**
     * Standardize variant glyphs in the title (峯→峰). Unlike VariantCharNormalizer
     * (which only affects the pinyin lookup and leaves the title untouched), this
     * rewrites the stored 中文書名 itself. It is applied ONCE in parseEntries() when
     * the row's title is first built, so every downstream consumer — c_title_chn,
     * the pinyin (c_title) and the unpinyinable check — receives an already
     * standardized title and the three can never disagree on which character the
     * title contains.
     */
    protected function standardizeTitleVariants(string $title): string {
        return strtr($title, self::TITLE_VARIANT_MAP);
    }

    /**
     * Remove redundant punctuation/spaces from the supplied title.
     */
    protected function normalizeTitle(string $title): string {
        $title = preg_replace('/\s+/u', '', $title);
        $title = str_replace(['（', '）'], ['(', ')'], $title);
        $title = preg_replace('/[:：]\s*/u', ': ', $title);

        return trim($title);
    }

    /**
     * Remove volume/annotation info trailing after colon characters.
     */
    protected function stripVolumeInfo(string $title): string {
        return trim(preg_replace('/[:：].*$/u', '', $title));
    }

    /**
     * Convert Chinese title to a space-separated pinyin string.
     */
    protected function buildPinyin(string $title): string {
        // 標準化異體字（僅用於拼音轉換，不修改原始標題）
        $normalizedTitle = VariantCharNormalizer::normalize($title);

        $chars = preg_split('//u', $normalizedTitle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $syllables = [];

        foreach ($chars as $char) {
            if (preg_match('/\p{Han}/u', $char)) {
                $syllables[] = strtolower(Pinyin::getPinyin($char));
            } elseif (preg_match('/[A-Za-z0-9]/u', $char)) {
                $syllables[] = strtolower($char);
            }
        }

        $syllables = array_filter($syllables, static function ($syllable) {
            return $syllable !== '';
        });

        if (empty($syllables)) {
            return strtolower(trim(preg_replace('/\s+/u', ' ', $normalizedTitle)));
        }

        // 止血：把生成拼音中殘留的 v 代寫正規化為 ü。
        return PinyinUmlaut::normalize(implode(' ', $syllables));
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
