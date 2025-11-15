@extends('layouts.dashboard')

@section('content')
<div class="panel panel-default">
    <div class="panel-heading">CBDB 內部表維護</div>
    <div class="panel-body">

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
                <div class="panel panel-{{ $tableInfo['color'] }}">
                    <div class="panel-heading">
                        <h3 class="panel-title">
                            <i class="fa fa-{{ $tableInfo['icon'] }}"></i>
                            {{ $tableInfo['name_chn'] }}
                        </h3>
                    </div>
                    <div class="panel-body">
                        <p><strong>資料表：</strong><code>{{ $tableInfo['name'] }}</code></p>
                        <p><strong>說明：</strong>{{ $tableInfo['description'] }}</p>
                        <p><strong>Artisan 命令：</strong><code>{{ $tableInfo['command'] }}</code></p>

                        @if($stats[$tableName]['exists'])
                            <p><strong>當前記錄數：</strong>
                                <span class="badge bg-{{ $tableInfo['color'] }}">
                                    {{ number_format($stats[$tableName]['count']) }} 筆
                                </span>
                            </p>
                        @else
                            <p><strong>狀態：</strong>
                                <span class="label label-warning">資料表不存在</span>
                            </p>
                        @endif

                        <hr>

                        <form class="rebuild-form" method="POST" action="{{ route('admin.cbdb-table-maintenance.rebuild') }}" data-table-name="{{ $tableInfo['name_chn'] }}">
                            {{ csrf_field() }}
                            <input type="hidden" name="table_name" value="{{ $tableName }}">

                            {{-- 繁簡映射表：只需要 truncate --}}
                            @if($tableName == 'CBDB__TRAD_SIMP_MAP')
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="truncate" value="1" checked>
                                        清空後重建（truncate）
                                    </label>
                                </div>
                            @endif

                            {{-- 姓名搜索索引：需要 truncate, id_from, id_to --}}
                            @if($tableName == 'CBDB__NAME_FTS')
                                <div class="form-group">
                                    <div class="checkbox">
                                        <label>
                                            <input type="checkbox" name="truncate" value="1" checked>
                                            清空後重建（truncate）
                                        </label>
                                        <span class="help-block small">不勾選則為增量更新（僅更新指定範圍的記錄）</span>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>人物 ID 範圍（可選）：</label>
                                    <div class="row">
                                        <div class="col-sm-6">
                                            <input type="number" name="id_from" class="form-control"
                                                   placeholder="起始 ID (含)" min="1">
                                        </div>
                                        <div class="col-sm-6">
                                            <input type="number" name="id_to" class="form-control"
                                                   placeholder="結束 ID (含)" min="1">
                                        </div>
                                    </div>
                                    <span class="help-block small">留空則處理全部人物記錄</span>
                                </div>
                            @endif

                            <button type="submit" class="btn btn-{{ $tableInfo['color'] }} rebuild-btn">
                                <i class="fa fa-refresh"></i> 重建資料表
                            </button>

                            <div class="loading-message" style="display: none; margin-top: 10px;">
                                <i class="fa fa-spinner fa-spin"></i> 正在重建，請稍候...
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            @endforeach
        </div>

        {{-- 說明文字 --}}
        <div class="panel panel-info">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fa fa-info-circle"></i> 說明</h3>
            </div>
            <div class="panel-body">
                <h4>繁簡映射表 (CBDB__TRAD_SIMP_MAP)</h4>
                <ul>
                    <li>從 OpenCC 專案下載最新的繁簡對照資料</li>
                    <li>用於支援繁簡體混合搜尋功能</li>
                    <li>重建時會清空並重新導入所有映射關係</li>
                    <li>執行時間：約 1-2 分鐘</li>
                </ul>

                <h4>姓名搜尋倒排索引 (CBDB__NAME_FTS)</h4>
                <ul>
                    <li>根據 BIOG_MAIN 和 ALTNAME_DATA 重建姓名搜尋索引</li>
                    <li>生成所有姓名的後綴索引（包含繁簡體變體）</li>
                    <li>用於快速姓名搜尋功能</li>
                    <li><strong>Truncate 模式：</strong>清空並重新生成所有索引記錄（預設勾選）</li>
                    <li><strong>增量模式：</strong>不勾選 truncate，僅更新指定 ID 範圍的記錄</li>
                    <li><strong>ID 範圍：</strong>可指定 c_personid 的起始和結束範圍，留空則處理全部</li>
                    <li>執行時間：全量重建約 5-10 分鐘，增量更新視範圍而定</li>
                </ul>

                <p class="text-danger">
                    <strong>注意：</strong>
                    重建操作會清空目標資料表並重新生成所有資料。
                    請在系統使用量較低時執行此操作，以避免影響正常服務。
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
$(document).ready(function() {
    // 為每個重建表單添加處理邏輯
    $('.rebuild-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $form.find('.rebuild-btn');
        var $loadingMsg = $form.find('.loading-message');
        var tableName = $form.data('table-name');
        var originalBtnHtml = $btn.html();

        // 確認重建
        if (!confirm('確定要重建「' + tableName + '」嗎？\n\n此操作將清空資料表並重新生成所有資料，可能需要數分鐘時間。')) {
            return false;
        }

        // 禁用按鈕並顯示 loading
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 處理中...');
        $loadingMsg.show();

        // 提交表單
        $.ajax({
            url: $form.attr('action'),
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            timeout: 600000, // 10 分鐘超時
            success: function(response) {
                if (response.success) {
                    alert('重建成功！\n\n' + response.message);
                    location.reload();
                } else {
                    alert('重建失敗：\n\n' + response.message);
                    $btn.prop('disabled', false).html(originalBtnHtml);
                    $loadingMsg.hide();
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = '重建失敗';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                } else if (status === 'timeout') {
                    errorMsg = '請求超時，但任務可能仍在後台執行。請稍後刷新頁面查看結果。';
                } else if (xhr.status === 0) {
                    errorMsg = '網路連接失敗';
                } else {
                    errorMsg = '服務器錯誤 (' + xhr.status + ')';
                }

                alert('錯誤：' + errorMsg);
                $btn.prop('disabled', false).html(originalBtnHtml);
                $loadingMsg.hide();
            }
        });
    });
});
</script>
@endsection
