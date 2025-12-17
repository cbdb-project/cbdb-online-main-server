@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">財產 Possession</h3>
        </div>
        <div class="card-body">
            <div class="card-body">
            <form action="{{ route('basicinformation.possession.update', ['basicinformation' => $id, 'possession' => $row->c_possession_record_id]) }}"  method="post">
                {{ method_field('PATCH') }}
                {{ csrf_field() }}
                <div class="form-group row">
                    <label for="person_id" class="col-sm-2 col-form-label">person id</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control person_id" value="{{ $id }}" disabled>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_sequence" class="col-sm-2 col-form-label">次序(entry_sequence)</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_sequence" value="{{ $row->c_sequence }}" maxlength="4">
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_possession_act_code" class="col-sm-2 col-form-label">行為&#60;擁有、捐出等&#62;(possession_act_code)</label>
                    <div class="col-sm-10">
                        <select-vue name="c_possession_act_code" model="possact" selected="{{ $row->c_possession_act_code }}"></select-vue>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_possession_desc" class="col-sm-2 col-form-label">財產&#60;英文描述&#62;(possession_desc)</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_possession_desc" value="{{ $row->c_possession_desc }}">
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_possession_desc_chn" class="col-sm-2 col-form-label">財產&#60;中文描述&#62;(possession_desc_chn)</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_possession_desc_chn" value="{{ $row->c_possession_desc_chn }}">
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_quantity" class="col-sm-2 col-form-label">數量(quantity)</label>
                    <div class="col-md-1">
                        <input type="text" name="c_quantity" class="form-control"
                               value="{{ $row->c_quantity }}">
                    </div>
                    <div class="col-md-5 from-inline">
                        <label for="c_measure_code">度量單位(measure_code)</label>
                        <select-vue name="c_measure_code" model="measure" selected="{{ $row->c_measure_code }}"></select-vue>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_possession_yr" class="col-sm-2 col-form-label">年份(possession_yr)</label>
                    <div class="col-md-1">
                        <input type="number" name="c_possession_yr" class="form-control"
                               value="{{ $row->c_possession_yr }}">
                    </div>
                    <div class="col-md-2 from-inline">
                        <label for="c_possession_nh_code">年号</label>
                        <select-vue name="c_possession_nh_code" model="nianhao" selected="{{ $row->c_possession_nh_code }}"></select-vue>
                        <input type="number" name="c_possession_nh_yr" class="form-control"
                               value="{{ $row->c_possession_nh_yr }}">
                        <span for="c_possession_nh_yr">年</span>
                    </div>
                    <div class="col-md-3">
                        <label for="c_possession_yr_range">時限</label>
                        <select-vue name="c_possession_yr_range" model="range" selected="{{ $row->c_possession_yr_range }}"></select-vue>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_addr_id" class="col-sm-2 col-form-label">地名</label>
                    <div class="col-sm-10">
                        <select class="form-control c_addr_id" name="c_addr_id[]" multiple="multiple">
                            @if($res['addr_str'])
                                @foreach($res['addr_str'] as $item)
                                    <option value="{{ $item[0] }}" selected="selected">{{ $item[1] }}</option>
                                @endforeach
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="" class="col-sm-2 col-form-label">出處(c_source)</label>
                    <div class="col-sm-10">
                        <select class="form-control c_source" name="c_source" id="c_source">
                            @if($res['text_str'])
                                <option value="{{ $row->c_source }}" selected="selected">{{ $res['text_str'] }}</option>
                            @else
                                <option value="" selected="selected">请搜索</option>
                            @endif
                        </select>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_pages" class="col-sm-2 col-form-label">頁數/條目</label>
                    <div class="col-sm-4">
                        <input type="text" class="form-control" name="c_pages" value="{{ $row->c_pages }}">
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_notes" class="col-sm-2 col-form-label">注(c_notes)</label>
                    <div class="col-sm-10">
                        <textarea class="form-control" name="c_notes" id="" cols="30"
                                  rows="5">{{ $row->c_notes }}</textarea>
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
                    <label for="" class="col-sm-2 col-form-label">建檔</label>
                    <div class="col-sm-10">
                        <input type="text" name="" class="form-control"
                               value="{{ $row->c_created_by.'/'.$row->c_created_date }}"
                               disabled>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="" class="col-sm-2 col-form-label">更新</label>
                    <div class="col-sm-10">
                        <input type="text" name="" class="form-control"
                               value="{{ $row->c_modified_by.'/'.$row->c_modified_date }}"
                               disabled>
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
        $(".c_source").select2(options('text'));
        $(".c_addr_id").select2(options('officeaddr'));

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
