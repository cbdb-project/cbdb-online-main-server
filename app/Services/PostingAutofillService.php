<?php

namespace App\Services;

use App\Support\LlmFallbackTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostingAutofillService {
    use LlmFallbackTrait;

    protected string $apiKey;
    protected string $apiEndpoint;
    protected string $model;
    protected string $promptTemplate;

    public function __construct() {
        // 完全從 config 讀取，config 層會從 .env 讀取並提供 fallback
        // 不在 Service 層 hard coding 任何默認值
        $this->apiKey = config('services.gemini.api_key', '');
        $this->apiEndpoint = config('services.gemini.api_endpoint');
        $this->model = config('services.gemini.model');
        $this->initLlmFallback();

        // 讀取 prompt 模板（AI 任官信息提取 prompt）
        $this->promptTemplate = resource_path('prompts/ai-posting-extraction-prompt.txt');
    }

    /**
     * 從古籍文本提取任官信息並返回填充建議
     *
     * @param string $sourceText 原始文本
     * @param int $personId 人物 ID（用於查詢候選出處）
     * @return array ['success' => bool, 'data' => array, 'error' => string|null]
     */
    public function extractAndMatch(string $sourceText, int $personId): array {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'AI API 未配置，請聯繫管理員',
            ];
        }

        try {
            // 1. 調用 AI 提取結構化數據
            $extractResult = $this->callAI($sourceText);

            if (!$extractResult['success']) {
                return $extractResult;
            }

            $aiData = $extractResult['data'];

            // 2. 對每個欄位進行模糊匹配
            $matchResult = $this->matchFields($aiData, $personId);

            return [
                'success' => true,
                'data' => [
                    'ai_extracted' => $aiData, // AI 原始提取結果
                    'matched_fields' => $matchResult['matched'], // 成功匹配的欄位
                    'suggested_fields' => $matchResult['suggested'], // 建議值（未匹配）
                    'empty_fields' => $matchResult['empty'], // 未提取的欄位
                    'statistics' => $this->buildStatistics($matchResult),
                ],
                'error' => null,
            ];

        } catch (\Exception $e) {
            Log::error('AI 提取任官信息失敗', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => '處理失敗：' . $e->getMessage(),
            ];
        }
    }

    /**
     * 建構統計信息，區分有值的建議和無匹配的建議
     */
    private function buildStatistics(array $matchResult): array {
        $suggestedWithValue = 0;
        $notFoundCount = 0;

        foreach ($matchResult['suggested'] as $fieldData) {
            if (isset($fieldData['value'])) {
                $suggestedWithValue++;
            } else {
                $notFoundCount++;
            }
        }

        return [
            'matched_count' => count($matchResult['matched']),
            'suggested_count' => $suggestedWithValue,
            'not_found_count' => $notFoundCount,
            'empty_count' => count($matchResult['empty']),
        ];
    }

    /**
     * 調用 AI API 提取結構化數據（含 fallback）
     */
    protected function callAI(string $sourceText): array {
        try {
            $result = $this->doCallAI($sourceText);
        } catch (\Exception $e) {
            Log::error('PostingAutofill 主要 LLM 連線異常', ['exception' => $e->getMessage()]);
            $result = [
                'success' => false,
                'data' => null,
                'error' => 'AI API 連線失敗：' . $e->getMessage(),
            ];
        }

        if (!$result['success'] && $this->hasFallback()) {
            Log::warning('PostingAutofill 主要 LLM 失敗，嘗試 fallback', ['primary_error' => $result['error']]);
            $original = $this->switchToFallback();

            try {
                $result = $this->doCallAI($sourceText);
            } finally {
                $this->restoreFromFallback($original);
            }
        }

        return $result;
    }

    /**
     * 實際調用 AI API 的邏輯
     */
    protected function doCallAI(string $sourceText): array {
        // 讀取 prompt 模板
        if (!file_exists($this->promptTemplate)) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Prompt 模板檔案不存在',
            ];
        }

        $promptContent = file_get_contents($this->promptTemplate);

        // 從資料庫動態載入代碼表，替換 prompt 中的佔位符
        $promptContent = $this->injectCodeMappings($promptContent);

        $fullPrompt = $promptContent . "\n\n" . $sourceText;

        // 調用 LLM API（完整 prompt 約 12KB，模型處理需要較長時間）
        $response = Http::connectTimeout(15)->timeout(90)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])
            ->post($this->apiEndpoint, [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $fullPrompt,
                    ],
                ],
                'temperature' => 0.1,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'posting_extraction',
                        'strict' => true,
                        'schema' => $this->getJsonSchema(),
                    ],
                ],
            ]);

        if (!$response->successful()) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'AI API 調用失敗：' . $response->body(),
            ];
        }

        $responseData = $response->json();
        $content = $responseData['choices'][0]['message']['content'] ?? null;

        if (!$content) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'AI 返回格式錯誤',
            ];
        }

        $jsonData = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'JSON 解析失敗：' . json_last_error_msg(),
            ];
        }

        // 提取第一筆任官記錄（通常一次只錄入一筆）
        $postings = $jsonData['postings'] ?? [];

        if (empty($postings)) {
            return [
                'success' => false,
                'data' => null,
                'error' => '未能從文本中提取任官信息',
            ];
        }

        return [
            'success' => true,
            'data' => $postings[0], // 返回第一筆
            'error' => null,
        ];
    }

    /**
     * 從資料庫載入代碼表，替換 prompt 模板中的佔位符
     */
    protected function injectCodeMappings(string $promptContent): string {
        // APPOINTMENT_CODES
        $apptCodes = DB::table('APPOINTMENT_CODES')
            ->select(['c_appt_code', 'c_appt_desc_chn', 'c_appt_desc'])
            ->orderBy('c_appt_code')
            ->get();

        $apptLines = $apptCodes->map(function ($row) {
            $desc = trim(($row->c_appt_desc_chn ?? '') . ' / ' . ($row->c_appt_desc ?? ''), ' /');

            return sprintf('    %d -> %s', $row->c_appt_code, $desc);
        })->implode("\n");

        $promptContent = str_replace('{{APPT_CODE_MAPPINGS}}', $apptLines, $promptContent);

        // ASSUME_OFFICE_CODES
        $assumeCodes = DB::table('ASSUME_OFFICE_CODES')
            ->select(['c_assume_office_code', 'c_assume_office_desc_chn', 'c_assume_office_desc'])
            ->orderBy('c_assume_office_code')
            ->get();

        $assumeLines = $assumeCodes->map(function ($row) {
            $desc = trim(($row->c_assume_office_desc_chn ?? '') . ' / ' . ($row->c_assume_office_desc ?? ''), ' /');

            return sprintf('    %d -> %s', $row->c_assume_office_code, $desc);
        })->implode("\n");

        $promptContent = str_replace('{{ASSUME_OFFICE_CODE_MAPPINGS}}', $assumeLines, $promptContent);

        return $promptContent;
    }

    /**
     * 對 AI 提取的數據進行模糊匹配
     */
    protected function matchFields(array $aiData, int $personId): array {
        $matched = [];
        $suggested = [];
        $empty = [];

        // 取得人物的朝代代碼（用於過濾官名和年號）
        $personDynasty = DB::table('BIOG_MAIN')
            ->where('c_personid', $personId)
            ->value('c_dy');

        // 注意：朝代欄位已在前端頁面加載時自動填充（從 person-id-display 組件的 dynasty_code），
        // 所以 AI 不需要再填充此欄位

        // 取得任官年份（用於過濾地名）
        $postingYear = $aiData['c_firstyear'] ?? null;

        // 智能朝代確定邏輯：優先使用任官時間來確定朝代
        $effectiveDynasty = $personDynasty; // 默認使用人物朝代

        // 如果AI生成了任官時間，嘗試使用任官時間確定朝代
        $firstYear = $aiData['c_firstyear'] ?? null;
        $lastYear = $aiData['c_lastyear'] ?? null;

        if ($firstYear !== null || $lastYear !== null) {
            $firstYearDynasty = $firstYear ? $this->getDynastyByYear($firstYear) : null;
            $lastYearDynasty = $lastYear ? $this->getDynastyByYear($lastYear) : null;

            // 情況1：只有 firstYear
            if ($firstYear !== null && $lastYear === null) {
                if ($firstYearDynasty !== null) {
                    $effectiveDynasty = $firstYearDynasty;
                }
            }
            // 情況2：只有 lastYear
            elseif ($firstYear === null && $lastYear !== null) {
                if ($lastYearDynasty !== null) {
                    $effectiveDynasty = $lastYearDynasty;
                }
            }
            // 情況3：兩者都有
            elseif ($firstYear !== null && $lastYear !== null) {
                // 如果兩個年份對應同一個朝代，使用該朝代
                if ($firstYearDynasty !== null && $firstYearDynasty === $lastYearDynasty) {
                    $effectiveDynasty = $firstYearDynasty;
                }
                // 如果兩個年份對應不同朝代，fallback 到人物朝代
                // $effectiveDynasty 已經是 $personDynasty，不需要額外處理
            }
        }

        Log::info('[AI Autofill] 朝代確定結果', [
            'person_id' => $personId,
            'person_dynasty' => $personDynasty,
            'first_year' => $firstYear,
            'last_year' => $lastYear,
            'first_year_dynasty' => $firstYear ? $this->getDynastyByYear($firstYear) : null,
            'last_year_dynasty' => $lastYear ? $this->getDynastyByYear($lastYear) : null,
            'effective_dynasty' => $effectiveDynasty,
        ]);

        // 智能朝代填充：如果根據任官時間確定的朝代與人物朝代不同，或者有任官時間時，填充朝代欄位
        if ($effectiveDynasty !== null && ($effectiveDynasty !== $personDynasty || ($firstYear !== null || $lastYear !== null))) {
            // 查詢朝代名稱用於顯示
            $dynastyInfo = DB::table('DYNASTIES')
                ->where('c_dy', $effectiveDynasty)
                ->first();

            // 如果查詢失敗（幾乎不會發生，因為幾乎所有人都有朝代），使用 0（未詳）作為 fallback
            $dynastyValue = $dynastyInfo ? $effectiveDynasty : 0;
            $dynastyText = $dynastyInfo?->c_dynasty_chn ?? '未詳';

            $matched['c_dy'] = [
                'value' => $dynastyValue,
                'text' => $dynastyText,
                'reason' => '根據任官時間自動確定',
            ];

            Log::info('[AI Autofill] 填充朝代欄位', [
                'person_dynasty' => $personDynasty,
                'effective_dynasty' => $effectiveDynasty,
                'dynasty_value' => $dynastyValue,
                'dynasty_text' => $dynastyText,
                'dynasties_query_success' => $dynastyInfo !== null,
            ]);
        }

        // 先取出 addr_str 的 admin_type，供官名消歧使用
        // （例如「同知」在府、州、縣語境下應對應不同的官名）
        $addrAdminType = null;
        if (!empty($aiData['addr_str']) && is_array($aiData['addr_str'])) {
            $addrAdminType = $aiData['addr_str']['admin_type'] ?? null;
        }

        // 1. 官名匹配（title_str）
        if (!empty($aiData['title_str'])) {
            $officeMatch = $this->fuzzyMatchOffice($aiData['title_str'], $effectiveDynasty, $addrAdminType);
            if ($officeMatch) {
                // 根據匹配類型決定是確認匹配還是建議
                if ($officeMatch['match_type'] === 'exact') {
                    // 精確匹配 → 綠色（確認）
                    $matched['c_office_id'] = [
                        'value' => $officeMatch['id'],
                        'text' => $officeMatch['text'],
                        'ai_extracted' => $aiData['title_str'],
                    ];
                } else {
                    // 模糊匹配 → 黃色（建議）
                    $suggested['c_office_id'] = [
                        'value' => $officeMatch['id'],
                        'text' => $officeMatch['text'],
                        'ai_extracted' => $aiData['title_str'],
                        'search_query' => $aiData['title_str'],
                    ];
                }
            } else {
                // 完全找不到 → 黃色（建議）
                $suggested['c_office_id'] = [
                    'ai_extracted' => $aiData['title_str'],
                    'search_query' => $aiData['title_str'],
                ];
            }
        } else {
            $empty[] = 'c_office_id';
        }

        // 2. 地名匹配（addr_str）
        if (!empty($aiData['addr_str']) && is_array($aiData['addr_str'])) {
            $addrData = $aiData['addr_str'];
            $addrData = $this->normalizeAddrName($addrData);
            $searchName = $addrData['name'] ?? null;
            $parentName = $addrData['parent'] ?? null;

            if (!empty($searchName)) {
                $addrMatch = $this->fuzzyMatchAddress($searchName, $postingYear, $effectiveDynasty, $parentName);

                if ($addrMatch) {
                    $inputLength = mb_strlen($searchName);
                    $matchedLength = $addrMatch['matched_length'];

                    // 判斷是否需要建議（黃色）：
                    // 1. 模糊匹配（前綴匹配，或有歧義但上層匹配失敗）
                    // 2. 輸入包含上層信息但只匹配到下層（輸入長度 > 匹配長度）
                    // 注意：match_type 已經考慮了上層匹配的情況，不需要再檢查 parent 欄位
                    $needsConfirmation = (
                        $addrMatch['match_type'] === 'fuzzy' ||
                        $inputLength > $matchedLength
                    );

                    if ($needsConfirmation) {
                        // 需要確認 → 黃色（建議）
                        $suggested['c_addr'] = [
                            'value' => [$addrMatch['id']],
                            'text' => [$addrMatch['text']],
                            'ai_extracted' => $addrData['full_text'] ?? $searchName,
                            'ai_structured' => $addrData, // 保留完整的結構化信息
                            'search_query' => $searchName,
                        ];
                    } else {
                        // 精確匹配且長度一致 → 綠色（確認）
                        $matched['c_addr'] = [
                            'value' => [$addrMatch['id']],
                            'text' => [$addrMatch['text']],
                            'ai_extracted' => $addrData['full_text'] ?? $searchName,
                            'ai_structured' => $addrData, // 保留完整的結構化信息
                        ];
                    }
                } else {
                    // 完全找不到 → 黃色（建議）
                    $suggested['c_addr'] = [
                        'ai_extracted' => $addrData['full_text'] ?? $searchName,
                        'ai_structured' => $addrData, // 保留完整的結構化信息
                        'search_query' => $searchName,
                    ];
                }
            } else {
                $empty[] = 'c_addr';
            }
        } else {
            $empty[] = 'c_addr';
        }

        // 3. 直接映射欄位（不需要模糊匹配）
        $directMappings = [
            'c_firstyear' => 'c_firstyear',
            'c_fy_nh_code' => 'c_fy_nh_code',
            'c_fy_nh_year' => 'c_fy_nh_year',
            'c_fy_range' => 'c_fy_range',
            'c_fy_intercalary' => 'c_fy_intercalary',
            'c_fy_month' => 'c_fy_month',
            'c_fy_day' => 'c_fy_day',
            'c_fy_day_gz' => 'c_fy_day_gz',
            'c_lastyear' => 'c_lastyear',
            'c_ly_nh_code' => 'c_ly_nh_code',
            'c_ly_nh_year' => 'c_ly_nh_year',
            'c_ly_range' => 'c_ly_range',
            'c_ly_intercalary' => 'c_ly_intercalary',
            'c_ly_month' => 'c_ly_month',
            'c_ly_day' => 'c_ly_day',
            'c_ly_day_gz' => 'c_ly_day_gz',
            'c_appt_code' => 'c_appt_code',
            'c_assume_office_code' => 'c_assume_office_code',
        ];

        foreach ($directMappings as $fieldName => $aiKey) {
            if (isset($aiData[$aiKey]) && $aiData[$aiKey] !== null) {
                $value = $aiData[$aiKey];

                // 特殊處理：年號欄位可能返回名稱而非 ID，需要轉換
                if (in_array($fieldName, ['c_fy_nh_code', 'c_ly_nh_code']) && !is_numeric($value)) {
                    // AI 返回的是年號名稱（如"雍正"），需要查詢 ID
                    Log::info("[AI Autofill] 查詢年號", [
                        'field' => $fieldName,
                        'name' => $value,
                        'dynasty' => $effectiveDynasty,
                    ]);

                    $nianhaoQuery = DB::table('NIAN_HAO')
                        ->where('c_nianhao_chn', $value);

                    // 按朝代過濾（避免跨朝代的同名年號混淆）
                    if ($effectiveDynasty !== null) {
                        $nianhaoQuery->where('c_dy', $effectiveDynasty);
                    }

                    $nianhaoId = $nianhaoQuery->value('c_nianhao_id');

                    Log::info("[AI Autofill] 年號查詢結果（按朝代過濾）", [
                        'field' => $fieldName,
                        'name' => $value,
                        'dynasty' => $effectiveDynasty,
                        'found_id' => $nianhaoId,
                    ]);

                    // 如果沒找到，嘗試不限制朝代再查一次
                    if (!$nianhaoId) {
                        $nianhaoId = DB::table('NIAN_HAO')
                            ->where('c_nianhao_chn', $value)
                            ->value('c_nianhao_id');

                        Log::info("[AI Autofill] 年號查詢結果（不限朝代）", [
                            'field' => $fieldName,
                            'name' => $value,
                            'found_id' => $nianhaoId,
                        ]);
                    }

                    if ($nianhaoId) {
                        $value = $nianhaoId;
                        Log::info("[AI Autofill] 年號匹配成功", [
                            'field' => $fieldName,
                            'name' => $aiData[$aiKey],
                            'id' => $nianhaoId,
                        ]);
                    } else {
                        // 找不到對應的年號，標記為建議而非確認
                        Log::warning("[AI Autofill] 年號匹配失敗", [
                            'field' => $fieldName,
                            'name' => $value,
                            'dynasty' => $effectiveDynasty,
                        ]);

                        $suggested[$fieldName] = [
                            'ai_extracted' => $value,
                            'search_query' => $value,
                        ];

                        continue;
                    }
                }

                // 年號欄位經過名稱→ID 轉換時，text 應保留原始中文名稱
                $text = $value;
                if (in_array($fieldName, ['c_fy_nh_code', 'c_ly_nh_code']) && $value !== $aiData[$aiKey]) {
                    $text = $aiData[$aiKey];
                }

                $matched[$fieldName] = [
                    'value' => $value,
                    'text' => $text,
                ];
            } else {
                $empty[] = $fieldName;
            }
        }

        return [
            'matched' => $matched,
            'suggested' => $suggested,
            'empty' => $empty,
        ];
    }

    /**
     * 模糊匹配官名
     *
     * @param string $officeName 官名
     * @param int|null $dynastyCode 朝代代碼（用於過濾）
     * @param string|null $adminType 地址的行政層級（府/州/縣），用於消歧泛稱官名
     * @return array|null ['id' => int, 'text' => string, 'match_type' => 'exact'|'fuzzy']
     */
    protected function fuzzyMatchOffice(string $officeName, ?int $dynastyCode = null, ?string $adminType = null): ?array {
        // ========== Step 1: 精確匹配 c_office_chn ==========
        $query = DB::table('OFFICE_CODES')
            ->select('c_office_id as id', 'c_office_chn as text')
            ->where('c_office_chn', '=', $officeName);

        if ($dynastyCode !== null) {
            $query->where('c_dy', '=', $dynastyCode);
        }

        $result = $query->first();
        if ($result) {
            return [
                'id' => $result->id,
                'text' => $result->text,
                'match_type' => 'exact',
            ];
        }

        // ========== Step 1.5: 根據 admin_type 消歧泛稱官名 ==========
        // 僅在朝代專屬精確匹配（Step 1）落空時才觸發。
        // 例如宋代 effectiveDynasty=15 時，OFFICE_CODES 沒有 c_dy=15 且 c_office_chn='同知' 的記錄，
        // 若直接落入 Step 2 的 alt 匹配，會誤命中「同知樞密院事」等泛稱 alt 清單。
        // 此處針對已知的泛稱（如同知）依 admin_type 對應抽象官名（如同知某府軍府事）。
        // 放在 Step 1 之後可保留明清等朝代已有的「同知」專屬記錄，避免覆蓋朝代專屬精確匹配。
        $disambiguated = $this->disambiguateOfficeByAdminType($officeName, $adminType);
        if ($disambiguated !== null) {
            return $disambiguated;
        }

        // ========== Step 2: 精確匹配 c_office_chn_alt（分號分割） ==========
        // 使用 SQL 模式匹配來找到可能包含該官名的記錄
        $query = DB::table('OFFICE_CODES')
            ->select('c_office_id as id', 'c_office_chn as text', 'c_office_chn_alt')
            ->whereNotNull('c_office_chn_alt')
            ->where(function ($q) use ($officeName) {
                $q->where('c_office_chn_alt', '=', $officeName)  // 完全相同
                    ->orWhere('c_office_chn_alt', 'like', $officeName . ';%')  // 開頭
                    ->orWhere('c_office_chn_alt', 'like', '%;' . $officeName)  // 結尾
                    ->orWhere('c_office_chn_alt', 'like', '%;' . $officeName . ';%');  // 中間
            });

        if ($dynastyCode !== null) {
            $query->where('c_dy', '=', $dynastyCode);
        }

        // 在 PHP 中驗證精確匹配（避免部分匹配的誤判）
        $candidates = $query->get();
        foreach ($candidates as $candidate) {
            $alternatives = explode(';', $candidate->c_office_chn_alt);
            foreach ($alternatives as $alt) {
                if (trim($alt) === $officeName) {
                    return [
                        'id' => $candidate->id,
                        'text' => $candidate->text,
                        'match_type' => 'exact',
                    ];
                }
            }
        }

        // ========== Step 3: 前綴匹配 c_office_chn（模糊匹配） ==========
        $query = DB::table('OFFICE_CODES')
            ->select('c_office_id as id', 'c_office_chn as text')
            ->where('c_office_chn', 'like', $officeName . '%');

        if ($dynastyCode !== null) {
            $query->where('c_dy', '=', $dynastyCode);
        }

        $result = $query->first();
        if ($result) {
            return [
                'id' => $result->id,
                'text' => $result->text,
                'match_type' => 'fuzzy',  // 前綴匹配視為模糊匹配
            ];
        }

        // ========== Step 4: 前綴匹配 c_office_chn_alt（模糊匹配） ==========
        $query = DB::table('OFFICE_CODES')
            ->select('c_office_id as id', 'c_office_chn as text', 'c_office_chn_alt')
            ->whereNotNull('c_office_chn_alt');

        if ($dynastyCode !== null) {
            $query->where('c_dy', '=', $dynastyCode);
        }

        $candidates = $query->get();
        foreach ($candidates as $candidate) {
            $alternatives = explode(';', $candidate->c_office_chn_alt);
            foreach ($alternatives as $alt) {
                $trimmedAlt = trim($alt);
                if (!empty($trimmedAlt) && str_starts_with($trimmedAlt, $officeName)) {
                    return [
                        'id' => $candidate->id,
                        'text' => $candidate->text,
                        'match_type' => 'fuzzy',  // 前綴匹配視為模糊匹配
                    ];
                }
            }
        }

        // ========== Step 5: 後綴匹配 c_office_chn（輸入可能包含地名前綴） ==========
        // 例如「寧夏將軍」→ 匹配「將軍」（去除地名前綴後的官名）
        if (mb_strlen($officeName) >= 2) {
            $isSqlite = is_sqlite();

            // LIKE '%' || col（SQLite）vs LIKE CONCAT('%', col)（MySQL）
            $likeExpr = $isSqlite
                ? "? LIKE '%' || c_office_chn"
                : "? LIKE CONCAT('%', c_office_chn)";

            // LENGTH() 在 SQLite 返回字節長度，CHAR_LENGTH() 在 MySQL 返回字符長度
            $lenExpr = $isSqlite ? 'LENGTH(c_office_chn)' : 'CHAR_LENGTH(c_office_chn)';

            // SQLite 的 LENGTH() 對 UTF-8 中文返回字節數（每字 3 bytes），需調整閾值
            $minLen = $isSqlite ? 6 : 2; // 2 個中文字符 = 6 bytes in UTF-8
            $maxLen = $isSqlite ? mb_strlen($officeName) * 3 : mb_strlen($officeName);

            $query = DB::table('OFFICE_CODES')
                ->select(
                    'c_office_id as id',
                    'c_office_chn as text',
                    DB::raw("{$lenExpr} as name_len")
                )
                ->whereRaw($likeExpr, [$officeName])
                ->where(DB::raw($lenExpr), '>=', $minLen)
                ->where(DB::raw($lenExpr), '<', $maxLen);

            if ($dynastyCode !== null) {
                $query->where('c_dy', '=', $dynastyCode);
            }

            // 優先取最長的匹配（最具體的官名）
            $result = $query->orderBy('name_len', 'desc')->first();
            if ($result) {
                return [
                    'id' => $result->id,
                    'text' => $result->text,
                    'match_type' => 'fuzzy',
                ];
            }
        }

        return null;
    }

    /**
     * 根據地址 admin_type 對泛稱官名進行消歧。
     *
     * AI 有時只抽出泛稱（例如「同知」），需要結合地名的行政層級才能對應到正確的抽象官名：
     *   - 府同知 → 同知某府軍府事（c_office_id = 6974）
     *   - 州同知、縣同知 → 同知某州軍州事（c_office_id = 3301）
     *
     * 這類抽象官名在 OFFICE_CODES 中有固定的 c_office_chn，使用 where 精確查詢，
     * 不受朝代過濾影響（抽象官名供各朝代檢索使用）。
     *
     * @return array|null ['id' => int, 'text' => string, 'match_type' => 'exact']
     */
    protected function disambiguateOfficeByAdminType(string $officeName, ?string $adminType): ?array {
        if ($adminType === null || $adminType === '') {
            return null;
        }

        $map = [
            '同知' => [
                '府' => '同知某府軍府事',
                '州' => '同知某州軍州事',
                '縣' => '同知某州軍州事',
            ],
        ];

        if (!isset($map[$officeName][$adminType])) {
            return null;
        }

        $targetName = $map[$officeName][$adminType];
        $row = DB::table('OFFICE_CODES')
            ->select('c_office_id as id', 'c_office_chn as text')
            ->where('c_office_chn', '=', $targetName)
            ->first();

        if (!$row) {
            return null;
        }

        Log::info('[AI Autofill] 官名消歧命中', [
            'office_name' => $officeName,
            'admin_type' => $adminType,
            'resolved_to' => $targetName,
            'office_id' => $row->id,
        ]);

        return [
            'id' => $row->id,
            'text' => $row->text,
            'match_type' => 'exact',
        ];
    }

    /**
     * 正規化 addr_str：當 name 剝離 admin_type 後只剩一個字時，將 admin_type 補回 name。
     *
     * 例如 AI 可能回傳 name=黃, admin_type=縣，應修正為 name=黃縣, admin_type=縣。
     */
    protected function normalizeAddrName(array $addrData): array {
        $name = $addrData['name'] ?? null;
        $adminType = $addrData['admin_type'] ?? null;

        if ($name !== null && $adminType !== null && mb_strlen($name) === 1) {
            $addrData['name'] = $name.$adminType;
        }

        return $addrData;
    }

    /**
     * 模糊匹配地名
     *
     * @param string $addrName 地名
     * @param int|null $year 任官年份（用於過濾地名的有效時間範圍）
     * @param int|null $dynastyCode 朝代代碼
     * @param string|null $parentName 上層地名（用於消除同名地址的歧義）
     * @return array|null ['id' => int, 'text' => string, 'match_type' => 'exact'|'fuzzy', 'matched_length' => int]
     */
    protected function fuzzyMatchAddress(string $addrName, ?int $year = null, ?int $dynastyCode = null, ?string $parentName = null): ?array {
        // ========== 年份 Fallback 邏輯 ==========
        // 優先使用任官年份；無具體年份時，改用朝代範圍交集過濾
        $effectiveYear = $year;
        $dynastyRangeFilter = null; // 朝代範圍（僅在無具體年份時使用）
        if ($effectiveYear === null && $dynastyCode !== null) {
            $dynastyRange = $this->getDynastyYearRange($dynastyCode);
            if ($dynastyRange) {
                // 無具體任官年份時，只要地名有效期與朝代有交集即可
                $dynastyRangeFilter = $dynastyRange;
            }
        }

        // ========== Step 1: 精確匹配 c_name_chn ==========
        // 注意：使用 ADDR_CODES 表（與 Select2 API 一致）
        $query = DB::table('ADDR_CODES')
            ->select('c_addr_id as id', 'c_name_chn as text', 'c_firstyear', 'c_lastyear')
            ->where('c_name_chn', '=', $addrName)
            // AI 填充時過濾交通設施相關地名
            ->where('c_name_chn', 'not like', '%驛')  // 驛：全部過濾
            ->where('c_name_chn', 'not like', '%渡')  // 渡：全部過濾
            ->where('c_name_chn', 'not like', '%鋪')  // 鋪：全部過濾
            // 津：只過濾 3 字以上（保留「延津」、「孟津」等 2 字地名）
            // SQLite 使用 LENGTH，MySQL/MariaDB 使用 CHAR_LENGTH
            ->whereRaw('NOT (c_name_chn LIKE ? AND ' . (is_sqlite() ? 'LENGTH' : 'CHAR_LENGTH') . '(c_name_chn) > 2)', ['%津']);

        // 加入時間過濾
        $this->applyAddrTimeFilter($query, $effectiveYear, $dynastyRangeFilter);

        $results = $query->get();
        if ($results->count() > 0) {
            // 檢查是否有歧義（多個同名地址）
            $isAmbiguous = $results->count() > 1;
            $parentMatched = false;  // 標記上層是否匹配成功

            // 如果有歧義且提供了上層地名，嘗試使用上層地名消除歧義
            if ($isAmbiguous && !empty($parentName)) {
                // ✅ 優化：預加載所有候選地址的上層信息（避免 N+1 查詢）
                $candidateIds = $results->pluck('id')->toArray();

                // 一次性 JOIN 查詢所有上層信息
                $parentMap = DB::table('ADDR_BELONGS_DATA as ab')
                    ->join('ADDR_CODES as parent', 'ab.c_belongs_to', '=', 'parent.c_addr_id')
                    ->whereIn('ab.c_addr_id', $candidateIds)
                    ->select(
                        'ab.c_addr_id as child_id',
                        'parent.c_addr_id as parent_id',
                        'parent.c_name_chn as parent_name'
                    )
                    ->get()
                    ->groupBy('child_id'); // 按子地址 ID 分組

                // 使用預加載的數據進行過濾（無額外查詢）
                $filteredResults = $results->filter(function ($addr) use ($parentName, $parentMap) {
                    // 從預加載的 map 中獲取上層信息（內存操作）
                    $parents = $parentMap->get($addr->id);

                    if (!$parents) {
                        return false;
                    }

                    foreach ($parents as $parent) {
                        // 上層地名使用雙向模糊匹配（A in B 或 B in A）
                        // 例如：AI 提取 "杭州" ⇔ 數據庫 "杭州府"
                        if (str_contains($parent->parent_name, $parentName) ||
                            str_contains($parentName, $parent->parent_name)) {
                            return true;
                        }
                    }

                    return false;
                });

                // 如果過濾後有結果，使用過濾後的結果
                if ($filteredResults->count() > 0) {
                    $results = $filteredResults;
                    $isAmbiguous = $results->count() > 1;
                    $parentMatched = true;  // 標記上層匹配成功
                }
            }

            // 朝代消歧：利用 ADDRESSES 表的層級鏈（最頂層為朝代）進行精確朝代匹配
            // 若朝代匹配無結果，則 fallback 到時間段重疊排序
            if ($isAmbiguous && $dynastyCode !== null) {
                $dynastyFiltered = $this->filterByDynastyHierarchy(
                    $results->pluck('id')->toArray(),
                    $dynastyCode,
                    $effectiveYear,
                    $dynastyRangeFilter
                );

                if ($dynastyFiltered !== null && $dynastyFiltered->count() > 0) {
                    $results = $results->whereIn('id', $dynastyFiltered->toArray());
                    $isAmbiguous = $results->count() > 1;
                    if (!$isAmbiguous) {
                        $parentMatched = true; // 朝代匹配成功視同消歧成功
                    }
                }
            }

            // Fallback：仍有歧義時，優先選有效期與朝代重疊最多的
            if ($isAmbiguous && $dynastyRangeFilter !== null) {
                $dyStart = $dynastyRangeFilter['start'];
                $dyEnd = $dynastyRangeFilter['end'];
                $results = $results->sortByDesc(function ($addr) use ($dyStart, $dyEnd) {
                    // 計算地名有效期與朝代的重疊天數（越大越優先）
                    $addrStart = $addr->c_firstyear ?? $dyStart;
                    $addrEnd = $addr->c_lastyear ?? $dyEnd;
                    $overlapStart = max($addrStart, $dyStart);
                    $overlapEnd = min($addrEnd, $dyEnd);

                    return max(0, $overlapEnd - $overlapStart);
                });
            }

            $result = $results->first();

            // 判斷 match_type：
            // 1. 無歧義 → exact（綠色）
            // 2. 有歧義但上層匹配成功 → exact（綠色）
            // 3. 有歧義且上層匹配失敗 → fuzzy（黃色）
            $matchType = ($isAmbiguous && !$parentMatched) ? 'fuzzy' : 'exact';

            return [
                'id' => $result->id,
                'text' => $result->text,
                'match_type' => $matchType,
                'matched_length' => mb_strlen($result->text),
            ];
        }

        // ========== Step 2: 前綴匹配 c_name_chn（模糊匹配） ==========
        $query = DB::table('ADDR_CODES')
            ->select('c_addr_id as id', 'c_name_chn as text')
            ->where('c_name_chn', 'like', $addrName . '%')
            // AI 填充時過濾交通設施相關地名
            ->where('c_name_chn', 'not like', '%驛')  // 驛：全部過濾
            ->where('c_name_chn', 'not like', '%渡')  // 渡：全部過濾
            ->where('c_name_chn', 'not like', '%鋪')  // 鋪：全部過濾
            // 津：只過濾 3 字以上（保留「延津」、「孟津」等 2 字地名）
            // SQLite 使用 LENGTH，MySQL/MariaDB 使用 CHAR_LENGTH
            ->whereRaw('NOT (c_name_chn LIKE ? AND ' . (is_sqlite() ? 'LENGTH' : 'CHAR_LENGTH') . '(c_name_chn) > 2)', ['%津']);

        // 加入時間過濾
        $this->applyAddrTimeFilter($query, $effectiveYear, $dynastyRangeFilter);

        $result = $query->first();

        return $result ? [
            'id' => $result->id,
            'text' => $result->text,
            'match_type' => 'fuzzy',  // 前綴匹配視為模糊匹配
            'matched_length' => mb_strlen($result->text),
        ] : null;
    }

    /**
     * 利用 ADDRESSES 表的行政層級鏈進行朝代消歧
     *
     * ADDRESSES 表的 belongsX_Name_chn 記錄了完整的行政層級，最頂層為朝代（如「宋朝」「遼朝」）。
     * 透過匹配層級鏈中的朝代名稱，可精確區分宋/遼/金等並存朝代下的同名地名。
     *
     * @param array $candidateIds 候選地址 ID 列表
     * @param int $dynastyCode 朝代代碼
     * @param int|null $effectiveYear 任官年份（用於過濾 ADDRESSES 的時間段）
     * @param array|null $dynastyRangeFilter 朝代年份範圍（無具體年份時使用）
     * @return \Illuminate\Support\Collection|null 匹配的地址 ID 集合，無法匹配時返回 null
     */
    protected function filterByDynastyHierarchy(array $candidateIds, int $dynastyCode, ?int $effectiveYear, ?array $dynastyRangeFilter): ?\Illuminate\Support\Collection {
        if (empty($candidateIds)) {
            return null;
        }

        // 取得朝代中文名（如「宋」「遼」「金」）
        $dynastyChn = DB::table('DYNASTIES')
            ->where('c_dy', $dynastyCode)
            ->value('c_dynasty_chn');

        if (empty($dynastyChn)) {
            return null;
        }

        // 從 ADDRESSES 表查詢候選地址的層級記錄
        $query = DB::table('ADDRESSES')
            ->whereIn('c_addr_id', $candidateIds)
            ->select(
                'c_addr_id',
                'c_belongs_firstyear',
                'c_belongs_lastyear',
                'belongs1_Name_chn',
                'belongs2_Name_chn',
                'belongs3_Name_chn',
                'belongs4_Name_chn',
                'belongs5_Name_chn'
            );

        // 加入時間過濾：只匹配任官時間內有效的層級記錄
        // 處理 NULL 值：視為開放式邊界（與 applyAddrTimeFilter 一致）
        if ($effectiveYear !== null) {
            $query->where(function ($q) use ($effectiveYear) {
                $q->where(function ($sub) use ($effectiveYear) {
                    $sub->where('c_belongs_firstyear', '<=', $effectiveYear)
                        ->where('c_belongs_lastyear', '>=', $effectiveYear);
                })->orWhere(function ($sub) {
                    $sub->whereNull('c_belongs_firstyear')
                        ->whereNull('c_belongs_lastyear');
                })->orWhere(function ($sub) use ($effectiveYear) {
                    $sub->where('c_belongs_firstyear', '<=', $effectiveYear)
                        ->whereNull('c_belongs_lastyear');
                })->orWhere(function ($sub) use ($effectiveYear) {
                    $sub->whereNull('c_belongs_firstyear')
                        ->where('c_belongs_lastyear', '>=', $effectiveYear);
                });
            });
        } elseif ($dynastyRangeFilter !== null) {
            $dyStart = $dynastyRangeFilter['start'];
            $dyEnd = $dynastyRangeFilter['end'];
            $query->where(function ($q) use ($dyStart, $dyEnd) {
                $q->where(function ($sub) use ($dyStart, $dyEnd) {
                    $sub->where('c_belongs_firstyear', '<=', $dyEnd)
                        ->where('c_belongs_lastyear', '>=', $dyStart);
                })->orWhere(function ($sub) {
                    $sub->whereNull('c_belongs_firstyear')
                        ->whereNull('c_belongs_lastyear');
                })->orWhere(function ($sub) use ($dyEnd) {
                    $sub->where('c_belongs_firstyear', '<=', $dyEnd)
                        ->whereNull('c_belongs_lastyear');
                })->orWhere(function ($sub) use ($dyStart) {
                    $sub->whereNull('c_belongs_firstyear')
                        ->where('c_belongs_lastyear', '>=', $dyStart);
                });
            });
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            Log::info('[AI Autofill] ADDRESSES 表無匹配記錄，跳過朝代消歧', [
                'candidate_ids' => $candidateIds,
                'dynasty_code' => $dynastyCode,
            ]);

            return null;
        }

        // 檢查每個候選地址層級鏈的最頂層（最後一個有值的 belongsX）是否為目標朝代
        // ADDRESSES 中朝代格式為「宋朝」「遼朝」，DYNASTIES 為「宋」「遼」，使用 contains 匹配
        $matchedIds = $rows->filter(function ($row) use ($dynastyChn) {
            // 取最後一個有值的 belongsX_Name_chn（即最頂層，通常是朝代）
            $levels = [
                $row->belongs5_Name_chn,
                $row->belongs4_Name_chn,
                $row->belongs3_Name_chn,
                $row->belongs2_Name_chn,
                $row->belongs1_Name_chn,
            ];

            $topLevel = null;
            foreach ($levels as $levelName) {
                if ($levelName !== null) {
                    $topLevel = $levelName;

                    break;
                }
            }

            return $topLevel !== null && str_contains($topLevel, $dynastyChn);
        })->pluck('c_addr_id')->unique();

        Log::info('[AI Autofill] 朝代層級消歧結果', [
            'candidate_ids' => $candidateIds,
            'dynasty_chn' => $dynastyChn,
            'matched_addr_ids' => $matchedIds->values()->toArray(),
            'total_addresses_rows' => $rows->count(),
        ]);

        return $matchedIds->isNotEmpty() ? $matchedIds : null;
    }

    /**
     * 為地址查詢加入時間過濾條件
     *
     * 兩種模式：
     * 1. 精確年份：地名有效期包含該年份
     * 2. 朝代範圍交集：地名有效期與朝代有任何重疊即可
     */
    protected function applyAddrTimeFilter($query, ?int $effectiveYear, ?array $dynastyRangeFilter): void {
        if ($effectiveYear !== null) {
            // 模式 1：有具體年份，地名有效期須包含該年份
            $query->where(function ($q) use ($effectiveYear) {
                $q->where(function ($subQ) use ($effectiveYear) {
                    $subQ->where('c_firstyear', '<=', $effectiveYear)
                        ->where('c_lastyear', '>=', $effectiveYear);
                })->orWhere(function ($subQ) {
                    $subQ->whereNull('c_firstyear')
                        ->whereNull('c_lastyear');
                })->orWhere(function ($subQ) use ($effectiveYear) {
                    $subQ->where('c_firstyear', '<=', $effectiveYear)
                        ->whereNull('c_lastyear');
                })->orWhere(function ($subQ) use ($effectiveYear) {
                    $subQ->whereNull('c_firstyear')
                        ->where('c_lastyear', '>=', $effectiveYear);
                });
            });
        } elseif ($dynastyRangeFilter !== null) {
            // 模式 2：無具體年份，只要地名有效期與朝代範圍有交集即可
            // 交集條件：addr.firstyear <= dynasty.end AND addr.lastyear >= dynasty.start
            $dyStart = $dynastyRangeFilter['start'];
            $dyEnd = $dynastyRangeFilter['end'];
            $query->where(function ($q) use ($dyStart, $dyEnd) {
                $q->where(function ($subQ) use ($dyStart, $dyEnd) {
                    // 兩邊都有值，且與朝代範圍有交集
                    $subQ->where('c_firstyear', '<=', $dyEnd)
                        ->where('c_lastyear', '>=', $dyStart);
                })->orWhere(function ($subQ) {
                    // 時間範圍為 NULL（不限時間）
                    $subQ->whereNull('c_firstyear')
                        ->whereNull('c_lastyear');
                })->orWhere(function ($subQ) use ($dyEnd) {
                    // 開放式結束：開始年份在朝代結束之前
                    $subQ->where('c_firstyear', '<=', $dyEnd)
                        ->whereNull('c_lastyear');
                })->orWhere(function ($subQ) use ($dyStart) {
                    // 開放式開始：結束年份在朝代開始之後
                    $subQ->whereNull('c_firstyear')
                        ->where('c_lastyear', '>=', $dyStart);
                });
            });
        }
    }

    /**
     * 獲取朝代的年份範圍（從 DYNASTIES 表讀取，帶緩存）
     *
     * @param int $dynastyCode 朝代代碼
     * @return array|null ['start' => int, 'end' => int] 或 null（如果朝代未知）
     */
    protected function getDynastyYearRange(int $dynastyCode): ?array {
        static $dynastyRangesCache = null;

        // 首次調用時從數據庫讀取所有朝代範圍
        if ($dynastyRangesCache === null) {
            $dynastyRangesCache = [];
            $dynasties = DB::table('DYNASTIES')
                ->select('c_dy', 'c_start', 'c_end')
                ->whereNotNull('c_start')
                ->whereNotNull('c_end')
                ->get();

            foreach ($dynasties as $dynasty) {
                $dynastyRangesCache[$dynasty->c_dy] = [
                    'start' => $dynasty->c_start,
                    'end' => $dynasty->c_end,
                ];
            }
        }

        return $dynastyRangesCache[$dynastyCode] ?? null;
    }

    /**
     * 根據年份獲取朝代代碼
     *
     * @param int $year 年份
     * @return int|null 朝代代碼，如果年份不在任何已知朝代範圍內則返回 null
     *                  如果年份匹配多個朝代：排除可排除朝代後若剩唯一朝代則使用，否則返回 null
     *                  如果年份只匹配一個朝代（無論是否為可排除朝代）：使用該朝代
     */
    protected function getDynastyByYear(int $year): ?int {
        static $allDynastyRanges = null;

        // 歧義時排除的朝代：當有多個朝代匹配時，優先排除這些朝代
        // 80=南明, 84=朝鮮, 85=大順, 86=大西
        // 但如果這些朝代是唯一匹配項（無其他朝代），仍然使用它
        $ambiguityExcludedDynasties = [80, 84, 85, 86];

        // 首次調用時從數據庫讀取所有朝代範圍
        if ($allDynastyRanges === null) {
            $allDynastyRanges = [];
            $dynasties = DB::table('DYNASTIES')
                ->select('c_dy', 'c_start', 'c_end', 'c_dynasty_chn')
                ->whereNotNull('c_start')
                ->whereNotNull('c_end')
                ->orderBy('c_start')
                ->get();

            foreach ($dynasties as $dynasty) {
                $allDynastyRanges[$dynasty->c_dy] = [
                    'start' => $dynasty->c_start,
                    'end' => $dynasty->c_end,
                    'name' => $dynasty->c_dynasty_chn,
                ];
            }
        }

        $matchedDynasties = [];
        foreach ($allDynastyRanges as $dynastyCode => $range) {
            if ($year >= $range['start'] && $year <= $range['end']) {
                $matchedDynasties[] = [
                    'code' => $dynastyCode,
                    'name' => $range['name'],
                ];
            }
        }

        // 如果只匹配到一個朝代，直接返回（無論是否為可排除朝代）
        if (count($matchedDynasties) === 1) {
            return $matchedDynasties[0]['code'];
        }

        // 如果匹配到多個朝代，嘗試排除可排除朝代以消除歧義
        if (count($matchedDynasties) > 1) {
            // 過濾掉可排除朝代
            $primaryDynasties = array_filter(
                $matchedDynasties,
                fn ($d) => !in_array($d['code'], $ambiguityExcludedDynasties)
            );

            // 如果排除後剩下唯一朝代，使用它
            if (count($primaryDynasties) === 1) {
                $selected = array_values($primaryDynasties)[0];
                $excludedNames = array_map(
                    fn ($d) => "{$d['name']}({$d['code']})",
                    array_filter($matchedDynasties, fn ($d) => in_array($d['code'], $ambiguityExcludedDynasties))
                );

                Log::info('[AI Autofill] 年份屬於多個朝代，排除可排除朝代後選擇唯一朝代', [
                    'year' => $year,
                    'all_matched' => array_map(fn ($d) => "{$d['name']}({$d['code']})", $matchedDynasties),
                    'excluded' => $excludedNames,
                    'selected_dynasty' => "{$selected['name']}({$selected['code']})",
                ]);

                return $selected['code'];
            }

            // 如果排除後仍有多個朝代，或全是可排除朝代，fallback 到人物朝代
            Log::info('[AI Autofill] 年份屬於多個朝代（排除後仍無法確定唯一朝代），將使用人物朝代', [
                'year' => $year,
                'matched_dynasties' => array_map(fn ($d) => "{$d['name']}({$d['code']})", $matchedDynasties),
                'primary_dynasties_count' => count($primaryDynasties),
            ]);

            return null;
        }

        // 沒有匹配到任何朝代
        return null;
    }

    /**
     * 獲取 JSON Schema（用於 Structured Output）
     */
    protected function getJsonSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'postings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title_str' => ['type' => 'string'],
                            'addr_str' => [
                                'type' => ['object', 'null'],
                                'properties' => [
                                    'full_text' => ['type' => 'string'],
                                    'parent' => ['type' => ['string', 'null']],
                                    'name' => ['type' => 'string'],
                                    'admin_type' => ['type' => ['string', 'null']],
                                ],
                                'required' => ['full_text', 'parent', 'name', 'admin_type'],
                                'additionalProperties' => false,
                            ],
                            'c_firstyear' => ['type' => ['integer', 'null']],
                            'c_fy_nh_code' => ['type' => ['string', 'null']],
                            'c_fy_nh_year' => ['type' => ['integer', 'null']],
                            'c_fy_range' => ['type' => ['integer', 'null']],
                            'c_fy_intercalary' => ['type' => ['boolean', 'null']],
                            'c_fy_month' => ['type' => ['integer', 'null']],
                            'c_fy_day' => ['type' => ['integer', 'null']],
                            'c_fy_day_gz' => ['type' => ['string', 'null']],
                            'c_lastyear' => ['type' => ['integer', 'null']],
                            'c_ly_nh_code' => ['type' => ['string', 'null']],
                            'c_ly_nh_year' => ['type' => ['integer', 'null']],
                            'c_ly_range' => ['type' => ['integer', 'null']],
                            'c_ly_intercalary' => ['type' => ['boolean', 'null']],
                            'c_ly_month' => ['type' => ['integer', 'null']],
                            'c_ly_day' => ['type' => ['integer', 'null']],
                            'c_ly_day_gz' => ['type' => ['string', 'null']],
                            'c_appt_code' => ['type' => ['integer', 'null']],
                            'c_assume_office_code' => ['type' => ['integer', 'null']],
                        ],
                        'required' => [
                            'title_str',
                            'addr_str',
                            'c_firstyear',
                            'c_fy_nh_code',
                            'c_fy_nh_year',
                            'c_fy_range',
                            'c_fy_intercalary',
                            'c_fy_month',
                            'c_fy_day',
                            'c_fy_day_gz',
                            'c_lastyear',
                            'c_ly_nh_code',
                            'c_ly_nh_year',
                            'c_ly_range',
                            'c_ly_intercalary',
                            'c_ly_month',
                            'c_ly_day',
                            'c_ly_day_gz',
                            'c_appt_code',
                            'c_assume_office_code',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['postings'],
            'additionalProperties' => false,
        ];
    }
}
