@extends('layouts.dashboard-v3')

@section('content')

    @php
        $currentUserName = optional(Auth::user())->name;
        $currentDateYmd = \Carbon\Carbon::now()->format('Ymd');
        $currentTimestampTaipei = \Carbon\Carbon::now('Asia/Taipei')->format('Y-m-d H:i:s');
    @endphp

    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ $table }}</h3>
        </div>
        <div class="card-body">
                <form action="/codes/{{ $table }}/{{ $id }}" method="post">
                    {{ method_field('PATCH') }}
                    {{ csrf_field() }}
                    @if($table === 'TEXT_CODES')
                    <div class="form-group row">
                        <label for="author" class="col-sm-2 col-form-label">author</label>
                        <div class="col-sm-8">
                            <select class="form-control author" name=""></select>
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
                        <div class="form-group row">
                            <label for="{{ $key }}" class="col-sm-2 col-form-label">{{ $key }}</label>
                            @if($table === 'TEXT_INSTANCE_DATA' && $key === 'c_textid')
                            <div class="col-sm-8">
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $inputValue) }}" @if($shouldDisable) readonly @endif>
                            </div>
                            <div class="col-sm-2">
                                <button type="button" id="button_ajax_load_instance" class="btn btn-info">Load Data</button>
                            </div>
                            <div class="offset-sm-2 col-sm-10">
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
                    <div class="form-group row">
                        <label for="__proposal_comment" class="col-sm-2 col-form-label">提案說明</label>
                        <div class="col-sm-10">
                            <textarea name="__proposal_comment" id="__proposal_comment" class="form-control" rows="3" placeholder="僅在提交提案時填寫（選填）">{{ old('__proposal_comment') }}</textarea>
                            <p class="help-block">如果直接儲存，此欄位會被忽略。</p>
                        </div>
                    </div>
                    <div class="form-group row">
                        <div class="col-sm-10 offset-sm-2">
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

@endsection
@section('js')
@if($table === 'TEXT_CODES')
<script>
    onViteReady(function() {
        // 初始化 select2
        const $author = $('.author');
        $author.select2({
            width: '100%',
            placeholder: '輸入姓名或 ID 搜尋作者',
            theme: 'bootstrap4',
            minimumInputLength: 1,
            ajax: {
                url: '/api/name',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        q: params.term,
                        num: 20,
                    };
                },
                processResults: function(data) {
                    const rows = Array.isArray(data?.data) ? data.data : [];
                    const results = rows.map(function(item) {
                        const parts = [];
                        if (item.c_name_chn) {
                            parts.push(item.c_name_chn);
                        }
                        if (item.c_name) {
                            parts.push(item.c_name);
                        }
                        const dynasty = item.c_dynasty_chn ? `（${item.c_dynasty_chn}）` : '';
                        const zi = item.c_alt_name_chn_zi ? `，字：${item.c_alt_name_chn_zi}` : '';
                        const hao = item.c_alt_name_chn_hao ? `，號：${item.c_alt_name_chn_hao}` : '';
                        const addr = item.ADDR_c_name_chn ? `，籍：${item.ADDR_c_name_chn}` : '';
                        const label = `${parts.join(' / ')}${dynasty}${zi}${hao}${addr}`;
                        return {
                            id: item.c_personid,
                            text: label || item.c_personid,
                        };
                    });
                    return { results };
                },
            },
        });

        // 首次載入作者列表（沿用舊行為：按 textid 預先帶出作者供跳轉）
        const author_first_load = function() {
            const c_textid = $("input[name='c_textid']").val();
            if (!c_textid) {
                return;
            }
            $.get('/api/select/search/textauthor', { q: c_textid }, function (data) {
                $author.empty();
                if (data && Array.isArray(data.data)) {
                    data.data.forEach(function(item) {
                        $author.append(new Option(item.text, item.value, false, false));
                    });
                }
                $author.trigger('change.select2');
            });
        };

        author_first_load();

        $("#button_ajax_load").click(function(){
            const author = $author.val();
            if (!author) {
                return;
            }
            const url = "/basicinformation/" + author + "/texts";
            const new_window = window.open('_blank');
            new_window.location = url ;
        });
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
