<?php

namespace Tests\Unit;

use App\Support\ResponsesApiAdapter;
use PHPUnit\Framework\TestCase;

class ResponsesApiAdapterTest extends TestCase {
    public function test_endpoint_detection_only_matches_responses_path_suffix() {
        $this->assertTrue(ResponsesApiAdapter::isResponsesEndpoint('https://h.example/ais-openai-direct/v2/responses'));
        $this->assertTrue(ResponsesApiAdapter::isResponsesEndpoint('https://h.example/v1/responses/'));
        $this->assertTrue(ResponsesApiAdapter::isResponsesEndpoint('https://h.example/v1/responses?api-version=1'));
        $this->assertFalse(ResponsesApiAdapter::isResponsesEndpoint('https://h.example/v2/chat/completions'));
        $this->assertFalse(ResponsesApiAdapter::isResponsesEndpoint('https://h.example/v1/responses-proxy'));
        $this->assertFalse(ResponsesApiAdapter::isResponsesEndpoint('https://h.example/chat/completions?x=/responses'));
    }

    public function test_non_responses_endpoint_passes_request_and_response_through_untouched() {
        $request = ['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'hi']], 'temperature' => 0.1];
        $json = ['choices' => [['message' => ['content' => 'x']]]];

        $this->assertSame($request, ResponsesApiAdapter::prepareRequest('https://h.example/chat/completions', $request));
        $this->assertSame($json, ResponsesApiAdapter::normalizeResponse('https://h.example/chat/completions', $json));
        $this->assertNull(ResponsesApiAdapter::normalizeResponse('https://h.example/v1/responses', null));
    }

    public function test_request_conversion_sets_low_effort_and_drops_sampling_params() {
        $request = ResponsesApiAdapter::toRequest([
            'model' => 'gpt-6-luna',
            'messages' => [
                ['role' => 'system', 'content' => 'sys'],
                ['role' => 'user', 'content' => 'q'],
            ],
            'temperature' => 0.1,
            'top_p' => 0.95,
            'max_completion_tokens' => 4096,
        ]);

        $this->assertSame('gpt-6-luna', $request['model']);
        $this->assertSame(['effort' => 'low'], $request['reasoning']);
        $this->assertSame(4096, $request['max_output_tokens']);
        $this->assertFalse($request['store']);
        $this->assertArrayNotHasKey('temperature', $request);
        $this->assertArrayNotHasKey('top_p', $request);
        $this->assertArrayNotHasKey('messages', $request);
        $this->assertSame([
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'q'],
        ], $request['input']);
    }

