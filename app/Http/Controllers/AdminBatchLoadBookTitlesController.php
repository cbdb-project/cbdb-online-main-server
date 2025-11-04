<?php

namespace App\Http\Controllers;

use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\TextCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            'page_description' => '貼上以 tab 分隔的作者 ID 與書名，新增至 TEXT_CODES',
            'page_url' => route('admin.batch-load-book-titles'),
            'input' => old('entries', ''),
            'results' => session('batch_results', []),
            'batchErrors' => session('batch_errors', []),
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

        $results = [];

        DB::transaction(function () use (&$results, $rows) {
            $nextId = (int) DB::table('TEXT_CODES')->max('c_textid');
            if ($nextId < 0) {
                $nextId = 0;
            }

            foreach ($rows as $row) {
                $nextId++;

                $payload = [
                    'c_textid' => $nextId,
                    'c_title_chn' => $row['title'],
                    'c_text_type_id' => '01',
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
                    'title' => $row['title'],
                    'c_textid' => $nextId,
                ];
            }
        });

        return redirect()
            ->route('admin.batch-load-book-titles')
            ->with('batch_results', $results)
            ->with('batch_errors', []);
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

            if (!$parts || count($parts) < 2) {
                $errors[] = "第 {$lineNumber} 行未找到兩欄資料";
                continue;
            }

            $authorId = trim($parts[0]);
            $title = trim($parts[1]);

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

            $rows[] = [
                'line' => $lineNumber,
                'author_id' => (int) $authorId,
                'title' => $title,
            ];
        }

        if (empty($rows) && empty($errors)) {
            $errors[] = '沒有有效資料，請確認輸入格式。';
        }

        return [$rows, $errors];
    }
}
