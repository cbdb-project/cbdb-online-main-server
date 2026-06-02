{{-- 共享表单组件 - Events --}}
@php
    use App\Support\CompositePrimaryKey;
    $isEdit = isset($row);

    if ($isEdit && isset($pk)) {
        // 查詢參數模式：使用完整複合主鍵
        $formAction = CompositePrimaryKey::buildUrl(
            'basicinformation.events.update.query',
            ['id' => $id],
            $pk
        );
    } elseif ($isEdit) {
        // 舊格式
        $formAction = route('basicinformation.events.update', ['basicinformation' => $id, 'event' => $row->c_sequence]);
    } else {
        $formAction = route('basicinformation.events.store', ['basicinformation' => $id]);
    }
@endphp

<form action="{{ $formAction }}" method="post">
    @if($isEdit)
        {{ method_field('PATCH') }}
    @endif
    {{ csrf_field() }}

    <x-forms.person-id-display :personId="$id" />

    <div class="form-group row">
        <label for="c_sequence" class="col-sm-2 col-form-label">{{ __('biogmains.sequence') }} (sequence)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_sequence" value="{{ $isEdit ? $row->c_sequence : '0' }}" maxlength="4">
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.event_name') }}</label>
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
        <label for="c_role" class="col-sm-2 col-form-label">{{ __('biogmains.event_role') }}</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_role" value="{{ $isEdit ? $row->c_role : '' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_year" class="col-sm-2 col-form-label">{{ __('biogmains.event_year_field') }}</label>
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
            :nhLabel="__('biogmains.event_reign_year')"
        />
    </div>

    <div class="form-group row">
        <label for="c_addr_id" class="col-sm-2 col-form-label">{{ __('biogmains.place_name') }}</label>
        <div class="col-sm-10">
            <select class="form-control c_addr_id" name="c_addr_id[]" multiple="multiple">
                @if($isEdit && isset($res['addr_str']))
                    @foreach($res['addr_str'] as $item)
                        <option value="{{ $item[0] }}" selected="selected">{{ $item[1] }}</option>
                    @endforeach
                @else
                    <option value="0" selected>{{ __('biogmains.not_specified') }}</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.source_field') }}</label>
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
        <label for="c_pages" class="col-sm-2 col-form-label">{{ __('biogmains.pages_entries') }}</label>
        <div class="col-sm-4">
            <input type="text" class="form-control" name="c_pages" value="{{ $isEdit ? $row->c_pages : '' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_event" class="col-sm-2 col-form-label">{{ __('biogmains.major_event') }}</label>
        <div class="col-sm-10">
            <textarea class="form-control" name="c_event" id="" cols="30"
                      rows="5">{{ $isEdit ? $row->c_event : '' }}</textarea>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_notes" class="col-sm-2 col-form-label">{{ __('biogmains.notes_field') }}</label>
        <div class="col-sm-10">
            <textarea class="form-control" name="c_notes" id="" cols="30"
                      rows="5">{{ $isEdit ? $row->c_notes : '' }}</textarea>
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
            @endif

            <a href="{{ route('basicinformation.events.index', ['basicinformation' => $id]) }}" class="btn btn-secondary">
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
        window.initAjaxSelect($(".c_source"), 'text');
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
        window.initAjaxSelect($(".c_event_code"), 'event');

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
