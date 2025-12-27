<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NaturalLanguageQueryService {
    protected DatabaseSchemaService $schemaService;
    protected string $apiKey;
    protected string $apiEndpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent';

    public function __construct(DatabaseSchemaService $schemaService) {
        $this->schemaService = $schemaService;
        $this->apiKey = config('services.gemini.api_key', '');
    }

    /**
     * 根据自然语言问题生成 SQL 查询
     *
     * @param string $question 用户的自然语言问题
     * @param array|null $tableNames 限制使用的表名（可选）
     * @return array ['success' => bool, 'sql' => string|null, 'error' => string|null, 'explanation' => string|null]
     */
    public function generateSQL(string $question, ?array $tableNames = null): array {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'sql' => null,
                'error' => 'Gemini API Key 未配置。请在 .env 文件中设置 GEMINI_API_KEY。',
                'explanation' => null,
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
            $fullPrompt = $systemPrompt . "\n\n用户问题：{$question}";

            // 记录发送给 LLM 的提示词
            $logData['llm_prompt'] = $fullPrompt;

            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($this->apiEndpoint . '?key=' . $this->apiKey, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $fullPrompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'topK' => 40,
                        'topP' => 0.95,
                        'maxOutputTokens' => 8192, // 增加输出 token 限制以支持复杂查询
                        'responseMimeType' => 'application/json',
                        'responseSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'sql' => [
                                    'type' => 'string',
                                    'description' => 'The SQL SELECT query statement',
                                ],
                                'explanation' => [
                                    'type' => 'string',
                                    'description' => 'A brief explanation of what the query does (1-2 sentences in Traditional Chinese)',
                                ],
                            ],
                            'required' => ['sql', 'explanation'],
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
                ];
            }

            // 记录 LLM 响应
            $logData['llm_response'] = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

            $result = $this->parseGeminiResponse($response->json());

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
5. 返回格式为 JSON，包含：
   - sql: SQL 查询语句（纯文本，不需要代码块标记）
   - explanation: 简短的查询解释（一到两句话，使用繁体中文）

**可用的数据库表结构：**

{$schemaPrompt}

**示例：**
用户问题：显示所有朝代名称
返回 JSON：
{
  "sql": "SELECT c_dy FROM DYNASTIES",
  "explanation": "此查询从 DYNASTIES 表中选择所有朝代名称字段。"
}
PROMPT;
    }

    /**
     * 解析 Gemini API 响应（使用 structured output）
     *
     * @param array $responseData
     * @return array
     */
    protected function parseGeminiResponse(array $responseData): array {
        try {
            // 从 structured output 中获取 JSON 文本
            $text = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if (!$text) {
                return [
                    'success' => false,
                    'sql' => null,
                    'error' => 'Gemini API 返回的响应格式不正确',
                    'explanation' => null,
                ];
            }

            // 清理可能的控制字符，但保留换行符和制表符（JSON 中合法）
            // 移除其他控制字符（0x00-0x1F，除了 0x09 制表符、0x0A 换行、0x0D 回车）
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

            // 解析 JSON 响应，使用选项忽略 UTF-8 错误
            $jsonData = json_decode($text, true, 512, JSON_INVALID_UTF8_IGNORE);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Gemini API 返回的 JSON 解析失败', [
                    'error' => json_last_error_msg(),
                    'text' => mb_substr($text, 0, 500), // 只记录前 500 个字符
                    'text_length' => strlen($text),
                ]);

                return [
                    'success' => false,
                    'sql' => null,
                    'error' => 'Gemini API 返回的 JSON 格式不正确: ' . json_last_error_msg() . '。请尝试简化您的问题。',
                    'explanation' => null,
                ];
            }

            // 验证必需字段
            if (empty($jsonData['sql'])) {
                Log::warning('Gemini API 返回的响应中缺少 SQL 字段', [
                    'json_data' => $jsonData,
                ]);

                return [
                    'success' => false,
                    'sql' => null,
                    'error' => 'Gemini API 返回的响应中缺少 SQL 字段。请尝试重新表述您的问题。',
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
            Log::error('解析 Gemini 响应时出错', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'sql' => null,
                'error' => '解析 Gemini 响应时出错: ' . $e->getMessage(),
                'explanation' => null,
            ];
        }
    }
}
