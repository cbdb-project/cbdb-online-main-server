<?php

namespace App\Http\Controllers;

use App\Services\Import\SocialInstituteImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AdminBatchLoadSocialInstitutesController extends Controller {
    /**
     * @var SocialInstituteImportService
     */
    protected $socialInstituteImportService;

    public function __construct(SocialInstituteImportService $socialInstituteImportService) {
        $this->socialInstituteImportService = $socialInstituteImportService;
    }

    public function showForm() {
        $this->ensureAdmin();

        return view('admin.batch_load_social_institutes', [
            'page_title' => __('admin.batch_load_social_institutes'),
            'page_title_key' => '批次匯入社會機構',
            'page_description' => __('admin.batch_load_social_institutes_desc'),
            'page_url' => route('admin.batch-load-social-institutes'),
            'input' => old('entries', ''),
            'results' => session('batch_results', []),
            'batchErrors' => session('batch_errors', []),
        ]);
    }

    /**
     * Inertia + React 版：批次匯入社會機構表單頁。
     */
    public function appShowForm(Request $request) {
        $this->ensureAdmin();

        return Inertia::render('Admin/BatchLoadSocialInstitutes/Index', [
            'input' => (string) old('entries', ''),
            'results' => session('batch_results', []),
            'batch_errors' => array_values(session('batch_errors', [])),
            'urls' => [
                'store' => route('app.admin.batch-load-social-institutes.store', [], false),
                'reset' => route('app.admin.batch-load-social-institutes', [], false),
            ],
            'page_translations' => [
                'admin' => is_array($t = trans('admin')) ? $t : [],
            ],
        ]);
    }

    /** store 完成後的列表路由（依請求路徑，Blade 與 Inertia 共用 store）。 */
    protected function listRouteName(Request $request): string {
        return $request->is('app/*') ? 'app.admin.batch-load-social-institutes' : 'admin.batch-load-social-institutes';
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

        $typeMap = $this->getTypeMap();
        $dynastyMap = $this->getDynastyMap();

        $additionalErrors = $this->validateLookups($rows, $typeMap, $dynastyMap);
        if (!empty($additionalErrors)) {
            return $this->backWithErrors($request, $additionalErrors);
        }

        $addressErrors = $this->validateAddressIds(array_column($rows, 'addr_id'));
        $sourceErrors = $this->validateSourceIds(array_column($rows, 'source_id'));

        $allErrors = array_merge($addressErrors, $sourceErrors);
        if (!empty($allErrors)) {
            return $this->backWithErrors($request, $allErrors);
        }

        $results = [];

        // 寫入委派給 SocialInstituteImportService（與 mutation API 共用同一存儲過程），
        // controller 只保留解析／校驗／組裝畫面結果。名稱去重由 service 於同一交易內以
        // DB 查詢為準：後一列能看見前一列剛插入的 NAME_CODES，故不需在此手動維護跨列狀態。
        DB::transaction(function () use ($rows, $typeMap, $dynastyMap, &$results) {
            foreach ($rows as $row) {
                $typeCode = $typeMap[$row['type_label']];
                $dynastyCode = $dynastyMap[$row['dynasty_label']];

                $created = $this->socialInstituteImportService->create([
                    'name' => $row['name'],
                    'type_code' => $typeCode,
                    'dynasty_code' => $dynastyCode,
                    'addr_id' => $row['addr_id'],
                    'source_id' => $row['source_id'],
                ]);

                $results[] = [
                    'line' => $row['line'],
                    'name' => $row['name'],
                    'name_code' => $created['name_code'],
                    'name_pinyin' => $created['name_pinyin'],
                    'name_created' => $created['name_created'],
                    'inst_code' => $created['inst_code'],
                    'type_label' => $row['type_label'],
                    'type_code' => $typeCode,
                    'dynasty_label' => $row['dynasty_label'],
                    'dynasty_code' => $dynastyCode,
                    'addr_id' => $row['addr_id'],
                    'source_id' => $row['source_id'],
                ];
            }
        });

        return redirect()
            ->route($this->listRouteName($request))
            ->with('batch_results', $results)
            ->with('batch_errors', []);
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
     * Parse tab-separated entries into structured rows.
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
                $errors[] = "第 {$lineNumber} 行需要至少六欄資料（名稱、類型、朝代、地址名稱、地址 ID、來源 TEXT_ID）";

                continue;
            }

            $name = trim($parts[0]);
            $typeLabel = trim($parts[1]);
            $dynastyLabel = trim($parts[2]);
            $addrId = trim($parts[4]);
            $sourceId = trim($parts[5]);

            $lineErrors = [];

            if ($name === '') {
                $lineErrors[] = "第 {$lineNumber} 行機構名稱為空";
            }

            if ($typeLabel === '') {
                $lineErrors[] = "第 {$lineNumber} 行機構類型為空";
            }

            if ($dynastyLabel === '') {
                $lineErrors[] = "第 {$lineNumber} 行朝代為空";
            }

            if ($addrId === '' || !ctype_digit($addrId)) {
                $lineErrors[] = "第 {$lineNumber} 行地址 ID 必須為整數";
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
                'type_label' => $typeLabel,
                'dynasty_label' => $dynastyLabel,
                'addr_id' => (int) $addrId,
                'source_id' => (int) $sourceId,
            ];
        }

        if (empty($rows) && empty($errors)) {
            $errors[] = '沒有有效資料，請確認輸入格式。';
        }

        return [$rows, $errors];
    }

    /**
     * Retrieve mapping of type label to code.
     *
     * @return array<string,int>
     */
    protected function getTypeMap(): array {
        $records = DB::table('SOCIAL_INSTITUTION_TYPES')
            ->select('c_inst_type_code', 'c_inst_type_hz', 'c_inst_type_py')
            ->orderBy('c_inst_type_code')
            ->get();

        $map = [];

        foreach ($records as $record) {
            $code = (int) $record->c_inst_type_code;
            $hz = trim((string) ($record->c_inst_type_hz ?? ''));
            $py = trim((string) ($record->c_inst_type_py ?? ''));

            if ($hz !== '') {
                $map[$hz] = $code;
            }

            if ($py !== '') {
                $map[$py] = $code;
            }
        }

        return $map;
    }

    /**
     * Retrieve mapping of dynasty label to code.
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
     * Validate type and dynasty labels exist.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,int> $typeMap
     * @param array<string,int> $dynastyMap
     * @return array<int,string>
     */
    protected function validateLookups(array $rows, array $typeMap, array $dynastyMap): array {
        $errors = [];

        foreach ($rows as $row) {
            if (!array_key_exists($row['type_label'], $typeMap)) {
                $errors[] = "第 {$row['line']} 行找不到類型「{$row['type_label']}」對應的代碼";
            }

            if (!array_key_exists($row['dynasty_label'], $dynastyMap)) {
                $errors[] = "第 {$row['line']} 行找不到朝代「{$row['dynasty_label']}」對應的代碼";
            }
        }

        return $errors;
    }

    /**
     * Ensure address IDs exist.
     *
     * @param array<int,int> $addrIds
     * @return array<int,string>
     */
    protected function validateAddressIds(array $addrIds): array {
        $unique = array_unique($addrIds);
        if (empty($unique)) {
            return [];
        }

        $found = DB::table('ADDR_CODES')
            ->whereIn('c_addr_id', $unique)
            ->pluck('c_addr_id')
            ->map(function ($value) {
                return (int) $value;
            })
            ->toArray();

        $missing = array_diff($unique, $found);
        if (empty($missing)) {
            return [];
        }

        return array_map(function ($id) {
            return "找不到地址 ID {$id}，請先建立位址資料";
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
            return "找不到來源 TEXT_ID {$id}，請確認資料是否已存在於 TEXT_CODES";
        }, $missing);
    }

    /**
     * Redirect back to form with errors.
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
