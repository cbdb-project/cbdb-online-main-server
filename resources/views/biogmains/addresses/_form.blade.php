{{-- 共享表单组件 - Addresses --}}
@php
    $isEdit = isset($row);
    $formAction = $isEdit
        ? route('basicinformation.addresses.update', ['basicinformation' => $id, 'address'=> $id.'-'.$row->c_addr_id.'-'.$row->c_addr_type.'-'.$row->c_sequence])
        : route('basicinformation.addresses.store', ['basicinformation' => $id]);
@endphp

<form action="{{ $formAction }}" method="post">
    @if($isEdit)
        {{ method_field('PATCH') }}
    @endif
    {{ csrf_field() }}

    <div class="form-group row">
        <label for="person_id" class="col-sm-2 col-form-label">person id</label>
        <div class="col-sm-10">
            <input type="text" class="form-control person_id" name="person_id" value="{{ $id }}" disabled>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_sequence" class="col-sm-2 col-form-label">遷徙次序</label>
        <div class="col-sm-10">
            <input type="number" class="form-control" name="c_sequence" value="{{ $isEdit ? $row->c_sequence : '' }}" maxlength="4" {{ $isEdit ? '' : 'required' }}>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_addr_type" class="col-sm-2 col-form-label">地址類別(c_addr_type)</label>
        <div class="col-sm-10">
            <select-vue name="c_addr_type" model="biogaddr" selected="{{ $isEdit ? $row->c_addr_type : '0' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_addr_id" class="col-sm-2 col-form-label">地名(c_addr_id)</label>
        <div class="col-sm-10">
            <select class="form-control c_addr_id" name="c_addr_id">
                @if($isEdit && isset($addr_str))
                    <option value="{{ $row->c_addr_id }}" selected="selected">{{ $addr_str }}</option>
                @else
                    <option value="0"> 0[Unknown][未详]</option>
                @endif
            </select>
            @if($isEdit && isset($other_belongs_str) && $other_belongs_str)
                其他上層歸屬資訊：{{$other_belongs_str}}
            @endif
        </div>
    </div>

    <div class="form-group row">
        <label for="c_firstyear" class="col-sm-2 col-form-label">始年(c_firstyear)</label>
        <x-inline-time-fields
            yearName="c_firstyear"
            :yearValue="$isEdit ? $row->c_firstyear : ''"
            nhCodeName="c_fy_nh_code"
            :nhCodeValue="$isEdit ? $row->c_fy_nh_code : ''"
            nhYearName="c_fy_nh_year"
            :nhYearValue="$isEdit ? $row->c_fy_nh_year : ''"
            rangeName="c_fy_range"
            :rangeValue="$isEdit ? $row->c_fy_range : ''"
            :showLunar="true"
            intercalaryName="c_fy_intercalary"
            :intercalaryValue="$isEdit ? $row->c_fy_intercalary : ''"
            monthName="c_fy_month"
            :monthValue="$isEdit ? $row->c_fy_month : ''"
            dayName="c_fy_day"
            :dayValue="$isEdit ? $row->c_fy_day : ''"
            dayGzName="c_fy_day_gz"
            :dayGzValue="$isEdit ? $row->c_fy_day_gz : ''"
        />
    </div>

    <div class="form-group row">
        <label for="c_lastyear" class="col-sm-2 col-form-label">終年(c_lastyear)</label>
        <x-inline-time-fields
            yearName="c_lastyear"
            :yearValue="$isEdit ? $row->c_lastyear : ''"
            nhCodeName="c_ly_nh_code"
            :nhCodeValue="$isEdit ? $row->c_ly_nh_code : ''"
            nhYearName="c_ly_nh_year"
            :nhYearValue="$isEdit ? $row->c_ly_nh_year : ''"
            rangeName="c_ly_range"
            :rangeValue="$isEdit ? $row->c_ly_range : ''"
            :showLunar="true"
            intercalaryName="c_ly_intercalary"
            :intercalaryValue="$isEdit ? $row->c_ly_intercalary : ''"
            monthName="c_ly_month"
            :monthValue="$isEdit ? $row->c_ly_month : ''"
            dayName="c_ly_day"
            :dayValue="$isEdit ? $row->c_ly_day : ''"
            dayGzName="c_ly_day_gz"
            :dayGzValue="$isEdit ? $row->c_ly_day_gz : ''"
        />
    </div>

    <div class="form-group row">
        <label for="c_source" class="col-sm-2 col-form-label">出處(c_source)</label>
        <div class="col-sm-5">
            <select class="form-control c_source" name="c_source" id="c_source">
                @if($isEdit && isset($text_str) && $text_str)
                    <option value="{{ $row->c_source }}" selected="selected">{{ $text_str }}</option>
                @else
                    <option value="" selected="selected">请搜索</option>
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
            <textarea class="form-control" name="c_notes" cols="30" rows="5">{{ $isEdit ? $row->c_notes : '' }}</textarea>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_natal" class="col-sm-2 col-form-label">娘家地址(c_natal)</label>
        <div class="col-sm-10">
            <select class="form-control select2" name="c_natal">
                <option disabled value="">请选择</option>
                <option value="0" {{ ($isEdit && $row->c_natal == 0) ? 'selected' : '' }}>0-否</option>
                <option value="1" {{ ($isEdit && $row->c_natal == 1) ? 'selected' : '' }}>1-是</option>
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

    <x-forms.audit-fields
        :show="$isEdit"
        :createdBy="$isEdit ? $row->c_created_by : null"
        :createdDate="$isEdit ? $row->c_created_date : null"
        :modifiedBy="$isEdit ? $row->c_modified_by : null"
        :modifiedDate="$isEdit ? $row->c_modified_date : null"
    />

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
        window.initAjaxSelect($(".c_addr_id"), 'addr');
        window.initAjaxSelect($(".c_source"), 'text');

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
