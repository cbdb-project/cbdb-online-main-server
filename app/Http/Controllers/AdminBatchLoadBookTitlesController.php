<?php

namespace App\Http\Controllers;

use App\Pinyin;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\TextCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminBatchLoadBookTitlesController extends Controller
{
    /**
     * @var OperationRepository
     */
    protected $operationRepository;

    /**
     * @var ToolsRepository
     */
    protected $toolsRepository;

    public function __construct(OperationRepository $operationRepository, ToolsRepository $toolsRepository)
    {
        $this->operationRepository = $operationRepository;
        $this->toolsRepository = $toolsRepository;
    }

    /**
     * Render the batch upload form.
     */
    public function showForm()
    {
        $this->ensureAdmin();

        return view('admin.batch_load_book_titles', [
            'page_title' => '批次匯入書稿資料',
            'page_description' => '貼上以 tab 分隔的作者 ID、書名與來源 TEXT_ID，新增至 TEXT_CODES',
            'page_url' => route('admin.batch-load-book-titles'),
            'input' => old('entries', ''),
            'results' => session('batch_results', []),
            'batchErrors' => session('batch_errors', []),
            'batchId' => session('batch_id'),
        ]);
    }

    /**
     * Handle the batch upload submission.
     */
    public function store(Request $request)
    {
        $this->ensureAdmin();

        $data = $request->validate([
            'entries' => 'required|string',
        ]);

        [$rows, $errors] = $this->parseEntries($data['entries']);

        if (!empty($errors)) {
            return redirect()
                ->route('admin.batch-load-book-titles')
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
                    'source' => $row['source'],
                    'dynasty' => $dynasty,
                    'c_textid' => $nextId,
                ];
            }
        });

        return redirect()
            ->route('admin.batch-load-book-titles')
            ->with('batch_results', $results)
            ->with('batch_errors', [])
            ->with('batch_id', $batchId);
    }

    /**
     * Ensure the current user is an active admin.
     */
    protected function ensureAdmin(): void
    {
        if (!Auth::check() || Auth::user()->is_active != 1 || Auth::user()->is_admin != 1) {
            abort(403);
        }
    }

    /**
     * Parse the submitted tab-separated lines.
     *
     * @param string $input
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,string>}
     */
    protected function parseEntries(string $input): array
    {
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
                'title' => $title,
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
    protected function generateBatchId(): string
    {
        return now()->format('YmdHis');
    }

    /**
     * Remove redundant punctuation/spaces from the supplied title.
     */
    protected function normalizeTitle(string $title): string
    {
        $title = preg_replace('/\s+/u', '', $title);
        $title = preg_replace('/[:：]\s*/u', ': ', $title);
        return trim($title);
    }

    /**
     * Remove volume/annotation info trailing after colon characters.
     */
    protected function stripVolumeInfo(string $title): string
    {
        return trim(preg_replace('/[:：].*$/u', '', $title));
    }

    /**
     * Convert Chinese title to a space-separated pinyin string.
     */
    protected function buildPinyin(string $title): string
    {
        $chars = preg_split('//u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
            return strtolower(trim(preg_replace('/\s+/u', ' ', $title)));
        }

        return implode(' ', $syllables);
    }

    /**
     * Look up dynasty (c_dy) for the given person ID.
     */
    protected function lookupDynasty(int $personId): ?string
    {
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
