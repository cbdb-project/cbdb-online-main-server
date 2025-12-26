{{-- 共享表单组件 - SocialInst --}}
@php
    $isEdit = isset($row);
    $formAction = $isEdit
        ? route('basicinformation.socialinst.update', ['basicinformation' => $id, 'socialinst' => $row->c_personid.'-'.$row->c_inst_code.'-'.$row->c_inst_name_code.'-'.$row->c_bi_role_code])
        : route('basicinformation.socialinst.store', ['basicinformation' => $id]);
@endphp

<form action="{{ $formAction }}" method="post">
    @if($isEdit)
        {{ method_field('PATCH') }}
    @endif
    {{ csrf_field() }}

    <div class="form-group row">
        <label for="person_id" class="col-sm-2 col-form-label">person id</label>
        <div class="col-sm-10">
            <input type="text" class="form-control person_id" value="{{ $id }}" disabled>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_inst_code" class="col-sm-2 col-form-label">社交機構(social_institution)</label>
        @if($isEdit)
            <input name="c_inst_name_code" type="hidden">
        @endif
        <div class="col-sm-10">
            <select class="form-control c_inst_code" name="c_inst_code">
                @if($isEdit && isset($res['inst_code']))
                    <option value="{{ $row->c_inst_code.'-'.$row->c_inst_name_code }}" selected="selected">{{ $res['inst_code'] }}</option>
                @else
                    <option value="0-0" selected="selected">0 [Unknown] [未詳] </option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_bi_role_code" class="col-sm-2 col-form-label">社交機構角色(c_bi_role_code)</label>
        <div class="col-sm-10">
            <select-vue name="c_bi_role_code" model="birole" selected="{{ $isEdit ? $row->c_bi_role_code : '0' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_bi_begin_year" class="col-sm-2 col-form-label">始年(firstyear)</label>
        <x-inline-time-fields
            yearName="c_bi_begin_year"
            :yearValue="$isEdit ? $row->c_bi_begin_year : ''"
            nhCodeName="c_bi_by_nh_code"
            :nhCodeValue="$isEdit ? $row->c_bi_by_nh_code : ''"
            nhYearName="c_bi_by_nh_year"
            :nhYearValue="$isEdit ? $row->c_bi_by_nh_year : ''"
            rangeName="c_bi_by_range"
            :rangeValue="$isEdit ? $row->c_bi_by_range : ''"
        />
    </div>

    <div class="form-group row">
        <label for="c_bi_end_year" class="col-sm-2 col-form-label">終年(lastyear)</label>
        <x-inline-time-fields
            yearName="c_bi_end_year"
            :yearValue="$isEdit ? $row->c_bi_end_year : ''"
            nhCodeName="c_bi_ey_nh_code"
            :nhCodeValue="$isEdit ? $row->c_bi_ey_nh_code : ''"
            nhYearName="c_bi_ey_nh_year"
            :nhYearValue="$isEdit ? $row->c_bi_ey_nh_year : ''"
            rangeName="c_bi_ey_range"
            :rangeValue="$isEdit ? $row->c_bi_ey_range : ''"
        />
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">出處(c_source)</label>
        <div class="col-sm-10">
            <select class="form-control c_source" name="c_source" id="c_source">
                @if($isEdit && isset($res['text_str']))
                    <option value="{{ $row->c_source }}" selected="selected">{{ $res['text_str'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_pages" class="col-sm-2 col-form-label">頁數/條目</label>
        <div class="col-sm-4">
            <input type="text" class="form-control" name="c_pages" value="{{ $isEdit ? $row->c_pages : '' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_notes" class="col-sm-2 col-form-label">註(c_notes)</label>
        <div class="col-sm-10">
            <textarea class="form-control" name="c_notes" id="" cols="30"
                      rows="5">{{ $isEdit ? $row->c_notes : '' }}</textarea>
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

    @if($isEdit)
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
    @endif

    <div class="form-group row">
        <div class="offset-sm-2 col-sm-10">
            <button type="submit" class="btn btn-secondary">Submit</button>
        </div>
    </div>
</form>

@section('js')
    <script>

    onViteReady(function() {
        $(".select2").select2();
        textperson_pair_first_load();
        $(".c_inst_code").select2(options('socialinstcode'));
        $(".c_source").select2(options('text'));

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
