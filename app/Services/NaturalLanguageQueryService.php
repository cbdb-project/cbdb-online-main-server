<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NaturalLanguageQueryService {
    protected DatabaseSchemaService $schemaService;
    protected NlQueryToolsService $toolsService;
    protected string $apiKey;
    protected string $apiEndpoint;
    protected string $model;

    public function __construct(DatabaseSchemaService $schemaService, NlQueryToolsService $toolsService) {
        $this->schemaService = $schemaService;
        $this->toolsService = $toolsService;
        $this->apiKey = config('services.gemini.api_key', '');
        $this->apiEndpoint = config('services.gemini.api_endpoint', 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions');
        $this->model = config('services.gemini.model', 'gemini-3-flash-preview');
    }

    /**
     * 根据自然语言问题生成 SQL 查询
     *
     * @param string $question 用户的自然语言问题
     * @param array|null $tableNames 限制使用的表名（可选）
     * @param callable|null $progressCallback 进度回调函数（用于 SSE 流式响应）
     * @param bool|null $useToolsOverride 是否強制啟用工具（為 null 時依配置）
     * @return array ['success' => bool, 'sql' => string|null, 'error' => string|null, 'explanation' => string|null, 'model' => string|null, 'tool_calls' => array|null]
     */
    public function generateSQL(string $question, ?array $tableNames = null, ?callable $progressCallback = null, ?bool $useToolsOverride = null): array {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'sql' => null,
                'error' => 'LLM API Key 未配置。请在 .env 文件中设置 GEMINI_API_KEY。',
                'explanation' => null,
                'model' => null,
                'tool_calls' => null,
            ];
        }

        $startTime = microtime(true);
        $logData = [
            'user_id' => Auth::id(),
            'question' => $question,
            'llm_prompt' => null,
            'llm_response' => null,
            'generated_sql' => null,
            'explanation' => null,
            'success' => false,
            'error_message' => null,
            'execution_time_ms' => null,
        ];

        try {
            $schemaPrompt = $this->schemaService->generateSchemaPrompt($tableNames);
            $toolsEnabled = config('nl_query_tools.enabled', true);
            if ($useToolsOverride !== null) {
                $toolsEnabled = $toolsEnabled && $useToolsOverride;
            }
            $systemPrompt = $this->buildSystemPrompt($schemaPrompt, $toolsEnabled);

            // 记录发送给 LLM 的提示词
            $logData['llm_prompt'] = $systemPrompt . "\n\n用户问题：{$question}";

            $tools = $toolsEnabled ? $this->toolsService->getToolDefinitions() : [];

            // 构建消息历史
            $messages = [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $question,
                ],
            ];

            // 第一次调用 LLM（可能返回工具调用请求）
            $response = $this->callLLM($messages, $tools, $toolsEnabled);

            // 发送进度：第一次 LLM 调用完成
            if ($progressCallback) {
                $progressCallback('llm_call_complete', [
                    'round' => 1,
                    'message' => '第一次 LLM 調用完成',
                ]);
            }

            if (!$response['success']) {
                if ($toolsEnabled) {
                    $fallback = $this->fallbackToNonToolMode($messages, $progressCallback, null, $response['error']);
                    if ($fallback['success']) {
                        $result = $fallback['result'];
                        $result['model'] = $this->model;

                        $logData['llm_response'] = json_encode([
                            'fallback_non_tool_mode' => true,
                            'tool_mode_error' => $response['error'],
                            'fallback_response' => $fallback['raw_response'],
                        ], JSON_UNESCAPED_UNICODE);
                        $logData['generated_sql'] = $result['sql'];
                        $logData['explanation'] = $result['explanation'];
                        $logData['success'] = $result['success'];
                        $logData['error_message'] = $result['error'];
                        $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                        $this->saveLog($logData);

                        return $result;
                    }
                }

                $logData['error_message'] = $response['error'];
                $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                $this->saveLog($logData);

                return [
                    'success' => false,
                    'sql' => null,
                    'error' => $response['error'],
                    'explanation' => null,
                    'model' => $this->model,
                    'tool_calls' => null,
                ];
            }

            $firstResponse = $response['data'];

            // 检测响应格式（新格式 vs OpenAI 格式）
            $isNewFormat = isset($firstResponse['rounds']) || isset($firstResponse['first_call']) || isset($firstResponse['second_call']);

            if ($isNewFormat) {
                // 新格式：可能是 rounds 格式或旧的 first_call/second_call 格式
                if (isset($firstResponse['rounds'])) {
                    // 已经是 rounds 格式，直接记录
                    $logData['llm_response'] = json_encode($firstResponse, JSON_UNESCAPED_UNICODE);

                    // 从最后一轮获取结果
                    $lastRound = end($firstResponse['rounds']);
                    $result = $this->parseResponse($lastRound['llm_response'] ?? $lastRound);

                    // 收集所有工具调用结果
                    $allToolResults = [];
                    foreach ($firstResponse['rounds'] as $round) {
                        if (!empty($round['tool_results'])) {
                            $allToolResults = array_merge($allToolResults, $round['tool_results']);
                        }
                    }
                    $result['tool_calls'] = !empty($allToolResults) ? $allToolResults : null;
                } else {
                    // 旧的 first_call/second_call 格式，转换为 rounds 格式
                    $rounds = [];
                    if (isset($firstResponse['first_call'])) {
                        $rounds[] = [
                            'round' => 1,
                            'llm_response' => $firstResponse['first_call'],
                            'tool_calls_requested' => $firstResponse['tool_calls'] ?? null,
                            'tool_results' => $firstResponse['tool_results'] ?? null,
                        ];
                    }
                    if (isset($firstResponse['second_call'])) {
                        $rounds[] = [
                            'round' => 2,
                            'llm_response' => $firstResponse['second_call'],
                            'tool_calls_requested' => null,
                            'tool_results' => null,
                        ];
                    }

                    $logData['llm_response'] = json_encode([
                        'rounds' => $rounds,
                        'total_rounds' => count($rounds),
                    ], JSON_UNESCAPED_UNICODE);

                    // 解析结果
                    if (isset($firstResponse['tool_results']) && !empty($firstResponse['tool_results'])) {
                        $toolResults = $firstResponse['tool_results'];
                        $secondCall = $firstResponse['second_call'] ?? $firstResponse['first_call'];
                        $result = $this->parseResponse($secondCall);
                        $result['tool_calls'] = $toolResults;
                    } else {
                        $result = $this->parseResponse($firstResponse['first_call'] ?? $firstResponse);
                        $result['tool_calls'] = null;
                    }
                }
            } else {
                // 旧格式（OpenAI 标准格式）
                $maxRounds = max(1, (int) config('nl_query_tools.max_tool_calls', 2));
                $round = 1;
                $roundsLog = [];
                $allToolResults = [];
                $currentResponse = $firstResponse;

                while (true) {
                    $cleanedResponse = $this->cleanLLMResponse($currentResponse);
                    $toolCalls = $currentResponse['choices'][0]['message']['tool_calls'] ?? null;

                    $roundEntry = [
                        'round' => $round,
                        'llm_response' => $cleanedResponse,
                        'tool_calls_requested' => $toolCalls,
                        'tool_results' => null,
                    ];

                    if ($toolCalls && $toolsEnabled) {
                        if ($progressCallback) {
                            $progressCallback('tool_calls_requested', [
                                'round' => $round,
                                'tool_calls' => array_map(function ($tc) {
                                    return [
                                        'name' => $tc['function']['name'] ?? 'unknown',
                                        'arguments' => json_decode($tc['function']['arguments'] ?? '{}', true),
                                    ];
                                }, $toolCalls),
                                'message' => 'LLM 請求調用 ' . count($toolCalls) . ' 個工具',
                            ]);
                        }

                        $toolResults = $this->executeToolCalls($toolCalls, $progressCallback);
                        $roundEntry['tool_results'] = $toolResults;
                        $roundsLog[] = $roundEntry;
                        $allToolResults = array_merge($allToolResults, $toolResults);

                        $messages[] = $currentResponse['choices'][0]['message'];
                        foreach ($toolResults as $toolResult) {
                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $toolResult['tool_call_id'],
                                'content' => json_encode($toolResult['result'], JSON_UNESCAPED_UNICODE),
                            ];
                        }

                        if ($round >= $maxRounds) {
                            $fallback = $this->fallbackToNonToolMode($messages, $progressCallback, $allToolResults, '工具調用次數超過上限');
                            if ($fallback['success']) {
                                $result = $fallback['result'];
                                $result['tool_calls'] = $allToolResults;
                                $result['model'] = $this->model;

                                $logData['llm_response'] = json_encode([
                                    'rounds' => $roundsLog,
                                    'total_rounds' => count($roundsLog),
                                    'fallback_non_tool_mode' => true,
                                    'fallback_response' => $fallback['raw_response'],
                                ], JSON_UNESCAPED_UNICODE);
                                $logData['generated_sql'] = $result['sql'];
                                $logData['explanation'] = $result['explanation'];
                                $logData['success'] = $result['success'];
                                $logData['error_message'] = $result['error'];
                                $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                                $this->saveLog($logData);

                                return $result;
                            }

                            $logData['llm_response'] = json_encode([
                                'rounds' => $roundsLog,
                                'total_rounds' => count($roundsLog),
                                'error' => '工具調用次數超過上限',
                            ], JSON_UNESCAPED_UNICODE);
                            $logData['error_message'] = '工具調用次數超過上限，且降級模式也失敗';
                            $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                            $this->saveLog($logData);

                            return [
                                'success' => false,
                                'sql' => null,
                                'error' => '工具調用次數超過上限，且降級為非工具模式後仍無法生成 SQL。',
                                'explanation' => null,
                                'model' => $this->model,
                                'tool_calls' => $allToolResults,
                            ];
                        }

                        $response = $this->callLLM($messages, $tools, true);
                        if ($progressCallback) {
                            $progressCallback('llm_call_complete', [
                                'round' => $round + 1,
                                'message' => 'LLM 再次調用完成（基於工具結果）',
                            ]);
                        }

                        if (!$response['success']) {
                            $fallback = $this->fallbackToNonToolMode($messages, $progressCallback, $allToolResults, $response['error']);
                            if ($fallback['success']) {
                                $result = $fallback['result'];
                                $result['tool_calls'] = $allToolResults;
                                $result['model'] = $this->model;

                                $logData['llm_response'] = json_encode([
                                    'rounds' => $roundsLog,
                                    'total_rounds' => count($roundsLog),
                                    'fallback_non_tool_mode' => true,
                                    'tool_mode_error' => $response['error'],
                                    'fallback_response' => $fallback['raw_response'],
                                ], JSON_UNESCAPED_UNICODE);
                                $logData['generated_sql'] = $result['sql'];
                                $logData['explanation'] = $result['explanation'];
                                $logData['success'] = $result['success'];
                                $logData['error_message'] = $result['error'];
                                $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                                $this->saveLog($logData);

                                return $result;
                            }

                            $logData['llm_response'] = json_encode([
                                'rounds' => $roundsLog,
                                'total_rounds' => count($roundsLog),
                                'error' => $response['error'],
                            ], JSON_UNESCAPED_UNICODE);
                            $logData['error_message'] = $response['error'] . '；降級模式仍失敗';
                            $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                            $this->saveLog($logData);

                            return [
                                'success' => false,
                                'sql' => null,
                                'error' => $response['error'],
                                'explanation' => null,
                                'model' => $this->model,
                                'tool_calls' => $allToolResults,
                            ];
                        }

                        $currentResponse = $response['data'];
                        $round++;

                        continue;
                    }

                    $roundsLog[] = $roundEntry;
                    $logData['llm_response'] = json_encode([
                        'rounds' => $roundsLog,
                        'total_rounds' => count($roundsLog),
                    ], JSON_UNESCAPED_UNICODE);

                    $result = $this->parseOpenAIResponse($currentResponse);
                    $result['tool_calls'] = !empty($allToolResults) ? $allToolResults : null;

                    break;
                }
            } // 结束旧格式处理

            // 添加模型信息
            $result['model'] = $this->model;

            // 更新日志数据
            $logData['generated_sql'] = $result['sql'];
            $logData['explanation'] = $result['explanation'];
            $logData['success'] = $result['success'];
            $logData['error_message'] = $result['error'];
            $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);

            // 保存日志
            $this->saveLog($logData);

            return $result;

        } catch (\Exception $e) {
            Log::error('生成 SQL 时发生异常', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $logData['error_message'] = '生成 SQL 时发生错误: ' . $e->getMessage();
            $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
            $this->saveLog($logData);

            return [
                'success' => false,
                'sql' => null,
                'error' => '生成 SQL 时发生错误: ' . $e->getMessage(),
                'explanation' => null,
                'model' => $this->model,
                'tool_calls' => null,
            ];
        }
    }

    /**
     * 根據自然語言問題生成段落式答案（歷史人物問答模式）
     *
     * 沿用與 generateSQL() 相同的 LLM 與工具鏈，但最終輸出為 Markdown 段落而非 SQL。
     *
     * @param string $question 使用者問題
     * @param array|null $tableNames 限制使用的表名（可選）
     * @param callable|null $progressCallback SSE 進度回調
     * @param bool|null $useToolsOverride 是否強制啟用工具
     * @return array
     */
    public function answerQuestion(string $question, ?array $tableNames = null, ?callable $progressCallback = null, ?bool $useToolsOverride = null): array {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'error' => 'LLM API Key 未配置。請在 .env 檔案中設置 GEMINI_API_KEY。',
            ];
        }

        $startTime = microtime(true);
        $logData = [
            'user_id' => Auth::id(),
            'question' => '[QA] ' . $question,
            'llm_prompt' => null,
            'llm_response' => null,
            'generated_sql' => null,
            'explanation' => null,
            'success' => false,
            'error_message' => null,
            'execution_time_ms' => null,
        ];

        try {
            $schemaPrompt = $this->schemaService->generateSchemaPrompt($tableNames);
            $toolsEnabled = config('nl_query_tools.enabled', true);
            if ($useToolsOverride !== null) {
                $toolsEnabled = $toolsEnabled && $useToolsOverride;
            }
            $systemPrompt = $this->buildQaSystemPrompt($schemaPrompt, $toolsEnabled);

            $logData['llm_prompt'] = $systemPrompt . "\n\n使用者問題：{$question}";

            $tools = $toolsEnabled ? $this->toolsService->getToolDefinitions() : [];

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $question],
            ];

            // 工具調用循環（與 generateSQL 相似邏輯）
            $maxRounds = max(1, (int) config('nl_query_tools.max_tool_calls', 20));
            $round = 0;
            $allToolResults = [];
            $allSqlUsed = [];

            while ($round < $maxRounds) {
                $round++;
                $allowTools = $toolsEnabled && $round < $maxRounds;
                $response = $this->callLLMForQa($messages, $tools, $allowTools);

                if ($progressCallback) {
                    $progressCallback('status', [
                        'message' => "LLM 第 {$round} 輪調用完成",
                    ]);
                }

                if (!$response['success']) {
                    $logData['error_message'] = $response['error'];
                    $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                    $this->saveLog($logData);

                    return ['success' => false, 'error' => $response['error']];
                }

                $data = $response['data'];
                $toolCalls = $data['choices'][0]['message']['tool_calls'] ?? null;

                if ($toolCalls && $allowTools) {
                    $toolResults = $this->executeToolCalls($toolCalls, $progressCallback);
                    $allToolResults = array_merge($allToolResults, $toolResults);

                    // 收集 SQL
                    foreach ($toolResults as $tr) {
                        if ($tr['tool_name'] === 'query_read_only_sql' && isset($tr['arguments']['sql'])) {
                            $allSqlUsed[] = $tr['arguments']['sql'];
                        }
                    }

                    $messages[] = $data['choices'][0]['message'];
                    foreach ($toolResults as $toolResult) {
                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolResult['tool_call_id'],
                            'content' => json_encode($toolResult['result'], JSON_UNESCAPED_UNICODE),
                        ];
                    }

                    continue;
                }

                // 沒有工具調用 → 解析最終回答
                $content = $data['choices'][0]['message']['content'] ?? '';
                $parsed = $this->parseQaResponse($content);

                $logData['llm_response'] = json_encode($data, JSON_UNESCAPED_UNICODE);
                $logData['generated_sql'] = !empty($allSqlUsed) ? implode(";\n", $allSqlUsed) : null;
                $logData['explanation'] = mb_substr($parsed['summary'] ?? '', 0, 500);
                $logData['success'] = true;
                $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                $this->saveLog($logData);

                return [
                    'success' => true,
                    'answer_markdown' => $parsed['answer_markdown'],
                    'summary' => $parsed['summary'],
                    'sql_used' => array_values(array_unique(array_merge($allSqlUsed, $parsed['sql_used'] ?? []))),
                    'tool_calls' => !empty($allToolResults) ? $allToolResults : [],
                    'evidence' => $parsed['evidence'] ?? [],
                    'caveat' => $parsed['caveat'] ?? '部分歷史背景為模型補充，非資料庫直接欄位。',
                    'model' => $this->model,
                ];
            }

            // 超過輪數限制，強制生成答案
            $logData['error_message'] = '工具調用次數超過上限';
            $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
            $this->saveLog($logData);

            return ['success' => false, 'error' => '工具調用次數超過上限，無法生成回答。'];
        } catch (\Exception $e) {
            Log::error('歷史問答生成異常', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $logData['error_message'] = '問答生成時發生錯誤: ' . $e->getMessage();
            $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
            $this->saveLog($logData);

            return ['success' => false, 'error' => '問答生成時發生錯誤: ' . $e->getMessage()];
        }
    }

    /**
     * 構建歷史問答模式的系統提示詞
     */
    protected function buildQaSystemPrompt(string $schemaPrompt, bool $toolsEnabled): string {
        $toolsHint = '';
        $maxToolRounds = max(1, (int) config('nl_query_tools.max_tool_calls', 20));

        if ($toolsEnabled) {
            $toolsHint = <<<TOOLS

**可用工具：**
- list_allowed_tables(): 列出所有允許查詢的資料表
- query_table_schema(table_name): 獲取完整表結構
- query_table(table_name, filters?, columns?, limit?, offset?): 查詢表格資料
- get_table_row_by_id(table_name, id_column, id_value): 依主鍵抓單筆資料
- query_read_only_sql(sql, limit?, offset?): 執行只讀 SQL（SELECT/WITH）
- get_table_schema(table_name): 獲取表格詳細結構
- get_sample_data_for_table(table_name, limit=10): 獲取表格樣例數據
- get_code_values(code_type): 獲取代碼表值（dynasties, sex, entry_codes, kinship_codes, status_codes, address_types）
- get_person_ids(person_name, limit=20): 根據人名搜索人物 ID

**工具使用策略（最多 {$maxToolRounds} 回合）：**
1. 查詢任何資料表之前，先用 query_table_schema 或 get_table_schema 確認欄位名稱；不要臆測欄位名
2. 涉及名稱、官名、地名、書名等查詢時，若 schema 顯示有 alternative name 欄位，必須同時檢查主名稱欄位與 alternative name 欄位
3. 先用 get_person_ids 搜尋人名，取得人物 ID
4. 用 query_read_only_sql 或 query_table 查詢人物基本資料、別名、入仕途徑、社會關係等
5. 用 get_code_values 解讀代碼值（如朝代、入仕方式）
6. 收集到足夠資料後，綜合整理成自然語言回答
TOOLS;
        }

        $allTables = array_keys(config('codes.tables', []));
        $allTables = array_filter($allTables, function ($tableName) {
            if ($tableName === 'ADDRESSES') {
                return false;
            }

            return !str_starts_with($tableName, 'CBDB__');
        });
        $tablesList = implode(', ', $allTables);

        $commonDynasties = <<<DYNASTIES
**常見朝代代碼速查：**
- 唐: c_dy = 6
- 宋: c_dy = 15
- 元: c_dy = 18
- 明: c_dy = 19
- 清: c_dy = 20
DYNASTIES;

        $coreSchemaInfo = $this->schemaService->getSchemaInfo(['BIOG_MAIN']);
        $coreSchemaText = "**核心表 BIOG_MAIN（人物主表）結構：**\n";
        if (isset($coreSchemaInfo['BIOG_MAIN'])) {
            foreach ($coreSchemaInfo['BIOG_MAIN']['columns'] ?? [] as $column) {
                $coreSchemaText .= sprintf(
                    "  - %s (%s): %s\n",
                    $column['name'],
                    $column['type'],
                    $column['comment'] ?? ''
                );
            }
        }

        return <<<PROMPT
你是 CBDB（中國歷代人物傳記資料庫）的歷史人物問答助手。你的任務是根據使用者的問題，查詢資料庫並提供準確的自然語言回答。
{$toolsHint}

**核心原則：**
1. 優先使用工具查詢 CBDB 資料庫獲取事實資料
2. 回答以繁體中文書寫，使用 Markdown 格式
3. 在使用任何資料表前，先查 schema 確認欄位名稱；不要臆測欄位名
4. 若 schema 顯示除了主名稱欄位外還有 alternative name 欄位，查詢時要一併檢查（例如 `OFFICE_CODES.c_office_chn` 與 `OFFICE_CODES.c_office_chn_alt`）
5. **必須清楚區分以下兩類資訊：**
   - **📋 資料庫事實**：直接來自 CBDB 查詢的數據（人物存在性、生卒年、朝代、別名、入仕方式、著述、關係等）
   - **📚 模型補充**：模型自身的歷史知識補充（朝代背景、制度解釋、歷史上下文等），應使用較保守語氣
6. 若資料不足，應明確說明，不要編造

**回答格式要求：**
回答必須是嚴格的 JSON，包含以下欄位：
- answer_markdown: Markdown 格式的完整回答（段落式，包含粗體、列表、引用等）
- summary: 一句話摘要（繁體中文）
- sql_used: 陣列，包含此次回答中使用過的 SQL 語句（若無則為空陣列）
- evidence: 陣列，每項包含 type（"database" 或 "model_background"）、label、detail
- caveat: 關於資料來源的注意事項說明

**回答撰寫指引：**
- 資料庫查到的事實以明確語氣陳述
- 模型補充的歷史背景使用「根據歷史記載」「一般認為」等保守措辭
- 在 answer_markdown 中以「**📋 資料庫記錄**」和「**📚 歷史背景補充**」等標題區分不同來源
- 如果資料庫找不到此人物，應明確告知使用者

{$coreSchemaText}

{$commonDynasties}

**其他可用數據表：**
{$tablesList}

**回覆 JSON 範例：**
{
  "answer_markdown": "## 李白\n\n**📋 資料庫記錄**\n\n根據 CBDB 資料庫...\n\n**📚 歷史背景補充**\n\n李白是唐代最著名的詩人之一...",
  "summary": "唐代詩人李白的基本資訊與歷史背景。",
  "sql_used": ["SELECT * FROM BIOG_MAIN WHERE c_personid = 12345"],
  "evidence": [
    {"type": "database", "label": "BIOG_MAIN", "detail": "人物基本資料"},
    {"type": "model_background", "label": "歷史背景補充", "detail": "唐代詩歌文化背景"}
  ],
  "caveat": "部分歷史背景為模型補充，非資料庫直接欄位。"
}

**重要：回覆必須是純 JSON，不要使用 Markdown 代碼區塊或額外文字包裹。**
PROMPT;
    }

    /**
     * 呼叫 LLM API（QA 模式：允許工具時不強制 structured output）
     */
    protected function callLLMForQa(array $messages, array $tools = [], bool $allowToolCalls = false): array {
        if ($allowToolCalls && !empty($tools)) {
            return $this->callLLM($messages, $tools, true);
        }

        // 最終回答輪：不使用 structured output，讓模型自由回答 JSON
        return $this->callLLM($messages, [], false);
    }

    /**
     * 解析 QA 回答（從 LLM 返回的 JSON 或純文字中提取結構化答案）
     */
    protected function parseQaResponse(string $content): array {
        $defaults = [
            'answer_markdown' => '',
            'summary' => '',
            'sql_used' => [],
            'evidence' => [],
            'caveat' => '部分歷史背景為模型補充，非資料庫直接欄位。',
        ];

        if (empty(trim($content))) {
            return array_merge($defaults, [
                'answer_markdown' => '抱歉，無法生成回答。',
            ]);
        }

        // 嘗試從內容中提取 JSON
        $jsonContent = $this->extractJsonFromContent($content);
        // 移除控制字元以避免 JSON 解析錯誤（保留換行、製表符等合法字元）
        $jsonContent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $jsonContent);
        $parsed = json_decode($jsonContent, true, 512, JSON_INVALID_UTF8_IGNORE);

        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            return [
                'answer_markdown' => $parsed['answer_markdown'] ?? $content,
                'summary' => $parsed['summary'] ?? '',
                'sql_used' => $parsed['sql_used'] ?? [],
                'evidence' => $parsed['evidence'] ?? [],
                'caveat' => $parsed['caveat'] ?? $defaults['caveat'],
            ];
        }

        // 無法解析 JSON 時，將純文字作為 answer_markdown
        return array_merge($defaults, [
            'answer_markdown' => $content,
            'summary' => mb_substr($content, 0, 100),
        ]);
    }

    /**
     * 保存查询日志
     *
     * @param array $logData
     * @return void
     */
    protected function saveLog(array $logData): void {
        try {
            DB::table('nl_query_logs')->insert([
                'user_id' => $logData['user_id'],
                'question' => $logData['question'],
                'generated_sql' => $logData['generated_sql'],
                'explanation' => $logData['explanation'],
                'llm_prompt' => $logData['llm_prompt'],
                'llm_response' => $logData['llm_response'],
                'success' => $logData['success'],
                'error_message' => $logData['error_message'],
                'execution_time_ms' => $logData['execution_time_ms'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            // 日志记录失败不应该影响主流程
            Log::warning('保存 NL 查询日志失败', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 构建系统提示词
     *
     * @param string $schemaPrompt
     * @return string
     */
    protected function buildSystemPrompt(string $schemaPrompt, bool $toolsEnabled): string {
        $toolsHint = '';
        $maxToolRounds = max(1, (int) config('nl_query_tools.max_tool_calls', 20));

        if (!$toolsEnabled) {
            return <<<PROMPT
你是一个专业的 SQL 查询生成助手。你的任务是根据用户的自然语言问题，生成准确的 SQL SELECT 查询语句。

**重要规则：**
1. 只生成 SELECT 查询，不要生成 UPDATE、DELETE、INSERT 等修改性语句
2. 不要使用 EXPLAIN、DESCRIBE、SHOW 等元查询
3. 查询只能使用以下提供的表和字段
4. 使用标准 SQL 语法（MySQL/MariaDB 兼容）
5. 先核对提供的 schema 再使用字段；不要臆測欄位名
6. 若 schema 顯示主名稱欄位之外還有 alternative name 欄位，查詢名稱時必須一併考慮
7. 返回格式为 JSON，包含以下字段：
   - sql: SQL 查询语句（纯文本，不需要代码块标记）；如果无法生成则为 null
   - explanation: 简短的查询解释（一到两句话，使用繁体中文）；如果无法生成则为 null
   - error: 错误信息（繁体中文），说明为何无法生成 SQL；成功时为 null

**何时返回 error（设置 sql 和 explanation 为 null）：**
- 用户问题不清楚或过于模糊
- 用户要求的表或字段不在提供的数据库结构中
- 用户要求执行非 SELECT 操作（如修改、删除数据）
- 用户问题无法用 SQL 查询表达
- 用户问题涉及数据库元信息（如 SHOW、DESCRIBE）

**可用的数据库表结构：**

{$schemaPrompt}

**成功示例：**
用户问题：显示所有朝代名称
返回 JSON：
{
  "sql": "SELECT c_dy FROM DYNASTIES",
  "explanation": "此查询从 DYNASTIES 表中选择所有朝代名称字段。",
  "error": null
}

**错误示例：**
用户问题：删除所有朝代
返回 JSON：
{
  "sql": null,
  "explanation": null,
  "error": "無法執行此操作，系統僅支援查詢（SELECT）操作，不支援刪除（DELETE）操作。"
}

用户问题：查询用户表
返回 JSON：
{
  "sql": null,
  "explanation": null,
  "error": "資料庫中沒有「用户表」，請檢查可用的表格清單並重新表述問題。"
}
PROMPT;
        }

        if ($toolsEnabled) {
            $toolsHint = <<<TOOLS

**可用工具：**
- list_allowed_tables(): 列出所有允許查詢的資料表
- query_table_schema(table_name): 獲取完整表結構（欄位、索引、外鍵、metadata）
- query_table(table_name, filters?, columns?, limit?, offset?): 查詢表格資料
- get_table_row_by_id(table_name, id_column, id_value): 依主鍵欄位抓單筆資料
- query_read_only_sql(sql, limit?, offset?): 執行只讀 SQL（SELECT/WITH）
- get_table_schema(table_name): 獲取指定表格的詳細結構信息（字段、類型、描述）
- get_sample_data_for_table(table_name, limit=10): 獲取表格樣例數據
- get_code_values(code_type): 獲取代碼表值（dynasties, sex, entry_codes, kinship_codes, status_codes, address_types）
- get_person_ids(person_name, limit=20): 根據人名搜索人物 ID

**使用建議：**
1. 先用 list_allowed_tables 確認候選表，再用 query_table_schema 或 get_table_schema 檢查欄位與關聯；不要臆測欄位名
2. 涉及名稱搜尋時，若 schema 顯示主名稱欄位之外另有 alternative name 欄位，必須一併查詢（例如 `OFFICE_CODES.c_office_chn` 與 `OFFICE_CODES.c_office_chn_alt`）
3. 需要確認實際值時，用 query_table / get_sample_data_for_table / get_table_row_by_id
4. 構造 WHERE 條件但不確定代碼值時，使用 get_code_values
5. 用戶提到具體人名時，使用 get_person_ids 查找 ID
6. 生成最終 SQL 前，可先用 query_read_only_sql(limit 小值) 驗證可執行性
7. 每次可以同時請求多個工具，並一次取得結果後再繼續推理（最多 {$maxToolRounds} 回合）
8. 回覆必須是純 JSON，請勿使用任何 Markdown 代碼區塊或額外文字
TOOLS;
        }

        // 获取所有可用表名（完整列表，不截斷，排除內部/地理明細表）
        $allTables = array_keys(config('codes.tables', []));
        $allTables = array_filter($allTables, function ($tableName) {
            if ($tableName === 'ADDRESSES') {
                return false;
            }

            return strpos($tableName, 'CBDB__') !== 0;
        });
        $tablesList = implode(', ', $allTables);

        // 常见朝代 ID 速查
        $commonDynasties = <<<DYNASTIES
**常見朝代代碼速查：**
- 唐: c_dy = 6
- 宋: c_dy = 15
- 元: c_dy = 18
- 明: c_dy = 19
- 清: c_dy = 20
DYNASTIES;

        // 只包含最重要表的完整 schema（BIOG_MAIN）
        $coreSchemaInfo = $this->schemaService->getSchemaInfo(['BIOG_MAIN']);
        $coreSchemaText = "**核心表 BIOG_MAIN（人物主表）結構：**\n";
        if (isset($coreSchemaInfo['BIOG_MAIN'])) {
            foreach ($coreSchemaInfo['BIOG_MAIN']['columns'] ?? [] as $column) {
                $coreSchemaText .= sprintf(
                    "  - %s (%s): %s\n",
                    $column['name'],
                    $column['type'],
                    $column['comment'] ?? ''
                );
            }
        }

        return <<<PROMPT
你是一个专业的 SQL 查询生成助手。你的任务是根据用户的自然语言问题，生成准确的 SQL SELECT 查询语句。
{$toolsHint}

**重要规则：**
1. 只生成 SELECT 查询，不要生成 UPDATE、DELETE、INSERT 等修改性语句
2. 不要使用 EXPLAIN、DESCRIBE、SHOW 等元查询
3. 查询只能使用以下提供的表和字段
4. 使用标准 SQL 语法（MySQL/MariaDB 兼容）
5. 返回格式为 JSON，包含以下字段：
   - sql: SQL 查询语句（纯文本，不需要代码块标记）；如果无法生成则为 null
   - explanation: 简短的查询解释（一到两句话，使用繁体中文）；如果无法生成则为 null
   - error: 错误信息（繁体中文），说明为何无法生成 SQL；成功时为 null
6. 不得臆測欄位或代碼值；不確定時先使用 get_table_schema 或 get_code_values
7. 若 schema 顯示主名稱欄位之外還有 alternative name 欄位，查詢名稱時必須一併納入條件（例如 `OFFICE_CODES.c_office_chn_alt`）

**何时返回 error（设置 sql 和 explanation 为 null）：**
- 用户问题不清楚或过于模糊
- 用户要求的表或字段不在提供的数据库结构中
- 用户要求执行非 SELECT 操作（如修改、删除数据）
- 用户问题无法用 SQL 查询表达
- 用户问题涉及数据库元信息（如 SHOW、DESCRIBE）

{$coreSchemaText}

{$commonDynasties}

**其他可用數據表：**
{$tablesList}

**成功示例：**
用户问题：显示所有朝代名称
返回 JSON：
{
  "sql": "SELECT c_dy FROM DYNASTIES",
  "explanation": "此查询从 DYNASTIES 表中选择所有朝代名称字段。",
  "error": null
}

**错误示例：**
用户问题：删除所有朝代
返回 JSON：
{
  "sql": null,
  "explanation": null,
  "error": "無法執行此操作，系統僅支援查詢（SELECT）操作，不支援刪除（DELETE）操作。"
}

用户问题：查询用户表
返回 JSON：
{
  "sql": null,
  "explanation": null,
  "error": "資料庫中沒有「用户表」，請檢查可用的表格清單並重新表述問題。"
}
PROMPT;
    }

    /**
     * 通用响应解析方法（支持新旧格式）
     *
     * @param array $responseData
     * @return array
     */
    protected function parseResponse(array $responseData): array {
        // 检查是否是新格式（直接包含 sql/explanation/error）
        if (isset($responseData['sql']) || isset($responseData['error']) || isset($responseData['explanation'])) {
            // 新格式：直接返回结构化数据
            return $this->validateAndNormalizeSQLResponse($responseData);
        }

        // 检查是否包含 OpenAI 格式的 choices
        if (isset($responseData['choices'])) {
            return $this->parseOpenAIResponse($responseData);
        }

        // 未知格式
        return [
            'success' => false,
            'sql' => null,
            'error' => 'API 返回的响应格式不正确',
            'explanation' => null,
        ];
    }

    /**
     * 验证并标准化 SQL 响应数据
     *
     * @param array $data
     * @return array
     */
    protected function validateAndNormalizeSQLResponse(array $data): array {
        // 检查 LLM 返回的 error 字段
        if (!empty($data['error'])) {
            $llmError = trim($data['error']);
            Log::info('LLM 返回錯誤，無法生成 SQL', [
                'error' => $llmError,
            ]);

            return [
                'success' => false,
                'sql' => null,
                'error' => $llmError,
                'explanation' => null,
            ];
        }

        // 验证必需字段
        if (empty($data['sql'])) {
            Log::warning('API 返回的响应中缺少 SQL 字段', [
                'json_data' => $data,
            ]);

            return [
                'success' => false,
                'sql' => null,
                'error' => 'API 返回的响应中缺少 SQL 字段。请尝试重新表述您的问题。',
                'explanation' => null,
            ];
        }

        $sql = trim($data['sql']);
        $explanation = trim($data['explanation'] ?? '');

        return [
            'success' => true,
            'sql' => $sql,
            'error' => null,
            'explanation' => $explanation ?: null,
        ];
    }

    /**
     * 解析 OpenAI 兼容 API 响应（使用 structured output）
     *
     * @param array $responseData
     * @return array
     */
    protected function parseOpenAIResponse(array $responseData): array {
        try {
            // 从 OpenAI 格式的响应中获取内容
            $content = $responseData['choices'][0]['message']['content'] ?? null;

            if (!$content) {
                return [
                    'success' => false,
                    'sql' => null,
                    'error' => 'API 返回的响应格式不正确',
                    'explanation' => null,
                ];
            }

            $rawContent = $content;
            // 优先提取代码块中的 JSON
            $content = $this->extractJsonFromContent($content);

            // 清理可能的控制字符，但保留换行符和制表符（JSON 中合法）
            // 移除其他控制字符（0x00-0x1F，除了 0x09 制表符、0x0A 换行、0x0D 回车）
            $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);

            // 解析 JSON 响应，使用选项忽略 UTF-8 错误
            $jsonData = json_decode($content, true, 512, JSON_INVALID_UTF8_IGNORE);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $fallback = $this->extractJsonObject($rawContent);
                if ($fallback) {
                    $fallback = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $fallback);
                    $jsonData = json_decode($fallback, true, 512, JSON_INVALID_UTF8_IGNORE);
                }

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $this->validateAndNormalizeSQLResponse($jsonData);
                }

                Log::warning('API 返回的 JSON 解析失败', [
                    'error' => json_last_error_msg(),
                    'content' => mb_substr($content, 0, 500), // 只记录前 500 个字符
                    'content_length' => strlen($content),
                    'fallback' => $fallback ? mb_substr($fallback, 0, 500) : null,
                    'fallback_length' => $fallback ? strlen($fallback) : null,
                ]);

                return [
                    'success' => false,
                    'sql' => null,
                    'error' => 'API 返回的 JSON 格式不正确: ' . json_last_error_msg() . '。请尝试简化您的问题。',
                    'explanation' => null,
                ];
            }

            // 使用共享的验证逻辑
            return $this->validateAndNormalizeSQLResponse($jsonData);

        } catch (\Exception $e) {
            Log::error('解析 API 响应时出错', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'sql' => null,
                'error' => '解析 API 响应时出错: ' . $e->getMessage(),
                'explanation' => null,
            ];
        }
    }

    /**
     * 从响应内容中提取 JSON（支持 ```json ... ``` 代码块）
     *
     * @param string $content
     * @return string
     */
    protected function extractJsonFromContent(string $content): string {
        if (preg_match('/```json\\s*(\\{.*?\\})\\s*```/is', $content, $matches)) {
            return $matches[1];
        }

        if (preg_match('/```\\s*(\\{.*?\\})\\s*```/is', $content, $matches)) {
            return $matches[1];
        }

        $jsonCandidate = $this->extractJsonObject($content);

        return $jsonCandidate ?? $content;
    }

    /**
     * 从文本中提取第一个 JSON 对象（以 {} 包裹）
     *
     * @param string $content
     * @return string|null
     */
    protected function extractJsonObject(string $content): ?string {
        $start = strpos($content, '{');
        if ($start === false) {
            return null;
        }

        $length = strlen($content);
        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;

                    continue;
                }
                if ($char === '\\') {
                    $escape = true;

                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * 调用 LLM API
     *
     * @param array $messages 消息历史
     * @param array $tools 可用工具定义
     * @param bool $allowToolCalls 是否允许工具调用
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    protected function callLLM(array $messages, array $tools = [], bool $allowToolCalls = false): array {
        try {
            $maxCompletionTokens = (int) config('services.gemini.max_completion_tokens', 8192);
            if ($maxCompletionTokens < 256) {
                $maxCompletionTokens = 256;
            }

            $requestData = [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.1,
                'top_p' => 0.95,
                'max_completion_tokens' => $maxCompletionTokens,
            ];

            // 如果提供了工具定义且允许工具调用，添加到请求中
            if (!empty($tools) && $allowToolCalls) {
                $requestData['tools'] = $tools;
                $requestData['tool_choice'] = 'auto';
            } else {
                // 如果不允许工具调用，使用 structured output
                $requestData['response_format'] = [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'sql_query_response',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'sql' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'The SQL SELECT query statement (null if error)',
                                ],
                                'explanation' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'A brief explanation of what the query does (1-2 sentences in Traditional Chinese)',
                                ],
                                'error' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'Error message explaining why SQL cannot be generated (null if successful)',
                                ],
                            ],
                            'required' => ['sql', 'explanation', 'error'],
                            'additionalProperties' => false,
                        ],
                    ],
                ];
            }

            $maxAttempts = 3;
            $retryDelayMs = 800;
            $response = null;
            $lastException = null;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $response = Http::connectTimeout(10)
                        ->timeout(45)
                        ->withHeaders([
                            'Content-Type' => 'application/json',
                            'Authorization' => 'Bearer ' . $this->apiKey,
                        ])
                        ->post($this->apiEndpoint, $requestData);
                    // 收到任何 HTTP 回應後，清除先前重試留下的 exception 狀態
                    $lastException = null;

                    if ($response->successful()) {
                        break;
                    }

                    if ($this->shouldRetryHttpResponse($response) && $attempt < $maxAttempts) {
                        usleep($retryDelayMs * 1000);

                        continue;
                    }

                    break;
                } catch (\Throwable $exception) {
                    $lastException = $exception;

                    if ($this->shouldRetryException($exception) && $attempt < $maxAttempts) {
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
                $errorMessage = $this->extractApiErrorMessage($response->json(), $response->body());
                Log::error('LLM API 调用失败', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                    'model' => $this->model,
                    'endpoint' => $this->apiEndpoint,
                ]);

                return [
                    'success' => false,
                    'data' => null,
                    'error' => "LLM API 调用失败: {$errorMessage}",
                ];
            }

            return [
                'success' => true,
                'data' => $response->json(),
                'error' => null,
            ];

        } catch (\Exception $e) {
            Log::error('调用 LLM 时发生异常', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'data' => null,
                'error' => '调用 LLM 时发生错误: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 從第三方 API 回應中抽取可讀錯誤訊息（支援 OpenAI-compatible 與 API Gateway fault 格式）
     */
    protected function extractApiErrorMessage(mixed $jsonBody, string $rawBody): string {
        $json = is_array($jsonBody) ? $jsonBody : [];

        $errorMessage = data_get($json, 'error.message');
        if (is_string($errorMessage) && $errorMessage !== '') {
            return $errorMessage;
        }

        $faultString = data_get($json, 'fault.faultstring');
        if (is_string($faultString) && $faultString !== '') {
            $reason = data_get($json, 'fault.detail.reason');
            $errorCode = data_get($json, 'fault.detail.errorcode');
            $parts = array_filter([$faultString, $errorCode, $reason], fn ($v) => is_string($v) && $v !== '');

            return implode(' | ', $parts);
        }

        $fallbackMessage = data_get($json, 'message');
        if (is_string($fallbackMessage) && $fallbackMessage !== '') {
            return $fallbackMessage;
        }

        return mb_substr($rawBody, 0, 2000);
    }

    protected function shouldRetryHttpResponse($response): bool {
        if ($response->status() >= 500) {
            return true;
        }

        $body = $response->body();

        return stripos($body, 'UnexpectedEOFAtTarget') !== false
            || stripos($body, 'Unexpected EOF at target') !== false;
    }

    protected function shouldRetryException(\Throwable $exception): bool {
        $message = $exception->getMessage();

        return stripos($message, 'timed out') !== false
            || stripos($message, 'connection') !== false
            || stripos($message, 'Unexpected EOF at target') !== false;
    }

    /**
     * 執行工具調用
     *
     * @param array $toolCalls LLM 请求的工具调用
     * @param callable|null $progressCallback 进度回调函数
     * @return array
     */
    protected function executeToolCalls(array $toolCalls, ?callable $progressCallback = null): array {
        $results = [];

        foreach ($toolCalls as $index => $toolCall) {
            $toolName = $toolCall['function']['name'] ?? '';
            $decodedArguments = json_decode($toolCall['function']['arguments'] ?? '{}', true);
            $arguments = is_array($decodedArguments) ? $decodedArguments : [];
            $toolCallId = $toolCall['id'] ?? '';

            Log::info('执行工具调用', [
                'tool' => $toolName,
                'arguments' => $arguments,
            ]);

            // 发送进度：开始执行工具
            if ($progressCallback) {
                $progressCallback('tool_execution_start', [
                    'tool_index' => $index + 1,
                    'total_tools' => count($toolCalls),
                    'tool_name' => $toolName,
                    'tool_call_id' => $toolCallId,
                    'arguments' => $arguments,
                    'message' => sprintf('正在執行工具 %d/%d: %s', $index + 1, count($toolCalls), $toolName),
                ]);
            }

            $result = $this->executeToolWithRetry($toolName, $arguments, $toolCallId, $index, count($toolCalls), $progressCallback);
            $resultSummary = $this->summarizeToolResult($toolName, $arguments, $result);
            $status = ($result['success'] ?? false) ? 'completed' : 'error';

            $toolResult = [
                'tool_call_id' => $toolCallId,
                'tool_name' => $toolName,
                'status' => $status,
                'arguments' => $arguments,
                'result' => $result,
                'result_summary' => $resultSummary,
            ];

            $results[] = $toolResult;

            // 发送进度：工具执行完成
            if ($progressCallback) {
                $progressCallback('tool_execution_complete', [
                    'tool_index' => $index + 1,
                    'total_tools' => count($toolCalls),
                    'tool_name' => $toolName,
                    'tool_call_id' => $toolCallId,
                    'status' => $status,
                    'arguments' => $arguments,
                    'result_summary' => $resultSummary,
                    'success' => !isset($result['error']),
                    'message' => sprintf('工具 %d/%d 執行完成: %s', $index + 1, count($toolCalls), $toolName),
                ]);
            }
        }

        return $results;
    }

    /**
     * 為工具執行結果生成可讀摘要，避免將完整原始 payload 暴露給前端。
     *
     * @param string $toolName 工具名稱
     * @param array  $arguments 呼叫參數
     * @param array  $result    工具回傳結果
     * @return array
     */
    protected function summarizeToolResult(string $toolName, array $arguments, array $result): array {
        if (!($result['success'] ?? false)) {
            return [
                'status' => 'error',
                'label' => '工具執行失敗',
                'error' => $result['error'] ?? '未知錯誤',
            ];
        }

        $data = $result['data'] ?? null;

        switch ($toolName) {
            case 'query_read_only_sql': {
                $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
                $count = count($rows);
                $columns = $count > 0 ? array_keys((array) $rows[0]) : [];
                $sql = $data['sql'] ?? ($arguments['sql'] ?? '');

                return [
                    'status' => 'completed',
                    'label' => "返回 {$count} 筆資料",
                    'sql' => $sql,
                    'row_count' => $count,
                    'columns' => $columns,
                    'preview' => array_slice($rows, 0, 3),
                ];
            }

            case 'query_table': {
                $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
                $count = count($rows);
                $total = (int) ($data['total_matching_rows'] ?? $count);
                $columns = $count > 0 ? array_keys((array) $rows[0]) : [];

                return [
                    'status' => 'completed',
                    'label' => "返回 {$count} 筆資料（共 {$total} 筆匹配）",
                    'row_count' => $count,
                    'total_matching' => $total,
                    'columns' => $columns,
                    'preview' => array_slice($rows, 0, 3),
                ];
            }

            case 'get_sample_data_for_table': {
                // NlQueryToolsService.getSampleDataForTable 直接返回 $formattedData 陣列
                $rows = is_array($data) ? $data : [];
                $count = count($rows);
                $columns = $count > 0 ? array_keys((array) $rows[0]) : [];

                return [
                    'status' => 'completed',
                    'label' => "取得 {$count} 筆樣例資料",
                    'row_count' => $count,
                    'columns' => $columns,
                    'preview' => array_slice($rows, 0, 3),
                ];
            }

            case 'get_table_row_by_id': {
                $row = (array) ($data['row'] ?? []);
                $found = !empty($row);
                $table = $data['table_name'] ?? ($arguments['table_name'] ?? '');
                $label = $found ? "找到 1 筆記錄（{$table}）" : '未找到記錄';

                return [
                    'status' => 'completed',
                    'label' => $label,
                    'found' => $found,
                    'table_name' => $table,
                    'row_preview' => array_slice($row, 0, 8, true),
                ];
            }

            case 'query_table_schema': {
                $cols = (array) ($data['columns'] ?? []);
                $count = count($cols);
                $table = $data['table_name'] ?? ($arguments['table_name'] ?? '');
                $colNames = array_map(
                    fn ($c) => is_array($c) ? ($c['Field'] ?? $c['name'] ?? '') : (string) $c,
                    array_slice($cols, 0, 15)
                );

                return [
                    'status' => 'completed',
                    'label' => "查詢 schema: {$table}（{$count} 個欄位）",
                    'table_name' => $table,
                    'columns_count' => $count,
                    'column_names' => array_values(array_filter($colNames)),
                ];
            }

            case 'get_table_schema': {
                // NlQueryToolsService.getTableSchema 回傳 {success, schema, error}
                $schema = $result['schema'] ?? $data ?? [];
                $cols = (array) ($schema['columns'] ?? []);
                $count = count($cols);
                $table = $arguments['table_name'] ?? '';

                return [
                    'status' => 'completed',
                    'label' => "查詢 schema: {$table}（{$count} 個欄位）",
                    'table_name' => $table,
                    'columns_count' => $count,
                ];
            }

            case 'get_person_ids': {
                $rows = is_array($data) ? $data : [];
                $count = $result['count'] ?? count($rows);
                $names = array_filter(array_column($rows, 'c_name_chn'));
                $personIds = array_column($rows, 'c_personid');

                return [
                    'status' => 'completed',
                    'label' => "找到 {$count} 個相關人物",
                    'count' => (int) $count,
                    'person_ids' => array_values(array_slice($personIds, 0, 5)),
                    'names' => array_values(array_slice($names, 0, 5)),
                ];
            }

            case 'get_code_values': {
                $rows = is_array($data) ? $data : [];
                $count = count($rows);
                $codeType = $arguments['code_type'] ?? '';

                return [
                    'status' => 'completed',
                    'label' => "取得 {$codeType} 代碼（{$count} 筆）",
                    'count' => $count,
                    'code_type' => $codeType,
                    'preview' => array_slice($rows, 0, 5),
                ];
            }

            case 'list_allowed_tables': {
                $tables = is_array($data) ? $data : [];
                $count = count($tables);

                return [
                    'status' => 'completed',
                    'label' => "列出 {$count} 個允許表格",
                    'count' => $count,
                    'tables' => array_slice($tables, 0, 10),
                ];
            }

            default:
                return [
                    'status' => 'completed',
                    'label' => '工具執行完成',
                ];
        }
    }

    protected function executeToolWithRetry(
        string $toolName,
        array $arguments,
        string $toolCallId,
        int $index,
        int $totalTools,
        ?callable $progressCallback = null
    ): array {
        $maxAttempts = 2;
        $lastResult = [
            'success' => false,
            'data' => null,
            'error' => '工具調用未執行',
        ];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $lastResult = $this->toolsService->executeTool($toolName, $arguments);
            } catch (\Throwable $e) {
                Log::error('工具執行失敗', [
                    'tool' => $toolName,
                    'arguments' => $arguments,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                $lastResult = [
                    'success' => false,
                    'data' => null,
                    'error' => "工具執行時發生錯誤: {$e->getMessage()}",
                ];
            }

            if (($lastResult['success'] ?? false) === true) {
                return $lastResult;
            }

            if (!$this->isRetryableToolFailure($lastResult) || $attempt >= $maxAttempts) {
                break;
            }

            if ($progressCallback) {
                $progressCallback('tool_execution_retry', [
                    'tool_index' => $index + 1,
                    'total_tools' => $totalTools,
                    'tool_name' => $toolName,
                    'tool_call_id' => $toolCallId,
                    'arguments' => $arguments,
                    'attempt' => $attempt + 1,
                    'message' => sprintf('工具 %s 第 %d 次失敗，正在重試', $toolName, $attempt),
                ]);
            }

            usleep(400 * 1000);
        }

        $fallbackCall = $this->buildFallbackToolCall($toolName, $arguments);
        if ($fallbackCall !== null) {
            if ($progressCallback) {
                $progressCallback('tool_execution_fallback', [
                    'tool_index' => $index + 1,
                    'total_tools' => $totalTools,
                    'tool_name' => $toolName,
                    'tool_call_id' => $toolCallId,
                    'fallback_tool_name' => $fallbackCall['tool_name'],
                    'fallback_arguments' => $fallbackCall['arguments'],
                    'message' => sprintf('工具 %s 失敗，改用替代工具 %s', $toolName, $fallbackCall['tool_name']),
                ]);
            }

            try {
                $fallbackResult = $this->toolsService->executeTool($fallbackCall['tool_name'], $fallbackCall['arguments']);
            } catch (\Throwable $e) {
                $fallbackResult = [
                    'success' => false,
                    'data' => null,
                    'error' => "替代工具執行時發生錯誤: {$e->getMessage()}",
                ];
            }

            if (($fallbackResult['success'] ?? false) === true) {
                $fallbackResult['fallback_from'] = $toolName;

                return $fallbackResult;
            }

            $lastError = $lastResult['error'] ?? '未知錯誤';
            $fallbackError = $fallbackResult['error'] ?? '未知錯誤';
            $lastResult['error'] = "原工具失敗: {$lastError}；替代工具失敗: {$fallbackError}";
        }

        return $lastResult;
    }

    protected function isRetryableToolFailure(array $result): bool {
        if (($result['success'] ?? false) === true) {
            return false;
        }

        $error = (string) ($result['error'] ?? '');
        $nonRetryableKeywords = [
            '不在允許',
            '未知的工具',
            '不能为空',
            '不能為空',
            '未知的代碼類型',
            'does not exist',
            'Limit must be between',
        ];

        foreach ($nonRetryableKeywords as $keyword) {
            if (stripos($error, $keyword) !== false) {
                return false;
            }
        }

        return true;
    }

    protected function buildFallbackToolCall(string $toolName, array $arguments): ?array {
        if ($toolName === 'query_table') {
            $tableName = (string) ($arguments['table_name'] ?? '');
            if ($tableName !== '') {
                return [
                    'tool_name' => 'get_sample_data_for_table',
                    'arguments' => [
                        'table_name' => $tableName,
                        'limit' => min((int) ($arguments['limit'] ?? 10), 20),
                    ],
                ];
            }
        }

        if ($toolName === 'query_table_schema' && !empty($arguments['table_name'])) {
            return [
                'tool_name' => 'get_table_schema',
                'arguments' => [
                    'table_name' => $arguments['table_name'],
                ],
            ];
        }

        if ($toolName === 'get_table_schema' && !empty($arguments['table_name'])) {
            return [
                'tool_name' => 'query_table_schema',
                'arguments' => [
                    'table_name' => $arguments['table_name'],
                ],
            ];
        }

        if ($toolName === 'get_table_row_by_id' && !empty($arguments['table_name']) && !empty($arguments['id_column'])) {
            return [
                'tool_name' => 'query_table',
                'arguments' => [
                    'table_name' => $arguments['table_name'],
                    'filters' => [
                        $arguments['id_column'] => $arguments['id_value'] ?? null,
                    ],
                    'limit' => 1,
                    'offset' => 0,
                ],
            ];
        }

        return null;
    }

    protected function fallbackToNonToolMode(
        array $messages,
        ?callable $progressCallback = null,
        ?array $toolResults = null,
        ?string $reason = null
    ): array {
        if ($progressCallback) {
            $progressCallback('llm_fallback_start', [
                'message' => '工具模式失敗，改用非工具模式直接生成 SQL',
                'reason' => $reason,
            ]);
        }

        $fallbackMessages = $this->sanitizeMessagesForNonToolMode($messages);
        $fallbackMessages[] = [
            'role' => 'user',
            'content' => '若工具不可用，請根據既有 schema 資訊直接生成最合理的 SQL，並嚴格返回 JSON。',
        ];

        $fallbackResponse = $this->callLLM($fallbackMessages, [], false);
        if (!$fallbackResponse['success']) {
            if ($progressCallback) {
                $progressCallback('llm_fallback_failed', [
                    'message' => '非工具模式生成也失敗',
                    'reason' => $fallbackResponse['error'],
                ]);
            }

            return [
                'success' => false,
                'result' => null,
                'raw_response' => null,
                'error' => $fallbackResponse['error'],
            ];
        }

        $result = $this->parseResponse($fallbackResponse['data']);
        $result['tool_calls'] = $toolResults;

        if ($progressCallback) {
            $progressCallback('llm_fallback_complete', [
                'message' => '已改用非工具模式完成生成',
                'success' => $result['success'] ?? false,
            ]);
        }

        return [
            'success' => true,
            'result' => $result,
            'raw_response' => $fallbackResponse['data'],
            'error' => null,
        ];
    }

    protected function sanitizeMessagesForNonToolMode(array $messages): array {
        $sanitized = [];

        foreach ($messages as $message) {
            $role = $message['role'] ?? '';
            if ($role === 'tool') {
                continue;
            }

            if ($role === 'assistant' && isset($message['tool_calls'])) {
                $plainContent = $message['content'] ?? '';
                if (!is_string($plainContent) || trim($plainContent) === '') {
                    continue;
                }

                $sanitized[] = [
                    'role' => 'assistant',
                    'content' => $plainContent,
                ];

                continue;
            }

            $sanitized[] = $message;
        }

        if (empty($sanitized)) {
            return $messages;
        }

        return $sanitized;
    }

    /**
     * 清理 LLM 響應中的冗餘數據（移除供應商附帶的內部 metadata）
     *
     * @param array $response
     * @return array
     */
    protected function cleanLLMResponse(array $response): array {
        if (isset($response['choices'])) {
            foreach ($response['choices'] as &$choice) {
                // 移除 extra_content（可能包含供應商內部思考簽名等 metadata）
                if (isset($choice['message']['extra_content'])) {
                    unset($choice['message']['extra_content']);
                }

                // 清理 tool_calls 中的 extra_content
                if (isset($choice['message']['tool_calls'])) {
                    foreach ($choice['message']['tool_calls'] as &$toolCall) {
                        if (isset($toolCall['extra_content'])) {
                            unset($toolCall['extra_content']);
                        }
                    }
                }
            }
        }

        return $response;
    }
}
