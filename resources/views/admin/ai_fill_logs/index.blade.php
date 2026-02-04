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
                                <label for="search">關鍵字搜尋</label>
                                <input type="text" class="form-control" id="search" name="search"
                                       value="{{ $filters['search'] ?? '' }}"
                                       placeholder="搜尋原始文本、用戶名稱或郵箱">
                            </div>
                        </div>
                        <div class="col-md-4">
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
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i> 搜尋
                                    </button>
                                    @if(($filters['search'] ?? '') || ($filters['user_id'] ?? ''))
                                        <a href="{{ route('admin.ai-fill-logs') }}" class="btn btn-secondary ml-1">
                                            <i class="fas fa-times"></i> 清除
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
                    共 {{ $logs->total() }} 筆記錄，顯示第 {{ $logs->firstItem() ?? 0 }} - {{ $logs->lastItem() ?? 0 }} 筆
                </div>

                <!-- 日誌列表 -->
                @if($logs->isEmpty())
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 暫無記錄
                    </div>
                @else
                    @foreach($logs as $log)
                        @php
                            $hasComparison = !empty($log->comparison_rows);
                            $aiMatched = $log->ai_matched ? json_decode($log->ai_matched, true) : null;
                            $statistics = $aiMatched['statistics'] ?? null;
                            $cardBorderClass = $log->user_submitted ? 'border-success' : 'border-info';
                            $cardHeaderClass = $log->user_submitted ? 'bg-success' : 'bg-info';
                        @endphp
                        <div class="card mb-3 {{ $cardBorderClass }}">
                            <div class="card-header {{ $cardHeaderClass }}">
                                <div class="d-flex justify-content-between align-items-center flex-wrap">
                                    <div>
                                        <strong>#{{ $log->id }}</strong>
                                        <span class="ml-2">
                                            <i class="fas fa-user"></i>
                                            {{ $log->user_name ?? '未知用戶' }}
                                            @if($log->user_email)
                                                <small>({{ $log->user_email }})</small>
                                            @endif
                                        </span>
                                        <span class="ml-2">
                                            <i class="fas fa-id-badge"></i>
                                            <a href="{{ route('basicinformation.edit', $log->c_personid) }}" class="text-white" target="_blank">
                                                人物 #{{ $log->c_personid }}
                                            </a>
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
                                            <span class="badge badge-light">已提交</span>
                                        @else
                                            <span class="badge badge-light">未提交</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- 原始文本 -->
                                <div class="mb-3">
                                    <h6><i class="fas fa-file-alt text-primary"></i> 原始史料：</h6>
                                    <div class="alert alert-light mb-2">
                                        {{ $log->source_text }}
                                    </div>
                                </div>

                                <!-- 匹配統計 -->
                                @if($statistics)
                                    <div class="mb-3">
                                        <span class="badge badge-success">匹配 {{ $statistics['matched_count'] ?? 0 }}</span>
                                        @if(($statistics['suggested_count'] ?? 0) > 0)
                                            <span class="badge badge-warning">建議 {{ $statistics['suggested_count'] }}</span>
                                        @endif
                                        @if(($statistics['not_found_count'] ?? 0) > 0)
                                            <span class="badge badge-info">未匹配 {{ $statistics['not_found_count'] }}</span>
                                        @endif
                                        <span class="badge badge-secondary">空 {{ $statistics['empty_count'] ?? 0 }}</span>
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
                                        <i class="fas fa-columns"></i> 比較
                                    </button>
                                @endif

                                <!-- AI 原始回應（折疊） -->
                                @if($log->ai_raw)
                                    <div class="mb-3">
                                        <h6>
                                            <a class="collapsed" data-toggle="collapse" href="#ai-raw-{{ $log->id }}" role="button">
                                                <i class="fas fa-chevron-right"></i> AI 原始回應
                                                <small class="text-muted">({{ strlen($log->ai_raw) }} 字符)</small>
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
                                                <i class="fas fa-chevron-right"></i> AI 匹配結果
                                                <small class="text-muted">({{ strlen($log->ai_matched) }} 字符)</small>
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
                                                <i class="fas fa-chevron-right"></i> 用戶提交數據
                                                <small class="text-muted">({{ strlen($log->user_submitted) }} 字符)</small>
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
                                                #{{ $log->id }} - AI 匹配結果 vs 用戶提交 比較
                                            </h4>
                                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                                        </div>
                                        <div class="modal-body" style="word-break: break-all;">
                                            <div class="alert alert-light mb-3">
                                                <strong><i class="fas fa-file-alt text-primary"></i> 原始史料：</strong>
                                                {{ $log->source_text }}
                                            </div>
                                            @include('components.ai-fill-diff-table', ['rows' => $log->comparison_rows])
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
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
