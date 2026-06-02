@extends('layouts.dashboard-v3')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-history mr-1"></i> {{ $page_title }}</h3>
                <div class="card-tools">
                    <a href="{{ route('app.query-playground.index') }}" class="btn btn-sm btn-primary">
                        <i class="fas fa-arrow-left"></i> {{ __('query.log_back_link') }}
                    </a>
                </div>
            </div>

            <div class="card-body">
                <!-- 搜尋與篩選 -->
                <form method="GET" action="{{ route('query-playground.nl-query-logs') }}" class="mb-3">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="search">{{ __('query.log_keyword_search') }}</label>
                                <input type="text" class="form-control" id="search" name="search"
                                       value="{{ $filters['search'] ?? '' }}"
                                       placeholder="{{ __('query.log_search_placeholder') }}">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="success">{{ __('operations.status_label') }}</label>
                                <select class="form-control" id="success" name="success">
                                    <option value="">{{ __('query.log_status_all') }}</option>
                                    <option value="1" {{ ($filters['success'] ?? '') === '1' ? 'selected' : '' }}>{{ __('query.log_status_success') }}</option>
                                    <option value="0" {{ ($filters['success'] ?? '') === '0' ? 'selected' : '' }}>{{ __('query.log_status_failure') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="user_id">{{ __('query.log_user_label') }}</label>
                                <select class="form-control" id="user_id" name="user_id">
                                    <option value="">{{ __('query.log_all_users') }}</option>
                                    @foreach($users as $user)
                                        <option value="{{ $user->id }}" {{ ($filters['user_id'] ?? '') == $user->id ? 'selected' : '' }}>
                                            {{ $user->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary btn-block">
                                        <i class="fas fa-search"></i> {{ __('common.search') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    @if($filters['search'] || $filters['success'] !== null || $filters['user_id'])
                        <div class="row">
                            <div class="col-12">
                                <a href="{{ route('query-playground.nl-query-logs') }}" class="btn btn-sm btn-secondary">
                                    <i class="fas fa-times"></i> {{ __('operations.clear_filter') }}
                                </a>
                            </div>
                        </div>
                    @endif
                </form>

                <!-- 統計信息 -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    {{ __('query.log_record_count', ['total' => $logs->total(), 'first' => $logs->firstItem() ?? 0, 'last' => $logs->lastItem() ?? 0]) }}
                </div>

                <!-- 日誌列表 -->
                @if($logs->isEmpty())
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> {{ __('query.log_no_records') }}
                    </div>
                @else
                    @foreach($logs as $log)
                        <div class="card mb-3 {{ $log->success ? 'border-success' : 'border-danger' }}">
                            <div class="card-header {{ $log->success ? 'bg-success' : 'bg-danger' }}">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>#{{ $log->id }}</strong>
                                        <span class="ml-2">
                                            <i class="fas fa-user"></i>
                                            {{ $log->user_name ?? __('common.unknown') }}
                                            @if($log->user_email)
                                                <small class="text-muted">({{ $log->user_email }})</small>
                                            @endif
                                        </span>
                                        <span class="ml-2">
                                            <i class="fas fa-clock"></i>
                                            {{ $log->created_at }}
                                        </span>
                                        @if($log->execution_time_ms)
                                            <span class="ml-2">
                                                <i class="fas fa-stopwatch"></i>
                                                {{ $log->execution_time_ms }}ms
                                            </span>
                                        @endif
                                    </div>
                                    <span class="badge badge-light">
                                        {{ $log->success ? __('query.log_status_success') : __('query.log_status_failure') }}
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- 用戶問題 -->
                                <div class="mb-3">
                                    <h6><i class="fas fa-question-circle text-primary"></i> {{ __('query.log_user_question') }}：</h6>
                                    <div class="alert alert-light mb-2">
                                        {{ $log->question }}
                                    </div>
                                </div>

                                @if($log->success)
                                    <!-- 生成的 SQL -->
                                    @if($log->generated_sql)
                                        <div class="mb-3">
                                            <h6><i class="fas fa-code text-success"></i> {{ __('query.log_generated_sql') }}：</h6>
                                            <pre class="bg-light p-2 border rounded" style="max-height: 200px; overflow-y: auto;"><code>{{ $log->generated_sql }}</code></pre>
                                        </div>
                                    @endif

                                    <!-- 說明 -->
                                    @if($log->explanation)
                                        <div class="mb-3">
                                            <h6><i class="fas fa-info-circle text-info"></i> {{ __('query.log_explanation') }}：</h6>
                                            <p class="text-muted">{{ $log->explanation }}</p>
                                        </div>
                                    @endif
                                @else
                                    <!-- 錯誤信息 -->
                                    @if($log->error_message)
                                        <div class="mb-3">
                                            <h6><i class="fas fa-exclamation-triangle text-danger"></i> {{ __('query.log_error_message') }}：</h6>
                                            <div class="alert alert-danger">
                                                {{ $log->error_message }}
                                            </div>
                                        </div>
                                    @endif
                                @endif

                                <!-- LLM Prompt（折疊） -->
                                @if($log->llm_prompt)
                                    <div class="mb-3">
                                        <h6>
                                            <a class="collapsed" data-toggle="collapse" href="#prompt-{{ $log->id }}" role="button">
                                                <i class="fas fa-chevron-right"></i> {{ __('query.log_llm_prompt') }}
                                                <small class="text-muted">({{ strlen($log->llm_prompt) }} {{ __('query.log_chars') }})</small>
                                            </a>
                                        </h6>
                                        <div class="collapse" id="prompt-{{ $log->id }}">
                                            <pre class="bg-light p-2 border rounded" style="max-height: 400px; overflow-y: auto; font-size: 0.85em;">{{ $log->llm_prompt }}</pre>
                                        </div>
                                    </div>
                                @endif

                                <!-- LLM Response（折疊並美化） -->
                                @if($log->llm_response)
                                    <div class="mb-3">
                                        <h6>
                                            <a class="collapsed" data-toggle="collapse" href="#response-{{ $log->id }}" role="button">
                                                <i class="fas fa-chevron-right"></i> {{ __('query.log_llm_response') }}
                                                <small class="text-muted">({{ strlen($log->llm_response) }} {{ __('query.log_chars') }})</small>
                                            </a>
                                        </h6>
                                        <div class="collapse" id="response-{{ $log->id }}">
                                            <div class="bg-light p-2 border rounded" style="max-height: 500px; overflow-y: auto;">
                                                <pre class="mb-0" style="font-size: 0.85em;"><code class="language-json">{{ $log->llm_response }}</code></pre>
                                            </div>

                                            <!-- 解析並顯示關鍵信息 -->
                                            @php
                                                $responseData = json_decode($log->llm_response, true);
                                                $isRoundsFormat = isset($responseData['rounds']);
                                            @endphp
                                            @if($responseData)
                                                <div class="mt-2">
                                                    <h6 class="text-muted">{{ __('query.log_key_info') }}：</h6>

                                                    @if($isRoundsFormat)
                                                        {{-- 新格式：rounds 數組 --}}
                                                        <div class="alert alert-info">
                                                            <strong>{{ __('query.log_total_rounds') }}：</strong>{{ $responseData['total_rounds'] ?? count($responseData['rounds']) }} {{ __('query.log_rounds_suffix') }}
                                                        </div>

                                                        @foreach($responseData['rounds'] ?? [] as $roundIndex => $round)
                                                            <div class="card mb-2">
                                                                <div class="card-header bg-light">
                                                                    <strong>{{ __('query.log_round_call', ['round' => $round['round'] ?? ($roundIndex + 1)]) }}</strong>
                                                                    @if(!empty($round['tool_calls_requested']))
                                                                        <span class="badge badge-primary ml-2">{{ __('query.log_tools_requested', ['count' => count($round['tool_calls_requested'])]) }}</span>
                                                                    @endif
                                                                </div>
                                                                <div class="card-body p-2">
                                                                    @php
                                                                        $roundResponse = $round['llm_response'] ?? [];
                                                                    @endphp

                                                                    <table class="table table-sm table-bordered mb-0">
                                                                        <tbody>
                                                                            @if(isset($roundResponse['model']))
                                                                                <tr>
                                                                                    <th style="width: 150px;">{{ __('query.log_model') }}</th>
                                                                                    <td><code>{{ $roundResponse['model'] }}</code></td>
                                                                                </tr>
                                                                            @endif

                                                                                    @if(isset($roundResponse['usage']))
                                                                                        <tr>
                                                                                            <th>{{ __('query.log_token_usage') }}</th>
                                                                                            <td>
                                                                                                <strong>{{ __('query.log_token_prompt') }}：</strong>{{ number_format($roundResponse['usage']['prompt_tokens'] ?? 0) }}<br>
                                                                                                <strong>{{ __('query.log_token_response') }}：</strong>{{ number_format($roundResponse['usage']['completion_tokens'] ?? 0) }}<br>
                                                                                                <strong>{{ __('query.log_token_total') }}：</strong>{{ number_format($roundResponse['usage']['total_tokens'] ?? 0) }}
                                                                                                @if(isset($roundResponse['usage']['prompt_tokens_details']['cached_tokens']))
                                                                                                    <br><strong class="text-info">{{ __('query.log_token_cached') }}：</strong>{{ number_format($roundResponse['usage']['prompt_tokens_details']['cached_tokens']) }}
                                                                                                @endif
                                                                                            </td>
                                                                                        </tr>
                                                                                    @endif

                                                                            @if(isset($roundResponse['choices'][0]['finish_reason']))
                                                                                <tr>
                                                                                    <th>{{ __('query.log_finish_reason') }}</th>
                                                                                    <td><code>{{ $roundResponse['choices'][0]['finish_reason'] }}</code></td>
                                                                                </tr>
                                                                            @endif

                                                                            @if(!empty($round['tool_results']))
                                                                                <tr>
                                                                                    <th>{{ __('query.log_tool_results') }}</th>
                                                                                    <td>
                                                                                        @foreach($round['tool_results'] as $toolResult)
                                                                                            <div class="mb-1">
                                                                                                <strong>{{ $toolResult['tool_name'] ?? __('query.log_unknown_tool') }}</strong>
                                                                                                @if($toolResult['result']['success'] ?? false)
                                                                                                    <span class="badge badge-success">{{ __('query.log_status_success') }}</span>
                                                                                                    @if(isset($toolResult['result']['data']))
                                                                                                        @php
                                                                                                            $toolData = $toolResult['result']['data'];
                                                                                                            $toolRows = is_array($toolData['rows'] ?? null) ? $toolData['rows'] : (is_array($toolData) ? $toolData : []);
                                                                                                            $toolDataCount = is_numeric($toolData['returned_rows'] ?? null) ? (int) $toolData['returned_rows'] : count($toolRows);
                                                                                                        @endphp
                                                                                                        <small class="text-muted">({{ __('query.log_data_rows', ['count' => $toolDataCount]) }})</small>
                                                                                                    @endif
                                                                                                @else
                                                                                                    <span class="badge badge-danger">{{ __('query.log_status_failure') }}</span>
                                                                                                    <small class="text-danger">{{ $toolResult['result']['error'] ?? __('query.log_unknown_error') }}</small>
                                                                                                @endif
                                                                                            </div>
                                                                                        @endforeach
                                                                                    </td>
                                                                                </tr>
                                                                            @endif
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    @else
                                                        {{-- 舊格式：直接顯示原始響應信息 --}}
                                                        <table class="table table-sm table-bordered">
                                                            <tbody>
                                                                {{-- OpenAI 格式：model --}}
                                                                @if(isset($responseData['model']))
                                                                <tr>
                                                                    <th style="width: 150px;">{{ __('query.log_model') }}</th>
                                                                    <td><code>{{ $responseData['model'] }}</code></td>
                                                                </tr>
                                                            @endif

                                                            {{-- Gemini 格式：modelVersion --}}
                                                            @if(isset($responseData['modelVersion']))
                                                                <tr>
                                                                    <th>{{ __('query.log_model_version') }}</th>
                                                                    <td><code>{{ $responseData['modelVersion'] }}</code></td>
                                                                </tr>
                                                            @endif

                                                            {{-- OpenAI 格式：object --}}
                                                            @if(isset($responseData['object']))
                                                                <tr>
                                                                    <th>{{ __('query.log_object_type') }}</th>
                                                                    <td><code>{{ $responseData['object'] }}</code></td>
                                                                </tr>
                                                            @endif

                                                            {{-- OpenAI 格式：id / Gemini 格式：responseId --}}
                                                            @if(isset($responseData['id']))
                                                                <tr>
                                                                    <th>{{ __('query.log_response_id') }}</th>
                                                                    <td><small><code>{{ $responseData['id'] }}</code></small></td>
                                                                </tr>
                                                            @elseif(isset($responseData['responseId']))
                                                                <tr>
                                                                    <th>{{ __('query.log_response_id') }}</th>
                                                                    <td><small><code>{{ $responseData['responseId'] }}</code></small></td>
                                                                </tr>
                                                            @endif

                                                            {{-- OpenAI 格式：created --}}
                                                            @if(isset($responseData['created']))
                                                                <tr>
                                                                    <th>{{ __('query.log_created_time') }}</th>
                                                                    <td>
                                                                        {{ date('Y-m-d H:i:s', $responseData['created']) }}
                                                                        <small class="text-muted">(Unix: {{ $responseData['created'] }})</small>
                                                                    </td>
                                                                </tr>
                                                            @endif

                                                            {{-- Finish Reason --}}
                                                            @if(isset($responseData['choices'][0]['finish_reason']))
                                                                <tr>
                                                                    <th>{{ __('query.log_finish_reason') }}</th>
                                                                    <td><code>{{ $responseData['choices'][0]['finish_reason'] }}</code></td>
                                                                </tr>
                                                            @endif
                                                            @if(isset($responseData['choices'][0]['finishReason']))
                                                                <tr>
                                                                    <th>{{ __('query.log_finish_reason') }} (Gemini)</th>
                                                                    <td><code>{{ $responseData['choices'][0]['finishReason'] }}</code></td>
                                                                </tr>
                                                            @endif

                                                            {{-- Choice Index --}}
                                                            @if(isset($responseData['choices'][0]['index']))
                                                                <tr>
                                                                    <th>Choice Index</th>
                                                                    <td><code>{{ $responseData['choices'][0]['index'] }}</code></td>
                                                                </tr>
                                                            @endif

                                                            {{-- Message Role --}}
                                                            @if(isset($responseData['choices'][0]['message']['role']))
                                                                <tr>
                                                                    <th>{{ __('query.log_role') }}</th>
                                                                    <td><code>{{ $responseData['choices'][0]['message']['role'] }}</code></td>
                                                                </tr>
                                                            @endif

                                                            {{-- OpenAI 格式：usage --}}
                                                            @if(isset($responseData['usage']))
                                                                <tr>
                                                                    <th>{{ __('query.log_token_usage') }}</th>
                                                                    <td>
                                                                        <strong>{{ __('query.log_token_prompt') }}：</strong>{{ number_format($responseData['usage']['prompt_tokens'] ?? 0) }}<br>
                                                                        <strong>{{ __('query.log_token_response') }}：</strong>{{ number_format($responseData['usage']['completion_tokens'] ?? 0) }}<br>
                                                                        <strong>{{ __('query.log_token_total') }}：</strong>{{ number_format($responseData['usage']['total_tokens'] ?? 0) }}
                                                                        @if(isset($responseData['usage']['prompt_tokens_details']['cached_tokens']))
                                                                            <br><strong class="text-info">{{ __('query.log_token_cached') }}：</strong>{{ number_format($responseData['usage']['prompt_tokens_details']['cached_tokens']) }}
                                                                        @endif
                                                                    </td>
                                                                </tr>
                                                            @endif

                                                            {{-- Gemini 格式：usageMetadata --}}
                                                            @if(isset($responseData['usageMetadata']))
                                                                <tr>
                                                                    <th>{{ __('query.log_token_usage') }} (Gemini)</th>
                                                                    <td>
                                                                        <strong>{{ __('query.log_token_prompt') }}：</strong>{{ number_format($responseData['usageMetadata']['promptTokenCount'] ?? 0) }}<br>
                                                                        <strong>{{ __('query.log_token_candidates') }}：</strong>{{ number_format($responseData['usageMetadata']['candidatesTokenCount'] ?? 0) }}<br>
                                                                        <strong>{{ __('query.log_token_total') }}：</strong>{{ number_format($responseData['usageMetadata']['totalTokenCount'] ?? 0) }}
                                                                        @if(isset($responseData['usageMetadata']['thoughtsTokenCount']))
                                                                            <br><strong class="text-info">{{ __('query.log_token_thoughts') }}：</strong>{{ number_format($responseData['usageMetadata']['thoughtsTokenCount']) }}
                                                                        @endif
                                                                    </td>
                                                                </tr>
                                                            @endif

                                                            {{-- Extra Content (thought_signature 已被過濾，不顯示) --}}
                                                            @if(isset($responseData['choices'][0]['message']['extra_content']) && !empty($responseData['choices'][0]['message']['extra_content']))
                                                                @php
                                                                    $extraContent = $responseData['choices'][0]['message']['extra_content'];
                                                                    if (isset($extraContent['google']['thought_signature'])) {
                                                                        unset($extraContent['google']['thought_signature']);
                                                                    }
                                                                    if (isset($extraContent['google']) && empty($extraContent['google'])) {
                                                                        unset($extraContent['google']);
                                                                    }
                                                                @endphp
                                                                @if(!empty($extraContent))
                                                                    <tr>
                                                                        <th>{{ __('query.log_extra_content') }}</th>
                                                                        <td><pre class="mb-0" style="font-size: 0.8em;">{{ json_encode($extraContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></td>
                                                                    </tr>
                                                                @endif
                                                            @endif
                                                        </tbody>
                                                    </table>
                                                    @endif {{-- 結束 @else (舊格式) --}}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    <!-- 分頁 -->
                    <div class="d-flex justify-content-center">
                        {{ $logs->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@section('js')
<script>
onViteReady(function() {
    // 折疊展開時切換圖標
    $('.collapse').on('show.bs.collapse', function() {
        $(this).prev().find('.fas').removeClass('fa-chevron-right').addClass('fa-chevron-down');
    }).on('hide.bs.collapse', function() {
        $(this).prev().find('.fas').removeClass('fa-chevron-down').addClass('fa-chevron-right');
    });
});
</script>
@endsection
