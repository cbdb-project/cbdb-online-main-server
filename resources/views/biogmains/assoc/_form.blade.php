{{-- 共享表单组件 - Assoc --}}
@php
    use App\Support\CompositePrimaryKey;
    $isEdit = isset($row);

    // 处理編輯模式的数据 - 必须在构建 formAction 之前执行
    if ($isEdit) {
        $row->c_text_title = unionPKDef($row->c_text_title);
        $row->c_notes = unionPKDef($row->c_notes);
    }

    if ($isEdit && isset($pk)) {
        $formAction = CompositePrimaryKey::buildUrl(
            'basicinformation.assoc.update.query',
            ['id' => $id],
            $pk
        );
    } elseif ($isEdit) {
        $formAction = route('basicinformation.assoc.update', ['basicinformation' => $id, 'assoc' => $row->c_personid.'-'.$row->c_assoc_code.'-'.$row->c_assoc_id.'-'.$row->c_kin_code.'-'.$row->c_kin_id.'-'.$row->c_assoc_kin_code.'-'.$row->c_assoc_kin_id.'-'.$row->c_text_title]);
    } else {
        $formAction = route('basicinformation.assoc.store', ['basicinformation' => $id]);
    }
@endphp

<form action="{{ $formAction }}" method="post">
    @if($isEdit)
        {{ method_field('PATCH') }}
    @endif
    {{ csrf_field() }}
    <input type="hidden" name="ai_fill_log_id" id="ai-fill-log-id" value="">

    <x-forms.person-id-display :personId="$id" />

    {{-- AI 智能識別社會關係代碼（已啟用用戶 + Gemini API 已配置） --}}
    @if(config('services.gemini.api_key') && auth()->check() && auth()->user()->isActive())
        <div class="card card-info mb-3" id="ai-code-lookup-section">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title mb-0">
                    <i class="fas fa-magic"></i> {{ __('biogmains.ai_assoc_recognition') }}
                </h3>
                <a class="ml-3 text-white" style="font-size: 0.85em; opacity: 0.85; cursor: pointer;"
                   data-toggle="collapse" href="#ai-code-privacy-notice" role="button" aria-expanded="false">
                    <i class="fas fa-exclamation-triangle"></i> {{ __('biogmains.ai_notice_title') }}
                </a>
            </div>
            <div class="collapse" id="ai-code-privacy-notice">
                <div class="alert alert-warning mb-0 rounded-0 border-left-0 border-right-0" style="font-size: 0.9em;">
                    <p class="mb-2">{{ __('biogmains.ai_consent_intro') }}</p>
                    <ul class="mb-0">
                        <li>{{ __('biogmains.ai_consent_record') }}</li>
                        <li>{{ __('biogmains.ai_consent_third_party') }}</li>
                        <li>{{ __('biogmains.ai_consent_verify') }}</li>
                    </ul>
                    <p class="mb-0 mt-2">{{ __('biogmains.ai_current_model') }}<code>{{ config('services.gemini.model') }}</code></p>
                </div>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="ai-code-query">{{ __('biogmains.ai_enter_description') }}</label>
                    <textarea class="form-control" id="ai-code-query" rows="3"
                              placeholder="{{ __('biogmains.ai_input_placeholder_assoc') }}"></textarea>
                    <small class="form-text text-muted">{{ __('biogmains.ai_description_hint_assoc') }}</small>
                </div>
                <button type="button" class="btn btn-info" id="btn-ai-code-lookup">
                    <i class="fas fa-bolt"></i> {{ __('biogmains.ai_recognize_btn') }}
                </button>
                <span class="ml-3" id="ai-code-status"></span>

                {{-- 候選結果區 --}}
                <div id="ai-code-results" style="display:none;" class="mt-3">
                    <h6><i class="fas fa-check-circle text-success"></i> {{ __('biogmains.ai_candidate_codes') }}</h6>
                    <div id="ai-code-candidates" class="mb-3"></div>

                    <div id="ai-code-not-found" style="display:none;">
                        <h6 class="text-warning"><i class="fas fa-exclamation-triangle"></i> {{ __('biogmains.ai_no_match') }}</h6>
                        <div id="ai-code-not-found-list"></div>
                    </div>

                    <div id="ai-code-summary" class="alert alert-light mt-2" style="display:none;"></div>
                </div>
            </div>
        </div>
    @endif

    <div class="form-group row">
        <label for="c_sequence" class="col-sm-2 col-form-label">{{ __('biogmains.sequence') }} (sequence)</label>
        <div class="col-sm-10">
            <input type="{{ $isEdit ? 'text' : 'number' }}" class="form-control" name="c_sequence" value="{{ $isEdit ? $row->c_sequence : '' }}" maxlength="4">
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.kinship_relation') }}</label>
        <div class="col-sm-1">{{ __('person.relation') }}</div>
        <div class="col-sm-3">
            <select class="form-control c_kin_code" name="c_kin_code">
                @if($isEdit && isset($res['kin_code']))
                    <option value="{{ $row->c_kin_code }}" selected="selected">{{ $res['kin_code'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
        <div class="col-sm-1">{{ __('person.name') }}</div>
        <div class="col-sm-3">
            <select class="form-control biog" name="c_kin_id">
                @if($isEdit && isset($res['kin_id']))
                    <option value="{{ $row->c_kin_id }}" selected="selected">{{ $res['kin_id'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.assoc_person_y') }}</label>
        <div class="col-sm-1">{{ __('person.relation') }}</div>
        <div class="col-sm-3">
            <select class="form-control c_assoc_code" name="c_assoc_code">
                @if($isEdit && isset($res['assoc_code']))
                    <option value="{{ $row->c_assoc_code }}" selected="selected">{{ $res['assoc_code'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
        <div class="col-sm-1">{{ __('person.name') }}</div>
        <div class="col-sm-3">
            <select class="form-control biog" name="c_assoc_id">
                @if($isEdit && isset($res['assoc_id']))
                    <option value="{{ $row->c_assoc_id }}" selected="selected">{{ $res['assoc_id'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.assoc_relative') }}</label>
        <div class="col-sm-1">{{ __('person.relation') }}</div>
        <div class="col-sm-3">
            <select class="form-control c_assoc_kin_code" name="c_assoc_kin_code">
                @if($isEdit && isset($res['assoc_kin_code']))
                    <option value="{{ $row->c_assoc_kin_code }}" selected="selected">{{ $res['assoc_kin_code'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
        <div class="col-sm-1">{{ __('person.name') }}</div>
        <div class="col-sm-3">
            <select class="form-control biog" name="c_assoc_kin_id">
                @if($isEdit && isset($res['assoc_kin_id']))
                    <option value="{{ $row->c_assoc_kin_id }}" selected="selected">{{ $res['assoc_kin_id'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_assoc_fy_year" class="col-sm-2 col-form-label">{{ __('biogmains.assoc_start_year') }}</label>
        <x-inline-time-fields
            yearName="c_assoc_first_year"
            :yearValue="$isEdit ? $row->c_assoc_first_year : ''"
            nhCodeName="c_assoc_fy_nh_code"
            :nhCodeValue="$isEdit ? $row->c_assoc_fy_nh_code : ''"
            nhYearName="c_assoc_fy_nh_year"
            :nhYearValue="$isEdit ? $row->c_assoc_fy_nh_year : ''"
            rangeName="c_assoc_fy_range"
            :rangeValue="$isEdit ? $row->c_assoc_fy_range : ''"
            :showLunar="true"
            intercalaryName="c_assoc_fy_intercalary"
            :intercalaryValue="$isEdit ? $row->c_assoc_fy_intercalary : ''"
            monthName="c_assoc_fy_month"
            :monthValue="$isEdit ? $row->c_assoc_fy_month : ''"
            dayName="c_assoc_fy_day"
            :dayValue="$isEdit ? $row->c_assoc_fy_day : ''"
            dayGzName="c_assoc_fy_day_gz"
            :dayGzValue="$isEdit ? $row->c_assoc_fy_day_gz : ''"
        />
    </div>

    <div class="form-group row">
        <label for="c_assoc_ly_year" class="col-sm-2 col-form-label">{{ __('biogmains.assoc_end_year') }}</label>
        <x-inline-time-fields
            yearName="c_assoc_last_year"
            :yearValue="$isEdit ? $row->c_assoc_last_year : ''"
            nhCodeName="c_assoc_ly_nh_code"
            :nhCodeValue="$isEdit ? $row->c_assoc_ly_nh_code : ''"
            nhYearName="c_assoc_ly_nh_year"
            :nhYearValue="$isEdit ? $row->c_assoc_ly_nh_year : ''"
            rangeName="c_assoc_ly_range"
            :rangeValue="$isEdit ? $row->c_assoc_ly_range : ''"
            :showLunar="true"
            intercalaryName="c_assoc_ly_intercalary"
            :intercalaryValue="$isEdit ? $row->c_assoc_ly_intercalary : ''"
            monthName="c_assoc_ly_month"
            :monthValue="$isEdit ? $row->c_assoc_ly_month : ''"
            dayName="c_assoc_ly_day"
            :dayValue="$isEdit ? $row->c_assoc_ly_day : ''"
            dayGzName="c_assoc_ly_day_gz"
            :dayGzValue="$isEdit ? $row->c_assoc_ly_day_gz : ''"
        />
    </div>

    <div class="form-group row">
        <label for="c_notes" class="col-sm-2 col-form-label">{{ __('biogmains.notes_field') }} (c_notes)</label>
        <div class="col-sm-10">
            @php
                $notes_value = $isEdit ? unionPKDef_decode_for_convert($row->c_notes) : '';
            @endphp
            <textarea class="form-control" name="c_notes" id="" cols="30" rows="5">{{ $notes_value }}</textarea>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_topic_code" class="col-sm-2 col-form-label">{{ __('biogmains.academic_topic') }}</label>
        <div class="col-sm-10">
            <select-vue name="c_topic_code" model="topic" selected="{{ $isEdit ? $row->c_topic_code : '' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_occasion_code" class="col-sm-2 col-form-label">{{ __('biogmains.occasion') }}</label>
        <div class="col-sm-10">
            <select-vue name="c_occasion_code" model="occasion" selected="{{ $isEdit ? $row->c_occasion_code : '' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_text_title" class="col-sm-2 col-form-label">{{ __('biogmains.work_title') }}</label>
        <div class="col-sm-10">
            @php
                $text_title_value = $isEdit ? unionPKDef_decode_for_convert($row->c_text_title) : '[n/a]';
            @endphp
            <input type="text" class="form-control" name="c_text_title" value="{{ $text_title_value }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_assoc_count" class="col-sm-2 col-form-label">{{ __('biogmains.assoc_count_field') }} (c_assoc_count)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_assoc_count" value="{{ $isEdit ? $row->c_assoc_count : '1' }}">
            <small class="text-muted">{{ __('biogmains.assoc_count_hint') }}</small>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.intermediary') }} (tertiary_personid)</label>
        <div class="col-sm-10">
            <select class="form-control biog" name="c_tertiary_personid">
                @if($isEdit && isset($res['tertiary_personid']))
                    <option value="{{ $row->c_tertiary_personid }}" selected="selected">{{ $res['tertiary_personid'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.intermediary_type') }} (tertiary_type)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_tertiary_type_notes" value="{{ $isEdit ? $row->c_tertiary_type_notes : '' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.witness') }}</label>
        <div class="col-sm-10">
            <select class="form-control biog" name="c_assoc_claimer_id">
                @if($isEdit && isset($res['assoc_claimer_id']))
                    <option value="{{ $row->c_assoc_claimer_id }}" selected="selected">{{ $res['assoc_claimer_id'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.assoc_location') }}</label>
        <div class="col-sm-10">
            <select class="form-control c_addr_id" name="c_addr_id">
                @if($isEdit && isset($res['addr_id']))
                    <option value="{{ $row->c_addr_id }}" selected="selected">{{ $res['addr_id'] }}</option>
                @else
                    <option value="0" selected="selected">0 [Unknown]</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.socialinst_field') }} (social_institution)</label>
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
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.source_field') }} (c_source)</label>
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
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.paired_assoc') }}</label>
        <div class="col-sm-10">
            <select class="form-control c_assocship_pair" name="c_assocship_pair">
                @if($isEdit)
                    <option value="" selected="selected"></option>
                @else
                    <option value="0">{{ __('biogmains.no_paired_assoc') }}</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.paired_kinship') }}</label>
        <div class="col-sm-10">
            <select class="form-control c_kinship_pair" name="c_kinship_pair">
                @if($isEdit && isset($res['kinship_pair']))
                    <option value="{{ $res['kinship_pair'] }}" selected="selected">{{ $res['kinship_pair'] }}</option>
                @endif
                <option value="0">{{ __('biogmains.no_paired_kinship') }}</option>
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('biogmains.paired_assoc_kinship') }}</label>
        <div class="col-sm-10">
            <select class="form-control c_assoc_kinship_pair" name="c_assoc_kinship_pair">
                @if($isEdit && isset($res['assoc_kinship_pair']))
                    <option value="{{ $res['assoc_kinship_pair'] }}" selected="selected">{{ $res['assoc_kinship_pair'] }}</option>
                @endif
                <option value="0">{{ __('biogmains.no_paired_kinship') }}</option>
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
            @endif

            <a href="{{ route('basicinformation.assoc.index', ['basicinformation' => $id]) }}" class="btn btn-secondary">
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
        var noPairedAssoc = {!! Js::from(__('biogmains.no_paired_assoc')) !!};
        var noPairedKinship = {!! Js::from(__('biogmains.no_paired_kinship')) !!};
        var pairedLabel = {!! Js::from(__('biogmains.paired_label')) !!};
        var aiEnterDescription = {!! Js::from(__('biogmains.ai_enter_description')) !!};
        var aiProcessing = {!! Js::from(__('biogmains.ai_processing')) !!};
        var aiRecognitionFailed = {!! Js::from(__('biogmains.ai_recognition_failed')) !!};
        var aiNoMatch = {!! Js::from(__('biogmains.ai_no_match')) !!};

        $(".select2").select2();
        textperson_pair_first_load();
        $(".c_kinship_pair").select2();
        $(".c_assoc_kinship_pair").select2();
        $(".c_assocship_pair").select2();

        // 使用统一的 AJAX Select2 初始化助手函数
        window.initAjaxSelect($(".biog"), 'biog');
        window.initAjaxSelect($(".c_kin_code"), 'kincode');
        window.initAjaxSelect($(".c_assoc_kin_code"), 'kincode');
        window.initAjaxSelect($(".c_assoc_code"), 'assoccode');
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
        window.initAjaxSelect($(".c_inst_code"), 'socialinstcode');
        window.initAjaxSelect($(".c_source"), 'text');

        if (window.initLunarValidation) {
            window.initLunarValidation();
        }

        // 绑定事件监听器
        $(".c_kin_code").on('change', function() {
            kinship_pair();
        });

        $(".c_assoc_code").on('change', function() {
            assocship_pair();
        });

        $(".c_assoc_kin_code").on('change', function() {
            assoc_kinship_pair();
        });

        @if($isEdit)
            assocship_pair();
        @endif

        function assocship_pair(){
            let c_assoc_code = $('.c_assoc_code').val();
            let c_assoc_id = $('.c_assoc_id').val();

            $(".c_assocship_pair").empty();

            $.get('/api/select/search/assocpair', {assoc_code: c_assoc_code, person_id: c_assoc_id}, function (data, textStatus){
                if (data && data.length > 0) {
                    for (let i = 0; i < data.length; i++){
                        const item = data[i];
                        const optionText = item['c_assoc_code'] + ' ' + item['c_assoc_desc_chn'] + ' ' + item['c_assoc_desc'];
                        $(".c_assocship_pair").append(new Option(optionText, item['c_assoc_code'], false, false));
                    }
                    $(".c_assocship_pair").val(data[0]['c_assoc_code']).trigger('change');
                } else {
                    $(".c_assocship_pair").append(new Option(noPairedAssoc, '0', true, true));
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                $(".c_assocship_pair").append(new Option(noPairedAssoc, '0', true, true));
            });
        }

        function kinship_pair(){
            let c_kin_code = $('.c_kin_code').val();
            let c_kin_id = $('.c_kin_id').val();

            $(".c_kinship_pair").empty();

            $.get('/api/select/search/kinpair', {kin_code: c_kin_code, person_id: c_kin_id}, function (data, textStatus){
                if (data && data.length > 0) {
                    for (let i = 0; i < data.length; i++){
                        const item = data[i];
                        const optionText = item['c_kincode'] + ' ' + item['c_kinrel_chn'] + ' ' + item['c_kinrel'];
                        $(".c_kinship_pair").append(new Option(optionText, item['c_kincode'], false, false));
                    }
                    $(".c_kinship_pair").val(data[0]['c_kincode']).trigger('change');
                } else {
                    $(".c_kinship_pair").append(new Option(noPairedKinship, '0', true, true));
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                $(".c_kinship_pair").append(new Option(noPairedKinship, '0', true, true));
            });
        }

        function assoc_kinship_pair(){
            let c_assoc_kin_code = $('.c_assoc_kin_code').val();
            let c_assoc_kin_id = $('.c_assoc_kin_id').val();

            $(".c_assoc_kinship_pair").empty();

            $.get('/api/select/search/kinpair', {kin_code: c_assoc_kin_code, person_id: c_assoc_kin_id}, function (data, textStatus){
                if (data && data.length > 0) {
                    for (let i = 0; i < data.length; i++){
                        const item = data[i];
                        const optionText = item['c_kincode'] + ' ' + item['c_kinrel_chn'] + ' ' + item['c_kinrel'];
                        $(".c_assoc_kinship_pair").append(new Option(optionText, item['c_kincode'], false, false));
                    }
                    $(".c_assoc_kinship_pair").val(data[0]['c_kincode']).trigger('change');
                } else {
                    $(".c_assoc_kinship_pair").append(new Option(noPairedKinship, '0', true, true));
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                $(".c_assoc_kinship_pair").append(new Option(noPairedKinship, '0', true, true));
            });
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

        // === AI 智能識別社會關係代碼 ===
        @if(config('services.gemini.api_key') && auth()->check() && auth()->user()->isActive())
        $('#btn-ai-code-lookup').on('click', function() {
            var query = $('#ai-code-query').val().trim();
            if (!query) {
                alert(aiEnterDescription);
                return;
            }

            var $btn = $(this);
            var $status = $('#ai-code-status');
            $btn.prop('disabled', true);
            $status.html('<i class="fas fa-spinner fa-spin"></i> ' + aiProcessing);
            $('#ai-code-results').hide();

            $.ajax({
                url: '{{ route("ai.code-lookup.suggest", [], false) }}',
                method: 'POST',
                data: {
                    query: query,
                    table: 'ASSOC_CODES',
                    person_id: {{ $id }},
                    route_name: '{{ Route::currentRouteName() ?? "" }}',
                    route_url: window.location.pathname,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    $btn.prop('disabled', false);
                    $status.text('');
                    if (response.ai_fill_log_id) {
                        $('#ai-fill-log-id').val(response.ai_fill_log_id);
                    }
                    renderCodeResults(response.data);
                },
                error: function(xhr) {
                    $btn.prop('disabled', false);
                    var msg = (xhr.responseJSON && xhr.responseJSON.error) || aiRecognitionFailed;
                    $status.html('<span class="text-danger"><i class="fas fa-times-circle"></i> ' + msg + '</span>');
                }
            });
        });

        function renderCodeResults(data) {
            var $results = $('#ai-code-results');
            var $candidates = $('#ai-code-candidates');
            var $notFound = $('#ai-code-not-found');
            var $notFoundList = $('#ai-code-not-found-list');
            var $summary = $('#ai-code-summary');

            $candidates.empty();
            $notFoundList.empty();

            if (data.matched_codes && data.matched_codes.length > 0) {
                data.matched_codes.forEach(function(code) {
                    var btnClass = code.relevance === '高' ? 'btn-success' : (code.relevance === '中' ? 'btn-warning' : 'btn-secondary');
                    var $btn = $('<button type="button" class="btn ' + btnClass + ' m-1"></button>');
                    $btn.html('<strong>' + code.code_id + '</strong> ' + code.desc_chn + ' <small>(' + code.desc_en + ')</small>');
                    $btn.attr('title', code.reason);
                    $btn.data('code', code);
                    $btn.on('click', function() {
                        applyAssocCode($(this).data('code'));
                    });
                    $candidates.append($btn);

                    // 顯示成對關係資訊
                    if (code.paired_codes && code.paired_codes.length > 0) {
                        var pairText = code.paired_codes.map(function(p) {
                            return p.code_id + ' ' + p.desc_chn + ' (' + p.desc_en + ')';
                        }).join(', ');
                        $candidates.append('<small class="d-block text-muted ml-2 mb-1"><i class="fas fa-exchange-alt"></i> ' + pairedLabel + pairText + '</small>');
                    }
                });
            } else {
                $candidates.html('<span class="text-muted">' + aiNoMatch + '</span>');
            }

            if (data.not_found && data.not_found.length > 0) {
                $notFoundList.html(data.not_found.map(function(item) {
                    return '<span class="badge badge-light mr-1">' + item + '</span>';
                }).join(''));
                $notFound.show();
            } else {
                $notFound.hide();
            }

            if (data.summary) {
                $summary.text(data.summary).show();
            } else {
                $summary.hide();
            }

            $results.show();
        }

        function applyAssocCode(code) {
            var $select = $('select.c_assoc_code');
            $select.empty();
            $select.append(new Option(code.code_id + ' ' + code.desc_chn + ' ' + code.desc_en, code.code_id, true, true));
            $select.trigger('change');

            assocship_pair();

            $select.closest('.form-group').find('label').css('color', '#28a745');

            $('html, body').animate({
                scrollTop: $select.closest('.form-group').offset().top - 100
            }, 300);
        }
        @endif
    });
    </script>
@endsection
