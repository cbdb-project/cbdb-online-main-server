@extends('layouts.dashboard-v3')

@section('content')
<div class="card card-default">
    <div class="card-header">
        <h3 class="card-title">{{ __('admin.table_maint_page_title') }}</h3>
    </div>
    <div class="card-body">

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

        {{-- 資料表統計信息 --}}
        <div class="row" style="margin-bottom: 20px;">
            @foreach($tables as $tableName => $tableInfo)
            <div class="col-md-6">
                <div class="card card-{{ $tableInfo['color'] }}">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fa fa-{{ $tableInfo['icon'] }}"></i>
                            {{ $tableInfo['name_chn'] }}
                        </h3>
                    </div>
                    <div class="card-body">
                        <p><strong>{{ __('admin.table_maint_db_table') }}</strong><code>{{ $tableInfo['name'] }}</code></p>
                        <p><strong>{{ __('admin.table_maint_description') }}</strong>{{ $tableInfo['description'] }}</p>
                        <p><strong>{{ __('admin.table_maint_artisan_cmd') }}</strong><code>{{ $tableInfo['command'] }}</code></p>

                        @if($stats[$tableName]['exists'])
                            <p><strong>{{ __('admin.table_maint_record_count') }}</strong>
                                <span class="badge bg-{{ $tableInfo['color'] }}">
                                    {{ number_format($stats[$tableName]['count']) }} {{ __('admin.table_maint_records_unit') }}
                                </span>
                            </p>
                        @else
                            <p><strong>{{ __('admin.table_maint_status') }}</strong>
                                <span class="badge badge-warning">{{ __('admin.table_maint_table_missing') }}</span>
                            </p>
                        @endif

                        <hr>

                        <form class="rebuild-form" method="POST" action="{{ route('admin.cbdb-table-maintenance.rebuild') }}" data-table-name="{{ $tableInfo['name_chn'] }}"
                              @if($tableName == 'CBDB__NAME_FTS')
                                  data-progress-url-template="{{ route('admin.cbdb-table-maintenance.progress', ['taskId' => '__TASK_ID__'], false) }}"
                              @endif
                        >
                            {{ csrf_field() }}
                            <input type="hidden" name="table_name" value="{{ $tableName }}">

                            {{-- 姓名搜索索引：需要清空, id_from, id_to --}}
                            @if($tableName == 'CBDB__NAME_FTS')
                                <div class="form-group">
                                    <div class="checkbox">
                                        <label>
                                            <input type="checkbox" name="truncate" value="1">
                                            {{ __('admin.table_maint_truncate_rebuild') }}
                                        </label>
                                        <span class="help-block small">{{ __('admin.table_maint_incremental_hint') }}</span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>{{ __('admin.table_maint_id_range') }}</label>
                                    <div class="row">
                                        <div class="col-sm-6">
                                            <input type="number" name="id_from" class="form-control"
                                                   placeholder="{{ __('admin.table_maint_id_from') }}" min="1">
                                        </div>
                                        <div class="col-sm-6">
                                            <input type="number" name="id_to" class="form-control"
                                                   placeholder="{{ __('admin.table_maint_id_to') }}" min="1">
                                        </div>
                                    </div>
                                    <span class="help-block small">{{ __('admin.table_maint_id_blank_hint') }}</span>
                                </div>

                                <div class="progress-container name-fts-progress" style="display:none; margin-top:15px;">
                                    <div class="progress">
                                        <div class="progress-bar progress-bar-success" role="progressbar" aria-valuenow="0"
                                             aria-valuemin="0" aria-valuemax="100" style="width:0%;">
                                            0%
                                        </div>
                                    </div>
                                    <p class="progress-message text-muted small" style="margin-top:5px;">{{ __('admin.table_maint_progress_placeholder') }}</p>
                                </div>
                            @endif

                            <button type="submit" class="btn btn-{{ $tableInfo['color'] }} rebuild-btn">
                                <i class="fa fa-refresh"></i> {{ __('admin.table_maint_rebuild_btn') }}
                            </button>

                            <div class="loading-message" style="display: none; margin-top: 10px;">
                                <i class="fa fa-spinner fa-spin"></i> {{ __('admin.table_maint_rebuilding') }}
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- 說明文字 --}}
        <div class="card card-info">
            <div class="card-header">
                <h3 class="card-title"><i class="fa fa-info-circle"></i> {{ __('admin.table_maint_info_title') }}</h3>
            </div>
            <div class="card-body">
                <h4>{{ __('admin.table_maint_name_fts_h4') }}</h4>
                <ul>
                    <li>{{ __('admin.table_maint_name_fts_li1') }}</li>
                    <li>{{ __('admin.table_maint_name_fts_li2') }}</li>
                    <li>{{ __('admin.table_maint_name_fts_li3') }}</li>
                    <li>{!! __('admin.table_maint_name_fts_li4') !!}</li>
                    <li>{!! __('admin.table_maint_name_fts_li5') !!}</li>
                    <li>{!! __('admin.table_maint_name_fts_li6') !!}</li>
                    <li>{{ __('admin.table_maint_name_fts_li7') }}</li>
                </ul>

                <p class="text-danger">
                    {!! __('admin.table_maint_danger_note') !!}
                </p>
            </div>
        </div>

    </div>
</div>
@endsection

@section('css')
<style>
.panel-heading h3 {
    margin: 0;
}

.panel-body p {
    margin-bottom: 8px;
}

.badge {
    font-size: 14px;
    padding: 5px 10px;
}

.progress {
    margin-bottom: 10px;
}

