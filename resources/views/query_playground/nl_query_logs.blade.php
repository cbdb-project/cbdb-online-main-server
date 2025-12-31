@extends('layouts.dashboard-v3')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-history mr-1"></i> {{ $page_title }}</h3>
                <div class="card-tools">
                    <a href="{{ route('query-playground.index') }}" class="btn btn-sm btn-primary">
                        <i class="fas fa-arrow-left"></i> 返回查詢練習場
                    </a>
                </div>
            </div>

            <div class="card-body">
                <!-- 搜尋與篩選 -->
                <form method="GET" action="{{ route('query-playground.nl-query-logs') }}" class="mb-3">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="search">關鍵字搜尋</label>
                                <input type="text" class="form-control" id="search" name="search"
                                       value="{{ $filters['search'] ?? '' }}"
                                       placeholder="搜尋問題、SQL、用戶名稱或郵箱">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="success">狀態</label>
                                <select class="form-control" id="success" name="success">
                                    <option value="">全部</option>
                                    <option value="1" {{ ($filters['success'] ?? '') === '1' ? 'selected' : '' }}>成功</option>
                                    <option value="0" {{ ($filters['success'] ?? '') === '0' ? 'selected' : '' }}>失敗</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="user_id">用戶</label>
                                <select class="form-control" id="user_id" name="user_id">
                                    <option value="">全部用戶</option>
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
                                        <i class="fas fa-search"></i> 搜尋
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    @if($filters['search'] || $filters['success'] !== null || $filters['user_id'])
                        <div class="row">
                            <div class="col-12">
                                <a href="{{ route('query-playground.nl-query-logs') }}" class="btn btn-sm btn-secondary">
                                    <i class="fas fa-times"></i> 清除篩選
                                </a>
                            </div>
                        </div>
                    @endif
                </form>

                <!-- 統計信息 -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    共 {{ $logs->total() }} 筆記錄，顯示第 {{ $logs->firstItem() ?? 0 }} - {{ $logs->lastItem() ?? 0 }} 筆
                </div>

                <!-- 日誌列表 -->
                @if($logs->isEmpty())
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 暫無記錄
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
                                            {{ $log->user_name ?? '未知用戶' }}
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
                                        {{ $log->success ? '成功' : '失敗' }}
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- 用戶問題 -->
                                <div class="mb-3">
                                    <h6><i class="fas fa-question-circle text-primary"></i> 用戶問題：</h6>
                                    <div class="alert alert-light mb-2">
                                        {{ $log->question }}
                                    </div>
                                </div>

                                @if($log->success)
                                    <!-- 生成的 SQL -->
                                    @if($log->generated_sql)
                                        <div class="mb-3">
                                            <h6><i class="fas fa-code text-success"></i> 生成的 SQL：</h6>
                                            <pre class="bg-light p-2 border rounded" style="max-height: 200px; overflow-y: auto;"><code>{{ $log->generated_sql }}</code></pre>
                                        </div>
                                    @endif

                                    <!-- 說明 -->
                                    @if($log->explanation)
                                        <div class="mb-3">
                                            <h6><i class="fas fa-info-circle text-info"></i> 說明：</h6>
                                            <p class="text-muted">{{ $log->explanation }}</p>
                                        </div>
                                    @endif
                                @else
                                    <!-- 錯誤信息 -->
                                    @if($log->error_message)
                                        <div class="mb-3">
                                            <h6><i class="fas fa-exclamation-triangle text-danger"></i> 錯誤信息：</h6>
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
                                                <i class="fas fa-chevron-right"></i> LLM 提示詞
                                                <small class="text-muted">({{ strlen($log->llm_prompt) }} 字符)</small>
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
                                                <i class="fas fa-chevron-right"></i> LLM 響應
                                                <small class="text-muted">({{ strlen($log->llm_response) }} 字符)</small>
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
                                                    <h6 class="text-muted">關鍵信息：</h6>

                                                    @if($isRoundsFormat)
                                                        {{-- 新格式：rounds 數組 --}}
                                                        <div class="alert alert-info">
                                                            <strong>總輪數：</strong>{{ $responseData['total_rounds'] ?? count($responseData['rounds']) }} 輪
                                                        </div>

                                                        @foreach($responseData['rounds'] ?? [] as $roundIndex => $round)
                                                            <div class="card mb-2">
                                                                <div class="card-header bg-light">
                                                                    <strong>第 {{ $round['round'] ?? ($roundIndex + 1) }} 輪調用</strong>
                                                                    @if(!empty($round['tool_calls_requested']))
                                                                        <span class="badge badge-primary ml-2">請求了 {{ count($round['tool_calls_requested']) }} 個工具</span>
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
                                                                                    <th style="width: 150px;">模型</th>
                                                                                    <td><code>{{ $roundResponse['model'] }}</code></td>
                                                                                </tr>
                                                                            @endif

                                                                                    @if(isset($roundResponse['usage']))
                                                                                        <tr>
                                                                                            <th>Token 使用</th>
                                                                                            <td>
                                                                                                <strong>提示詞：</strong>{{ number_format($roundResponse['usage']['prompt_tokens'] ?? 0) }}<br>
                                                                                                <strong>響應：</strong>{{ number_format($roundResponse['usage']['completion_tokens'] ?? 0) }}<br>
                                                                                                <strong>總計：</strong>{{ number_format($roundResponse['usage']['total_tokens'] ?? 0) }}
                                                                                                @if(isset($roundResponse['usage']['prompt_tokens_details']['cached_tokens']))
                                                                                                    <br><strong class="text-info">快取：</strong>{{ number_format($roundResponse['usage']['prompt_tokens_details']['cached_tokens']) }}
                                                                                                @endif
                                                                                            </td>
                                                                                        </tr>
                                                                                    @endif

                                                                            @if(isset($roundResponse['choices'][0]['finish_reason']))
                                                                                <tr>
                                                                                    <th>完成原因</th>
                                                                                    <td><code>{{ $roundResponse['choices'][0]['finish_reason'] }}</code></td>
                                                                                </tr>
                                                                            @endif

                                                                            @if(!empty($round['tool_results']))
                                                                                <tr>
                                                                                    <th>工具結果</th>
                                                                                    <td>
                                                                                        @foreach($round['tool_results'] as $toolResult)
                                                                                            <div class="mb-1">
                                                                                                <strong>{{ $toolResult['tool_name'] ?? '未知工具' }}</strong>
                                                                                                @if($toolResult['result']['success'] ?? false)
                                                                                                    <span class="badge badge-success">成功</span>
                                                                                                    @if(isset($toolResult['result']['data']))
                                                                                                        <small class="text-muted">({{ count($toolResult['result']['data']) }} 筆數據)</small>
                                                                                                    @endif
                                                                                                @else
                                                                                                    <span class="badge badge-danger">失敗</span>
                                                                                                    <small class="text-danger">{{ $toolResult['result']['error'] ?? '未知錯誤' }}</small>
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
                                                                    <th style="width: 150px;">模型</th>
                                                                    <td><code>{{ $responseData['model'] }}</code></td>
                                                                </tr>
                                                            @endif

                                                            {{-- Gemini 格式：modelVersion --}}
                                                            @if(isset($responseData['modelVersion']))
                                                                <tr>
                                                                    <th>模型版本</th>
                                                                    <td><code>{{ $responseData['modelVersion'] }}</code></td>
                                                                </tr>
                                                            @endif

                                                            {{-- OpenAI 格式：object --}}
                                                            @if(isset($responseData['object']))
                                                                <tr>
                                                                    <th>對象類型</th>
                                                                    <td><code>{{ $responseData['object'] }}</code></td>
                                                                </tr>
                                                            @endif

                                                            {{-- OpenAI 格式：id / Gemini 格式：responseId --}}
                                                            @if(isset($responseData['id']))
                                                                <tr>
                                                                    <th>響應 ID</th>
                                                                    <td><small><code>{{ $responseData['id'] }}</code></small></td>
                                                                </tr>
                                                            @elseif(isset($responseData['responseId']))
                                                                <tr>
                                                                    <th>響應 ID</th>
                                                                    <td><small><code>{{ $responseData['responseId'] }}</code></small></td>
                                                                </tr>
                                                            @endif

                                                            {{-- OpenAI 格式：created --}}
                                                            @if(isset($responseData['created']))
                                                                <tr>
                                                                    <th>創建時間</th>
                                                                    <td>
                                                                        {{ date('Y-m-d H:i:s', $responseData['created']) }}
                                                                        <small class="text-muted">(Unix: {{ $responseData['created'] }})</small>
                                                                    </td>
                                                                </tr>
                                                            @endif

                                                            {{-- Finish Reason --}}
                                                            @if(isset($responseData['choices'][0]['finish_reason']))
                                                                <tr>
                                                                    <th>完成原因</th>
                                                                    <td><code>{{ $responseData['choices'][0]['finish_reason'] }}</code></td>
                                                                </tr>
                                                            @endif
                                                            @if(isset($responseData['choices'][0]['finishReason']))
                                                                <tr>
                                                                    <th>完成原因 (Gemini)</th>
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
                                                                    <th>角色</th>
                                                                    <td><code>{{ $responseData['choices'][0]['message']['role'] }}</code></td>
                                                                </tr>
                                                            @endif

                                                            {{-- OpenAI 格式：usage --}}
                                                            @if(isset($responseData['usage']))
                                                                <tr>
                                                                    <th>Token 使用</th>
                                                                    <td>
                                                                        <strong>提示詞：</strong>{{ number_format($responseData['usage']['prompt_tokens'] ?? 0) }}<br>
                                                                        <strong>響應：</strong>{{ number_format($responseData['usage']['completion_tokens'] ?? 0) }}<br>
                                                                        <strong>總計：</strong>{{ number_format($responseData['usage']['total_tokens'] ?? 0) }}
                                                                        @if(isset($responseData['usage']['prompt_tokens_details']['cached_tokens']))
                                                                            <br><strong class="text-info">快取：</strong>{{ number_format($responseData['usage']['prompt_tokens_details']['cached_tokens']) }}
                                                                        @endif
                                                                    </td>
                                                                </tr>
                                                            @endif

                                                            {{-- Gemini 格式：usageMetadata --}}
                                                            @if(isset($responseData['usageMetadata']))
                                                                <tr>
                                                                    <th>Token 使用 (Gemini)</th>
                                                                    <td>
                                                                        <strong>提示詞：</strong>{{ number_format($responseData['usageMetadata']['promptTokenCount'] ?? 0) }}<br>
                                                                        <strong>候選：</strong>{{ number_format($responseData['usageMetadata']['candidatesTokenCount'] ?? 0) }}<br>
                                                                        <strong>總計：</strong>{{ number_format($responseData['usageMetadata']['totalTokenCount'] ?? 0) }}
                                                                        @if(isset($responseData['usageMetadata']['thoughtsTokenCount']))
                                                                            <br><strong class="text-info">思考：</strong>{{ number_format($responseData['usageMetadata']['thoughtsTokenCount']) }}
                                                                        @endif
                                                                    </td>
                                                                </tr>
                                                            @endif

                                                            {{-- Extra Content (thought_signature 已被過濾，不顯示) --}}
                                                            @if(isset($responseData['choices'][0]['message']['extra_content']) && !empty($responseData['choices'][0]['message']['extra_content']))
                                                                @php
                                                                    $extraContent = $responseData['choices'][0]['message']['extra_content'];
                                                                    // 移除 thought_signature
                                                                    if (isset($extraContent['google']['thought_signature'])) {
                                                                        unset($extraContent['google']['thought_signature']);
                                                                    }
                                                                    if (isset($extraContent['google']) && empty($extraContent['google'])) {
                                                                        unset($extraContent['google']);
                                                                    }
                                                                @endphp
                                                                @if(!empty($extraContent))
                                                                    <tr>
                                                                        <th>額外內容</th>
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
