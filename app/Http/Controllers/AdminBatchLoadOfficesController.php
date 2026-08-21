<?php

namespace App\Http\Controllers;

use App\Services\CharVariantMapService;
use App\Services\Import\OfficeImportService;
use App\Support\VariantLabelMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AdminBatchLoadOfficesController extends Controller {
    /**
     * @var OfficeImportService
     */
    protected $officeImportService;

    public function __construct(OfficeImportService $officeImportService) {
        $this->officeImportService = $officeImportService;
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

    /**
     * Inertia + React 版：批次匯入官職表單頁。
     */
    public function appShowForm(Request $request) {
        $this->ensureAdmin();

        return Inertia::render('Admin/BatchLoadOffices/Index', [
            'input' => (string) old('entries', ''),
            'results' => session('batch_results', []),
            'batch_errors' => array_values(session('batch_errors', [])),
            'urls' => [
                'store' => route('app.admin.batch-load-offices.store', [], false),
                'reset' => route('app.admin.batch-load-offices', [], false),
            ],
            'page_translations' => [
                'admin' => is_array($t = trans('admin')) ? $t : [],
            ],
        ]);
    }

    /** store 完成後的列表路由（依請求路徑，Blade 與 Inertia 共用 store）。 */
    protected function listRouteName(Request $request): string {
        return $request->is('app/*') ? 'app.admin.batch-load-offices' : 'admin.batch-load-offices';
    }

    public function store(Request $request) {
        $this->ensureAdmin();

        $data = $request->validate([
            'entries' => 'required|string',
        ]);

        [$rows, $errors] = $this->parseEntries($data['entries']);

        if (!empty($errors)) {
            return $this->backWithErrors($request, $errors);
        }

        $dynastyMap = $this->getDynastyMap();
        $additionalErrors = $this->validateDynasties($rows, $dynastyMap);

        $typeErrors = $this->validateOfficeTypes(array_column($rows, 'type_id'));
        $sourceErrors = $this->validateSourceIds(array_column($rows, 'source_id'));

        $allErrors = array_merge($additionalErrors, $typeErrors, $sourceErrors);
        if (!empty($allErrors)) {
            return $this->backWithErrors($request, $allErrors);
        }

        $results = [];

        // 寫入委派給 OfficeImportService（與 mutation API 共用同一存儲過程），
        // controller 只保留解析／校驗／組裝畫面結果。逐列於同一交易內呼叫 create()，
        // 派生（拼音）、自動 c_office_id、配套 REL 行、operations + audit_log 皆由 service 負責。
        DB::transaction(function () use (&$results, $rows, $dynastyMap) {
            foreach ($rows as $row) {
                $dynastyCode = $dynastyMap[$row['dynasty_label']];

                $created = $this->officeImportService->create([
                    'name' => $row['name'],
                    'translation' => $row['translation'],
                    'dynasty_code' => $dynastyCode,
                    'type_id' => $row['type_id'],
                    'source_id' => $row['source_id'],
                ]);

                $results[] = [
                    'line' => $row['line'],
                    'office_id' => $created['office_id'],
                    // 顯示**實際落庫**的職名（可能已做異體字替換），不是使用者原輸入，
                    // 否則結果頁與資料庫不一致。變更明細另外列在 variant_replacements。
                    'name' => $created['name'] ?? $row['name'],
                    'variant_replacements' => CharVariantMapService::flattenReplaced($created['variant_replaced'] ?? []),
                    'pinyin' => $created['pinyin'],
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
            ->route($this->listRouteName($request))
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
            // 標籤在此就歸一：validateDynasties() 與交易內的 $dynastyMap[$row['dynasty_label']]
            // （無檢查陣列存取）吃的都是這個值。只在驗證階段歸一會讓後者拋
            // "Undefined array key" 而不是乾淨的逐列錯誤。
            $dynastyLabelRaw = trim($parts[2]);
            $dynastyLabel = VariantLabelMap::normalizeLabel($dynastyLabelRaw, 'DYNASTIES', 'c_dynasty_chn');
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
                // 保留使用者原輸入供錯誤訊息使用：查表用歸一後的值，但訊息裡要顯示他真的
                // 打了什麼（顯示歸一後的字會讓人一頭霧水——他從沒打過那個字）。
                'dynasty_label_raw' => $dynastyLabelRaw,
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
        // 鍵已做異體字歸一（見 App\Support\VariantLabelMap）：D6 不回溯校正，既有 DYNASTIES
        // 列若字面是「淸」就永遠是「淸」，只歸一表格裡的標籤查不到它。兩側都歸一，
        // 「表格寫淸／代碼表寫清」與反方向才都命中。碰撞取最小碼並記 warning。
        $pairs = DB::table('DYNASTIES')
            ->orderBy('c_dy')
            ->get(['c_dy', 'c_dynasty_chn'])
            ->map(fn ($row) => [(string) ($row->c_dynasty_chn ?? ''), (int) $row->c_dy])
            ->all();

        return VariantLabelMap::build($pairs, 'DYNASTIES', 'c_dynasty_chn')[0];
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
                $label = $row['dynasty_label_raw'] ?? $row['dynasty_label'];
                $errors[] = "第 {$row['line']} 行找不到朝代「{$label}」對應的代碼";
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
     * Redirect back with errors.
     *
     * @param array<int,string> $errors
     */
    protected function backWithErrors(Request $request, array $errors) {
        return redirect()
            ->route($this->listRouteName($request))
            ->withInput()
            ->with('batch_errors', $errors);
    }
}
