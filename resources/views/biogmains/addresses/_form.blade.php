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

    <x-forms.person-id-display :personId="$id" />

    <div class="form-group row">
        <label for="c_sequence" class="col-sm-2 col-form-label">{{ __('biogmains.migration_sequence') }}</label>
        <div class="col-sm-10">
            <input type="number" class="form-control" name="c_sequence" value="{{ $isEdit ? $row->c_sequence : '0' }}" maxlength="4" required>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_addr_type" class="col-sm-2 col-form-label">{{ __('biogmains.address_type') }} (c_addr_type)</label>
        <div class="col-sm-10">
            <select-vue name="c_addr_type" model="biogaddr" selected="{{ $isEdit ? $row->c_addr_type : '0' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_addr_id" class="col-sm-2 col-form-label">{{ __('biogmains.place_name') }} (c_addr_id)</label>
        <div class="col-sm-10">
            <select class="form-control c_addr_id" name="c_addr_id">
                @if($isEdit && isset($addr_str))
                    <option value="{{ $row->c_addr_id }}" selected="selected">{{ $addr_str }}</option>
                @else
                    <option value="0"> 0[Unknown][未详]</option>
                @endif
            </select>
            @if($isEdit && isset($other_belongs_str) && $other_belongs_str)
                {{ __('biogmains.other_upper_info') }}: {{$other_belongs_str}}
            @endif
        </div>
    </div>

    <div class="form-group row">
        <label for="c_firstyear" class="col-sm-2 col-form-label">{{ __('biogmains.start_year') }} (c_firstyear)</label>
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
        <label for="c_lastyear" class="col-sm-2 col-form-label">{{ __('biogmains.end_year') }} (c_lastyear)</label>
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
        <label for="c_source" class="col-sm-2 col-form-label">{{ __('biogmains.source_field') }} (c_source)</label>
        <div class="col-sm-5">
            <select class="form-control c_source" name="c_source" id="c_source">
                @if($isEdit && isset($text_str) && $text_str)
                    <option value="{{ $row->c_source }}" selected="selected">{{ $text_str }}</option>
                @else
                    <option value="" selected="selected">{{ __('biogmains.please_search') }}</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_pages" class="col-sm-2 col-form-label">{{ __('biogmains.pages_entries') }}</label>
        <div class="col-sm-4">
            <input type="text" class="form-control" name="c_pages" value="{{ $isEdit ? $row->c_pages : '' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_notes" class="col-sm-2 col-form-label">{{ __('biogmains.notes_field') }} (c_notes)</label>
        <div class="col-sm-10">
            <textarea class="form-control" name="c_notes" cols="30" rows="5">{{ $isEdit ? $row->c_notes : '' }}</textarea>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_natal" class="col-sm-2 col-form-label">{{ __('biogmains.maiden_addr') }} (c_natal)</label>
        <div class="col-sm-10">
            <select class="form-control select2" name="c_natal">
                <option disabled value="">{{ __('biogmains.please_select') }}</option>
                <option value="0" {{ ($isEdit && $row->c_natal == 0) ? 'selected' : '' }}>0-{{ __('common.no') }}</option>
                <option value="1" {{ ($isEdit && $row->c_natal == 1) ? 'selected' : '' }}>1-{{ __('common.yes') }}</option>
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="textperson_pair" class="col-sm-2 col-form-label">{{ __('biogmains.candidate_source_title') }}</label>
        <div class="col-sm-10">
            <select class="form-control textperson_pair" name="">
                <option value="">{{ __('biogmains.candidate_source_hint') }}</option>
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
        <label for="__proposal_comment" class="col-sm-2 col-form-label">{{ __('biogmains.modification_note_label') }}</label>
        <div class="col-sm-10">
            <textarea class="form-control" name="__proposal_comment" rows="3" placeholder="{{ __('biogmains.modification_note_placeholder') }}"></textarea>
            <small class="text-muted">{{ __('biogmains.modification_note_hint') }}</small>
        </div>
    </div>

    <div class="form-group row">
        <div class="offset-sm-2 col-sm-10">
            @if(Auth::check() && Auth::user()->isActive())
                @if(Auth::user()->canWriteDirectly())
                    <button type="submit" name="action" value="save" class="btn btn-primary">
                        <i class="fa fa-save"></i> {{ __('biogmains.save_directly') }}
                    </button>
                @endif

                <button type="submit" name="action" value="proposal" class="btn btn-info">
                    <i class="fa fa-paper-plane"></i> {{ __('biogmains.submit_proposal') }}
                </button>
            @else
                <button type="submit" class="btn btn-secondary">{{ __('common.submit') }}</button>
            @endif

            <a href="{{ route('basicinformation.addresses.index', ['basicinformation' => $id]) }}" class="btn btn-secondary">
                <i class="fa fa-times"></i> {{ __('common.cancel') }}
            </a>
        </div>
    </div>
</form>

@section('js')
    <script>
    onViteReady(function() {
        var updateSourceSuccess = {!! Js::from(__('biogmains.update_source_success')) !!};
        var pleaseFillSource = {!! Js::from(__('biogmains.please_fill_source')) !!};

        $(".select2").select2();
        textperson_pair_first_load();

        // 使用统一的 AJAX Select2 初始化助手函数
        window.initAjaxSelect($(".c_addr_id"), 'addr', {
            ajax: {
                data: function (params) {
                    return {
                        q: params.term,
                        page: params.page || 1,
                        dy_start: $('.dynasty_start').val() || '',
                        dy_end: $('.dynasty_end').val() || '',
                    };
                }
            }
        });
        window.initAjaxSelect($(".c_source"), 'text');

        if (window.initLunarValidation) {
            window.initLunarValidation();
        }

        function textperson_pair_first_load(){
            let person_id = $('.person_id').val();
            let data = [{
                id: 0,
                text: pleaseFillSource
            }];
            $.get('/api/select/search/textperson', {q: person_id}, function (data, textStatus){
                for (let i=data.data.length-1; i>-1; i--){
                    item = data.data[i];
                    $(".textperson_pair").append(new Option(item['text'], item['value']));
                }
            });
        }

        $(".textperson_pair").change(function(){
            var hasValue = $(".textperson_pair").val();
            var textperson_value = hasValue.split("&and&");
            $.get('/api/select/search/text', {q: textperson_value[0]}, function (data, textStatus){
                for (var i=data.data.length-1; i>-1; i--){
                    item = data.data[i];
                    console.log(item);
                    var textperson_text = item['text'];
                }
                $("select[name='c_source'] option[selected]").val(textperson_value[0]);
                $("select[name='c_source']").val(textperson_value[0]);
                $("#select2-c_source-container").text(textperson_text);
                $("#select2-c_source-container").css("background","#FFFFBB");
                $("input[name='c_pages']").val(textperson_value[1]);
                $("input[name='c_pages']").css("background","#FFFFBB");
                alert(updateSourceSuccess);
            });
        });
    });
    </script>
@endsection
