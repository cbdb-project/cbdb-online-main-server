@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">著作版本表</div>
        <div class="panel-body">
            <div class="panel-body">
                <form action="{{ route('textinstancedata.store') }}" class="form-horizontal" method="post">
                    {{ csrf_field() }}
                    <div class="form-group">
                        <label for="c_textid" class="col-sm-2 control-label">c_textid</label>
                        <div class="col-sm-8">
                            <input type="text" name="c_textid" class="form-control" value="{{ $temp_id or '' }}" {{ $temp_id or '' }} placeholder="請先從TEXT_CODES表中複製這本書的c_textid填入" required>
                        </div>
                        <div class="col-sm-2">
                            <button type="button" id="button_ajax_load" class="btn btn-info">Load Data</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="c_instance_title_chn" class="col-sm-2 control-label">c_instance_title_chn（中）</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_instance_title_chn" class="form-control" placeholder="c_instance_title_chn（中）" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="c_instance_title" class="col-sm-2 control-label">c_instance_title（英）</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_instance_title" class="form-control" placeholder="c_instance_title（英）" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="c_text_edition_id " class="col-sm-2 control-label">c_text_edition_id （版本 ID）</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_text_edition_id" class="form-control" placeholder="c_text_edition_id （版本 ID）" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="c_text_instance_id" class="col-sm-2 control-label">c_text_instance_id（版本實例 ID）</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_text_instance_id" class="form-control" placeholder="c_instance_title（版本實例 ID）" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="col-sm-offset-2 col-sm-10">
                            <button type="submit" class="btn btn-default">Submit</button>
                        </div>
                    </div>
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
