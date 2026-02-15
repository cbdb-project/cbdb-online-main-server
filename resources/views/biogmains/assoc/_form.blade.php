{{-- 共享表单组件 - Assoc --}}
@php
    use App\Support\CompositePrimaryKey;
    $isEdit = isset($row);

    // 处理编辑模式的数据 - 必须在构建 formAction 之前执行
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

    <x-forms.person-id-display :personId="$id" />

    <div class="form-group row">
        <label for="c_sequence" class="col-sm-2 col-form-label">次序(sequence)</label>
        <div class="col-sm-10">
            <input type="{{ $isEdit ? 'text' : 'number' }}" class="form-control" name="c_sequence" value="{{ $isEdit ? $row->c_sequence : '' }}" maxlength="4">
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">親屬關係人</label>
        <div class="col-sm-1">關係</div>
        <div class="col-sm-3">
            <select class="form-control c_kin_code" name="c_kin_code">
                @if($isEdit && isset($res['kin_code']))
                    <option value="{{ $row->c_kin_code }}" selected="selected">{{ $res['kin_code'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
        <div class="col-sm-1">姓名</div>
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
        <label for="" class="col-sm-2 col-form-label">社會關係人Y</label>
        <div class="col-sm-1">關係</div>
        <div class="col-sm-3">
            <select class="form-control c_assoc_code" name="c_assoc_code">
                @if($isEdit && isset($res['assoc_code']))
                    <option value="{{ $row->c_assoc_code }}" selected="selected">{{ $res['assoc_code'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
        <div class="col-sm-1">姓名</div>
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
        <label for="" class="col-sm-2 col-form-label">社會關係人親屬</label>
        <div class="col-sm-1">關係</div>
        <div class="col-sm-3">
            <select class="form-control c_assoc_kin_code" name="c_assoc_kin_code">
                @if($isEdit && isset($res['assoc_kin_code']))
                    <option value="{{ $row->c_assoc_kin_code }}" selected="selected">{{ $res['assoc_kin_code'] }}</option>
                @else
                    <option value="0" selected="selected"></option>
                @endif
            </select>
        </div>
        <div class="col-sm-1">姓名</div>
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
        <label for="c_assoc_fy_year" class="col-sm-2 col-form-label">社會關係始年</label>
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
        <label for="c_assoc_ly_year" class="col-sm-2 col-form-label">社會關係終年</label>
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
        <label for="c_notes" class="col-sm-2 col-form-label">註(c_notes)</label>
        <div class="col-sm-10">
            @php
                $notes_value = $isEdit ? unionPKDef_decode_for_convert($row->c_notes) : '';
            @endphp
            <textarea class="form-control" name="c_notes" id="" cols="30" rows="5">{{ $notes_value }}</textarea>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_topic_code" class="col-sm-2 col-form-label">學術主題</label>
        <div class="col-sm-10">
            <select-vue name="c_topic_code" model="topic" selected="{{ $isEdit ? $row->c_topic_code : '' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_occasion_code" class="col-sm-2 col-form-label">場合</label>
        <div class="col-sm-10">
            <select-vue name="c_occasion_code" model="occasion" selected="{{ $isEdit ? $row->c_occasion_code : '' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_text_title" class="col-sm-2 col-form-label">作品標題</label>
        <div class="col-sm-10">
            @php
                $text_title_value = $isEdit ? unionPKDef_decode_for_convert($row->c_text_title) : '[n/a]';
            @endphp
            <input type="text" class="form-control" name="c_text_title" value="{{ $text_title_value }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_assoc_count" class="col-sm-2 col-form-label">關係次數(c_assoc_count)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_assoc_count" value="{{ $isEdit ? $row->c_assoc_count : '1' }}">
            此欄位僅適用於書信 : 當無法以標題及日期區分多次信件時 , 則僅建「一筆」社會關係 , 並將信件總數填於此欄 . 請填阿拉伯數字
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">社會關係中介人(tertiary_personid)</label>
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
        <label for="" class="col-sm-2 col-form-label">社會關係中介類型(tertiary_type)</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" name="c_tertiary_type_notes" value="{{ $isEdit ? $row->c_tertiary_type_notes : '' }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">社會關係指證人</label>
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
        <label for="" class="col-sm-2 col-form-label">社會關係發生地</label>
        <div class="col-sm-10">
            <select class="form-control c_addr_id" name="c_addr_id">
                @if($isEdit && isset($res['addr_id']))
                    <option value="{{ $row->c_addr_id }}" selected="selected">{{ $res['addr_id'] }}</option>
                @else
                    <option value="0" selected="selected">0 [Unknown] [未詳] </option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">社交機構(social_institution)</label>
        @if($isEdit)
            <input name="c_inst_name_code" type="hidden">
        @endif
        <div class="col-sm-10">
            <select class="form-control c_inst_code" name="c_inst_code">
                @if($isEdit && isset($res['inst_code']))
                    <option value="{{ $row->c_inst_code.'-'.$row->c_inst_name_code }}" selected="selected">{{ $res['inst_code'] }}</option>
                @else
                    <option value="0-0" selected="selected">0 [Unknown] [未詳] </option>
                @endif
            </select>
        </div>
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
        <label for="" class="col-sm-2 col-form-label">成對社會關係</label>
        <div class="col-sm-10">
            <select class="form-control c_assocship_pair" name="c_assocship_pair">
                @if($isEdit)
                    <option value="" selected="selected"></option>
                @else
                    <option value="0">無對應社會關係</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">成對親屬關係</label>
        <div class="col-sm-10">
            <select class="form-control c_kinship_pair" name="c_kinship_pair">
                @if($isEdit && isset($res['kinship_pair']))
                    <option value="{{ $res['kinship_pair'] }}" selected="selected">{{ $res['kinship_pair'] }}</option>
                @endif
                <option value="0">無對應親屬關係</option>
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">成對社會關係人的親屬關係</label>
        <div class="col-sm-10">
            <select class="form-control c_assoc_kinship_pair" name="c_assoc_kinship_pair">
                @if($isEdit && isset($res['assoc_kinship_pair']))
                    <option value="{{ $res['assoc_kinship_pair'] }}" selected="selected">{{ $res['assoc_kinship_pair'] }}</option>
                @endif
                <option value="0">無對應親屬關係</option>
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

            <a href="{{ route('basicinformation.assoc.index', ['basicinformation' => $id]) }}" class="btn btn-secondary">
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

            // 清空现有选项
            $(".c_assocship_pair").empty();

            // 调用API获取成对社会关系
            $.get('/api/select/search/assocpair', {assoc_code: c_assoc_code, person_id: c_assoc_id}, function (data, textStatus){
                // 如果API返回了数据，添加所有选项
                if (data && data.length > 0) {
                    for (let i = 0; i < data.length; i++){
                        const item = data[i];
                        const optionText = item['c_assoc_code'] + ' ' + item['c_assoc_desc_chn'] + ' ' + item['c_assoc_desc'];
                        $(".c_assocship_pair").append(new Option(optionText, item['c_assoc_code'], false, false));
                    }
                    // 默认选中第一个选项
                    $(".c_assocship_pair").val(data[0]['c_assoc_code']).trigger('change');
                } else {
                    // 没有匹配的成对关系，添加默认选项
                    $(".c_assocship_pair").append(new Option('無對應社會關係', '0', true, true));
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                $(".c_assocship_pair").append(new Option('無對應社會關係', '0', true, true));
            });

        }

        function kinship_pair(){
            let c_kin_code = $('.c_kin_code').val();
            let c_kin_id = $('.c_kin_id').val();

            // 清空现有选项
            $(".c_kinship_pair").empty();

            // 调用API获取成对亲属关系
            $.get('/api/select/search/kinpair', {kin_code: c_kin_code, person_id: c_kin_id}, function (data, textStatus){
                // 如果API返回了数据，添加所有选项
                if (data && data.length > 0) {
                    for (let i = 0; i < data.length; i++){
                        const item = data[i];
                        const optionText = item['c_kincode'] + ' ' + item['c_kinrel_chn'] + ' ' + item['c_kinrel'];
                        $(".c_kinship_pair").append(new Option(optionText, item['c_kincode'], false, false));
                    }
                    // 默认选中第一个选项
                    $(".c_kinship_pair").val(data[0]['c_kincode']).trigger('change');
                } else {
                    // 没有匹配的成对关系，添加默认选项
                    $(".c_kinship_pair").append(new Option('無對應親屬關係', '0', true, true));
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                $(".c_kinship_pair").append(new Option('無對應親屬關係', '0', true, true));
            });

        }

        function assoc_kinship_pair(){
            let c_assoc_kin_code = $('.c_assoc_kin_code').val();
            let c_assoc_kin_id = $('.c_assoc_kin_id').val();

            // 清空现有选项
            $(".c_assoc_kinship_pair").empty();

            // 调用API获取成对亲属关系
            $.get('/api/select/search/kinpair', {kin_code: c_assoc_kin_code, person_id: c_assoc_kin_id}, function (data, textStatus){
                // 如果API返回了数据，添加所有选项
                if (data && data.length > 0) {
                    for (let i = 0; i < data.length; i++){
                        const item = data[i];
                        const optionText = item['c_kincode'] + ' ' + item['c_kinrel_chn'] + ' ' + item['c_kinrel'];
                        $(".c_assoc_kinship_pair").append(new Option(optionText, item['c_kincode'], false, false));
                    }
                    // 默认选中第一个选项
                    $(".c_assoc_kinship_pair").val(data[0]['c_kincode']).trigger('change');
                } else {
                    // 没有匹配的成对关系，添加默认选项
                    $(".c_assoc_kinship_pair").append(new Option('無對應親屬關係', '0', true, true));
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                $(".c_assoc_kinship_pair").append(new Option('無對應親屬關係', '0', true, true));
            });

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