.progress-text {
    font-weight: bold;
}
</style>
@endsection

@section('js')
<script>
onViteReady(function() {
    var msgConfirm = {!! Js::from(__('admin.table_maint_js_confirm')) !!};
    var msgProcessing = {!! Js::from(__('admin.table_maint_js_processing')) !!};
    var msgSuccess = {!! Js::from(__('admin.table_maint_js_success')) !!};
    var msgFailed = {!! Js::from(__('admin.table_maint_js_failed')) !!};
    var msgTimeout = {!! Js::from(__('admin.table_maint_js_timeout')) !!};
    var msgNetwork = {!! Js::from(__('admin.table_maint_js_network')) !!};
    var msgServerError = {!! Js::from(__('admin.table_maint_js_server_error')) !!};
    var msgErrorPrefix = {!! Js::from(__('admin.table_maint_js_error_prefix')) !!};
    var msgNoProgressUrl = {!! Js::from(__('admin.table_maint_js_no_progress_url')) !!};
    var msgScheduled = {!! Js::from(__('admin.table_maint_js_scheduled')) !!};
    var msgRebuildDone = {!! Js::from(__('admin.table_maint_js_rebuild_done')) !!};
    var msgIndexDone = {!! Js::from(__('admin.table_maint_js_index_done')) !!};
    var msgCheckLogs = {!! Js::from(__('admin.table_maint_js_check_logs')) !!};
    var msgProgressFail = {!! Js::from(__('admin.table_maint_js_progress_fail')) !!};
    var msgProgressError = {!! Js::from(__('admin.table_maint_js_progress_error')) !!};

    var progressPollers = {};
    var PROGRESS_POLL_INTERVAL = 5000;

    $('.rebuild-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $form.find('.rebuild-btn');
        var $loadingMsg = $form.find('.loading-message');
        var tableName = $form.data('table-name');
        var originalBtnHtml = $btn.html();

        if (!confirm(msgConfirm.replace(':name', tableName))) {
            return false;
        }

        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> ' + msgProcessing);
        $loadingMsg.show();

        $.ajax({
            url: $form.attr('action'),
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            timeout: 600000,
            success: function(response) {
                if (response.success && response.task_id) {
                    startNameFtsProgress($form, response.task_id, response.message, originalBtnHtml, $btn, $loadingMsg);
                } else if (response.success) {
                    alert(msgSuccess + response.message);
                    location.reload();
                } else {
                    alert(msgFailed + response.message);
                    resetButton($btn, $loadingMsg, originalBtnHtml);
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = msgFailed.trim();
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                } else if (status === 'timeout') {
                    errorMsg = msgTimeout;
                } else if (xhr.status === 0) {
                    errorMsg = msgNetwork;
                } else {
                    errorMsg = msgServerError.replace(':status', xhr.status);
                }

                alert(msgErrorPrefix + errorMsg);
                resetButton($btn, $loadingMsg, originalBtnHtml);
            }
        });
    });

    function resetButton($btn, $loadingMsg, originalBtnHtml) {
        $btn.prop('disabled', false).html(originalBtnHtml);
        $loadingMsg.hide();
    }

    function startNameFtsProgress($form, taskId, initialMessage, originalBtnHtml, $btn, $loadingMsg) {
        var template = $form.data('progress-url-template');
        if (!template) {
            alert(msgNoProgressUrl);
            resetButton($btn, $loadingMsg, originalBtnHtml);
            return;
        }

        var url = template.replace('__TASK_ID__', taskId);
        var $container = $form.find('.name-fts-progress');
        var $bar = $container.find('.progress-bar');
        var $message = $container.find('.progress-message');

        $container.show();
        updateProgressBar($bar, 5);
        $message.text(initialMessage || msgScheduled);

        var poll = function() {
            $.ajax({
                url: url,
                method: 'GET',
                dataType: 'json',
                cache: false,
                success: function(resp) {
                    if (!resp.success) {
                        handleProgressError(resp.message || msgCheckLogs);
                        return;
                    }

                    var progress = resp.progress || {};
                    updateProgressBar($bar, progress.progress || 0);
                    if (progress.message) {
                        $message.text(progress.message);
                    }

                    if (progress.status === 'completed') {
                        if (progressPollers[taskId]) {
                            clearInterval(progressPollers[taskId]);
                            delete progressPollers[taskId];
                        }
                        $message.text(progress.message || msgRebuildDone);
                        setTimeout(function() {
                            alert(msgSuccess + (progress.message || msgIndexDone));
                            location.reload();
                        }, 500);
                    } else if (progress.status === 'error') {
                        if (progressPollers[taskId]) {
                            clearInterval(progressPollers[taskId]);
                            delete progressPollers[taskId];
                        }
                        alert(msgFailed + (progress.message || msgCheckLogs));
                        resetButton($btn, $loadingMsg, originalBtnHtml);
                    }
                },
                error: function() {
                    handleProgressError(msgProgressFail);
                }
            });
        };

        var handleProgressError = function(message) {
            if (progressPollers[taskId]) {
                clearInterval(progressPollers[taskId]);
                delete progressPollers[taskId];
            }
            alert(msgProgressError + message);
            resetButton($btn, $loadingMsg, originalBtnHtml);
        };

        progressPollers[taskId] = setInterval(poll, PROGRESS_POLL_INTERVAL);
        poll();
    }

    function updateProgressBar($bar, value) {
        var percent = Math.max(0, Math.min(100, parseInt(value, 10) || 0));
        $bar.css('width', percent + '%')
            .attr('aria-valuenow', percent)
            .text(percent + '%');
    }
});
</script>
@endsection
