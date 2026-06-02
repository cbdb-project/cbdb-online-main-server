@extends('layouts.dashboard-v3')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-robot mr-1"></i> {{ $page_title }}</h3>
            </div>

            <div class="card-body">
                <!-- 搜尋與篩選 -->
                <form method="GET" action="{{ route('admin.ai-fill-logs') }}" class="mb-3">
                    <div class="row">
                        <div class="col-md-5">
                            <div class="form-group">
                                <label for="search">{{ __('admin.ai_log_keyword') }}</label>
                                <input type="text" class="form-control" id="search" name="search"
                                       value="{{ $filters['search'] ?? '' }}"
                                       placeholder="{{ __('admin.ai_log_search_placeholder') }}">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="user_id">{{ __('admin.ai_log_user') }}</label>
                                <select class="form-control" id="user_id" name="user_id">
                                    <option value="">{{ __('admin.ai_log_all_users') }}</option>
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
                                <label for="category">{{ __('admin.ai_log_category') }}</label>
                                <select class="form-control" id="category" name="category">
                                    <option value="">{{ __('admin.ai_log_all_categories') }}</option>
                                    <option value="posting" {{ ($filters['category'] ?? '') === 'posting' ? 'selected' : '' }}>{{ __('admin.ai_log_cat_posting') }}</option>
                                    <option value="assoc" {{ ($filters['category'] ?? '') === 'assoc' ? 'selected' : '' }}>{{ __('admin.ai_log_cat_assoc') }}</option>
                                    <option value="status" {{ ($filters['category'] ?? '') === 'status' ? 'selected' : '' }}>{{ __('admin.ai_log_cat_status') }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-1">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i> {{ __('admin.ai_log_search_btn') }}
                                    </button>
                                    @if(($filters['search'] ?? '') || ($filters['user_id'] ?? '') || ($filters['category'] ?? ''))
                                        <a href="{{ route('admin.ai-fill-logs') }}" class="btn btn-secondary ml-1">
                                            <i class="fas fa-times"></i> {{ __('admin.ai_log_clear_btn') }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- 統計信息 -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    {{ __('admin.ai_log_summary', ['total' => $logs->total(), 'first' => $logs->firstItem() ?? 0, 'last' => $logs->lastItem() ?? 0]) }}
                </div>

                <!-- 日誌列表 -->
                @if($logs->isEmpty())
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> {{ __('admin.ai_log_no_records') }}
                    </div>
                @else
                    @foreach($logs as $log)
                        @php
                            $hasComparison = !empty($log->comparison_rows);
                            $aiMatched = $log->ai_matched ? json_decode($log->ai_matched, true) : null;
                            $statistics = $aiMatched['statistics'] ?? null;
                            $cardBorderClass = $log->user_submitted ? 'border-success' : 'border-info';
                            $cardHeaderClass = $log->user_submitted ? 'bg-success' : 'bg-info';
                            $categoryLabels = ['posting' => __('admin.ai_log_cat_posting'), 'assoc' => __('admin.ai_log_cat_assoc'), 'status' => __('admin.ai_log_cat_status')];
                            $categoryBadgeClasses = ['posting' => 'badge-primary', 'assoc' => 'badge-info', 'status' => 'badge-warning'];
                            $logCategory = $log->category ?? 'posting';
                        @endphp
                        <div class="card mb-3 {{ $cardBorderClass }}">
                            <div class="card-header {{ $cardHeaderClass }}">
                                <div class="d-flex justify-content-between align-items-center flex-wrap">
                                    <div>
                                        <strong>#{{ $log->id }}</strong>
                                        <span class="badge {{ $categoryBadgeClasses[$logCategory] ?? 'badge-secondary' }} ml-2">{{ $categoryLabels[$logCategory] ?? $logCategory }}</span>
                                        <span class="ml-2">
                                            <i class="fas fa-user"></i>
                                            {{ $log->user_name ?? __('admin.ai_log_unknown_user') }}
                                            @if($log->user_email)
                                                <small>({{ $log->user_email }})</small>
                                            @endif
                                        </span>
                                        <span class="ml-2">
                                            <i class="fas fa-id-badge"></i>
                                            @if($logCategory === 'assoc')
                                                <a href="{{ route('basicinformation.assoc.index', ['basicinformation' => $log->c_personid]) }}" class="text-white" target="_blank">
                                                    {{ __('admin.ai_log_person', ['id' => $log->c_personid]) }}
                                                </a>
                                            @elseif($logCategory === 'status')
                                                <a href="{{ route('basicinformation.statuses.index', ['basicinformation' => $log->c_personid]) }}" class="text-white" target="_blank">
                                                    {{ __('admin.ai_log_person', ['id' => $log->c_personid]) }}
                                                </a>
                                            @else
                                                <a href="{{ route('basicinformation.offices.index', ['basicinformation' => $log->c_personid]) }}" class="text-white" target="_blank">
                                                    {{ __('admin.ai_log_person', ['id' => $log->c_personid]) }}
                                                </a>
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
                                    <div>
                                        @if($log->user_submitted)
                                            <span class="badge badge-light">{{ __('admin.ai_log_submitted') }}</span>
                                        @else
                                            <span class="badge badge-light">{{ __('admin.ai_log_not_submitted') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- 原始文本 -->
                                <div class="mb-3">
                                    <h6><i class="fas fa-file-alt text-primary"></i> {{ __('admin.ai_log_source_text') }}</h6>
                                    <div class="alert alert-light mb-2">
                                        {{ $log->source_text }}
                                    </div>
                                </div>

                                <!-- 匹配統計 -->
                                @if($statistics)
                                    <div class="mb-3">
                                        <span class="badge badge-success">{{ __('admin.ai_log_matched', ['count' => $statistics['matched_count'] ?? 0]) }}</span>
                                        @if(($statistics['suggested_count'] ?? 0) > 0)
                                            <span class="badge badge-warning">{{ __('admin.ai_log_suggested', ['count' => $statistics['suggested_count']]) }}</span>
                                        @endif
                                        @if(($statistics['not_found_count'] ?? 0) > 0)
                                            <span class="badge badge-info">{{ __('admin.ai_log_not_matched', ['count' => $statistics['not_found_count']]) }}</span>
                                        @endif
                                        <span class="badge badge-secondary">{{ __('admin.ai_log_empty', ['count' => $statistics['empty_count'] ?? 0]) }}</span>
                                    </div>
                                @endif

                                <!-- 路由資訊 -->
                                @if($log->route_name || $log->route_url)
                                    <div class="mb-3">
                                        <small class="text-muted">
                                            <i class="fas fa-route"></i>
                                            {{ $log->route_name }}
                                            @if($log->route_url)
                                                ({{ $log->route_url }})
                                            @endif
                                        </small>
                                    </div>
                                @endif

                                <!-- 比較按鈕 -->
                                @if($hasComparison)
                                    <button type="button" class="btn btn-info mb-3" data-toggle="modal" data-target="#modal-compare-{{ $log->id }}">
                                        <i class="fas fa-columns"></i> {{ __('admin.ai_log_compare_btn') }}
                                    </button>
                                @endif

                                <!-- AI 原始回應（折疊） -->
                                @if($log->ai_raw)
                                    <div class="mb-3">
                                        <h6>
                                            <a class="collapsed" data-toggle="collapse" href="#ai-raw-{{ $log->id }}" role="button">
                                                <i class="fas fa-chevron-right"></i> {{ __('admin.ai_log_ai_raw') }}
                                                <small class="text-muted">({{ __('admin.ai_log_chars', ['count' => strlen($log->ai_raw)]) }})</small>
                                            </a>
                                        </h6>
                                        <div class="collapse" id="ai-raw-{{ $log->id }}">
                                            <pre class="bg-light p-2 border rounded" style="max-height: 400px; overflow-y: auto; font-size: 0.85em;">{{ json_encode(json_decode($log->ai_raw, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                        </div>
                                    </div>
                                @endif

                                <!-- AI 匹配結果（折疊） -->
                                @if($log->ai_matched)
                                    <div class="mb-3">
                                        <h6>
                                            <a class="collapsed" data-toggle="collapse" href="#ai-matched-{{ $log->id }}" role="button">
                                                <i class="fas fa-chevron-right"></i> {{ __('admin.ai_log_ai_matched') }}
                                                <small class="text-muted">({{ __('admin.ai_log_chars', ['count' => strlen($log->ai_matched)]) }})</small>
                                            </a>
                                        </h6>
                                        <div class="collapse" id="ai-matched-{{ $log->id }}">
                                            <pre class="bg-light p-2 border rounded" style="max-height: 400px; overflow-y: auto; font-size: 0.85em;">{{ json_encode(json_decode($log->ai_matched, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                        </div>
                                    </div>
                                @endif

                                <!-- 用戶提交數據（折疊） -->
                                @if($log->user_submitted)
                                    <div class="mb-3">
                                        <h6>
                                            <a class="collapsed" data-toggle="collapse" href="#user-submitted-{{ $log->id }}" role="button">
                                                <i class="fas fa-chevron-right"></i> {{ __('admin.ai_log_user_submitted') }}
                                                <small class="text-muted">({{ __('admin.ai_log_chars', ['count' => strlen($log->user_submitted)]) }})</small>
                                            </a>
                                        </h6>
                                        <div class="collapse" id="user-submitted-{{ $log->id }}">
                                            <pre class="bg-light p-2 border rounded" style="max-height: 400px; overflow-y: auto; font-size: 0.85em;">{{ json_encode(json_decode($log->user_submitted, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- 比較 Modal -->
                        @if($hasComparison)
                            <div id="modal-compare-{{ $log->id }}" class="modal fade" role="dialog">
                                <div class="modal-dialog modal-lg" style="width:80vw;max-width:80vw;">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h4 class="modal-title">
                                                <i class="fas fa-columns"></i>
                                                {{ __('admin.ai_log_modal_title', ['id' => $log->id]) }}
                                            </h4>
                                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                                        </div>
                                        <div class="modal-body" style="word-break: break-all;">
                                            <div class="alert alert-light mb-3">
                                                <strong><i class="fas fa-file-alt text-primary"></i> {{ __('admin.ai_log_modal_source') }}</strong>
                                                {{ $log->source_text }}
                                            </div>
                                            @include('components.ai-fill-diff-table', ['rows' => $log->comparison_rows])
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-default" data-dismiss="modal">{{ __('admin.ai_log_close') }}</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
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
