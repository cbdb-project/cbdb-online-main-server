{{-- 共享表单组件 - Events --}}
@php
    $isEdit = isset($row);
    $formAction = $isEdit
        ? route('basicinformation.events.update', ['basicinformation' => $id, 'event' => $row->c_sequence])
        : route('basicinformation.events.store', ['basicinformation' => $id]);
@endphp

<form action="{{ $formAction }}" method="post">
    @if($isEdit)
        {{ method_field('PATCH') }}
        <input type="hidden" name="c_event_record_id" value="{{ $row->c_event_record_id }}">
    @endif
    {{ csrf_field() }}

    <div class="form-group row">
        <label for="person_id" class="col-sm-2 col-form-label">person id</label>
        <div class="col-sm-10">
            <input type="text" class="form-control person_id" value="{{ $id }}" disabled>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_sequence" class="col-sm-2 col-form-label">次序(sequence)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_sequence" value="{{ $isEdit ? $row->c_sequence : '' }}" maxlength="4">
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">事件名稱</label>
        <div class="col-sm-10">
            <select class="form-control c_event_code" name="c_event_code">
                @if($isEdit && isset($res['event_str']))
                    <option value="{{ $row->c_event_code }}" selected="selected">{{ $res['event_str'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_role" class="col-sm-2 col-form-label">傳主在該事件中角色</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_role" value="{{ $isEdit ? $row->c_role : '' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_year" class="col-sm-2 col-form-label">事件發生年</label>
        <x-inline-time-fields
            yearName="c_year"
            :yearValue="$isEdit ? $row->c_year : ''"
            nhCodeName="c_nh_code"
            :nhCodeValue="$isEdit ? $row->c_nh_code : ''"
            nhYearName="c_nh_year"
            :nhYearValue="$isEdit ? $row->c_nh_year : ''"
            rangeName="c_yr_range"
            :rangeValue="$isEdit ? $row->c_yr_range : ''"
            :showLunar="true"
            intercalaryName="c_intercalary"
            :intercalaryValue="$isEdit ? $row->c_intercalary : ''"
            monthName="c_month"
            :monthValue="$isEdit ? $row->c_month : ''"
            dayName="c_day"
            :dayValue="$isEdit ? $row->c_day : ''"
            dayGzName="c_day_ganzhi"
            :dayGzValue="$isEdit ? $row->c_day_ganzhi : ''"
            nhLabel="事件年號"
        />
    </div>

    <div class="form-group row">
        <label for="c_addr_id" class="col-sm-2 col-form-label">地名</label>
        <div class="col-sm-10">
            <select class="form-control c_addr_id" name="c_addr_id[]" multiple="multiple">
                @if($isEdit && isset($res['addr_str']))
                    @foreach($res['addr_str'] as $item)
                        <option value="{{ $item[0] }}" selected="selected">{{ $item[1] }}</option>
                    @endforeach
                @else
                    <option value="0" selected>未详</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">出處</label>
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
        <label for="c_event" class="col-sm-2 col-form-label">大事件</label>
        <div class="col-sm-10">
            <textarea class="form-control" name="c_event" id="" cols="30"
                      rows="5">{{ $isEdit ? $row->c_event : '' }}</textarea>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_notes" class="col-sm-2 col-form-label">註</label>
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

        // 使用统一的 AJAX Select2 初始化助手函数
        window.initAjaxSelect($(".c_source"), 'text');
        window.initAjaxSelect($(".c_addr_id"), 'addr');
        window.initAjaxSelect($(".c_event_code"), 'event');

        if (window.initLunarValidation) {
            window.initLunarValidation();
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
