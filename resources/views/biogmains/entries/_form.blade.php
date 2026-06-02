{{-- 共享表单组件 - Entries --}}
@php
    use App\Support\CompositePrimaryKey;
    $isEdit = isset($row);

    if ($isEdit && isset($pk)) {
        $formAction = CompositePrimaryKey::buildUrl(
            'basicinformation.entries.update.query',
            ['id' => $id],
            $pk
        );
    } elseif ($isEdit) {
        $formAction = route('basicinformation.entries.update', ['basicinformation' => $id, 'entry' => $row->c_personid.'-'.$row->c_entry_code.'-'.$row->c_sequence.'-'.$row->c_kin_code.'-'.$row->c_assoc_code.'-'.$row->c_kin_id.'-'.$row->c_year.'-'.$row->c_assoc_id.'-'.$row->c_inst_code.'-'.$row->c_inst_name_code]);
    } else {
        $formAction = route('basicinformation.entries.store', ['basicinformation' => $id]);
    }
@endphp

<form action="{{ $formAction }}" method="post">
    @if($isEdit)
        {{ method_field('PATCH') }}
    @endif
    {{ csrf_field() }}

    <x-forms.person-id-display :personId="$id" />

    <div class="form-group row">
        <label for="c_sequence" class="col-sm-2 col-form-label">{{ __('biogmains.sequence') }} (entry_sequence)</label>
        <div class="col-sm-10">
            <input type="{{ $isEdit ? 'text' : 'number' }}" class="form-control" name="c_sequence" maxlength="4" value="{{ $isEdit ? $row->c_sequence : '0' }}" {{ $isEdit ? '' : 'required' }}>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_entry_code" class="col-sm-2 col-form-label">{{ __('biogmains.entry_method') }} (entry_code)</label>
        <div class="col-sm-10">
            <select class="form-control c_entry_code" name="c_entry_code">
                @if($isEdit && isset($res['entry_str']))
                    <option value="{{ $row->c_entry_code }}" selected="selected">{{ $res['entry_str'] }}</option>
                @else
                    <option value="0" selected="selected">0 unknown not available/applicable</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_year" class="col-sm-2 col-form-label">{{ __('biogmains.entry_year_field') }} (year)</label>
        <x-inline-time-fields
            yearName="c_year"
            :yearValue="$isEdit ? $row->c_year : '0'"
            :yearRequired="!$isEdit"
            nhCodeName="c_entry_nh_id"
            :nhCodeValue="$isEdit ? $row->c_entry_nh_id : ''"
            nhYearName="c_entry_nh_year"
            :nhYearValue="$isEdit ? $row->c_entry_nh_year : ''"
            rangeName="c_entry_range"
            :rangeValue="$isEdit ? $row->c_entry_range : ''"
        />
    </div>

    <div class="form-group row">
        <label for="c_exam_rank" class="col-sm-2 col-form-label">{{ __('biogmains.exam_ranking') }} (exam_rank)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_exam_rank" value="{{ $isEdit ? $row->c_exam_rank : '' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_attempt_count" class="col-sm-2 col-form-label">{{ __('biogmains.nth_attempt') }} (c_attempt_count)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_attempt_count" value="{{ $isEdit ? $row->c_attempt_count : '' }}">
            {{ __('biogmains.arabic_numerals_hint') }}
        </div>
    </div>

    <div class="form-group row">
        <label for="c_exam_field" class="col-sm-2 col-form-label">{{ __('biogmains.exam_subject') }} (c_exam_field)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_exam_field" value="{{ $isEdit ? $row->c_exam_field : '' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_parental_status_code" class="col-sm-2 col-form-label">{{ __('biogmains.parental_status') }} (c_parental_status_code)</label>
        <div class="col-sm-10">
            <select-vue name="c_parental_status_code" model="parentstatus" selected="{{ $isEdit ? $row->c_parental_status_code : '' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_entry_addr_id" class="col-sm-2 col-form-label">{{ __('biogmains.location') }} (c_addr_id)</label>
        <div class="col-sm-10">
            <select class="form-control c_entry_addr_id" name="c_entry_addr_id" {{ $isEdit ? 'required' : '' }}>
                @if($isEdit && isset($res['addr_str']))
                    <option value="{{ $row->c_entry_addr_id }}" selected="selected">{{ $res['addr_str'] }}</option>
                @else
                    <option value="0" selected="selected">0 unknown weizhi</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_age" class="col-sm-2 col-form-label">{{ __('biogmains.entry_age') }} (age)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_age" value="{{ $isEdit ? $row->c_age : '' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_posting_notes" class="col-sm-2 col-form-label">{{ __('biogmains.official_appt') }} (posting_id)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_posting_notes" value="{{ $isEdit ? $row->c_posting_notes : '' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_kin_code" class="col-sm-2 col-form-label">{{ __('biogmains.kinship_type') }} (kin_code)</label>
        <div class="col-sm-10">
            <select class="form-control c_kin_code" name="c_kin_code">
                @if($isEdit && isset($res['kin_str']))
                    <option value="{{ $row->c_kin_code }}" selected="selected">{{ $res['kin_str'] }}</option>
                @else
                    <option value="0" selected="selected">0 unknown weizhi</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_kin_id" class="col-sm-2 col-form-label">{{ __('biogmains.relative_field') }} (kin_id)</label>
        <div class="col-sm-10">
            <select class="form-control c_kin_id" name="c_kin_id">
                @if($isEdit && isset($res['biog_str']))
                    <option value="{{ $row->c_kin_id }}" selected="selected">{{ $res['biog_str'] }}</option>
                @else
                    <option value="0" selected="selected">0 unknown weizhi</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_assoc_code" class="col-sm-2 col-form-label">{{ __('biogmains.social_rel_type') }} (assoc_code)</label>
        <div class="col-sm-10">
            <select class="form-control c_assoc_code" name="c_assoc_code">
                @if($isEdit && isset($res['assoc_str']))
                    <option value="{{ $row->c_assoc_code }}" selected="selected">{{ $res['assoc_str'] }}</option>
                @else
                    <option value="0" selected="selected">0 unknown weizhi</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_assoc_id" class="col-sm-2 col-form-label">{{ __('biogmains.social_rel_person') }} (assoc_id)</label>
        <div class="col-sm-10">
            <select class="form-control c_assoc_id" name="c_assoc_id">
                @if($isEdit && isset($res['biog_str2']))
                    <option value="{{ $row->c_assoc_id }}" selected="selected">{{ $res['biog_str2'] }}</option>
                @else
                    <option value="0" selected="selected">0 unknown weizhi</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_inst_code" class="col-sm-2 col-form-label">{{ __('biogmains.social_inst_field') }} (social_institution)</label>
        @if($isEdit)
            <input name="c_inst_name_code" type="hidden">
        @endif
        <div class="col-sm-10">
            <select class="form-control c_inst_code" name="c_inst_code">
                @if($isEdit && isset($res['inst_code']))
                    <option value="{{ $row->c_inst_code.'-'.$row->c_inst_name_code }}" selected="selected">{{ $res['inst_code'] }}</option>
                @else
                    <option value="0-0" selected="selected">0 [Unknown]</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.source_field') }} (source)</label>
        <div class="col-sm-10">
            <select class="form-control c_source" name="c_source" id="c_source">
                @if($isEdit && isset($res['text_str']))
                    <option value="{{ $row->c_source }}" selected="selected">{{ $res['text_str'] }}</option>
                @else
                    <option value="{{ $isEdit ? '' : '0' }}" selected="selected">{{ $isEdit ? __('biogmains.please_search') : '' }}</option>
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
        <label for="c_notes" class="col-sm-2 col-form-label">{{ __('biogmains.notes_field') }} (notes)</label>
        <div class="col-sm-10">
            <textarea class="form-control" name="c_notes" id="" cols="30" rows="5">{{ $isEdit ? $row->c_notes : '' }}</textarea>
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

            <a href="{{ route('basicinformation.entries.index', ['basicinformation' => $id]) }}" class="btn btn-secondary">
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
        window.initAjaxSelect($(".c_entry_code"), 'entry');
        window.initAjaxSelect($(".c_entry_addr_id"), 'addr', {
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
        window.initAjaxSelect($(".c_kin_code"), 'kincode');
        window.initAjaxSelect($(".c_assoc_code"), 'assoccode');
        window.initAjaxSelect($(".c_inst_code"), 'socialinstcode');
        window.initAjaxSelect($(".c_source"), 'text');
        window.initAjaxSelect($(".c_kin_id"), 'biog');
        window.initAjaxSelect($(".c_assoc_id"), 'biog');

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
