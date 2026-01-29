{{-- 共享表单组件 - Offices --}}
@php
    $isEdit = isset($row);
    $formAction = $isEdit
        ? route('basicinformation.offices.update', ['basicinformation' => $id, 'office' => $row->c_office_id.'-'.$row->c_posting_id])
        : route('basicinformation.offices.store', ['basicinformation' => $id]);
@endphp

<form id="{{ $isEdit ? 'office-edit-form' : '' }}" action="{{ $formAction }}" method="post">
    @if($isEdit)
        {{ method_field('PATCH') }}
        <input name="_id" type="hidden" value="{{ $id }}">
        <input name="_postingid" type="hidden" value="{{ $row->c_posting_id }}">
        <input name="_officeid" type="hidden" value="{{ $row->c_office_id }}">
    @endif
    {{ csrf_field() }}

    <x-forms.person-id-display :personId="$id" />

    @if($isEdit)
        <div class="form-group row">
            <label for="person_id" class="col-sm-2 col-form-label">posting_id</label>
            <div class="col-sm-10">
                <input type="text" class="form-control" value="{{ $row->c_posting_id }}" disabled>
            </div>
        </div>
    @endif

    <div class="form-group row">
        <label for="person_id" class="col-sm-2 col-form-label">次序(sequence)</label>
        <div class="col-sm-10">
            <input name="c_sequence" type="text" class="form-control" value="{{ $isEdit ? $row->c_sequence : '' }}" maxlength="4">
            <p>註:若有同時任命的官職, 請手動填上相同的sequence</p>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_office_id" class="col-sm-2 col-form-label">官名(office_id)</label>
        <div class="col-sm-10">
            <select class="form-control c_office_id" name="c_office_id">
                @if($isEdit && isset($res['office_str']))
                    <option value="{{ $row->c_office_id }}" selected="selected">{{ $res['office_str'] }}</option>
                @else
                    <option value="0">0 unknown 未详</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_inst_code" class="col-sm-2 col-form-label">社交機構(social_institution)</label>
        @if($isEdit)
            <input name="c_inst_name_code" type="hidden">
        @endif
        <div class="col-sm-10">
            <select class="form-control c_inst_code" name="c_inst_code">
                @if($isEdit && isset($res['posting_str']))
                    <option value="{{ $row->c_inst_code.'-'.$row->c_inst_name_code }}" selected="selected">{{ $res['posting_str'] }}</option>
                @else
                    <option value="0">0 unknown 未详</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_addr" class="col-sm-2 col-form-label">地名</label>
        <div class="col-sm-10">
            {{-- 隱藏欄位：當用戶清除所有地址時，標記為已清除（因為空的 multi-select 不會傳送欄位） --}}
            <input type="hidden" name="c_addr_cleared" id="c_addr_cleared" value="0">
            <select class="form-control c_addr" name="c_addr[]" multiple="multiple">
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
        <label for="c_source" class="col-sm-2 col-form-label">出處(c_source)</label>
        <div class="col-sm-10">
            <select class="form-control c_source" name="c_source" id="c_source">
                @if($isEdit && isset($res['text_str']))
                    <option value="{{ $row->c_source }}" selected="selected">{{ $res['text_str'] }}</option>
                @else
                    <option value="0" selected>0 Weizhi 未知</option>
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
        <label for="c_firstyear" class="col-sm-2 col-form-label">始年(firstyear)</label>
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
        <label for="c_lastyear" class="col-sm-2 col-form-label">終年(lastyear)</label>
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
        <label for="c_appt_code" class="col-sm-2 col-form-label">除授類別(c_appt_code)</label>
        <div class="col-sm-10">
            <select-vue name="c_appt_code" model="appttype" selected="{{ $isEdit ? $row->c_appt_code : '' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_assume_office_code" class="col-sm-2 col-form-label">是否赴任(c_assume_office_code)</label>
        <div class="col-sm-10">
            <select-vue name="c_assume_office_code" model="assumeoffice" selected="{{ $isEdit ? $row->c_assume_office_code : '' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_office_category_id" class="col-sm-2 col-form-label">職官類別(c_office_category_id)</label>
        <div class="col-sm-10">
            <select-vue name="c_office_category_id" model="officecate" selected="{{ $isEdit ? $row->c_office_category_id : '' }}"></select-vue>
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
        <label for="c_dy" class="col-sm-2 col-form-label">朝代(dy)</label>
        <div class="col-sm-10">
            <select-vue name="c_dy" model="dynasty" selected="{{ $isEdit ? $row->c_dy : '' }}"></select-vue>
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
            <button type="submit" class="btn btn-secondary" id="{{ $isEdit ? 'office-edit-submit' : '' }}">Submit</button>
            @if($isEdit)
                <a href="../../../../basicinformation/{{ $row->c_personid }}/offices/{{ $row->c_office_id.'-'.$row->c_posting_id }}/saveas" class="btn btn-success" style="margin-left:40px;">save as</a>
            @endif
        </div>
    </div>
</form>

@section('js')
    <script>
    onViteReady(function() {
        @if($isEdit)
            var $officeForm = $('#office-edit-form');
            var $submitButton = $('#office-edit-submit');
            var pristineSnapshot = $officeForm.serialize();

            function evaluateFormDirty() {
                var isDirty = $officeForm.serialize() !== pristineSnapshot;
                $submitButton.prop('disabled', !isDirty);
            }

            evaluateFormDirty();

            $officeForm.on('change input', 'input, select, textarea', function () {
                evaluateFormDirty();
            });

            $officeForm.on('submit', function () {
                // 當地址欄位為空時，設置 c_addr_cleared 標記
                // 這樣後端可以區分「用戶沒有修改地址」和「用戶清除了所有地址」
                var addrVal = $('.c_addr').val();
                if (!addrVal || addrVal.length === 0) {
                    $('#c_addr_cleared').val('1');
                } else {
                    $('#c_addr_cleared').val('0');
                }
                pristineSnapshot = $officeForm.serialize();
                $submitButton.prop('disabled', true);
            });
        @endif

        $(".select2").select2();
        textperson_pair_first_load();

        // 使用统一的 AJAX Select2 初始化助手函数
        window.initAjaxSelect($(".c_office_id"), 'office');
        window.initAjaxSelect($(".c_source"), 'text');
        window.initAjaxSelect($(".c_inst_code"), 'socialinstcode');
        window.initAjaxSelect($(".c_addr"), 'addr');

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
