<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostingAutofillService {
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
     * 調用 AI API 提取結構化數據
     */
    protected function callAI(string $sourceText): array {
        // 讀取 prompt 模板
        if (!file_exists($this->promptTemplate)) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Prompt 模板檔案不存在',
            ];
        }

        $promptContent = file_get_contents($this->promptTemplate);
        $fullPrompt = $promptContent . "\n\n" . $sourceText;

        // 調用 LLM API
        $response = Http::timeout(30)
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

        // 1. 官名匹配（posting_str）
        if (!empty($aiData['posting_str'])) {
            $officeMatch = $this->fuzzyMatchOffice($aiData['posting_str'], $effectiveDynasty);
            if ($officeMatch) {
                // 根據匹配類型決定是確認匹配還是建議
                if ($officeMatch['match_type'] === 'exact') {
                    // 精確匹配 → 綠色（確認）
                    $matched['c_office_id'] = [
                        'value' => $officeMatch['id'],
                        'text' => $officeMatch['text'],
                        'ai_extracted' => $aiData['posting_str'],
                    ];
                } else {
                    // 模糊匹配 → 黃色（建議）
                    $suggested['c_office_id'] = [
                        'value' => $officeMatch['id'],
                        'text' => $officeMatch['text'],
                        'ai_extracted' => $aiData['posting_str'],
                        'search_query' => $aiData['posting_str'],
                    ];
                }
            } else {
                // 完全找不到 → 黃色（建議）
                $suggested['c_office_id'] = [
                    'ai_extracted' => $aiData['posting_str'],
                    'search_query' => $aiData['posting_str'],
                ];
            }
        } else {
            $empty[] = 'c_office_id';
        }

        // 2. 地名匹配（addr_str）
        if (!empty($aiData['addr_str']) && is_array($aiData['addr_str'])) {
            $addrData = $aiData['addr_str'];
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
     * @return array|null ['id' => int, 'text' => string, 'match_type' => 'exact'|'fuzzy']
     */
    protected function fuzzyMatchOffice(string $officeName, ?int $dynastyCode = null): ?array {
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
        // 優先使用任官年份，如果為 null 則 fallback 到朝代年份範圍
        $effectiveYear = $year;
        if ($effectiveYear === null && $dynastyCode !== null) {
            $dynastyRange = $this->getDynastyYearRange($dynastyCode);
            if ($dynastyRange) {
                // 使用朝代的中點年份作為過濾條件
                $effectiveYear = (int)(($dynastyRange['start'] + $dynastyRange['end']) / 2);
            }
        }

        // ========== Step 1: 精確匹配 c_name_chn ==========
        // 注意：使用 ADDR_CODES 表（與 Select2 API 一致）
        $query = DB::table('ADDR_CODES')
            ->select('c_addr_id as id', 'c_name_chn as text')
            ->where('c_name_chn', '=', $addrName)
            // AI 填充時過濾交通設施相關地名
            ->where('c_name_chn', 'not like', '%驛')  // 驛：全部過濾
            ->where('c_name_chn', 'not like', '%渡')  // 渡：全部過濾
            ->where('c_name_chn', 'not like', '%鋪')  // 鋪：全部過濾
            // 津：只過濾 3 字以上（保留「延津」、「孟津」等 2 字地名）
            // SQLite 使用 LENGTH，MySQL/MariaDB 使用 CHAR_LENGTH
            ->whereRaw('NOT (c_name_chn LIKE ? AND ' . (is_sqlite() ? 'LENGTH' : 'CHAR_LENGTH') . '(c_name_chn) > 2)', ['%津']);

        // 加入時間範圍過濾（如果有提供年份）
        if ($effectiveYear !== null) {
            $query->where(function ($q) use ($effectiveYear) {
                $q->where(function ($subQ) use ($effectiveYear) {
                    // 地名有效期間包含任官年份（兩邊都有值）
                    $subQ->where('c_firstyear', '<=', $effectiveYear)
                        ->where('c_lastyear', '>=', $effectiveYear);
                })->orWhere(function ($subQ) {
                    // 時間範圍為 NULL（代表不限時間）
                    $subQ->whereNull('c_firstyear')
                        ->whereNull('c_lastyear');
                })->orWhere(function ($subQ) use ($effectiveYear) {
                    // 開放式結束（只有開始年份，沒有結束年份）
                    $subQ->where('c_firstyear', '<=', $effectiveYear)
                        ->whereNull('c_lastyear');
                })->orWhere(function ($subQ) use ($effectiveYear) {
                    // 開放式開始（只有結束年份，沒有開始年份）
                    $subQ->whereNull('c_firstyear')
                        ->where('c_lastyear', '>=', $effectiveYear);
                });
            });
        }

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

        if ($effectiveYear !== null) {
            $query->where(function ($q) use ($effectiveYear) {
                $q->where(function ($subQ) use ($effectiveYear) {
                    // 兩邊都有值且包含任官年份
                    $subQ->where('c_firstyear', '<=', $effectiveYear)
                        ->where('c_lastyear', '>=', $effectiveYear);
                })->orWhere(function ($subQ) {
                    // 兩邊都為 NULL
                    $subQ->whereNull('c_firstyear')
                        ->whereNull('c_lastyear');
                })->orWhere(function ($subQ) use ($effectiveYear) {
                    // 開放式結束（只有開始年份）
                    $subQ->where('c_firstyear', '<=', $effectiveYear)
                        ->whereNull('c_lastyear');
                })->orWhere(function ($subQ) use ($effectiveYear) {
                    // 開放式開始（只有結束年份）
                    $subQ->whereNull('c_firstyear')
                        ->where('c_lastyear', '>=', $effectiveYear);
                });
            });
        }

        $result = $query->first();

        return $result ? [
            'id' => $result->id,
            'text' => $result->text,
            'match_type' => 'fuzzy',  // 前綴匹配視為模糊匹配
            'matched_length' => mb_strlen($result->text),
        ] : null;
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
                            'posting_str' => ['type' => 'string'],
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
                            'posting_str',
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
