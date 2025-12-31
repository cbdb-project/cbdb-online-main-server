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
                'error' => 'Gemini API Key 未配置。请在 .env 文件中设置 GEMINI_API_KEY。',
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
                            $logData['llm_response'] = json_encode([
                                'rounds' => $roundsLog,
                                'total_rounds' => count($roundsLog),
                                'error' => '工具調用次數超過上限',
                            ], JSON_UNESCAPED_UNICODE);
                            $logData['error_message'] = '工具調用次數超過上限';
                            $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                            $this->saveLog($logData);

                            return [
                                'success' => false,
                                'sql' => null,
                                'error' => '工具調用次數超過上限，請縮小查詢範圍或提供更明確的問題。',
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
                            $logData['llm_response'] = json_encode([
                                'rounds' => $roundsLog,
                                'total_rounds' => count($roundsLog),
                                'error' => $response['error'],
                            ], JSON_UNESCAPED_UNICODE);
                            $logData['error_message'] = $response['error'];
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

        if (!$toolsEnabled) {
            return <<<PROMPT
你是一个专业的 SQL 查询生成助手。你的任务是根据用户的自然语言问题，生成准确的 SQL SELECT 查询语句。

**重要规则：**
1. 只生成 SELECT 查询，不要生成 UPDATE、DELETE、INSERT 等修改性语句
2. 不要使用 EXPLAIN、DESCRIBE、SHOW 等元查询
3. 查询只能使用以下提供的表和字段
4. 使用标准 SQL 语法（MySQL/MariaDB 兼容）
5. 返回格式为 JSON，包含以下字段：
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
- get_table_schema(table_name): 獲取指定表格的詳細結構信息（字段、類型、描述）
- get_sample_data_for_table(table_name, limit=10): 獲取表格樣例數據
- get_code_values(code_type): 獲取代碼表值（dynasties, sex, entry_codes, kinship_codes, status_codes, address_types）
- get_person_ids(person_name, limit=20): 根據人名搜索人物 ID

**使用建議：**
1. 對於不熟悉的表格，使用 get_table_schema 了解結構
2. 需要了解實際數據格式時，使用 get_sample_data_for_table
3. 構造 WHERE 條件但不確定代碼值時，使用 get_code_values
4. 用戶提到具體人名時，使用 get_person_ids 查找 ID
5. 每次可以同時請求多個工具，並一次取得結果後再繼續推理（最多 20 回合）
6. 回覆必須是純 JSON，請勿使用任何 Markdown 代碼區塊或額外文字
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
            $requestData = [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.1,
                'top_p' => 0.95,
                'max_completion_tokens' => 16384,
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

            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ])
                ->post($this->apiEndpoint, $requestData);

            if (!$response->successful()) {
                $errorMessage = $response->json('error.message') ?? $response->body();
                Log::error('Gemini API 调用失败', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                ]);

                return [
                    'success' => false,
                    'data' => null,
                    'error' => "Gemini API 调用失败: {$errorMessage}",
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
     * 执行工具调用
     *
     * @param array $toolCalls LLM 请求的工具调用
     * @param callable|null $progressCallback 进度回调函数
     * @return array
     */
    protected function executeToolCalls(array $toolCalls, ?callable $progressCallback = null): array {
        $results = [];

        foreach ($toolCalls as $index => $toolCall) {
            $toolName = $toolCall['function']['name'] ?? '';
            $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true);
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

            try {
                $result = $this->toolsService->executeTool($toolName, $arguments);
            } catch (\Throwable $e) {
                Log::error('工具執行失敗', [
                    'tool' => $toolName,
                    'arguments' => $arguments,
                    'error' => $e->getMessage(),
                ]);

                $result = [
                    'success' => false,
                    'data' => null,
                    'error' => "工具執行時發生錯誤: {$e->getMessage()}",
                ];
            }

            $toolResult = [
                'tool_call_id' => $toolCallId,
                'tool_name' => $toolName,
                'arguments' => $arguments,
                'result' => $result,
            ];

            $results[] = $toolResult;

            // 发送进度：工具执行完成
            if ($progressCallback) {
                $progressCallback('tool_execution_complete', [
                    'tool_index' => $index + 1,
                    'total_tools' => count($toolCalls),
                    'tool_name' => $toolName,
                    'tool_call_id' => $toolCallId,
                    'arguments' => $arguments,
                    'result' => $result,
                    'success' => !isset($result['error']),
                    'message' => sprintf('工具 %d/%d 執行完成: %s', $index + 1, count($toolCalls), $toolName),
                ]);
            }
        }

        return $results;
    }

    /**
     * 清理 LLM 響應中的冗餘數據（移除 Gemini 的 thought_signature 等內部數據）
     *
     * @param array $response
     * @return array
     */
    protected function cleanLLMResponse(array $response): array {
        if (isset($response['choices'])) {
            foreach ($response['choices'] as &$choice) {
                // 移除 extra_content（包含 Gemini 的內部思考簽名）
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
