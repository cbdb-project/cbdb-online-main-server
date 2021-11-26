@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">著作版本表</div>
        <div class="panel-body">
            <div class="panel-body">
                <form action="/textinstancedata/{{ $id }}" class="form-horizontal" method="post">
                    {{ method_field('PATCH') }}
                    {{ csrf_field() }}
                    @foreach($row->toArray() as $key => $value)
                        @if ($key == 'c_textid')
                        <div class="form-group">
                            <label for="{{ $key }}" class="col-sm-2 control-label">{{ $key }}</label>
                            <div class="col-sm-8">
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ $value }}">
                            </div>
                            <div class="col-sm-2">
                                <button type="button" id="button_ajax_load" class="btn btn-info">Load Data</button>
                            </div>
                        </div>
                        @else
                        <div class="form-group">
                            <label for="{{ $key }}" class="col-sm-2 control-label">{{ $key }}</label>
                            <div class="col-sm-10">
                                <input type="text" name="{{ $key }}" class="form-control" value="{{ $value }}" 
                                @if ($key == 'c_created_by' || $key == 'c_created_date' || $key == 'c_modified_by' || $key == 'c_modified_date')
                                    disabled="disabled"
                                @endif
                                >
                            </div>
                        </div>
                        @endif
                    @endforeach
                    <div class="form-group">
                        <div class="col-sm-offset-2 col-sm-10">
                            <button type="submit" class="btn btn-default">Submit</button>
                        </div>
                    </div>
<!-- HTML -->
<!--
<div id="Ajax Load Data">    
    <div id="div_ajax_show" class="div_ajax_show">顯示結果區</div>
    伺服器回傳原始資料: <input type="text" id="input_ajax_data" value="" style="width:400px">
    <div style="text-align:center;padding-top:10px">
        <button type="button" id="button_ajax_load">模擬成功的ajax請求</button>
    </div>
</div>
-->
<!-- HTML End -->
                </form>
            </div>
        </div>
    </div>

@endsection
@section('js')
<!-- Javascript -->
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
 
    /* Simulate succeed ajax */
    $("#button_ajax_load").click(function(){

        /*修改這兩行參數就可以更換ajax查詢*/
        var c_textid = $("input[name='c_textid']").val();
        var url = "/api/select/search/text?q=" + c_textid + "";
        /* disable trigger button, preventing multiple requests */
        $(this).attr("disabled", true);
         
        /* show requesting message */
        $("#div_ajax_show").html("requsting....");
        $("#input_ajax_data").val("");

        /* wait 2 seconds before sending ajax */
        setTimeout(function(){

            DoAjax(url, {todo : "exSucceed"},
                function(data, textStatus, jqXHR){
                    var html = [];
                    html.push("Request url : ", url, "<br>",
                                "Server response : ", data, "<br>",
                                "Status code : ", jqXHR.status, "<br>",
                                "Status text : ", jqXHR.statusText);
                            
                    $("#div_ajax_show").html(html.join(''));
                    //$("#input_ajax_data").val(data);
                    //console.log(data);
                    if(data.data == '') { alert('Load Data 沒有查詢到資料'); }
                    else if(data.data != '') {
                        /*在這裡添加錄入表單更新的欄位與資料*/
                        $("#input_ajax_data").val(data.data[0].c_title_chn);
                        $("input[name='c_instance_title_chn']").val(data.data[0].c_title_chn);
                        $("input[name='c_instance_title_chn']").css("background","#FFFFBB");
                        $("input[name='c_instance_title']").val(data.data[0].c_title);
                        $("input[name='c_instance_title']").css("background","#FFFFBB");
                        alert('Load Data 更新[c_instance_title_chn]與[c_instance_title]成功');
                    }
                    else { alert('Load Data 查詢失敗'); }
                });    
 
            /* enable trigger button */
            $("#button_ajax_load").attr("disabled", false);
        }, 10);
    });    
 
});
</script>
<!-- Javascript End -->
@endsection
