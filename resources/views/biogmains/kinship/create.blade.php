@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">親屬 Kinship</h3>
        </div>
        <div class="card-body">
            <div class="card-body">
            <form action="{{ route('basicinformation.kinship.store', ['basicinformation' => $id]) }}" method="post">
                {{ csrf_field() }}
                <div class="form-group row">
                    <label for="person_id" class="col-sm-2 col-form-label">person id</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control person_id" value="{{ $id }}" disabled>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_kin_code" class="col-sm-2 col-form-label">親屬關係(c_kin_code)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_kin_code" name="c_kin_code" onchange="kinship_pair()">
                            <option value="0" selected="selected">0 未详</option>
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_kin_id" class="col-sm-2 col-form-label">親戚姓名(c_kin_id)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_kin_id" name="c_kin_id">
                            <option value="0" selected="selected">0 未详</option>
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="" class="col-sm-2 col-form-label">出處(c_source)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_source" name="c_source" id="c_source">
                            <option value="0" selected="selected">0 未详</option>
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_pages" class="col-sm-2 col-form-label">頁數/條目</label>
                    <div class="col-sm-4">
                        <input type="text" class="form-control" name="c_pages" value="">
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_notes" class="col-sm-2 col-form-label">注(c_notes)</label>
                    <div class="col-sm-10">
                        <textarea class="form-control" name="c_notes" id="" cols="30"
                                  rows="5"></textarea>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_autogen_notes" class="col-sm-2 col-form-label">c_autogen_notes</label>
                    <div class="col-sm-10">
                        <textarea class="form-control" name="c_autogen_notes" id="" cols="30"
                                  rows="5"></textarea>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="" class="col-sm-2 col-form-label">成對親屬關係</label>
                    <div class="col-sm-10">
                        <select class="form-control c_kinship_pair" name="c_kinship_pair">
                            <option value="0">無對應親屬關係</option>
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="textperson_pair" class="col-sm-2 col-form-label">候選出處與頁數</label>
                    <div class="col-sm-10">
                        <select class="form-control textperson_pair" name="">
                            <option value="">由此選取[出處]頁面中的出處與頁碼資訊</option>
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <div class="offset-sm-2 col-sm-10">
                        <button type="submit" class="btn btn-secondary">Submit</button>
                    </div>
                </div>
            </form>
            </div>
        </div>
    </div>

@endsection
@section('js')
    <script>

    onViteReady(function() {
        $(".select2").select2();
        textperson_pair_first_load();
        $(".c_kinship_pair").select2();
        $(".c_source").select2(options('text'));
        $(".c_kin_code").select2(options('kincode'));
        $(".c_kin_id").select2(options('biog'));

        function formatRepo (repo) {
            if (repo.loading) {
                return repo.text;
            }

            return "<div class='select2-result-repository clearfix'>" +
                "<div class='select2-result-repository__meta'>" +
                "<div class='select2-result-repository__title'>" +
                repo.text +
                "</div></div></div>";
        }

        function formatRepoSelection (repo) {
            return repo.text || repo.text;
        }

        function options(model) {
            return {
                ajax: {
                    url: "/api/select/search/"+model,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            q: params.term, // search term
                            page: params.page,
                        };
                    },
                    processResults: function (data, params) {
                        // parse the results into the format expected by Select2
                        // since we are using custom formatting functions we do not need to
                        // alter the remote JSON data, except to indicate that infinite
                        // scrolling can be used
                        params.page = params.page || 1;

                        return {
                            results: data.data,
                            pagination: {
                                more: (params.page * 30) < data.total
                            }
                        };
                    },
                    cache: true
                },
                placeholder: '请搜索',
                escapeMarkup: function (markup) { return markup; }, // let our custom formatter work
                minimumInputLength: 1,
                templateResult: formatRepo,
                templateSelection: formatRepoSelection
            }
        }

        function kinship_pair(){
            let c_kin_code = $('.c_kin_code').val();
            let c_kin_id = $('.c_kin_id').val();
            // console.log(c_kin_id, c_kin_code);
            // if (c_kin_id == 0 || c_kin_id == -999) {return}
            let data = [{
                id: 0,
                text: '请选择对应亲属关系'
            }];
            // $(".c_kinship_pair").val(null).trigger("change");
            // console.log($(".c_kinship_pair").val());
            $.get('/api/select/search/kinpair', {kin_code: c_kin_code, person_id: c_kin_id}, function (data, textStatus){
                //返回的 data 可以是 xmlDoc, jsonObj, html, text, 等等.
                // console.log(data);
                for (let i=data.length-1; i>-1; i--){

                    item = data[i];
                    // console.log(item);
                    //$(".c_kinship_pair").append(new Option(item['c_kinrel'] + ' ' + item['c_kinrel_chn'], item['c_kincode'], false, true));
                    $(".c_kinship_pair").append(new Option(item['c_kincode'] + ' ' + item['c_kinrel_chn'] + ' ' + item['c_kinrel'], item['c_kincode'], false, true));
                }
            });

        }

        function textperson_pair_first_load(){
            let person_id = $('.person_id').val();
            //console.log(person_id);
            let data = [{
                id: 0,
                text: '請填寫[人物 >> 出處]'
            }];
            $.get('/api/select/search/textperson', {q: person_id}, function (data, textStatus){
                //console.log(data);
                for (let i=data.data.length-1; i>-1; i--){
                    item = data.data[i];
                    //console.log(item);
                    $(".textperson_pair").append(new Option(item['text'], item['value']));
                }
            });
        }

        $(".textperson_pair").change(function(){
            var hasValue = $(".textperson_pair").val();
            //console.log(hasValue);
            var textperson_value = hasValue.split("&and&");
            $.get('/api/select/search/text', {q: textperson_value[0]}, function (data, textStatus){
                //console.log(data);
                for (var i=data.data.length-1; i>-1; i--){
                    item = data.data[i];
                    console.log(item);
                    var textperson_text = item['text'];
                }
                //console.log(textperson_value);
                /*在這裡添加錄入表單更新的欄位與資料*/
                $("select[name='c_source'] option[selected]").val(textperson_value[0]);
                $("select[name='c_source']").val(textperson_value[0]);
                $("#select2-c_source-container").text(textperson_text);
                $("#select2-c_source-container").css("background","#FFFFBB");
                $("input[name='c_pages']").val(textperson_value[1]);
                $("input[name='c_pages']").css("background","#FFFFBB");
                alert('更新[出處]與[頁數/條目]成功');
            });
        });
    });

    </script>
@endsection
