<?php

namespace App\Http\Controllers;

use App\Models\Pinyin;
use App\Repositories\OperationRepository;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminBatchLoadOfficesController extends Controller {
    /**
     * @var OperationRepository
     */
    protected $operationRepository;

    public function __construct(OperationRepository $operationRepository) {
        $this->operationRepository = $operationRepository;
    }

    public function showForm() {
        $this->ensureAdmin();

        return view('admin.batch_load_offices', [
            'page_title' => __('admin.batch_load_offices'),
            'page_title_key' => '批次匯入官職',
            'page_description' => __('admin.batch_load_offices_desc'),
            'page_url' => route('admin.batch-load-offices'),
            'input' => old('entries', ''),
            'results' => session('batch_results', []),
            'batchErrors' => session('batch_errors', []),
        ]);
    }

    public function store(Request $request) {
        $this->ensureAdmin();

        $data = $request->validate([
            'entries' => 'required|string',
        ]);

        [$rows, $errors] = $this->parseEntries($data['entries']);

        if (!empty($errors)) {
            return $this->backWithErrors($errors);
        }

        $dynastyMap = $this->getDynastyMap();
        $additionalErrors = $this->validateDynasties($rows, $dynastyMap);

        $typeErrors = $this->validateOfficeTypes(array_column($rows, 'type_id'));
        $sourceErrors = $this->validateSourceIds(array_column($rows, 'source_id'));

        $allErrors = array_merge($additionalErrors, $typeErrors, $sourceErrors);
        if (!empty($allErrors)) {
            return $this->backWithErrors($allErrors);
        }

        $results = [];

        DB::transaction(function () use (&$results, $rows, $dynastyMap) {
            $nextOfficeId = (int) DB::table('OFFICE_CODES')->max('c_office_id');

            foreach ($rows as $row) {
                $nextOfficeId++;
                $pinyin = $this->buildPinyin($row['name']);
                $dynastyCode = $dynastyMap[$row['dynasty_label']];

                $officePayload = [
                    'c_office_id' => $nextOfficeId,
                    'c_dy' => $dynastyCode,
                    'c_office_pinyin' => $pinyin,
                    'c_office_trans' => $row['translation'],
                    'c_office_chn' => $row['name'],
                    'c_source' => $row['source_id'],
                ];

                DB::table('OFFICE_CODES')->insert($officePayload);
                $this->operationRepository->store(Auth::id(), '', 1, 'OFFICE_CODES', $nextOfficeId, $officePayload);

                $relationPayload = [
                    'c_office_id' => $nextOfficeId,
                    'c_office_tree_id' => $row['type_id'],
                ];

                DB::table('OFFICE_CODE_TYPE_REL')->insert($relationPayload);
                $this->operationRepository->store(
                    Auth::id(),
                    '',
                    1,
                    'OFFICE_CODE_TYPE_REL',
                    CompositePrimaryKey::buildStoredResourceId([
                        'c_office_id' => $nextOfficeId,
                        'c_office_tree_id' => $row['type_id'],
                    ]),
                    $relationPayload
                );

                $results[] = [
                    'line' => $row['line'],
                    'office_id' => $nextOfficeId,
                    'name' => $row['name'],
                    'pinyin' => $pinyin,
                    'translation' => $row['translation'],
                    'dynasty_label' => $row['dynasty_label'],
                    'dynasty_code' => $dynastyCode,
                    'type_id' => $row['type_id'],
                    'department' => $row['department'],
                    'source_id' => $row['source_id'],
                ];
            }
        });

        return redirect()
            ->route('admin.batch-load-offices')
            ->with('batch_results', $results)
            ->with('batch_errors', []);
    }

    protected function ensureAdmin(): void {
        if (!Auth::check() || !Auth::user()->canRunBatchImport()) {
            abort(403);
        }
    }

    /**
     * Parse tab separated entries.
     *
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
            if (!$parts || count($parts) < 6) {
                $errors[] = "第 {$lineNumber} 行需要至少六欄資料（職名、英文、朝代、官職類型 ID、所屬單位、來源 TEXT_ID）";

                continue;
            }

            $name = trim($parts[0]);
            $translation = trim($parts[1]);
            $dynastyLabel = trim($parts[2]);
            $typeId = trim($parts[3]);
            $department = trim($parts[4]);
            $sourceId = trim($parts[5]);

            $lineErrors = [];
            if ($name === '') {
                $lineErrors[] = "第 {$lineNumber} 行職名為空";
            }

            if ($dynastyLabel === '') {
                $lineErrors[] = "第 {$lineNumber} 行朝代為空";
            }

            if ($typeId === '') {
                $lineErrors[] = "第 {$lineNumber} 行官職類型 ID 為空";
            }

            if ($sourceId === '' || !ctype_digit($sourceId)) {
                $lineErrors[] = "第 {$lineNumber} 行來源 TEXT_ID 必須為整數";
            }

            if (!empty($lineErrors)) {
                $errors = array_merge($errors, $lineErrors);

                continue;
            }

            $rows[] = [
                'line' => $lineNumber,
                'name' => $name,
                'translation' => $translation,
                'dynasty_label' => $dynastyLabel,
                'type_id' => $typeId,
                'department' => $department,
                'source_id' => (int) $sourceId,
            ];
        }

        if (empty($rows) && empty($errors)) {
            $errors[] = '沒有有效資料，請確認輸入格式。';
        }

        return [$rows, $errors];
    }

    /**
     * Build mapping of dynasty label to code.
     *
     * @return array<string,int>
     */
    protected function getDynastyMap(): array {
        return DB::table('DYNASTIES')
            ->orderBy('c_dy')
            ->pluck('c_dy', 'c_dynasty_chn')
            ->mapWithKeys(function ($code, $label) {
                return [trim((string) $label) => (int) $code];
            })
            ->toArray();
    }

    /**
     * Validate dynasty labels exist.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,int> $dynastyMap
     * @return array<int,string>
     */
    protected function validateDynasties(array $rows, array $dynastyMap): array {
        $errors = [];
        foreach ($rows as $row) {
            if (!array_key_exists($row['dynasty_label'], $dynastyMap)) {
                $errors[] = "第 {$row['line']} 行找不到朝代「{$row['dynasty_label']}」對應的代碼";
            }
        }

        return $errors;
    }

    /**
     * Ensure office type nodes exist.
     *
     * @param array<int,string> $typeIds
     * @return array<int,string>
     */
    protected function validateOfficeTypes(array $typeIds): array {
        $unique = array_unique(array_filter($typeIds, static function ($value) {
            return $value !== '';
        }));

        if (empty($unique)) {
            return [];
        }

        $found = DB::table('OFFICE_TYPE_TREE')
            ->whereIn('c_office_type_node_id', $unique)
            ->pluck('c_office_type_node_id')
            ->toArray();

        $missing = array_diff($unique, $found);
        if (empty($missing)) {
            return [];
        }

        return array_map(function ($id) {
            return "找不到官職類型 ID {$id}，請確認是否存在於 OFFICE_TYPE_TREE";
        }, $missing);
    }

    /**
     * Ensure source TEXT IDs exist.
     *
     * @param array<int,int> $sourceIds
     * @return array<int,string>
     */
    protected function validateSourceIds(array $sourceIds): array {
        $unique = array_unique($sourceIds);
        if (empty($unique)) {
            return [];
        }

        $found = DB::table('TEXT_CODES')
            ->whereIn('c_textid', $unique)
            ->pluck('c_textid')
            ->map(function ($value) {
                return (int) $value;
            })
            ->toArray();

        $missing = array_diff($unique, $found);
        if (empty($missing)) {
            return [];
        }

        return array_map(function ($id) {
            return "找不到來源 TEXT_ID {$id}，請確認 TEXT_CODES 是否已有資料";
        }, $missing);
    }

    /**
     * Convert Chinese string to space-separated pinyin.
     */
    protected function buildPinyin(string $value): string {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
            return strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
        }

        return implode(' ', $syllables);
    }

    /**
     * Redirect back with errors.
     *
     * @param array<int,string> $errors
     */
    protected function backWithErrors(array $errors) {
        return redirect()
            ->route('admin.batch-load-offices')
            ->withInput()
            ->with('batch_errors', $errors);
    }
}
