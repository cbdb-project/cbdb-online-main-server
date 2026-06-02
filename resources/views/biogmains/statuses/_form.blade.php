{{-- 共享表单组件 - Statuses --}}
@php
    use App\Support\CompositePrimaryKey;
    $isEdit = isset($row);

    if ($isEdit && isset($pk)) {
        $formAction = CompositePrimaryKey::buildUrl(
            'basicinformation.statuses.update.query',
            ['id' => $id],
            $pk
        );
    } elseif ($isEdit) {
        $formAction = route('basicinformation.statuses.update', ['basicinformation' => $id, 'status' => $row->c_personid.'-'.$row->c_sequence.'-'.$row->c_status_code]);
    } else {
        $formAction = route('basicinformation.statuses.store', ['basicinformation' => $id]);
    }
@endphp

<form action="{{ $formAction }}" method="post">
    @if($isEdit)
        {{ method_field('PATCH') }}
    @endif
    {{ csrf_field() }}
    <input type="hidden" name="ai_fill_log_id" id="ai-fill-log-id" value="">

    <x-forms.person-id-display :personId="$id" />

    {{-- AI 智能識別社會區分類別代碼（已啟用用戶 + Gemini API 已配置） --}}
    @if(config('services.gemini.api_key') && auth()->check() && auth()->user()->isActive())
        <div class="card card-info mb-3" id="ai-code-lookup-section">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title mb-0">
                    <i class="fas fa-magic"></i> {{ __('biogmains.ai_status_recognition') }}
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
                              placeholder="{{ __('biogmains.ai_input_placeholder_status') }}"></textarea>
                    <small class="form-text text-muted">{{ __('biogmains.ai_description_hint_status') }}</small>
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
        <label for="c_sequence" class="col-sm-2 col-form-label">{{ __('biogmains.sequence') }} (c_sequence)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_sequence" maxlength="4" value="{{ $isEdit ? $row->c_sequence : '0' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">{{ __('person.status') }} (c_status_code)</label>
        <div class="col-sm-10">
            <select class="form-control c_status_code" name="c_status_code">
                @if($isEdit && isset($res['statuse_str']))
                    <option value="{{ $row->c_status_code }}" selected="selected">{{ $res['statuse_str'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_supplement" class="col-sm-2 col-form-label">{{ __('biogmains.supplement_text') }} (c_supplement)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_supplement" value="{{ $isEdit ? $row->c_supplement : '' }}" placeholder="{{ __('biogmains.supplement_placeholder') }}">
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
        />
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
        <label for="c_notes" class="col-sm-2 col-form-label">{{ __('biogmains.notes_field') }} (c_notes)</label>
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

            <a href="{{ route('basicinformation.statuses.index', ['basicinformation' => $id]) }}" class="btn btn-secondary">
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
        var aiEnterDescription = {!! Js::from(__('biogmains.ai_enter_description')) !!};
        var aiProcessing = {!! Js::from(__('biogmains.ai_processing')) !!};
        var aiRecognitionFailed = {!! Js::from(__('biogmains.ai_recognition_failed')) !!};
        var aiNoMatch = {!! Js::from(__('biogmains.ai_no_match')) !!};

        $(".select2").select2();
        textperson_pair_first_load();

        // 使用统一的 AJAX Select2 初始化助手函数
        window.initAjaxSelect($(".c_source"), 'text');
        window.initAjaxSelect($(".c_status_code"), 'status');

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

        // === AI 智能識別社會區分類別代碼 ===
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
                    table: 'STATUS_CODES',
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
                        applyStatusCode($(this).data('code'));
                    });
                    $candidates.append($btn);
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

        function applyStatusCode(code) {
            var $select = $('select.c_status_code');
            $select.empty();
            $select.append(new Option(code.code_id + ' ' + code.desc_chn + ' ' + code.desc_en, code.code_id, true, true));
            $select.trigger('change');

            $select.closest('.form-group').find('label').css('color', '#28a745');

            $('html, body').animate({
                scrollTop: $select.closest('.form-group').offset().top - 100
            }, 300);
        }
        @endif
    });

    </script>
@endsection
