@extends('layouts.dashboard-v3')

@section('content')

    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ $table }}</h3>
        </div>
        <div class="card-body">
                <form action="/codes/{{ $table }}" method="post">
                    {{ csrf_field() }}
                    @php($i = 1)
                    @foreach($row as $key)
                        <div class="form-group row">
                            <label for="{{ $key }}" class="col-sm-2 col-form-label">{{ $key }}</label>
                            @if($table === 'TEXT_INSTANCE_DATA' && $key === 'c_textid')
                            <div class="col-sm-8">
                                @php($defaultValue = $defaults[$key] ?? ($i === 1 ? $id : null))
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $defaultValue) }}">
                            </div>
                            <div class="col-sm-2">
                                <button type="button" id="button_ajax_load" class="btn btn-info">Load Data</button>
                            </div>
                            <div class="offset-sm-2 col-sm-10">
                                <p class="help-block text-muted">請確保 <a href="/codes/TEXT_CODES" target="_blank">TEXT_CODES</a> 表中存在這本書的 c_textid，再複製 ID 填入</p>
                            </div>
                            @elseif($table === 'ADDR_BELONGS_DATA' && $key === 'c_addr_id')
                            <div class="col-sm-10">
                                @php($defaultValue = $defaults[$key] ?? ($i === 1 ? $id : null))
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $defaultValue) }}">
                                <p class="help-block text-muted">請從 <a href="/codes/ADDR_CODES" target="_blank">ADDR_CODES</a> 表中複製 c_addr_id 填入</p>
                            </div>
                            @elseif($table === 'ADDR_BELONGS_DATA' && $key === 'c_belongs_to')
                            <div class="col-sm-10">
                                @php($defaultValue = $defaults[$key] ?? ($i === 1 ? $id : null))
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $defaultValue) }}">
                                <p class="help-block text-muted">請從 <a href="/codes/ADDR_CODES" target="_blank">ADDR_CODES</a> 表中複製 c_addr_id 填入</p>
                            </div>
                            @else
                            <div class="col-sm-10">
                                @php($defaultValue = $defaults[$key] ?? ($i === 1 ? $id : null))
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $defaultValue) }}">
                            </div>
                            @endif
                        </div>
                    @php($i++)
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
                            <button type="submit" class="btn btn-info" formaction="{{ route('codes.propose.store', ['table_name' => $table], false) }}">提交提案</button>
                        </div>
                    </div>
                    @endif
                </form>
        </div>
    </div>

@endsection
@section('js')
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
    $("#button_ajax_load").click(function(){

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
            $("#button_ajax_load").attr("disabled", false);
        }, 10);
    });

});
</script>
@endif
@endsection
