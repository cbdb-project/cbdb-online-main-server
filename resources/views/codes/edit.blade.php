@extends('layouts.dashboard')

@section('content')

    @php
        $currentUserName = optional(Auth::user())->name;
        $currentDateYmd = \Carbon\Carbon::now()->format('Ymd');
        $currentTimestampTaipei = \Carbon\Carbon::now('Asia/Taipei')->format('Y-m-d H:i:s');
    @endphp

    <div class="panel panel-default">
        <div class="panel-heading">{{ $table }}</div>
        <div class="panel-body">
            <div class="panel-body">
                <form action="/codes/{{ $table }}/{{ $id }}" class="form-horizontal" method="post">
                    {{ method_field('PATCH') }}
                    {{ csrf_field() }}
                    @if($table === 'TEXT_CODES')
                    <div class="form-group">
                        <label for="author" class="col-sm-2 control-label">author</label>
                        <div class="col-sm-8">
                            <select class="form-control author" name="" readonly="readonly"></select>
                        </div>
                        <div class="col-sm-2">
                            <button type="button" id="button_ajax_load" class="btn btn-info">Jump to author</button>
                        </div>
                    </div>
                    @endif
                    @foreach($row as $key => $value)
                        @php
                            $isCreatedField = in_array($key, ['c_created_by', 'c_created_date', 'c_created_date_timestamp_temporary'], true);
                            $isModifiedField = in_array($key, ['c_modified_by', 'c_modified_date', 'c_modified_date_timestamp_temporary'], true);
                            // 显示原始值而不是替换后的值
                            $inputValue = $value;
                            $shouldDisable = $isCreatedField || $isModifiedField;

                            // 为 modified_* 字段准备提示文字
                            $helpText = null;
                            if ($isModifiedField && Auth::check() && Auth::user()->isActive()) {
                                if ($key === 'c_modified_by' && $currentUserName !== null) {
                                    $helpText = '欄位內容提交後會被替換為：' . $currentUserName;
                                } elseif ($key === 'c_modified_date') {
                                    $helpText = '欄位內容提交後會被替換為：' . $currentDateYmd;
                                } elseif ($key === 'c_modified_date_timestamp_temporary') {
                                    $helpText = '欄位內容提交後會被替換為：' . $currentTimestampTaipei . ' (GMT+8)';
                                }
                            }
                        @endphp
                        <div class="form-group">
                            <label for="{{ $key }}" class="col-sm-2 control-label">{{ $key }}</label>
                            @if($table === 'TEXT_INSTANCE_DATA' && $key === 'c_textid')
                            <div class="col-sm-8">
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $inputValue) }}" @if($shouldDisable) readonly @endif>
                            </div>
                            <div class="col-sm-2">
                                <button type="button" id="button_ajax_load_instance" class="btn btn-info">Load Data</button>
                            </div>
                            <div class="col-sm-offset-2 col-sm-10">
                                <p class="help-block text-muted">請確保 <a href="/codes/TEXT_CODES" target="_blank">TEXT_CODES</a> 表中存在這本書的 c_textid，再複製 ID 填入</p>
                            </div>
                            @elseif($table === 'ADDR_BELONGS_DATA' && $key === 'c_addr_id')
                            <div class="col-sm-10">
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $inputValue) }}" @if($shouldDisable) readonly @endif>
                                <p class="help-block text-muted">請從 <a href="/codes/ADDR_CODES" target="_blank">ADDR_CODES</a> 表中複製 c_addr_id 填入</p>
                                @if($helpText)
                                <p class="help-block text-info"><strong>{{ $helpText }}</strong></p>
                                @endif
                            </div>
                            @elseif($table === 'ADDR_BELONGS_DATA' && $key === 'c_belongs_to')
                            <div class="col-sm-10">
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $inputValue) }}" @if($shouldDisable) readonly @endif>
                                <p class="help-block text-muted">請從 <a href="/codes/ADDR_CODES" target="_blank">ADDR_CODES</a> 表中複製 c_addr_id 填入</p>
                                @if($helpText)
                                <p class="help-block text-info"><strong>{{ $helpText }}</strong></p>
                                @endif
                            </div>
                            @else
                            <div class="col-sm-10">
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $inputValue) }}" @if($shouldDisable) readonly @endif>
                                @if($helpText)
                                <p class="help-block text-info"><strong>{{ $helpText }}</strong></p>
                                @endif
                            </div>
                            @endif
                        </div>
                    @endforeach
                    @if(Auth::check() && Auth::user()->isActive())
                    <div class="form-group">
                        <label for="__proposal_comment" class="col-sm-2 control-label">提案說明</label>
                        <div class="col-sm-10">
                            <textarea name="__proposal_comment" id="__proposal_comment" class="form-control" rows="3" placeholder="僅在提交提案時填寫（選填）">{{ old('__proposal_comment') }}</textarea>
                            <p class="help-block">如果直接儲存，此欄位會被忽略。</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="col-sm-offset-2 col-sm-10">
                            <button type="submit" class="btn btn-primary">直接儲存</button>
                            <button type="submit" class="btn btn-info"
                                    formaction="{{ route('codes.propose.update', ['table_name' => $table, 'id' => $id], false) }}">
                                提交提案
                            </button>
                        </div>
                    </div>
                    @endif
                </form>
            </div>
        </div>
    </div>

