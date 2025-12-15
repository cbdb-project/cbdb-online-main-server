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
                <form action="{{ route('codes.update', ['table_name' => $table, 'id' => $id], false) }}" method="post">
                    {{ method_field('PATCH') }}
                    {{ csrf_field() }}
                    @if($table === 'TEXT_CODES')
                    <div class="form-group row">
                        <label for="author" class="col-sm-2 col-form-label">author</label>
                        <div class="col-sm-8">
                            <select class="form-control author js-person-select" name="" disabled></select>
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
                            @elseif($table === 'ALTNAME_DATA' && $key === 'c_personid')
                            <div class="col-sm-10">
                                <select class="form-control altname-person js-person-select" name="{{ $key }}" data-initial-id="{{ $inputValue }}">
                                    <option value="{{ $inputValue }}" selected>{{ $inputValue }}</option>
                                </select>
                                @if($helpText)
                                <p class="help-block text-info"><strong>{{ $helpText }}</strong></p>
                                @endif
                            </div>
                            @elseif($key === 'c_personid' || $key === 'c_kin_id')
                            <div class="col-sm-10">
                                <select class="form-control person-select js-person-select" name="{{ $key }}" data-initial-id="{{ $inputValue }}">
                                    <option value="{{ $inputValue }}" selected>{{ $inputValue }}</option>
                                </select>
                                @if($helpText)
                                <p class="help-block text-info"><strong>{{ $helpText }}</strong></p>
                                @endif
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
        const $author = $('.author');

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
                        const option = new Option(item.text, item.value, false, false);
                        $author.append(option);
                    });
                }
                $author.trigger('change');
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
@php
    $hasPersonIdField = in_array('c_personid', array_keys($row ?? []), true) || in_array('c_kin_id', array_keys($row ?? []), true);
@endphp
@if(in_array($table, ['ALTNAME_DATA', 'BIOG_ADDR_DATA']) || $hasPersonIdField)
<script>
    onViteReady(function() {
        const $persons = $('.js-person-select');

        $persons.each(function() {
            const $person = $(this);
            const initialId = $person.data('initial-id') || $person.data('initial-value') || $person.val();

            window.initPersonSelect($person, {
                placeholder: '輸入姓名或 ID 搜尋人物',
            });

            if (initialId) {
                window.fetchPersonOption(initialId).then(function(opt) {
                    if (opt) {
                        const option = new Option(opt.text, opt.id, true, true);
                        $person.append(option).trigger('change.select2');
                    } else {
                        $person.val(initialId).trigger('change.select2');
                    }
                });
            }
        });
    });
</script>
@endif
@endsection