    public function test_request_conversion_flattens_tools_and_tool_round_trip_messages() {
        $request = ResponsesApiAdapter::toRequest([
            'model' => 'm',
            'messages' => [
                ['role' => 'user', 'content' => 'q'],
                ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'call_1', 'type' => 'function',
                    'function' => ['name' => 'lookup', 'arguments' => '{"a":1}'],
                ]]],
                ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"ok":true}'],
            ],
            'tools' => [['type' => 'function', 'function' => [
                'name' => 'lookup', 'description' => 'd',
                'parameters' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]],
            ]]],
            'tool_choice' => 'auto',
        ]);

        $this->assertSame([
            'type' => 'function', 'name' => 'lookup', 'description' => 'd',
            'parameters' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]],
            'strict' => false,
        ], $request['tools'][0]);
        $this->assertSame('auto', $request['tool_choice']);
        $this->assertSame([
            ['role' => 'user', 'content' => 'q'],
            ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'lookup', 'arguments' => '{"a":1}'],
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"ok":true}'],
        ], $request['input']);
    }

    public function test_request_conversion_keeps_assistant_text_alongside_tool_calls() {
        $request = ResponsesApiAdapter::toRequest([
            'model' => 'm',
            'messages' => [['role' => 'assistant', 'content' => 'thinking', 'tool_calls' => [[
                'id' => 'c', 'type' => 'function', 'function' => ['name' => 'f', 'arguments' => '{}'],
            ]]]],
        ]);

        $this->assertSame(['role' => 'assistant', 'content' => 'thinking'], $request['input'][0]);
        $this->assertSame('function_call', $request['input'][1]['type']);
    }

    public function test_request_conversion_maps_json_schema_response_format() {
        $request = ResponsesApiAdapter::toRequest([
            'model' => 'm',
            'messages' => [['role' => 'user', 'content' => 'q']],
            'response_format' => ['type' => 'json_schema', 'json_schema' => [
                'name' => 'sql_query_response', 'strict' => true,
                'schema' => ['type' => 'object', 'properties' => []],
            ]],
        ]);

        $this->assertSame([
            'type' => 'json_schema', 'name' => 'sql_query_response',
            'schema' => ['type' => 'object', 'properties' => []], 'strict' => true,
        ], $request['text']['format']);
    }

    public function test_request_conversion_maps_named_tool_choice_to_responses_shape() {
        $request = ResponsesApiAdapter::toRequest([
            'model' => 'm',
            'messages' => [['role' => 'user', 'content' => 'q']],
            'tool_choice' => ['type' => 'function', 'function' => ['name' => 'lookup']],
        ]);

        $this->assertSame(['type' => 'function', 'name' => 'lookup'], $request['tool_choice']);
    }

    public function test_response_conversion_text_message() {
        $chat = ResponsesApiAdapter::toChatResponse([
            'id' => 'resp_1', 'model' => 'gpt-6-luna', 'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"sql":"x"}']]],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ]);

        $this->assertSame('{"sql":"x"}', $chat['choices'][0]['message']['content']);
        $this->assertArrayNotHasKey('tool_calls', $chat['choices'][0]['message']);
        $this->assertSame('stop', $chat['choices'][0]['finish_reason']);
        $this->assertSame(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15], $chat['usage']);
    }

    public function test_response_conversion_function_calls_become_tool_calls() {
        $chat = ResponsesApiAdapter::toChatResponse([
            'status' => 'completed',
            'output' => [
                ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'lookup', 'arguments' => '{"a":1}'],
                ['type' => 'function_call', 'id' => 'fc_2', 'call_id' => 'call_2', 'name' => 'other', 'arguments' => '{}'],
            ],
        ]);

        $message = $chat['choices'][0]['message'];
        $this->assertNull($message['content']);
        $this->assertSame('call_1', $message['tool_calls'][0]['id']);
        $this->assertSame('lookup', $message['tool_calls'][0]['function']['name']);
        $this->assertSame('{"a":1}', $message['tool_calls'][0]['function']['arguments']);
        $this->assertCount(2, $message['tool_calls']);
        $this->assertSame('tool_calls', $chat['choices'][0]['finish_reason']);
    }

    public function test_response_conversion_incomplete_maps_to_length() {
        $chat = ResponsesApiAdapter::toChatResponse([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [],
        ]);

        $this->assertSame('length', $chat['choices'][0]['finish_reason']);
        $this->assertNull($chat['choices'][0]['message']['content']);
    }

    public function test_error_body_is_returned_untouched() {
        $error = ['error' => ['message' => 'boom']];

        $this->assertSame($error, ResponsesApiAdapter::toChatResponse($error));
    }

    public function test_http_200_with_failed_status_is_returned_untouched_so_callers_treat_it_as_failure() {
        $failed = ['status' => 'failed', 'error' => ['message' => 'upstream'], 'output' => []];
        $cancelled = ['status' => 'cancelled', 'output' => []];

        $this->assertSame($failed, ResponsesApiAdapter::toChatResponse($failed));
        $this->assertArrayNotHasKey('choices', ResponsesApiAdapter::toChatResponse($cancelled));
        $this->assertSame('upstream', ResponsesApiAdapter::responseFailureMessage($failed));
        $this->assertNotNull(ResponsesApiAdapter::responseFailureMessage($cancelled));
        $this->assertNotNull(ResponsesApiAdapter::responseFailureMessage([
            'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'],
        ]));
    }

    /**
     * Responses API 的函式工具預設 strict=true，現有工具定義不滿足 strict，
     * 每個轉出的工具都必須明確 strict=false，否則整個請求 400。
     */
    public function test_every_real_nl_query_tool_is_converted_with_strict_false() {
        $config = require __DIR__ . '/../../config/nl_query_tools.php';
        $tools = $config['tools'];
        $this->assertNotEmpty($tools);

        $request = ResponsesApiAdapter::toRequest([
            'model' => 'm',
            'messages' => [['role' => 'user', 'content' => 'q']],
            'tools' => $tools,
            'tool_choice' => 'auto',
        ]);

        $this->assertCount(count($tools), $request['tools']);
        foreach ($request['tools'] as $i => $tool) {
            $this->assertSame('function', $tool['type']);
            $this->assertSame($tools[$i]['function']['name'], $tool['name']);
            $this->assertFalse($tool['strict'], $tool['name'] . ' 必須 strict=false');
            $this->assertSame($tools[$i]['function']['parameters'], $tool['parameters']);
        }
    }

    public function test_empty_tool_call_ids_from_a_gemini_fallback_history_are_synthesized_and_paired() {
        $request = ResponsesApiAdapter::toRequest([
            'model' => 'm',
            'messages' => [
                ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                    ['id' => '', 'type' => 'function', 'function' => ['name' => 'a', 'arguments' => '{}']],
                    ['type' => 'function', 'function' => ['name' => 'b', 'arguments' => '{}']],
                ]],
                ['role' => 'tool', 'tool_call_id' => '', 'content' => 'ra'],
                ['role' => 'tool', 'tool_call_id' => '', 'content' => 'rb'],
            ],
        ]);

        [$callA, $callB, $outA, $outB] = $request['input'];
        $this->assertNotSame('', $callA['call_id']);
        $this->assertNotSame($callA['call_id'], $callB['call_id']);
        $this->assertSame($callA['call_id'], $outA['call_id']);
        $this->assertSame($callB['call_id'], $outB['call_id']);
    }

    public function test_incomplete_response_drops_possibly_truncated_tool_calls() {
        $chat = ResponsesApiAdapter::toChatResponse([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [
                ['type' => 'function_call', 'call_id' => 'c1', 'name' => 'f', 'arguments' => '{"a":'],
            ],
        ]);

        $this->assertSame('length', $chat['choices'][0]['finish_reason']);
        $this->assertArrayNotHasKey('tool_calls', $chat['choices'][0]['message']);
    }

    public function test_refusal_is_reported_as_content_filter() {
        $chat = ResponsesApiAdapter::toChatResponse([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'no']]]],
        ]);

        $this->assertSame('content_filter', $chat['choices'][0]['finish_reason']);
        $this->assertSame('no', $chat['choices'][0]['message']['content']);
    }
}