@endsection
@section('js')
@if($table === 'TEXT_CODES')
<script>
    author_first_load();
    function author_first_load(){
        let c_textid = $("input[name='c_textid']").val();
        let data = [{
            id: 0,
            text: 'author'
        }];
        $.get('/api/select/search/textauthor', {q: c_textid}, function (data, textStatus){
            for (let i=data.data.length-1; i>-1; i--){
                item = data.data[i];
                $(".author").append(new Option(item['text'], item['value']));
            }
        });
    }

    $("#button_ajax_load").click(function(){
        let author = $(".author").val();
        if (!author) {
            return;
        }
        let url = "/basicinformation/" + author + "/texts";
        let new_window = window.open('_blank');
        new_window.location = url ;
    });
</script>
@endif
@if($table === 'TEXT_INSTANCE_DATA')
<script type="text/javascript">
$(document).ready(function (){

    var DoAjax = function(requestUrl, sentData, sHandler, eHandler, pageNotFoundHandler){
        $.ajax({
            type: 'GET',
            url: requestUrl,
            cache: false,
            data: sentData,
            success: sHandler,
            error: eHandler,
            statusCode: {
              404: pageNotFoundHandler
            }
        });
    };

    /* Load Data from TEXT_CODES */
    $("#button_ajax_load_instance").click(function(){

        var c_textid = $("input[name='c_textid']").val();
        var url = "/api/select/search/text?q=" + c_textid + "";
        /* disable trigger button, preventing multiple requests */
        $(this).attr("disabled", true);

        /* wait for ajax */
        setTimeout(function(){

            DoAjax(url, {todo : "exSucceed"},
                function(data, textStatus, jqXHR){
                    if(data.data == '') {
                        alert('Load Data 沒有查詢到資料');
                    }
                    else if(data.data != '') {
                        /* 在這裡添加錄入表單更新的欄位與資料 */
                        $("input[name='c_instance_title_chn']").val(data.data[0].c_title_chn);
                        $("input[name='c_instance_title_chn']").css("background","#FFFFBB");
                        $("input[name='c_instance_title']").val(data.data[0].c_title);
                        $("input[name='c_instance_title']").css("background","#FFFFBB");
                        alert('Load Data 更新[c_instance_title_chn]與[c_instance_title]成功');
                    }
                    else {
                        alert('Load Data 查詢失敗');
                    }
                });

            /* enable trigger button */
            $("#button_ajax_load_instance").attr("disabled", false);
        }, 10);
    });

});
</script>
@endif
@endsection
