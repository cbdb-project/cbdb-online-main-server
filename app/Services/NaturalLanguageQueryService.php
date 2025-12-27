<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NaturalLanguageQueryService {
    protected DatabaseSchemaService $schemaService;
    protected string $apiKey;
    protected string $apiEndpoint;
    protected string $model;

    public function __construct(DatabaseSchemaService $schemaService) {
        $this->schemaService = $schemaService;
        $this->apiKey = config('services.gemini.api_key', '');
        $this->apiEndpoint = config('services.gemini.api_endpoint', 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions');
        $this->model = config('services.gemini.model', 'gemini-3-flash-preview');
    }

    /**
     * 根据自然语言问题生成 SQL 查询
     *
     * @param string $question 用户的自然语言问题
     * @param array|null $tableNames 限制使用的表名（可选）
     * @return array ['success' => bool, 'sql' => string|null, 'error' => string|null, 'explanation' => string|null, 'model' => string|null]
     */
    public function generateSQL(string $question, ?array $tableNames = null): array {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'sql' => null,
                'error' => 'Gemini API Key 未配置。请在 .env 文件中设置 GEMINI_API_KEY。',
                'explanation' => null,
                'model' => null,
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
            $systemPrompt = $this->buildSystemPrompt($schemaPrompt);

            // 记录发送给 LLM 的提示词
            $logData['llm_prompt'] = $systemPrompt . "\n\n用户问题：{$question}";

            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ])
                ->post($this->apiEndpoint, [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $question,
                        ],
                    ],
                    'temperature' => 0.1,
                    'top_p' => 0.95,
                    'max_completion_tokens' => 8192,
                    'response_format' => [
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
                    ],
                ]);

            if (!$response->successful()) {
                $errorMessage = $response->json('error.message') ?? $response->body();
                Log::error('Gemini API 调用失败', [
                    'status' => $response->status(),
                    'error' => $errorMessage,
                ]);

                $logData['error_message'] = "Gemini API 调用失败: {$errorMessage}";
                $logData['execution_time_ms'] = (int) ((microtime(true) - $startTime) * 1000);
                $this->saveLog($logData);

                return [
                    'success' => false,
                    'sql' => null,
                    'error' => "Gemini API 调用失败: {$errorMessage}",
                    'explanation' => null,
                    'model' => $this->model,
                ];
            }

            // 记录 LLM 响应
            $logData['llm_response'] = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

            $result = $this->parseOpenAIResponse($response->json());

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
    protected function buildSystemPrompt(string $schemaPrompt): string {
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

            // 清理可能的控制字符，但保留换行符和制表符（JSON 中合法）
            // 移除其他控制字符（0x00-0x1F，除了 0x09 制表符、0x0A 换行、0x0D 回车）
            $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);

            // 解析 JSON 响应，使用选项忽略 UTF-8 错误
            $jsonData = json_decode($content, true, 512, JSON_INVALID_UTF8_IGNORE);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('API 返回的 JSON 解析失败', [
                    'error' => json_last_error_msg(),
                    'content' => mb_substr($content, 0, 500), // 只记录前 500 个字符
                    'content_length' => strlen($content),
                ]);

                return [
                    'success' => false,
                    'sql' => null,
                    'error' => 'API 返回的 JSON 格式不正确: ' . json_last_error_msg() . '。请尝试简化您的问题。',
                    'explanation' => null,
                ];
            }

            // 检查 LLM 返回的 error 字段
            if (!empty($jsonData['error'])) {
                $llmError = trim($jsonData['error']);
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
            if (empty($jsonData['sql'])) {
                Log::warning('API 返回的响应中缺少 SQL 字段', [
                    'json_data' => $jsonData,
                ]);

                return [
                    'success' => false,
                    'sql' => null,
                    'error' => 'API 返回的响应中缺少 SQL 字段。请尝试重新表述您的问题。',
                    'explanation' => null,
                ];
            }

            $sql = trim($jsonData['sql']);
            $explanation = trim($jsonData['explanation'] ?? '');

            return [
                'success' => true,
                'sql' => $sql,
                'error' => null,
                'explanation' => $explanation ?: null,
            ];

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
}
