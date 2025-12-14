@extends('layouts.dashboard-v3')

@section('content')
<div class="card card-default">
    <div class="card-header">
        <h3 class="card-title">Wiki 對照資料維護</h3>
    </div>
    <div class="card-body">

        {{-- 統計信息 --}}
        <div class="row" style="margin-bottom: 20px;">
            @foreach($targetSourceIds as $id)
            <div class="col-md-4">
                <a href="{{ route('admin.wiki-maintenance', ['source_id' => $id]) }}" style="text-decoration: none;">
                    <div class="info-box{{ $currentSourceId == $id ? ' info-box-selected' : '' }}">
                        <span class="info-box-icon bg-{{ $id == 60795 ? 'blue' : ($id == 68942 ? 'green' : 'orange') }}">
                            <i class="{{ $id == 68942 ? 'fas fa-globe' : 'fab fa-wikipedia-w' }}"></i>
                        </span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ $sourceNames[$id] }}</span>
                            <span class="info-box-number">{{ number_format($stats[$id]) }} 筆記錄</span>
                        </div>
                    </div>
                </a>
            </div>
            @endforeach
        </div>


        {{-- URL 導入功能 --}}
        <div class="row" style="margin-bottom: 20px;">
            <div class="col-md-12">
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fa fa-download"></i> 從 URL 導入 Wiki 對照資料
                        </h3>
                    </div>
                    <div class="card-body">
                        <form id="import-form" method="POST" action="{{ route('admin.wiki-maintenance.import-url') }}" class="form-horizontal">
                            {{ csrf_field() }}

                            <div class="form-group">
                                <label for="import_url" class="col-sm-2 control-label">資料 URL：</label>
                                <div class="col-sm-8">
                                    <input type="text" name="import_url" id="import_url" class="form-control"
                                           placeholder="https://cbdb-dev.linshuang.net/wikidata_20251105.json.gz"
                                           required>
                                    <span class="help-block">
                                        請輸入包含 Wiki 對照資料的 JSON 或 JSON.gz 檔案 URL
                                    </span>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="col-sm-2 control-label">目標來源：</label>
                                <div class="col-sm-4">
                                    <p class="form-control-static">
                                        <strong>{{ $sourceNames[$currentSourceId] }}</strong>
                                    </p>
                                    <input type="hidden" name="target_source" value="{{ $currentSourceId }}">
                                    <span class="help-block">
                                        <small class="text-muted">目標來源會根據當前選擇的資料來源自動設定</small>
                                    </span>
                                </div>
                            </div>

                            <div class="form-group">
                                <div class="col-sm-offset-2 col-sm-10">
                                    <div class="btn-toolbar" role="toolbar">
                                        <div class="btn-group" role="group">
                                            <button type="submit" class="btn btn-info" id="import-btn">
                                                <i class="fa fa-download"></i> 下載並導入資料
                                            </button>
                                        </div>
                                        <div class="btn-group float-right" role="group">
                                            <form method="POST" action="{{ route('admin.wiki-maintenance.delete-all') }}" style="display: inline;">
                                                {{ csrf_field() }}
                                                <input type="hidden" name="source_id" value="{{ $currentSourceId }}">
                                                <button type="submit" class="btn btn-danger"
                                                        onclick="return confirm('確定要刪除「{{ $sourceNames[$currentSourceId] }}」的所有 {{ number_format($stats[$currentSourceId]) }} 筆記錄嗎？此操作無法復原！')">
                                                    <i class="fa fa-trash"></i> 全部刪除 ({{ $sourceNames[$currentSourceId] }})
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="clearfix"></div>
                                    <span class="help-block" style="margin-top: 10px;">
                                        <strong>注意：</strong>此操作將先清空目標來源的所有現有記錄，然後導入新資料。請確保備份重要資料。
                                    </span>
                                </div>
                            </div>

                            {{-- 進度顯示區域 --}}
                            <div id="progress-container" class="form-group" style="display: none;">
                                <div class="col-sm-offset-2 col-sm-10">
                                    <div class="card card-default">
                                        <div class="card-body">
                                            <h4 id="progress-title">正在處理導入任務...</h4>
                                            <div class="progress">
                                                <div id="progress-bar" class="progress-bar progress-bar-info progress-bar-striped progress-bar-animated"
                                                     role="progressbar" style="width: 0%">
                                                    <span id="progress-text">0%</span>
                                                </div>
                                            </div>
                                            <p id="progress-message" class="text-muted">準備開始...</p>
                                            <div id="progress-details" class="small text-muted">
                                                <span id="task-id"></span> | 開始時間: <span id="start-time"></span>
                                            </div>
                                            <div class="text-center" style="margin-top: 15px;">
                                                <button id="cancel-btn" class="btn btn-warning" style="display: none;">
                                                    <i class="fa fa-stop"></i> 取消導入
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- 顯示操作結果消息 --}}
        @if(session('success'))
            <div class="alert alert-success alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                {{ session('error') }}
            </div>
        @endif

        @if(session('info'))
            <div class="alert alert-info alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                {{ session('info') }}
            </div>
        @endif

        {{-- 記錄列表 --}}
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>人物 ID</th>
                        <th>人名(CHN)</th>
                        <th>文本 ID</th>
                        <th>頁碼（標題/ID）</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $record)
                        <tr>
                            <td>
                                <a href="/basicinformation/{{ $record->c_personid }}/sources" target="_blank">
                                    {{ $record->c_personid }}
                                </a>
                            </td>
                            <td>{{ $record->c_name_chn ?? '-' }}</td>
                            <td>{{ $record->c_textid }}</td>
                            <td>
                                @if($record->c_url_api && $record->c_textid && $record->c_pages)
                                    @php
                                        $url_part = $record->c_pages;
                                        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $url_part)) {
                                            $url_part = rawurlencode($url_part);
                                        }
                                        $full_url = $record->c_url_api . $url_part . ($record->c_url_api_coda ?? '');
                                    @endphp
                                    <a href="{{ $full_url }}" target="_blank">{{ $record->c_pages }}</a>
                                @else
                                    {{ $record->c_pages ?? '-' }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted">沒有找到記錄</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 分頁導航 --}}
        @if($total > 0)
            <div class="row">
                <div class="col-md-6">
                    <p class="text-muted">
                        顯示第 {{ ($page - 1) * $perPage + 1 }} - {{ min($page * $perPage, $total) }} 筆，
                        共 {{ number_format($total) }} 筆記錄
                    </p>
                </div>
                <div class="col-md-6">
                    <nav aria-label="分頁導航" class="float-right">
                        <ul class="pagination">
                            {{-- 上一頁 --}}
                            @if($hasPrev)
                                <li class="page-item">
                                    <a class="page-link" href="{{ route('admin.wiki-maintenance', ['source_id' => $currentSourceId, 'page' => $page - 1]) }}">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            @else
                                <li class="page-item disabled">
                                    <span class="page-link" aria-hidden="true">&laquo;</span>
                                </li>
                            @endif

                            {{-- 頁碼 --}}
                            @php
                                $startPage = max(1, $page - 2);
                                $endPage = min(ceil($total / $perPage), $page + 2);
                            @endphp

                            @for($i = $startPage; $i <= $endPage; $i++)
                                @if($i == $page)
                                    <li class="page-item active">
                                        <span class="page-link">{{ $i }}</span>
                                    </li>
                                @else
                                    <li class="page-item">
                                        <a class="page-link" href="{{ route('admin.wiki-maintenance', ['source_id' => $currentSourceId, 'page' => $i]) }}">
                                            {{ $i }}
                                        </a>
                                    </li>
                                @endif
                            @endfor

                            {{-- 下一頁 --}}
                            @if($hasNext)
                                <li class="page-item">
                                    <a class="page-link" href="{{ route('admin.wiki-maintenance', ['source_id' => $currentSourceId, 'page' => $page + 1]) }}">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            @else
                                <li class="page-item disabled">
                                    <span class="page-link" aria-hidden="true">&raquo;</span>
                                </li>
                            @endif
                        </ul>
                    </nav>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@section('css')
<style>
.info-box {
    transition: all 0.3s ease;
    cursor: pointer;
}

.info-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.info-box-selected {
    border: 3px solid #3c8dbc;
    box-shadow: 0 2px 8px rgba(60, 141, 188, 0.3);
}

.info-box-selected:hover {
    border-color: #2f7ba8;
    box-shadow: 0 4px 12px rgba(60, 141, 188, 0.5);
}

a:hover {
    text-decoration: none;
}

.btn-toolbar {
    margin-bottom: 0;
}

.btn-toolbar .btn-group {
    display: inline-block;
    vertical-align: top;
}

@media (max-width: 768px) {
    .btn-toolbar .btn-group.float-right {
        float: none !important;
        display: block;
        margin-top: 10px;
        text-align: center;
    }

    .btn-toolbar .btn-group .btn {
        margin-bottom: 5px;
    }
}
</style>
@endsection

@section('js')
<script>
$(document).ready(function() {
    let progressInterval;
    let currentTaskId;

    // URL 導入表單提交處理
    $('#import-form').on('submit', function(e) {
        e.preventDefault(); // 阻止默認提交

        var $form = $(this);
        var $btn = $('#import-btn');
        var originalText = $btn.html();

        // 如果用戶確認，開始導入
        if (!confirm('確定要從指定 URL 導入資料嗎？此操作將清空目標來源的所有現有記錄！')) {
            return false;
        }

        // 禁用按鈕並顯示進度區域
        $btn.prop('disabled', true)
            .html('<i class="fa fa-spinner fa-spin"></i> 正在準備導入...');
        $('#progress-container').show();

        // 提交表單並開始進度跟踪
        $.ajax({
            url: $form.attr('action'),
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(response) {
                console.log('導入請求響應:', response); // Debug info
                if (response.success) {
                    currentTaskId = response.task_id;
                    console.log('開始追蹤任務:', currentTaskId); // Debug info
                    startProgressTracking(currentTaskId);
                } else {
                    showError(response.message);
                    resetInterface();
                }
            },
            error: function(xhr, status, error) {
                console.log('導入請求失敗:', xhr, status, error); // Debug info
                console.log('響應狀態:', xhr.status);
                console.log('響應文本:', xhr.responseText);
                console.log('響應 JSON:', xhr.responseJSON);

                var errorMsg = '導入失敗';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                } else if (xhr.status === 0) {
                    errorMsg = '網路連接失敗，請檢查網路連接';
                } else {
                    errorMsg = '服務器錯誤 (' + xhr.status + ')';
                }
                showError(errorMsg);
                resetInterface();
            }
        });

        function startProgressTracking(taskId) {
            $('#task-id').text('任務 ID: ' + taskId);
            $('#start-time').text(new Date().toLocaleString());
            $('#cancel-btn').show().off('click').on('click', function() {
                cancelImport(taskId);
            });

            // 每2秒查詢一次進度
            progressInterval = setInterval(function() {
                updateProgress(taskId);
            }, 2000);

            // 立即查詢一次
            updateProgress(taskId);
        }

        function updateProgress(taskId) {
            var progressUrl = '/admin/wiki-maintenance/progress/' + taskId;
            console.log('查詢進度 URL:', progressUrl); // Debug info
            console.log('TaskId:', taskId); // Debug info

            $.get(progressUrl)
                .done(function(response) {
                    console.log('進度響應:', response); // Debug info
                    if (response.success) {
                        var progress = response.progress;
                        updateProgressDisplay(progress);

                        // 如果任務完成、出錯或取消，停止輪詢
                        console.log('檢查狀態:', progress.status); // Debug info
                        if (progress.status === 'completed' || progress.status === 'error' || progress.status === 'cancelled') {
                            console.log('任務完成，停止輪詢'); // Debug info
                            clearInterval(progressInterval);
                            handleTaskCompletion(progress);
                        }
                    } else {
                        console.log('進度查詢失敗:', response.message);
                    }
                })
                .fail(function(xhr, status, error) {
                    // 如果查詢進度失敗，繼續嘗試
                    console.log('查詢進度失敗:', status, error);
                });
        }

        function cancelImport(taskId) {
            if (confirm('確定要取消導入任務嗎？已完成的部分將無法恢復。')) {
                $('#cancel-btn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 正在取消...');

                $.post('/admin/wiki-maintenance/cancel/' + taskId)
                    .done(function(response) {
                        console.log('取消響應:', response);
                        if (response.success) {
                            // 取消成功後會自動通過進度查詢更新狀態
                        }
                    })
                    .fail(function(xhr) {
                        console.log('取消失敗:', xhr);
                        alert('取消請求失敗，請重試');
                        $('#cancel-btn').prop('disabled', false).html('<i class="fa fa-stop"></i> 取消導入');
                    });
            }
        }

        function updateProgressDisplay(progress) {
            var percentage = Math.round(progress.progress);
            console.log('更新進度:', percentage + '%', progress.message); // Debug info

            $('#progress-bar').css('width', percentage + '%');
            $('#progress-text').text(percentage + '%');
            $('#progress-message').text(progress.message);

            // 根據狀態更改進度條顏色
            $('#progress-bar').removeClass('progress-bar-success progress-bar-danger progress-bar-warning')
                .addClass(progress.status === 'error' ? 'progress-bar-danger' :
                         progress.status === 'cancelled' ? 'progress-bar-warning' : 'progress-bar-info');

            // 如果任務正在運行，顯示取消按鈕
            if (progress.status === 'running') {
                $('#cancel-btn').show();
            } else {
                $('#cancel-btn').hide();
            }

            // 確保進度條可見
            if (!$('#progress-container').is(':visible')) {
                $('#progress-container').show();
            }
        }

        function handleTaskCompletion(progress) {
            var $btn = $('#import-btn');
            var originalText = '<i class="fa fa-download"></i> 下載並導入資料';

            if (progress.status === 'completed') {
                $('#progress-bar').removeClass('progress-bar-info progress-bar-striped progress-bar-animated')
                    .addClass('progress-bar-success');
                $('#progress-title').text('導入完成！').addClass('text-success');

                // 顯示成功消息
                setTimeout(function() {
                    alert('導入成功！\n\n' + progress.message);
                    location.reload(); // 重新載入頁面以更新統計
                }, 1000);

            } else if (progress.status === 'cancelled') {
                $('#progress-bar').removeClass('progress-bar-info progress-bar-striped progress-bar-animated')
                    .addClass('progress-bar-warning');
                $('#progress-title').text('導入已取消').addClass('text-warning');

                // 顯示取消消息
                setTimeout(function() {
                    alert('導入任務已取消\n\n' + progress.message);
                    resetInterface();
                }, 1000);

            } else {
                $('#progress-bar').removeClass('progress-bar-info progress-bar-striped progress-bar-animated')
                    .addClass('progress-bar-danger');
                $('#progress-title').text('導入失敗').addClass('text-danger');

                // 顯示錯誤消息
                setTimeout(function() {
                    alert('導入失敗：\n\n' + progress.message);
                    resetInterface();
                }, 1000);
            }

            $btn.prop('disabled', false).html(originalText);
        }

        function showError(message) {
            alert('錯誤：' + message);
        }

        function resetInterface() {
            var $btn = $('#import-btn');
            var originalText = '<i class="fa fa-download"></i> 下載並導入資料';

            $btn.prop('disabled', false).html(originalText);
            $('#progress-container').hide();
            $('#cancel-btn').hide().prop('disabled', false).html('<i class="fa fa-stop"></i> 取消導入');

            if (progressInterval) {
                clearInterval(progressInterval);
            }
        }
    });
});
</script>
@endsection
