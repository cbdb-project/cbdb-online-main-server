{{-- 共享表单组件 - Kinship --}}
@php
    use App\Support\CompositePrimaryKey;
    $isEdit = isset($row);

    if ($isEdit && isset($pk)) {
        $formAction = CompositePrimaryKey::buildUrl(
            'basicinformation.kinship.update.query',
            ['id' => $id],
            $pk
        );
    } elseif ($isEdit) {
        $formAction = route('basicinformation.kinship.update', ['basicinformation' => $id, 'kinship' => $row->c_personid.'-'.$row->c_kin_id.'-'.$row->c_kin_code]);
    } else {
        $formAction = route('basicinformation.kinship.store', ['basicinformation' => $id]);
    }
@endphp

<form action="{{ $formAction }}" method="post">
    @if($isEdit)
        {{ method_field('PATCH') }}
    @endif
    {{ csrf_field() }}

    <x-forms.person-id-display :personId="$id" />

    <div class="form-group row">
        <label for="c_kin_code" class="col-sm-2 col-form-label">親屬關係(c_kin_code)</label>
        <div class="col-sm-10">
            <select class="form-control c_kin_code" name="c_kin_code">
                @if($isEdit && isset($res['kin_str']))
                    <option value="{{ $row->c_kin_code }}" selected="selected">{{ $res['kin_str'] }}</option>
                @else
                    <option value="0" selected="selected">0 未详</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_kin_id" class="col-sm-2 col-form-label">親戚姓名(c_kin_id)</label>
        <div class="col-sm-10">
            <select class="form-control c_kin_id" name="c_kin_id">
                @if($isEdit && isset($res['biog_str']))
                    <option value="{{ $row->c_kin_id }}" selected="selected">{{ $res['biog_str'] }}</option>
                @else
                    <option value="0" selected="selected">0 未详</option>
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
                    <option value="0" selected="selected">0 未详</option>
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
            <textarea class="form-control" name="c_notes" id="" cols="30" rows="5">{{ $isEdit ? $row->c_notes : '' }}</textarea>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_autogen_notes" class="col-sm-2 col-form-label">c_autogen_notes</label>
        <div class="col-sm-10">
            <textarea class="form-control" name="c_autogen_notes" id="" cols="30" rows="5">{{ $isEdit ? $row->c_autogen_notes : '' }}</textarea>
        </div>
    </div>

    <div class="form-group row">
        <label for="" class="col-sm-2 col-form-label">成對親屬關係</label>
        <div class="col-sm-10">
            <select class="form-control c_kinship_pair" name="c_kinship_pair">
                @if($isEdit && isset($res['kinpair_str']))
                    <option value="{{ $res['k_p_code'] }}" selected="selected">{{ $res['kinpair_str'] }}</option>
                @else
                    <option value="0">無對應親屬關係</option>
                @endif
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

            <a href="{{ route('basicinformation.kinship.index', ['basicinformation' => $id]) }}" class="btn btn-secondary">
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

        // 使用统一的 AJAX Select2 初始化助手函数
        window.initAjaxSelect($(".c_source"), 'text');
        window.initAjaxSelect($(".c_kin_code"), 'kincode');
        window.initAjaxSelect($(".c_kin_id"), 'biog');

        // 绑定 c_kin_code 的 change 事件
        $(".c_kin_code").on('change', function() {
            kinship_pair();
        });

        @if($isEdit)
        kinship_pair_first_load();
        @endif

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
                // API调用失败，添加默认选项
                $(".c_kinship_pair").append(new Option('無對應親屬關係', '0', true, true));
            });

        }

        function kinship_pair_first_load(){
            let c_kin_code = $('.c_kin_code').val();
            let c_kin_id = $('.c_kin_id').val();
            let current_selected = $('.c_kinship_pair').val(); // 保存当前选中的值

            $.get('/api/select/search/kinpair', {kin_code: c_kin_code, person_id: c_kin_id}, function (data, textStatus){
                // 如果API返回了数据，添加选项
                if (data && data.length > 0) {
                    for (let i = 0; i < data.length; i++){
                        const item = data[i];
                        // 检查是否已存在该选项，避免重复
                        if ($(".c_kinship_pair option[value='" + item['c_kincode'] + "']").length === 0) {
                            const is_selected = (current_selected == item['c_kincode']);
                            $(".c_kinship_pair").append(new Option(item['c_kincode'] + ' ' + item['c_kinrel_chn'] + ' ' + item['c_kinrel'], item['c_kincode'], is_selected, is_selected));
                        }
                    }
                } else {
                    // 如果没有匹配的成对关系，保留默认的"無對應親屬關係"选项
                    if ($(".c_kinship_pair option").length === 1) {
                        // 已有默认选项，不需要额外处理
                    }
                }
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
