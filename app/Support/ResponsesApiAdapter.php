<?php

namespace App\Support;

/**
 * Chat Completions ⇄ Responses API（/v1/responses）轉接器。
 *
 * 背景：HUIT 網關上的 gpt-6-luna／terra 預設帶推理強度，而 /v1/chat/completions
 * 在「函式工具 + reasoning_effort」並用時一律回 400（只有 reasoning_effort=none 能過）。
 * 要在帶工具時保留 reasoning_effort=low，必須改走 /v1/responses。
 *
 * 是否啟用只看目前端點（GEMINI_API_ENDPOINT 或備援的 _1 端點）是否以 /responses 結尾，
 * 不新增任何 env；其餘端點（例如 Gemini 的 chat/completions）完全不受影響。
 *
 * 為了不動各服務內所有以 choices[0].message 為準的呼叫端，
 * 這裡把請求轉成 Responses 格式、再把回應轉回 chat-completions 形狀。
 *
 * 已知限制：不回傳 reasoning item（無狀態、store=false），多輪工具呼叫之間推理脈絡不延續。
 */
final class ResponsesApiAdapter {
    /** 走 Responses API 時固定使用的推理強度。 */
    public const REASONING_EFFORT = 'low';

    public static function isResponsesEndpoint(string $endpoint): bool {
        $path = parse_url($endpoint, PHP_URL_PATH);

        return is_string($path) && str_ends_with(rtrim($path, '/'), '/responses');
    }

    /** 依端點決定是否轉換請求；非 /responses 端點原樣返回。 */
    public static function prepareRequest(string $endpoint, array $chatRequest): array {
        return self::isResponsesEndpoint($endpoint)
            ? self::toRequest($chatRequest)
            : $chatRequest;
    }

    /** 依端點決定是否轉換回應；非 /responses 端點或非陣列原樣返回。 */
    public static function normalizeResponse(string $endpoint, mixed $json): mixed {
        return (is_array($json) && self::isResponsesEndpoint($endpoint))
            ? self::toChatResponse($json)
            : $json;
    }

    public static function responseFailureMessage(mixed $json): ?string {
        if (!is_array($json)) {
            return null;
        }

        $status = $json['status'] ?? null;
        if (in_array($status, ['failed', 'cancelled', 'incomplete'], true) || !empty($json['error'])) {
            $message = $json['error']['message'] ?? null;
            if (is_string($message) && $message !== '') {
                return $message;
            }

            $reason = $json['incomplete_details']['reason'] ?? $status;

            return 'Responses API 回應未完成（' . (string) $reason . '）';
        }

        return null;
    }

