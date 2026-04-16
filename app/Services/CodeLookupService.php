<?php

namespace App\Services;

use App\Support\LlmFallbackTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CodeLookupService {
    use LlmFallbackTrait;

    protected string $apiKey;
    protected string $apiEndpoint;
    protected string $model;

    public function __construct() {
        $this->apiKey = config('services.gemini.api_key', '');
        $this->apiEndpoint = config('services.gemini.api_endpoint');
        $this->model = config('services.gemini.model', 'gemini-2.0-flash');
        $this->initLlmFallback();
    }

    /**
     * 語義查詢代碼表，回傳結構化候選結果
     *
     * @return array{success: bool, data?: array, error?: string}
     */
    public function lookup(string $query, string $table): array {
        $codes = $this->loadCodes($table);

        if (empty($codes)) {
            return [
                'success' => false,
                'error' => '代碼表中沒有任何記錄。',
            ];
        }

        $systemPrompt = $this->buildSystemPrompt($table, $codes);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $query],
        ];

        $result = $this->callLLM($messages);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => '呼叫 AI 服務失敗：' . $result['error'],
            ];
        }

        $content = $result['data']['choices'][0]['message']['content'] ?? null;

        if ($content === null) {
            return [
                'success' => false,
                'error' => 'AI 未回傳有效結果。',
            ];
        }

        $parsed = json_decode($content, true);

        if (!is_array($parsed)) {
            return [
                'success' => false,
                'error' => 'AI 回傳格式不正確。',
            ];
        }

        // 過濾 AI 幻覺：移除不存在於真實代碼表中的 code_id
        if (!empty($parsed['matched_codes'])) {
            $beforeCount = count($parsed['matched_codes']);
            $parsed['matched_codes'] = $this->filterValidCodes($parsed['matched_codes'], $table);
            $filtered = $beforeCount - count($parsed['matched_codes']);
            if ($filtered > 0) {
                $parsed['filtered_hallucinations'] = $filtered;
                Log::info('CodeLookup 過濾了 ' . $filtered . ' 個不存在的 code_id');
            }
        }

        // 為 assoc 類型補充成對關係資訊
        if ($table === 'ASSOC_CODES' && !empty($parsed['matched_codes'])) {
            $parsed['matched_codes'] = $this->enrichWithPairInfo($parsed['matched_codes']);
        }

        return [
            'success' => true,
            'data' => $parsed,
        ];
    }

    protected function loadCodes(string $table): array {
        if ($table === 'ASSOC_CODES') {
            return DB::table('ASSOC_CODES')
                ->select(['c_assoc_code', 'c_assoc_desc', 'c_assoc_desc_chn'])
                ->orderBy('c_assoc_code')
                ->get()
                ->map(fn ($row) => [
                    'id' => $row->c_assoc_code,
                    'desc_en' => $row->c_assoc_desc,
                    'desc_chn' => $row->c_assoc_desc_chn,
                ])
                ->toArray();
        }

        return DB::table('STATUS_CODES')
            ->select(['c_status_code', 'c_status_desc', 'c_status_desc_chn'])
            ->orderBy('c_status_code')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->c_status_code,
                'desc_en' => $row->c_status_desc,
                'desc_chn' => $row->c_status_desc_chn,
            ])
            ->toArray();
    }

    /**
     * 過濾掉 AI 幻覺的 code_id（不存在於真實代碼表中的）
     */
    protected function filterValidCodes(array $matchedCodes, string $table): array {
        $codeIds = array_column($matchedCodes, 'code_id');

        if (empty($codeIds)) {
            return $matchedCodes;
        }

        $pkCol = $table === 'ASSOC_CODES' ? 'c_assoc_code' : 'c_status_code';

        $validIds = DB::table($table)
            ->whereIn($pkCol, $codeIds)
            ->pluck($pkCol)
            ->map(fn ($v) => (int) $v)
            ->toArray();

        return array_values(array_filter($matchedCodes, fn ($code) => in_array((int) $code['code_id'], $validIds, true)));
    }

    /**
     * 為匹配的 ASSOC_CODES 補充成對關係資訊
     */
    protected function enrichWithPairInfo(array $matchedCodes): array {
        $codeIds = array_column($matchedCodes, 'code_id');

        if (empty($codeIds)) {
            return $matchedCodes;
        }

        $pairData = DB::table('ASSOC_CODES')
            ->whereIn('c_assoc_code', $codeIds)
            ->select(['c_assoc_code', 'c_assoc_pair', 'c_assoc_pair2'])
            ->get()
            ->keyBy('c_assoc_code');

        foreach ($matchedCodes as &$code) {
            $id = $code['code_id'];
            $row = $pairData[$id] ?? null;

            if (!$row) {
                continue;
            }

            $pairIds = array_filter([
                $row->c_assoc_pair ?? null,
                $row->c_assoc_pair2 ?? null,
            ], fn ($v) => $v !== null && $v != 0);

            if (!empty($pairIds)) {
                $pairs = DB::table('ASSOC_CODES')
                    ->whereIn('c_assoc_code', $pairIds)
                    ->select(['c_assoc_code', 'c_assoc_desc', 'c_assoc_desc_chn'])
                    ->get()
                    ->map(fn ($p) => [
                        'code_id' => $p->c_assoc_code,
                        'desc_en' => $p->c_assoc_desc,
                        'desc_chn' => $p->c_assoc_desc_chn,
                    ])
                    ->toArray();

                $code['paired_codes'] = $pairs;
            }
        }

        return $matchedCodes;
    }

    protected function buildSystemPrompt(string $table, array $codes): string {
        $tableName = $table === 'ASSOC_CODES' ? '社會關係代碼表（ASSOC_CODES）' : '社會區分類別代碼表（STATUS_CODES）';
        $codeList = $this->formatCodeList($codes);

        return <<<PROMPT
你是 CBDB（中國歷代人物傳記資料庫）的代碼查詢助手。

你的任務：根據使用者輸入的短句或描述，從{$tableName}中找出語義上相關的代碼。

## 重要規則
1. **語義匹配**：不只是字面匹配，要理解語義。例如使用者說「土匪」，你需要考慮「rebel（叛臣）」等語義相近的代碼。
2. **全面掃描**：掃過整張表的所有代碼，不要遺漏。
3. **候選排序**：按相關度由高到低排列。
4. **使用繁體中文**回覆（summary 和 reason 欄位）。
5. matched_codes 最多回傳 10 個最相關的候選。

## 回應格式
必須回傳以下 JSON 格式：
{
  "matched_codes": [
    {
      "code_id": 數字,
      "desc_en": "英文描述",
      "desc_chn": "中文描述",
      "relevance": "高/中/低",
      "reason": "為何匹配的簡短說明"
    }
  ],
  "not_found": ["使用者提到但表中無對應的概念1", "概念2"],
  "summary": "總結性說明，幫助使用者理解匹配情況與代碼表的涵蓋範圍"
}

若沒有找到任何匹配，matched_codes 為空陣列。

## 代碼表完整內容
{$codeList}
PROMPT;
    }

    protected function formatCodeList(array $codes): string {
        $lines = [];
        foreach ($codes as $code) {
            $id = $code['id'];
            $en = $code['desc_en'] ?? '';
            $chn = $code['desc_chn'] ?? '';
            $lines[] = "{$id} — {$en}（{$chn}）";
        }

        return implode("\n", $lines);
    }

    protected function callLLM(array $messages): array {
        $result = $this->doCallLLM($messages);

        if (!$result['success'] && $this->hasFallback()) {
            Log::warning('CodeLookup 主要 LLM 失敗，嘗試 fallback', ['primary_error' => $result['error']]);
            $original = $this->switchToFallback();

            try {
                $result = $this->doCallLLM($messages);
            } finally {
                $this->restoreFromFallback($original);
            }
        }

        return $result;
    }

    protected function doCallLLM(array $messages): array {
        try {
            $maxCompletionTokens = (int) config('services.gemini.max_completion_tokens', 8192);
            if ($maxCompletionTokens < 256) {
                $maxCompletionTokens = 256;
            }

            $requestData = [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.2,
                'top_p' => 0.95,
                'max_completion_tokens' => $maxCompletionTokens,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'code_lookup_response',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'matched_codes' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'code_id' => ['type' => 'integer'],
                                            'desc_en' => ['type' => 'string'],
                                            'desc_chn' => ['type' => 'string'],
                                            'relevance' => ['type' => 'string'],
                                            'reason' => ['type' => 'string'],
                                        ],
                                        'required' => ['code_id', 'desc_en', 'desc_chn', 'relevance', 'reason'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                                'not_found' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                                'summary' => ['type' => 'string'],
                            ],
                            'required' => ['matched_codes', 'not_found', 'summary'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ];

            $maxAttempts = 3;
            $retryDelayMs = 800;
            $response = null;
            $lastException = null;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $response = Http::connectTimeout(10)
                        ->timeout(60)
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'Authorization' => 'Bearer ' . $this->apiKey,
                        ])
                        ->post($this->apiEndpoint, $requestData);

                    $lastException = null;

                    if ($response->successful()) {
                        break;
                    }

                    if ($attempt < $maxAttempts && $response->status() >= 500) {
                        usleep($retryDelayMs * 1000);

                        continue;
                    }

                    break;
                } catch (\Throwable $exception) {
                    $lastException = $exception;

                    if ($attempt < $maxAttempts) {
                        usleep($retryDelayMs * 1000);

                        continue;
                    }

                    throw $exception;
                }
            }

            if ($lastException !== null && $response === null) {
                throw $lastException;
            }

            if (!$response->successful()) {
                $body = $response->json();
                $errorMessage = $body['error']['message'] ?? $response->body();
                Log::error('CodeLookup LLM API 呼叫失敗', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                ]);

                return [
                    'success' => false,
                    'data' => null,
                    'error' => $errorMessage,
                ];
            }

            return [
                'success' => true,
                'data' => $response->json(),
                'error' => null,
            ];
        } catch (\Exception $e) {
            Log::error('CodeLookup 呼叫 LLM 時發生異常', [
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
