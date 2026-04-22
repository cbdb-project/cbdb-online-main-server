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
                    <i class="fas fa-magic"></i> AI 智能識別社會區分類別代碼
                </h3>
                <a class="ml-3 text-white" style="font-size: 0.85em; opacity: 0.85; cursor: pointer;"
                   data-toggle="collapse" href="#ai-code-privacy-notice" role="button" aria-expanded="false">
                    <i class="fas fa-exclamation-triangle"></i> 重要提示：數據收集與第三方服務
                </a>
            </div>
            <div class="collapse" id="ai-code-privacy-notice">
                <div class="alert alert-warning mb-0 rounded-0 border-left-0 border-right-0" style="font-size: 0.9em;">
                    <p class="mb-2">使用智能識別功能即表示您理解並同意：</p>
                    <ul class="mb-0">
                        <li>您輸入的文本及 AI 識別結果將被記錄用於研究與改進</li>
                        <li>您的文本將發送至第三方 AI 服務進行處理</li>
                        <li>AI 識別結果僅供參考，請務必核實後再提交</li>
                    </ul>
                    <p class="mb-0 mt-2">當前使用模型：<code>{{ config('services.gemini.model') }}</code></p>
                </div>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="ai-code-query">輸入描述（AI 將從 STATUS_CODES 中識別相關代碼）</label>
                    <textarea class="form-control" id="ai-code-query" rows="3"
                              placeholder="例如：通醫學"></textarea>
                    <small class="form-text text-muted">AI 會語義理解您的描述，從社會區分類別代碼表中找出最相關的代碼</small>
                </div>
                <button type="button" class="btn btn-info" id="btn-ai-code-lookup">
                    <i class="fas fa-bolt"></i> AI 智能識別
                </button>
                <span class="ml-3" id="ai-code-status"></span>

                {{-- 候選結果區 --}}
                <div id="ai-code-results" style="display:none;" class="mt-3">
                    <h6><i class="fas fa-check-circle text-success"></i> 候選代碼（點擊填入表單）</h6>
                    <div id="ai-code-candidates" class="mb-3"></div>

                    <div id="ai-code-not-found" style="display:none;">
                        <h6 class="text-warning"><i class="fas fa-exclamation-triangle"></i> 表中未找到對應的概念</h6>
                        <div id="ai-code-not-found-list"></div>
                    </div>

                    <div id="ai-code-summary" class="alert alert-light mt-2" style="display:none;"></div>
                </div>
            </div>
        </div>
    @endif

    <div class="form-group row">
        <label for="c_sequence" class="col-sm-2 col-form-label">次序(c_sequence)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_sequence" maxlength="4" value="{{ $isEdit ? $row->c_sequence : '0' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">社會區分(c_status_code)</label>
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
        <label for="c_supplement" class="col-sm-2 col-form-label">補充文字(c_supplement)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_supplement" value="{{ $isEdit ? $row->c_supplement : '' }}">
            請補充 "並稱/齊名" 的稱號 , 如「東南三賢」,「四俊」等
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

    <x-forms.audit-fields
        :show="$isEdit"
        :createdBy="$isEdit ? $row->c_created_by : null"
        :createdDate="$isEdit ? $row->c_created_date : null"
        :modifiedBy="$isEdit ? $row->c_modified_by : null"
        :modifiedDate="$isEdit ? $row->c_modified_date : null"
    />

    <div class="form-group row">
        <label for="__proposal_comment" class="col-sm-2 col-form-label">修改說明 / 提案理由</label>
        <div class="col-sm-10">
            <textarea class="form-control" name="__proposal_comment" rows="3" placeholder="請簡述本次修改的原因（直接儲存或提交提案時均會記錄此說明）"></textarea>
            <small class="text-muted">此說明將記錄於操作歷史中。提交提案時必填，直接儲存時可選填。</small>
        </div>
    </div>

    <div class="form-group row">
        <div class="offset-sm-2 col-sm-10">
            @if(Auth::check() && Auth::user()->isActive())
                <!-- 直接儲存按鈕（非眾包用戶可見） -->
                @if(Auth::user()->canWriteDirectly())
                    <button type="submit" name="action" value="save" class="btn btn-primary">
                        <i class="fa fa-save"></i> 直接儲存
                    </button>
                @endif

                <!-- 提交提案按鈕（所有活躍用戶可見） -->
                <button type="submit" name="action" value="proposal" class="btn btn-info">
                    <i class="fa fa-paper-plane"></i> 提交提案
                </button>
            @endif

            <a href="{{ route('basicinformation.statuses.index', ['basicinformation' => $id]) }}" class="btn btn-secondary">
                <i class="fa fa-times"></i> 取消
            </a>
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
        window.initAjaxSelect($(".c_status_code"), 'status');

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

        // === AI 智能識別社會區分類別代碼 ===
        @if(config('services.gemini.api_key') && auth()->check() && auth()->user()->isActive())
        $('#btn-ai-code-lookup').on('click', function() {
            var query = $('#ai-code-query').val().trim();
            if (!query) {
                alert('請輸入描述文字');
                return;
            }

            var $btn = $(this);
            var $status = $('#ai-code-status');
            $btn.prop('disabled', true);
            $status.html('<i class="fas fa-spinner fa-spin"></i> AI 識別中，請稍候...');
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
                    var msg = (xhr.responseJSON && xhr.responseJSON.error) || '識別失敗';
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

            // 渲染候選代碼按鈕
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
                $candidates.html('<span class="text-muted">未找到匹配的代碼</span>');
            }

            // 渲染未找到的概念
            if (data.not_found && data.not_found.length > 0) {
                $notFoundList.html(data.not_found.map(function(item) {
                    return '<span class="badge badge-light mr-1">' + item + '</span>';
                }).join(''));
                $notFound.show();
            } else {
                $notFound.hide();
            }

            // 渲染總結
            if (data.summary) {
                $summary.text(data.summary).show();
            } else {
                $summary.hide();
            }

            $results.show();
        }

        function applyStatusCode(code) {
            // 填入 c_status_code
            var $select = $('select.c_status_code');
            $select.empty();
            $select.append(new Option(code.code_id + ' ' + code.desc_chn + ' ' + code.desc_en, code.code_id, true, true));
            $select.trigger('change');

            // 標記已填充
            $select.closest('.form-group').find('label').css('color', '#28a745');

            // 滾動到 c_status_code 欄位
            $('html, body').animate({
                scrollTop: $select.closest('.form-group').offset().top - 100
            }, 300);
        }
        @endif
    });

    </script>
@endsection