    /**
     * @param array $chat chat-completions 請求體
     */
    public static function toRequest(array $chat): array {
        $request = [
            'model' => $chat['model'] ?? null,
            'input' => self::convertMessages($chat['messages'] ?? []),
            'store' => false,
            // 啟用推理時推理模型不接受 temperature／top_p，故不轉送。
            'reasoning' => ['effort' => self::REASONING_EFFORT],
        ];

        if (isset($chat['max_completion_tokens'])) {
            // 推理 token 也算在內，上限沿用呼叫端給的值。
            $request['max_output_tokens'] = $chat['max_completion_tokens'];
        }

        if (!empty($chat['tools'])) {
            $request['tools'] = array_map(static function (array $tool): array {
                $fn = $tool['function'] ?? $tool;

                return array_filter([
                    'type' => 'function',
                    'name' => $fn['name'] ?? '',
                    'description' => $fn['description'] ?? null,
                    'parameters' => $fn['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                    // Responses API 的函式工具預設 strict=true（要求 additionalProperties:false 且全欄位 required），
                    // chat/completions 預設 false；現有工具定義不滿足 strict，必須明確關掉，否則整個請求 400。
                    'strict' => $fn['strict'] ?? false,
                ], static fn ($v) => $v !== null);
            }, $chat['tools']);

        }

        if (isset($chat['tool_choice'])) {
            $choice = $chat['tool_choice'];
            if (is_array($choice) && isset($choice['function']['name'])) {
                $choice = ['type' => 'function', 'name' => $choice['function']['name']];
            }
            $request['tool_choice'] = $choice;
        }

        $format = $chat['response_format'] ?? null;
        if (is_array($format) && ($format['type'] ?? null) === 'json_schema' && isset($format['json_schema'])) {
            $schema = $format['json_schema'];
            $request['text'] = ['format' => array_filter([
                'type' => 'json_schema',
                'name' => $schema['name'] ?? 'response',
                'schema' => $schema['schema'] ?? null,
                'strict' => $schema['strict'] ?? null,
            ], static fn ($v) => $v !== null)];
        }

        return $request;
    }

    /**
     * @param array $responses Responses API 回應 JSON
     * @return array chat-completions 形狀（choices／usage）；失敗回應（status 為 failed／cancelled，
     *               或帶非空 error）原樣返回，交由呼叫端走失敗路徑
     */
    public static function toChatResponse(array $responses): array {
        if (
            in_array($responses['status'] ?? null, ['failed', 'cancelled'], true)
            || !empty($responses['error'])
        ) {
            return $responses;
        }

        $text = '';
        $refused = false;
        $toolCalls = [];

        foreach ($responses['output'] ?? [] as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'message') {
                foreach ($item['content'] ?? [] as $part) {
                    $partType = $part['type'] ?? '';
                    if ($partType === 'output_text') {
                        $text .= (string) ($part['text'] ?? '');
                    } elseif ($partType === 'refusal') {
                        $refused = true;
                        $text .= (string) ($part['refusal'] ?? '');
                    }
                }
            } elseif ($type === 'function_call') {
                $toolCalls[] = [
                    'id' => (string) ($item['call_id'] ?? $item['id'] ?? ''),
                    'type' => 'function',
                    'function' => [
                        'name' => (string) ($item['name'] ?? ''),
                        'arguments' => (string) ($item['arguments'] ?? '{}'),
                    ],
                ];
            }
        }

        $incomplete = ($responses['status'] ?? 'completed') === 'incomplete';
        if ($incomplete) {
            // 被截斷時 function_call 的 arguments 可能是殘缺 JSON，不可當成完整工具呼叫去執行。
            $toolCalls = [];
        }

        $message = ['role' => 'assistant', 'content' => $text !== '' ? $text : null];
        if ($toolCalls !== []) {
            $message['tool_calls'] = $toolCalls;
        }

        if ($incomplete) {
            $reason = $responses['incomplete_details']['reason'] ?? '';
            $finish = $reason === 'content_filter' ? 'content_filter' : 'length';
        } elseif ($toolCalls !== []) {
            $finish = 'tool_calls';
        } elseif ($refused) {
            $finish = 'content_filter';
        } else {
            $finish = 'stop';
        }

        $usage = $responses['usage'] ?? [];
        $prompt = (int) ($usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['output_tokens'] ?? 0);

        return [
            'id' => $responses['id'] ?? null,
            'model' => $responses['model'] ?? null,
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]],
            'usage' => [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'total_tokens' => (int) ($usage['total_tokens'] ?? ($prompt + $completion)),
            ],
        ];
    }

    private static function convertMessages(array $messages): array {
        $input = [];
        // Gemini 的 OpenAI 相容層有時不給 tool_call id；備援產生的歷史回到 Responses 主通道時
        // call_id 不可為空，按出現順序合成並讓對應的 tool 訊息取用同一個 id。
        $synthesized = [];
        $counter = 0;

        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = self::flattenContent($message['content'] ?? null);

            if ($role === 'tool') {
                $callId = (string) ($message['tool_call_id'] ?? '');
                if ($callId === '') {
                    $callId = array_shift($synthesized) ?? '';
                }
                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => $callId,
                    'output' => $content,
                ];

                continue;
            }

            if ($role === 'assistant' && !empty($message['tool_calls'])) {
                if ($content !== '') {
                    $input[] = ['role' => 'assistant', 'content' => $content];
                }
                foreach ($message['tool_calls'] as $call) {
                    $callId = (string) ($call['id'] ?? '');
                    if ($callId === '') {
                        $callId = 'call_synth_' . (++$counter);
                        $synthesized[] = $callId;
                    }
                    $input[] = [
                        'type' => 'function_call',
                        'call_id' => $callId,
                        'name' => (string) ($call['function']['name'] ?? ''),
                        'arguments' => (string) ($call['function']['arguments'] ?? '{}'),
                    ];
                }

                continue;
            }

            $input[] = ['role' => $role, 'content' => $content];
        }

        return $input;
    }

    private static function flattenContent(mixed $content): string {
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            return implode('', array_map(
                static fn ($part) => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part,
                $content
            ));
        }

        return '';
    }
}
