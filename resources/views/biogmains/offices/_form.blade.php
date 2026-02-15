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

    {{-- AI 智能填充區塊（僅在新增模式且用戶有直接寫入權限時顯示） --}}
    @if(!$isEdit && config('services.gemini.api_key') && auth()->user()->canWriteDirectly())
        <input type="hidden" name="ai_fill_log_id" id="ai-fill-log-id" value="">
        <div class="card card-info mb-3" id="ai-autofill-section">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title mb-0">
                    <i class="fas fa-magic"></i> AI 智能填充
                </h3>
                <a class="ml-3 text-white" style="font-size: 0.85em; opacity: 0.85; cursor: pointer;"
                   data-toggle="collapse" href="#ai-privacy-notice" role="button" aria-expanded="false">
                    <i class="fas fa-exclamation-triangle"></i> 重要提示：數據收集與第三方服務
                </a>
            </div>
            <div class="collapse" id="ai-privacy-notice">
                <div class="alert alert-warning mb-0 rounded-0 border-left-0 border-right-0" style="font-size: 0.9em;">
                    <p class="mb-2">使用智能填充功能即表示您理解並同意：</p>
                    <ul class="mb-0">
                        <li>您輸入的原始文本及 AI 填充結果將被記錄用於研究與改進</li>
                        <li>您的文本將發送至第三方 AI 服務（Google Gemini API、OpenAI API 等，恕不另行通知）進行處理</li>
                        <li>AI 填充結果僅供參考，請務必核實後再提交</li>
                    </ul>
                </div>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="ai-source-text">原始文本（請粘貼包含任官記錄的文本）</label>
                    <textarea class="form-control" id="ai-source-text" rows="4"
                              placeholder="例如：雍正元年正月初三知涿州新城縣至於六月十五卒于任"></textarea>
                    <small class="form-text text-muted">AI 將自動提取官名、地名、日期等信息並填充表單</small>
                </div>
                <button type="button" class="btn" id="btn-ai-autofill">
                    <i class="fas fa-bolt"></i> AI 智能填充
                </button>
                <button type="button" class="btn btn-secondary" id="btn-clear-ai" style="display:none;">
                    <i class="fas fa-eraser"></i> 清除 AI 建議
                </button>
                <span class="ml-3" id="ai-status"></span>
            </div>
        </div>

        {{-- 填充結果摘要（成功後顯示） --}}
        <div class="alert alert-success" id="ai-result-summary" style="display:none;">
            <h5><i class="fas fa-check-circle"></i> AI 填充完成</h5>
            <ul class="mb-2">
                <li>✅ <strong id="matched-count">0</strong> 個欄位成功匹配</li>
                <li id="suggested-line" style="display:none;">⚠️ <strong id="suggested-count">0</strong> 個欄位需要確認（黃色標記，請檢查後直接提交）</li>
                <li id="not-found-line" style="display:none;">🔍 <strong id="not-found-count">0</strong> 個欄位需要手動搜尋（AI 已提取關鍵字但未找到匹配）</li>
                <li>❌ <strong id="empty-count">0</strong> 個欄位無法提取</li>
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
                    <button type="submit" id="{{ $isEdit ? 'office-edit-submit' : '' }}" name="action" value="save" class="btn btn-primary">
                        <i class="fa fa-save"></i> 直接儲存
                    </button>
                    @if($isEdit)
                        <a href="../../../../basicinformation/{{ $row->c_personid }}/offices/{{ $row->c_office_id.'-'.$row->c_posting_id }}/saveas" class="btn btn-success" style="margin-left:40px;">save as</a>
                    @endif
                @endif

                <!-- 提交提案按鈕（所有活躍用戶可見） -->
                <button type="submit" name="action" value="proposal" class="btn btn-info">
                    <i class="fa fa-paper-plane"></i> 提交提案
                </button>
            @endif

            <a href="{{ route('basicinformation.offices.index', ['basicinformation' => $id]) }}" class="btn btn-secondary">
                <i class="fa fa-times"></i> 取消
            </a>
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
        // AI 智能填充功能（僅在新增模式且用戶有直接寫入權限時啟用）
        // ===================================================================
        @if(!$isEdit && config('services.gemini.api_key') && optional(auth()->user())->canWriteDirectly())
        (function() {
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

            let aiSuggestions = null; // 儲存 AI 建議結果

            /**
             * 給表單欄位添加 AI 樣式 class（支持普通欄位和 Select2）
             * @param {jQuery} $field - jQuery 欄位元素
             * @param {string} className - 要添加的 class 名稱（'ai-matched' 或 'ai-suggested'）
             */
            function addAiClass($field, className) {
                // 1. 給原始欄位添加 class
                $field.addClass(className);

                // 2. 如果是 Select2 欄位，也要給 Select2 容器添加 class
                if ($field.hasClass('select2-hidden-accessible')) {
                    // 找到對應的 Select2 selection 元素
                    const fieldId = $field.attr('id');
                    if (fieldId) {
                        // 使用 ID 找到對應的 Select2 容器
                        const $select2Container = $field.next('.select2-container');
                        if ($select2Container.length > 0) {
                            $select2Container.find('.select2-selection').addClass(className);
                        }
                    } else {
                        // 沒有 ID，使用相鄰元素查找
                        $field.next('.select2-container').find('.select2-selection').addClass(className);
                    }
                }
            }

            /**
             * 移除 AI 樣式 class（支持普通欄位和 Select2）
             * @param {jQuery} $field - jQuery 欄位元素
             */
            function removeAiClasses($field) {
                $field.removeClass('ai-matched ai-suggested');

                // 如果是 Select2 欄位，也要移除 Select2 容器的 class
                if ($field.hasClass('select2-hidden-accessible')) {
                    $field.next('.select2-container').find('.select2-selection').removeClass('ai-matched ai-suggested');
                }
            }

            // 點擊「AI 智能填充」按鈕
            $btnAiAutofill.on('click', function() {
                const sourceText = $aiSourceText.val().trim();
                debugLog('[AI Autofill] 按鈕點擊，原始文本:', sourceText);

                if (!sourceText) {
                    alert('請先輸入原始文本');
                    return;
                }

                // 顯示載入狀態
                $btnAiAutofill.prop('disabled', true);
                $aiStatus.html('<span class="text-info"><i class="fas fa-spinner fa-spin"></i> AI 處理中...</span>');

                // 調用 API
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
                        debugLog('[AI Autofill] API 響應:', response);
                        if (response.success) {
                            aiSuggestions = response.data;
                            debugLog('[AI Autofill] 提取的數據:', aiSuggestions);

                            // 延遲填充，確保 Vue 組件已完全渲染
                            setTimeout(function() {
                                applyAiSuggestions(aiSuggestions);

                                // AI 填充完成後，如果 AI 沒有返回朝代欄位，才使用人物的朝代自動填充
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

                            // 顯示結果摘要（先重置所有計數與可見性，避免前次殘留）
                            var stats = response.data.statistics;
                            $('#matched-count').text(stats.matched_count);
                            $('#empty-count').text(stats.empty_count);
                            $('#suggested-count').text(stats.suggested_count);
                            $('#not-found-count').text(stats.not_found_count);

                            if (stats.suggested_count > 0) {
                                $('#suggested-line').show();
                            } else {
                                $('#suggested-line').hide();
                            }
                            if (stats.not_found_count > 0) {
                                $('#not-found-line').show();
                            } else {
                                $('#not-found-line').hide();
                            }
                            $aiResultSummary.show();

                            $btnClearAi.show();
                            $aiStatus.html('<span class="text-success"><i class="fas fa-check-circle"></i> 填充完成</span>');

                            // 儲存 AI 填充日誌 ID，用於表單提交時關聯
                            if (response.ai_fill_log_id) {
                                $('#ai-fill-log-id').val(response.ai_fill_log_id);
                            }
                        } else {
                            $aiStatus.html('<span class="text-danger"><i class="fas fa-exclamation-circle"></i> ' + response.error + '</span>');
                        }
                    },
                    error: function(xhr) {
                        const errorMsg = xhr.responseJSON?.error || '請求失敗';
                        $aiStatus.html('<span class="text-danger"><i class="fas fa-exclamation-circle"></i> ' + errorMsg + '</span>');
                    },
                    complete: function() {
                        $btnAiAutofill.prop('disabled', false);
                    }
                });
            });

            // 應用 AI 建議到表單
            function applyAiSuggestions(data) {
                debugLog('[AI Autofill] 開始應用建議到表單');
                debugLog('[AI Autofill] matched_fields:', data.matched_fields);
                debugLog('[AI Autofill] suggested_fields:', data.suggested_fields);

                const matched = data.matched_fields;
                const suggested = data.suggested_fields;

                // 清除舊的標記
                $('.ai-matched, .ai-suggested').each(function() {
                    removeAiClasses($(this));
                });
                $('.ai-field-label').removeClass('ai-field-label');

                // 1. 填充成功匹配的欄位（綠色）
                for (const [fieldName, fieldData] of Object.entries(matched)) {
                    debugLog(`[AI Autofill] 處理 matched 欄位: ${fieldName}`, fieldData);
                    // 嘗試多種選擇器（處理多選欄位的 name="field[]" 情況）
                    let $field = $(`[name="${fieldName}"]`);
                    if ($field.length === 0) {
                        $field = $(`[name="${fieldName}[]"]`);
                    }

                    if ($field.length === 0) {
                        // 嘗試其他選擇器
                        $field = $(`#${fieldName}`);
                        if ($field.length === 0) {
                            continue;
                        }
                    }

                    if ($field.is('select')) {
                        // Select2 欄位或 Vue select 組件
                        const isVueComponent = $field.closest('select-vue').length > 0 || $field.hasClass('select2');

                        if (Array.isArray(fieldData.value)) {
                            // 多選（如地址）- 需要獲取完整的格式化文本
                            if (fieldName === 'c_addr') {
                                debugLog('[AI Autofill] 處理地址欄位 (matched):', fieldData);
                                // 對於地址欄位，調用 AJAX 獲取完整格式化數據
                                $field.empty();
                                const promises = fieldData.text.map((addrName, idx) => {
                                    debugLog(`[AI Autofill] 搜索地址: ${addrName}, 目標ID: ${fieldData.value[idx]}`);
                                    return $.ajax({
                                        url: '/api/select/search/addr',
                                        data: { q: addrName },
                                        method: 'GET'
                                    }).then(response => {
                                        debugLog(`[AI Autofill] 地址搜索結果 (${addrName}):`, response.data);
                                        // 找到匹配的地址（優先完全匹配）
                                        const items = response.data || [];
                                        const exactMatch = items.find(item => item.id === fieldData.value[idx]);
                                        debugLog(`[AI Autofill] exactMatch (ID=${fieldData.value[idx]}):`, exactMatch);
                                        debugLog(`[AI Autofill] 使用結果:`, exactMatch || items[0]);
                                        return exactMatch || items[0];
                                    });
                                });

                                Promise.all(promises).then(results => {
                                    debugLog('[AI Autofill] 所有地址查詢完成:', results);
                                    results.forEach((item) => {
                                        if (item) {
                                            const option = new Option(item.text, item.id, true, true);
                                            debugLog('[AI Autofill] 添加地址選項:', { text: item.text, id: item.id });
                                            $field.append(option);
                                        }
                                    });
                                    $field.trigger('change');
                                    addAiClass($field, 'ai-matched');
                                    debugLog('[AI Autofill] 地址欄位填充完成 (matched)');
                                }).catch(err => {
                                    debugLog(`[AI Autofill] ❌ 獲取完整地址信息失敗:`, err);
                                    // Fallback: 使用簡單格式
                                    fieldData.value.forEach((val, idx) => {
                                        const option = new Option(fieldData.text[idx], val, true, true);
                                        $field.append(option);
                                    });
                                    $field.trigger('change');
                                    addAiClass($field, 'ai-matched');
                                });
                            } else {
                                // 其他多選欄位，直接填充
                                $field.empty();
                                fieldData.value.forEach((val, idx) => {
                                    const option = new Option(fieldData.text[idx], val, true, true);
                                    $field.append(option);
                                });
                                $field.trigger('change');
                                addAiClass($field, 'ai-matched');
                            }
                        } else {
                            // 單選（包括 Vue 組件和 AJAX Select2）
                            // 對於 AJAX Select2，需要先創建 option 元素再設置 value
                            if ($field.hasClass('select2-hidden-accessible')) {
                                // 這是 Select2 欄位，可能需要獲取完整格式化數據
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
                                    // 調用搜索 API 獲取完整格式化數據
                                    $.ajax({
                                        url: `/api/select/search/${searchModel}`,
                                        data: { q: fieldData.text },
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
                                        debugLog(`❌ 獲取完整 ${searchModel} 信息失敗:`, err);
                                        // Fallback: 使用簡單格式
                                        $field.empty();
                                        const option = new Option(fieldData.text, fieldData.value, true, true);
                                        $field.append(option).trigger('change');
                                        addAiClass($field, 'ai-matched');
                                    });
                                } else if (listModel) {
                                    // Vue select-vue 組件：需要等待 Vue 加載完數據
                                    // 嘗試設置 value，支持重試機制
                                    let retryCount = 0;
                                    const maxRetries = 30; // 最多重試 30 次（3 秒）

                                    const trySetValue = () => {
                                        // 檢查該 value 的 option 是否存在（排除空值的默認選項）
                                        const $targetOption = $field.find(`option[value="${fieldData.value}"]`).filter(function() {
                                            return $(this).val() !== '';
                                        });
                                        const optionExists = $targetOption.length > 0;

                                        if (optionExists) {
                                            // Option 存在，直接設置 value（不清空其他選項）
                                            $field.val(fieldData.value).trigger('change');
                                            addAiClass($field, 'ai-matched');
                                        } else if (retryCount < maxRetries) {
                                            // Option 不存在且未達重試上限，繼續等待
                                            retryCount++;
                                            setTimeout(trySetValue, 100);
                                        } else {
                                            // 達到重試上限，保留現有選項，不設置值
                                            console.warn(`   ⚠️ 無法填充 Vue select 欄位 ${fieldName}：選項 ${fieldData.value} 不存在，保留現有選項`);
                                            addAiClass($field, 'ai-suggested'); // 標記為建議，讓用戶知道需要確認
                                        }
                                    };

                                    // 延遲執行，給 Vue 組件一些時間加載數據
                                    setTimeout(trySetValue, 200);
                                } else {
                                    // 沒有對應的 API，使用簡單格式
                                    $field.empty();
                                    const option = new Option(fieldData.text, fieldData.value, true, true);
                                    $field.append(option).trigger('change');
                                    addAiClass($field, 'ai-matched');
                                }
                            } else {
                                // 普通 select，直接設置 value
                                $field.val(fieldData.value).trigger('change');
                                addAiClass($field, 'ai-matched');
                            }
                        }
                    } else {
                        // 普通 input
                        $field.val(fieldData.value);
                        addAiClass($field, 'ai-matched');
                    }

                    // 標記 label
                    $field.closest('.form-group').find('label').first().addClass('ai-field-label');
                }

                // 2. 顯示建議值（黃色）- 需要用戶確認
                for (const [fieldName, fieldData] of Object.entries(suggested)) {
                    debugLog(`[AI Autofill] 處理 suggested 欄位: ${fieldName}`, fieldData);
                if (fieldName === 'c_addr' && fieldData.ai_structured) {
                    debugLog(`[AI Autofill] ai_structured 詳細:`, {
                        full_text: fieldData.ai_structured.full_text,
                        parent: fieldData.ai_structured.parent,
                        name: fieldData.ai_structured.name,
                        admin_type: fieldData.ai_structured.admin_type
                    });
                }
                    // 嘗試多種選擇器
                    let $field = $(`[name="${fieldName}"]`);
                    if ($field.length === 0) {
                        $field = $(`[name="${fieldName}[]"]`);
                    }
                    if ($field.length === 0) {
                        debugLog(`[AI Autofill] 找不到欄位: ${fieldName}`);
                        continue;
                    }

                    if ($field.is('select')) {
                        // 檢查欄位類型（AJAX Select2 vs Vue select-vue）
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
                            // 情況 1: 模糊匹配找到結果（有 value 和 text）→ 填充實際值但標記黃色
                            if (Array.isArray(fieldData.value)) {
                                // 多選欄位（如地址）- 只有 AJAX Select2 需要清空
                                if (isAjaxSelect) {
                                    $field.empty();
                                }

                                if (fieldName === 'c_addr') {
                                    debugLog('[AI Autofill] 處理地址欄位 (suggested):', fieldData);
                                    // 調用 AJAX 獲取完整格式化數據
                                    const promises = fieldData.text.map((addrName, idx) => {
                                        debugLog(`[AI Autofill] 搜索地址 (suggested): ${addrName}, 目標ID: ${fieldData.value[idx]}`);
                                        return $.ajax({
                                            url: '/api/select/search/addr',
                                            data: { q: addrName },
                                            method: 'GET'
                                        }).then(response => {
                                            debugLog(`[AI Autofill] 地址搜索結果 (suggested, ${addrName}):`, response.data);
                                            const items = response.data || [];
                                            const exactMatch = items.find(item => item.id === fieldData.value[idx]);
                                            debugLog(`[AI Autofill] exactMatch (suggested, ID=${fieldData.value[idx]}):`, exactMatch);
                                            debugLog(`[AI Autofill] 使用結果 (suggested):`, exactMatch || items[0]);
                                            return exactMatch || items[0];
                                        });
                                    });

                                    Promise.all(promises).then(results => {
                                        debugLog('[AI Autofill] 所有地址查詢完成 (suggested):', results);
                                        results.forEach((item) => {
                                            if (item) {
                                                const option = new Option(item.text, item.id, true, true);
                                                debugLog('[AI Autofill] 添加地址選項 (suggested):', { text: item.text, id: item.id });
                                                $field.append(option);
                                            }
                                        });
                                        $field.trigger('change');
                                        addAiClass($field, 'ai-suggested');
                                        debugLog('[AI Autofill] 地址欄位填充完成 (suggested)');
                                    }).catch(err => {
                                        debugLog(`[AI Autofill] ❌ 獲取完整地址信息失敗 (suggested):`, err);
                                        // Fallback: 使用簡單格式
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
                                // 單選欄位
                                if (isAjaxSelect) {
                                    // AJAX Select2：調用 AJAX 獲取完整格式化數據
                                    const model = ajaxModelMap[fieldName];
                                    $.ajax({
                                        url: `/api/select/search/${model}`,
                                        data: { q: fieldData.text },
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
                                        debugLog(`❌ 獲取完整 ${model} 信息失敗:`, err);
                                        // Fallback: 使用簡單格式
                                        $field.empty();
                                        const option = new Option(fieldData.text, fieldData.value, true, true);
                                        $field.append(option).trigger('change');
                                        addAiClass($field, 'ai-suggested');
                                    });
                                } else if (isVueSelect) {
                                    // Vue select-vue：不清空選項，直接設置 value
                                    $field.val(fieldData.value).trigger('change');
                                    addAiClass($field, 'ai-suggested');
                                } else {
                                    // 其他類型：使用簡單格式
                                    const option = new Option(fieldData.text, fieldData.value, true, true);
                                    $field.append(option).trigger('change');
                                    addAiClass($field, 'ai-suggested');
                                }
                            }
                        } else {
                            // 情況 2: 完全找不到匹配（只有 ai_extracted）→ 不修改欄位，跳過
                            debugLog(`[AI Autofill] 欄位 ${fieldName} 未找到匹配，跳過填充`);
                            continue;
                        }
                    } else {
                        // 普通 input 欄位
                        const displayValue = fieldData.value !== undefined ? fieldData.value : fieldData.ai_extracted;
                        $field.val(displayValue);
                        addAiClass($field, 'ai-suggested');
                    }

                    $field.closest('.form-group').find('label').first().addClass('ai-field-label');
                }
            }

            // 清除 AI 建議
            $btnClearAi.on('click', function() {
                if (!confirm('確定要清除所有 AI 填充的內容嗎？')) {
                    return;
                }

                // 清除標記和值
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
