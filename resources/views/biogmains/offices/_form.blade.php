{{-- 共享表单组件 - Offices --}}
@php
    use App\Support\CompositePrimaryKey;
    $isEdit = isset($row);

    if ($isEdit && isset($pk)) {
        $formAction = CompositePrimaryKey::buildUrl(
            'basicinformation.offices.update.query',
            ['id' => $id],
            $pk
        );
    } elseif ($isEdit) {
        $formAction = route('basicinformation.offices.update', ['basicinformation' => $id, 'office' => $row->c_office_id.'-'.$row->c_posting_id]);
    } else {
        $formAction = route('basicinformation.offices.store', ['basicinformation' => $id]);
    }
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

    {{-- AI 智能填充區塊（僅在新增模式且用戶已啟用時顯示） --}}
    @if(!$isEdit && config('services.gemini.api_key') && auth()->user()->isActive())
        <input type="hidden" name="ai_fill_log_id" id="ai-fill-log-id" value="">
        <div class="card card-info mb-3" id="ai-autofill-section">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title mb-0">
                    <i class="fas fa-magic"></i> {{ __('biogmains.ai_offices_autofill') }}
                </h3>
                <a class="ml-3 text-white" style="font-size: 0.85em; opacity: 0.85; cursor: pointer;"
                   data-toggle="collapse" href="#ai-privacy-notice" role="button" aria-expanded="false">
                    <i class="fas fa-exclamation-triangle"></i> {{ __('biogmains.ai_notice_title') }}
                </a>
            </div>
            <div class="collapse" id="ai-privacy-notice">
                <div class="alert alert-warning mb-0 rounded-0 border-left-0 border-right-0" style="font-size: 0.9em;">
                    <p class="mb-2">{{ __('biogmains.ai_fill_consent_intro') }}</p>
                    <ul class="mb-0">
                        <li>{{ __('biogmains.ai_consent_record') }}</li>
                        <li>{{ __('biogmains.ai_consent_fill_third_party') }}</li>
                        <li>{{ __('biogmains.ai_consent_verify') }}</li>
                    </ul>
                    <p class="mb-0 mt-2">{{ __('biogmains.ai_current_model') }}<code>{{ config('services.gemini.model') }}</code></p>
                </div>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="ai-source-text">{{ __('biogmains.ai_original_text_label') }}</label>
                    <textarea class="form-control" id="ai-source-text" rows="4"
                              placeholder="{{ __('biogmains.ai_input_placeholder_offices') }}"></textarea>
                    <small class="form-text text-muted">{{ __('biogmains.ai_description_hint_offices') }}</small>
                </div>
                <button type="button" class="btn" id="btn-ai-autofill">
                    <i class="fas fa-bolt"></i> {{ __('biogmains.ai_fill_btn') }}
                </button>
                <button type="button" class="btn btn-secondary" id="btn-clear-ai" style="display:none;">
                    <i class="fas fa-eraser"></i> {{ __('biogmains.ai_clear_btn') }}
                </button>
                <span class="ml-3" id="ai-status"></span>
            </div>
        </div>

        {{-- 填充結果摘要（成功後顯示） --}}
        <div class="alert alert-light border" id="ai-result-summary" style="display:none;">
            <h5><i class="fas fa-check-circle"></i> {{ __('biogmains.ai_fill_complete') }}</h5>
            <ul class="mb-2">
                <li>✅ <strong id="matched-count">0</strong> {{ __('biogmains.ai_fields_matched_suffix') }}</li>
                <li id="suggested-line" style="display:none;">⚠️ <strong id="suggested-count">0</strong> {{ __('biogmains.ai_fields_confirm_suffix') }}</li>
                <li id="not-found-line" style="display:none;">🔍 <strong id="not-found-count">0</strong> {{ __('biogmains.ai_fields_manual_suffix') }}</li>
                <li id="empty-line" style="display:none;">❌ <strong id="empty-count">0</strong> {{ __('biogmains.ai_fields_failed_suffix') }}</li>
            </ul>
        </div>
    @endif

    @if($isEdit)
        <div class="form-group row">
            <label for="person_id" class="col-sm-2 col-form-label">posting_id</label>
            <div class="col-sm-10">
                <input type="text" class="form-control" value="{{ $row->c_posting_id }}" disabled>
            </div>
        </div>
    @endif

    <div class="form-group row">
        <label for="person_id" class="col-sm-2 col-form-label">{{ __('biogmains.sequence') }} (sequence)</label>
        <div class="col-sm-10">
            <input name="c_sequence" type="text" class="form-control" value="{{ $isEdit ? $row->c_sequence : '' }}" maxlength="4">
            <small class="text-muted">{{ __('biogmains.sequence_same_note') }}</small>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_office_id" class="col-sm-2 col-form-label">{{ __('biogmains.office_name_field') }} (office_id)</label>
        <div class="col-sm-10">
            <select class="form-control c_office_id" name="c_office_id">
                @if($isEdit && isset($res['office_str']))
                    <option value="{{ $row->c_office_id }}" selected="selected">{{ $res['office_str'] }}</option>
                @else
                    <option value="0">0 unknown</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_inst_code" class="col-sm-2 col-form-label">{{ __('biogmains.socialinst_field') }} (social_institution)</label>
        @if($isEdit)
            <input name="c_inst_name_code" type="hidden">
        @endif
        <div class="col-sm-10">
            <select class="form-control c_inst_code" name="c_inst_code">
                @if($isEdit && isset($res['posting_str']))
                    <option value="{{ $row->c_inst_code.'-'.$row->c_inst_name_code }}" selected="selected">{{ $res['posting_str'] }}</option>
                @else
                    <option value="0">0 unknown</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_addr" class="col-sm-2 col-form-label">{{ __('biogmains.place_name') }}</label>
        <div class="col-sm-10">
            {{-- 隱藏欄位：當用戶清除所有地址時，標記為已清除（因為空的 multi-select 不會傳送欄位） --}}
            <input type="hidden" name="c_addr_cleared" id="c_addr_cleared" value="0">
            <select class="form-control c_addr" name="c_addr[]" multiple="multiple">
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
        <label for="c_source" class="col-sm-2 col-form-label">{{ __('biogmains.source_field') }} (c_source)</label>
        <div class="col-sm-10">
            <select class="form-control c_source" name="c_source" id="c_source">
                @if($isEdit && isset($res['text_str']))
                    <option value="{{ $row->c_source }}" selected="selected">{{ $res['text_str'] }}</option>
                @else
                    <option value="0" selected>0 Weizhi unknown</option>
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
        <label for="c_firstyear" class="col-sm-2 col-form-label">{{ __('biogmains.start_year') }} (firstyear)</label>
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
        <label for="c_lastyear" class="col-sm-2 col-form-label">{{ __('biogmains.end_year') }} (lastyear)</label>
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
        <label for="c_appt_code" class="col-sm-2 col-form-label">{{ __('biogmains.appt_type') }} (c_appt_code)</label>
        <div class="col-sm-10">
            <select-vue name="c_appt_code" model="appttype" selected="{{ $isEdit ? $row->c_appt_code : '' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_assume_office_code" class="col-sm-2 col-form-label">{{ __('biogmains.assume_office') }} (c_assume_office_code)</label>
        <div class="col-sm-10">
            <select-vue name="c_assume_office_code" model="assumeoffice" selected="{{ $isEdit ? $row->c_assume_office_code : '' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_office_category_id" class="col-sm-2 col-form-label">{{ __('biogmains.office_category') }} (c_office_category_id)</label>
        <div class="col-sm-10">
            <select-vue name="c_office_category_id" model="officecate" selected="{{ $isEdit ? $row->c_office_category_id : '' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_notes" class="col-sm-2 col-form-label">{{ __('biogmains.notes_field') }} (c_notes)</label>
        <div class="col-sm-10">
            <textarea class="form-control" name="c_notes" id="" cols="30"
                      rows="5">{{ $isEdit ? $row->c_notes : '' }}</textarea>

        </div>
    </div>

    <div class="form-group row">
        <label for="c_dy" class="col-sm-2 col-form-label">{{ __('person.dynasty') }} (dy)</label>
        <div class="col-sm-10">
            <select-vue name="c_dy" model="dynasty" selected="{{ $isEdit ? $row->c_dy : '' }}"></select-vue>
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
                    <button type="submit" id="{{ $isEdit ? 'office-edit-submit' : '' }}" name="action" value="save" class="btn btn-primary">
                        <i class="fa fa-save"></i> {{ __('biogmains.save_directly') }}
                    </button>
                    @if($isEdit)
                        <button type="submit" name="action" value="saveas" class="btn btn-success" style="margin-left:40px;">
                            <i class="fa fa-copy"></i> {{ __('biogmains.save_as') }}
                        </button>
                    @endif
                @endif

                <button type="submit" name="action" value="proposal" class="btn btn-info">
                    <i class="fa fa-paper-plane"></i> {{ __('biogmains.submit_proposal') }}
                </button>
            @endif

            <a href="{{ route('basicinformation.offices.index', ['basicinformation' => $id]) }}" class="btn btn-secondary">
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
        window.initAjaxSelect($(".c_office_id"), 'office', {
            ajax: {
                data: function (params) {
                    return {
                        q: params.term,
                        page: params.page || 1,
                        c_dy: '{{ $biog_dy ?? '' }}',
                    };
                }
            }
        });
        window.initAjaxSelect($(".c_source"), 'text');
        window.initAjaxSelect($(".c_inst_code"), 'socialinstcode');
        window.initAjaxSelect($(".c_addr"), 'addr', {
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

        if (window.initLunarValidation) {
            window.initLunarValidation();
        }

        // ===================================================================
        // AI 智能填充功能（僅在新增模式且用戶已啟用時啟用）
        // ===================================================================
        @if(!$isEdit && config('services.gemini.api_key') && optional(auth()->user())->isActive())
        (function() {
            var aiEnterTextFirst = {!! Js::from(__('biogmains.ai_enter_text_first')) !!};
            var aiFillProcessing = {!! Js::from(__('biogmains.ai_fill_processing')) !!};
            var aiFillDoneStatus = {!! Js::from(__('biogmains.ai_fill_done_status')) !!};
            var aiClearConfirm = {!! Js::from(__('biogmains.ai_clear_confirm')) !!};

            // 環境變量控制：僅在開發模式下輸出調試日誌
            const DEBUG = {{ config('app.debug') ? 'true' : 'false' }};
            const debugLog = (...args) => {
                if (DEBUG) {
                    console.log(...args);
                }
            };

            const $aiSection = $('#ai-autofill-section');
            const $aiSourceText = $('#ai-source-text');
            const $btnAiAutofill = $('#btn-ai-autofill');
            const $btnClearAi = $('#btn-clear-ai');
            const $aiStatus = $('#ai-status');
            const $aiResultSummary = $('#ai-result-summary');

            let aiSuggestions = null;

            function addAiClass($field, className) {
                $field.addClass(className);

                if ($field.hasClass('select2-hidden-accessible')) {
                    const fieldId = $field.attr('id');
                    if (fieldId) {
                        const $select2Container = $field.next('.select2-container');
                        if ($select2Container.length > 0) {
                            $select2Container.find('.select2-selection').addClass(className);
                        }
                    } else {
                        $field.next('.select2-container').find('.select2-selection').addClass(className);
                    }
                }
            }

            function removeAiClasses($field) {
                $field.removeClass('ai-matched ai-suggested');

                if ($field.hasClass('select2-hidden-accessible')) {
                    $field.next('.select2-container').find('.select2-selection').removeClass('ai-matched ai-suggested');
                }
            }

            function showAiNotFoundHint($field, hintText) {
                const $container = $field.closest('.col-sm-10, .col-sm-4, .col-sm-12').first();
                if ($container.length === 0) return;
                $container.find('.ai-not-found-hint').remove();

                const safe = $('<div>').text(hintText).html();
                const $hint = $(
                    '<p class="ai-not-found-hint small mb-0 mt-1" ' +
                        'style="color:#6c757d; background:#f6f8fa; padding:4px 10px; ' +
                        'border-left:3px solid #adb5bd; border-radius:2px;">' +
                        '<i class="fas fa-search" style="opacity:.6; margin-right:4px;"></i>' +
                        'AI — <strong style="color:#495057;">' + safe + '</strong> ' +
                        '<button type="button" class="btn btn-link btn-sm p-0 ai-not-found-search" ' +
                            'style="vertical-align:baseline;">Search</button>' +
                    '</p>'
                );
                $container.append($hint);

                $hint.find('.ai-not-found-search').on('click', function() {
                    fillSelect2Search($field, hintText);
                });
            }

            function fillSelect2Search($field, text) {
                if (!$field.is('select') || !$field.hasClass('select2-hidden-accessible')) {
                    $field.val(text).trigger('change').focus();
                    return;
                }

                const isMulti = $field.prop('multiple');
                if (isMulti) {
                    const cleaned = ($field.val() || []).filter(function (v) {
                        return String(v) !== '0';
                    });
                    $field.val(cleaned).trigger('change');
                }

                $field.one('select2:open', function () {
                    const $search = $('.select2-search__field:visible').last();
                    if ($search.length > 0) {
                        $search.val(text).trigger('input').trigger('keyup');
                        $search.focus();
                    }
                });
                $field.select2('open');
            }

            function clearAiNotFoundHints() {
                $('.ai-not-found-hint').remove();
            }

            $btnAiAutofill.on('click', function() {
                const sourceText = $aiSourceText.val().trim();

                if (!sourceText) {
                    alert(aiEnterTextFirst);
                    return;
                }

                $btnAiAutofill.prop('disabled', true);
                $aiStatus.html('<span class="text-info"><i class="fas fa-spinner fa-spin"></i> ' + aiFillProcessing + '</span>');

                $.ajax({
                    url: '{{ route("ai.posting.extract", [], false) }}',
                    method: 'POST',
                    data: {
                        source_text: sourceText,
                        person_id: {{ $id }},
                        route_name: '{{ Route::currentRouteName() ?? '' }}',
                        route_url: window.location.pathname,
                        _token: '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        debugLog('[AI Autofill] API response:', response);
                        if (response.success) {
                            aiSuggestions = response.data;

                            setTimeout(function() {
                                applyAiSuggestions(aiSuggestions);

                                const hasDynastyFromAi = aiSuggestions.matched_fields?.c_dy || aiSuggestions.suggested_fields?.c_dy;
                                if (!hasDynastyFromAi) {
                                    const dynastyCode = $('.dynasty_code').val();
                                    if (dynastyCode) {
                                        const $dynastyField = $('[name="c_dy"]');
                                        if ($dynastyField.length > 0 && !$dynastyField.val()) {
                                            $dynastyField.val(dynastyCode).trigger('change');
                                        }
                                    }
                                }
                            }, 500);

                            var stats = response.data.statistics;
                            $('#matched-count').text(stats.matched_count);
                            $('#empty-count').text(stats.empty_count);
                            $('#suggested-count').text(stats.suggested_count);
                            $('#not-found-count').text(stats.not_found_count);

                            if (stats.suggested_count > 0) { $('#suggested-line').show(); } else { $('#suggested-line').hide(); }
                            if (stats.not_found_count > 0) { $('#not-found-line').show(); } else { $('#not-found-line').hide(); }
                            var anyHit = stats.matched_count + stats.suggested_count + stats.not_found_count;
                            if (anyHit === 0 && stats.empty_count > 0) { $('#empty-line').show(); } else { $('#empty-line').hide(); }
                            $aiResultSummary.show();

                            $btnClearAi.show();
                            $aiStatus.html('<span class="text-success"><i class="fas fa-check-circle"></i> ' + aiFillDoneStatus + '</span>');

                            if (response.ai_fill_log_id) {
                                $('#ai-fill-log-id').val(response.ai_fill_log_id);
                            }
                        } else {
                            $aiStatus.html('<span class="text-danger"><i class="fas fa-exclamation-circle"></i> ' + response.error + '</span>');
                        }
                    },
                    error: function(xhr) {
                        const errorMsg = xhr.responseJSON?.error || 'Request failed';
                        $aiStatus.html('<span class="text-danger"><i class="fas fa-exclamation-circle"></i> ' + errorMsg + '</span>');
                    },
                    complete: function() {
                        $btnAiAutofill.prop('disabled', false);
                    }
                });
            });

            function applyAiSuggestions(data) {
                const matched = data.matched_fields;
                const suggested = data.suggested_fields;

                $('.ai-matched, .ai-suggested').each(function() {
                    removeAiClasses($(this));
                });
                $('.ai-field-label').removeClass('ai-field-label');
                clearAiNotFoundHints();

                for (const [fieldName, fieldData] of Object.entries(matched)) {
                    let $field = $(`[name="${fieldName}"]`);
                    if ($field.length === 0) {
                        $field = $(`[name="${fieldName}[]"]`);
                    }
                    if ($field.length === 0) {
                        $field = $(`#${fieldName}`);
                        if ($field.length === 0) { continue; }
                    }

                    if ($field.is('select')) {
                        const isVueComponent = $field.closest('select-vue').length > 0 || $field.hasClass('select2');

                        if (Array.isArray(fieldData.value)) {
                            if (fieldName === 'c_addr') {
                                $field.empty();
                                const promises = fieldData.text.map((addrName, idx) => {
                                    return $.ajax({
                                        url: '/api/select/search/addr',
                                        data: { q: addrName },
                                        method: 'GET'
                                    }).then(response => {
                                        const items = response.data || [];
                                        const exactMatch = items.find(item => item.id === fieldData.value[idx]);
                                        return exactMatch || items[0];
                                    });
                                });

                                Promise.all(promises).then(results => {
                                    results.forEach((item) => {
                                        if (item) {
                                            const option = new Option(item.text, item.id, true, true);
                                            $field.append(option);
                                        }
                                    });
                                    $field.trigger('change');
                                    addAiClass($field, 'ai-matched');
                                }).catch(err => {
                                    fieldData.value.forEach((val, idx) => {
                                        const option = new Option(fieldData.text[idx], val, true, true);
                                        $field.append(option);
                                    });
                                    $field.trigger('change');
                                    addAiClass($field, 'ai-matched');
                                });
                            } else {
                                $field.empty();
                                fieldData.value.forEach((val, idx) => {
                                    const option = new Option(fieldData.text[idx], val, true, true);
                                    $field.append(option);
                                });
                                $field.trigger('change');
                                addAiClass($field, 'ai-matched');
                            }
                        } else {
                            if ($field.hasClass('select2-hidden-accessible')) {
                                const searchApiMap = {
                                    'c_office_id': 'office',
                                    'c_source': 'text',
                                    'c_inst_code': 'socialinstcode'
                                };
                                const listApiMap = {
                                    'c_appt_code': 'appttype',
                                    'c_assume_office_code': 'assumeoffice',
                                    'c_dy': 'dynasty',
                                    'c_fy_nh_code': 'nianhao',
                                    'c_ly_nh_code': 'nianhao',
                                    'c_fy_range': 'range',
                                    'c_ly_range': 'range',
                                    'c_fy_day_gz': 'ganzhi',
                                    'c_ly_day_gz': 'ganzhi'
                                };

                                const searchModel = searchApiMap[fieldName];
                                const listModel = listApiMap[fieldName];

                                if (searchModel) {
                                    $.ajax({
                                        url: `/api/select/search/${searchModel}`,
                                        data: { q: fieldData.value },
                                        method: 'GET'
                                    }).done(response => {
                                        $field.empty();
                                        const items = response.data || [];
                                        const exactMatch = items.find(item => item.id === fieldData.value);
                                        const item = exactMatch || items[0];
                                        if (item) {
                                            const option = new Option(item.text, item.id, true, true);
                                            $field.append(option).trigger('change');
                                            addAiClass($field, 'ai-matched');
                                        }
                                    }).fail(err => {
                                        $field.empty();
                                        const option = new Option(fieldData.text, fieldData.value, true, true);
                                        $field.append(option).trigger('change');
                                        addAiClass($field, 'ai-matched');
                                    });
                                } else if (listModel) {
                                    let retryCount = 0;
                                    const maxRetries = 30;

                                    const trySetValue = () => {
                                        const $targetOption = $field.find(`option[value="${fieldData.value}"]`).filter(function() {
                                            return $(this).val() !== '';
                                        });
                                        const optionExists = $targetOption.length > 0;

                                        if (optionExists) {
                                            $field.val(fieldData.value).trigger('change');
                                            addAiClass($field, 'ai-matched');
                                        } else if (retryCount < maxRetries) {
                                            retryCount++;
                                            setTimeout(trySetValue, 100);
                                        } else {
                                            addAiClass($field, 'ai-suggested');
                                        }
                                    };

                                    setTimeout(trySetValue, 200);
                                } else {
                                    $field.empty();
                                    const option = new Option(fieldData.text, fieldData.value, true, true);
                                    $field.append(option).trigger('change');
                                    addAiClass($field, 'ai-matched');
                                }
                            } else {
                                $field.val(fieldData.value).trigger('change');
                                addAiClass($field, 'ai-matched');
                            }
                        }
                    } else {
                        $field.val(fieldData.value);
                        addAiClass($field, 'ai-matched');
                    }

                    $field.closest('.form-group').find('label').first().addClass('ai-field-label');
                }

                for (const [fieldName, fieldData] of Object.entries(suggested)) {
                    let $field = $(`[name="${fieldName}"]`);
                    if ($field.length === 0) { $field = $(`[name="${fieldName}[]"]`); }
                    if ($field.length === 0) { continue; }

                    if ($field.is('select')) {
                        const ajaxModelMap = {
                            'c_office_id': 'office',
                            'c_source': 'text',
                            'c_inst_code': 'socialinstcode',
                            'c_addr': 'addr'
                        };
                        const vueSelectModelMap = {
                            'c_appt_code': 'appttype',
                            'c_assume_office_code': 'assumeoffice',
                            'c_dy': 'dynasty',
                            'c_fy_nh_code': 'nianhao',
                            'c_ly_nh_code': 'nianhao',
                            'c_office_category_id': 'officecate'
                        };
                        const isAjaxSelect = ajaxModelMap[fieldName] !== undefined;
                        const isVueSelect = vueSelectModelMap[fieldName] !== undefined;

                        if (fieldData.value !== undefined && fieldData.text !== undefined) {
                            if (Array.isArray(fieldData.value)) {
                                if (isAjaxSelect) { $field.empty(); }

                                if (fieldName === 'c_addr') {
                                    const promises = fieldData.text.map((addrName, idx) => {
                                        return $.ajax({
                                            url: '/api/select/search/addr',
                                            data: { q: addrName },
                                            method: 'GET'
                                        }).then(response => {
                                            const items = response.data || [];
                                            const exactMatch = items.find(item => item.id === fieldData.value[idx]);
                                            return exactMatch || items[0];
                                        });
                                    });

                                    Promise.all(promises).then(results => {
                                        results.forEach((item) => {
                                            if (item) {
                                                const option = new Option(item.text, item.id, true, true);
                                                $field.append(option);
                                            }
                                        });
                                        $field.trigger('change');
                                        addAiClass($field, 'ai-suggested');
                                    }).catch(err => {
                                        fieldData.value.forEach((val, idx) => {
                                            const option = new Option(fieldData.text[idx], val, true, true);
                                            $field.append(option);
                                        });
                                        $field.trigger('change');
                                        addAiClass($field, 'ai-suggested');
                                    });
                                } else {
                                    fieldData.value.forEach((val, idx) => {
                                        const option = new Option(fieldData.text[idx], val, true, true);
                                        $field.append(option);
                                    });
                                    $field.trigger('change');
                                    addAiClass($field, 'ai-suggested');
                                }
                            } else {
                                if (isAjaxSelect) {
                                    const model = ajaxModelMap[fieldName];
                                    $.ajax({
                                        url: `/api/select/search/${model}`,
                                        data: { q: fieldData.value },
                                        method: 'GET'
                                    }).done(response => {
                                        $field.empty();
                                        const items = response.data || [];
                                        const exactMatch = items.find(item => item.id === fieldData.value);
                                        const item = exactMatch || items[0];
                                        if (item) {
                                            const option = new Option(item.text, item.id, true, true);
                                            $field.append(option).trigger('change');
                                            addAiClass($field, 'ai-suggested');
                                        }
                                    }).fail(err => {
                                        $field.empty();
                                        const option = new Option(fieldData.text, fieldData.value, true, true);
                                        $field.append(option).trigger('change');
                                        addAiClass($field, 'ai-suggested');
                                    });
                                } else if (isVueSelect) {
                                    $field.val(fieldData.value).trigger('change');
                                    addAiClass($field, 'ai-suggested');
                                } else {
                                    const option = new Option(fieldData.text, fieldData.value, true, true);
                                    $field.append(option).trigger('change');
                                    addAiClass($field, 'ai-suggested');
                                }
                            }
                        } else {
                            const hintText = fieldData.ai_extracted || fieldData.search_query || '';
                            if (hintText) {
                                showAiNotFoundHint($field, hintText);
                            }
                            continue;
                        }
                    } else {
                        const displayValue = fieldData.value !== undefined ? fieldData.value : fieldData.ai_extracted;
                        $field.val(displayValue);
                        addAiClass($field, 'ai-suggested');
                    }

                    $field.closest('.form-group').find('label').first().addClass('ai-field-label');
                }
            }

            $btnClearAi.on('click', function() {
                if (!confirm(aiClearConfirm)) {
                    return;
                }

                $('.ai-matched, .ai-suggested').each(function() {
                    const $field = $(this);

                    if ($field.is('select')) {
                        $field.val(null).trigger('change');
                    } else {
                        $field.val('');
                    }

                    removeAiClasses($field);
                });

                $('.ai-field-label').removeClass('ai-field-label');
                clearAiNotFoundHints();
                $aiResultSummary.hide();
                $btnClearAi.hide();
                $aiStatus.empty();
                aiSuggestions = null;
            });
        })();
        @endif
        // ===================================================================
        // End AI 智能填充功能
        // ===================================================================

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
    });
    </script>
@endsection
