{{-- 共享表单组件 - Alt Names --}}
@php
    $isEdit = isset($row);
    $formAction = $isEdit
        ? route('basicinformation.altnames.update', ['basicinformation' => $id, 'altname' => $alt])
        : route('basicinformation.altnames.store', ['basicinformation' => $id]);

    // 处理编辑模式的数据
    if ($isEdit) {
        $alt = unionPKDef($alt);
        $row->c_alt_name_chn = unionPKDef($row->c_alt_name_chn);
        $row->c_alt_name = unionPKDef($row->c_alt_name);
        $row->c_notes = unionPKDef($row->c_notes);
    }
@endphp

<form action="{{ $formAction }}" method="post">
    @if($isEdit)
        {{ method_field('PATCH') }}
    @endif
    {{ csrf_field() }}

    <div class="form-group row">
        <label for="person_id" class="col-sm-2 col-form-label">person id</label>
        <div class="col-sm-10">
            <input type="text" class="form-control person_id" value="{{ $id }}" disabled>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_sequence" class="col-sm-2 col-form-label">次序(c_sequence)</label>
        <div class="col-sm-{{ $isEdit ? '4' : '10' }}">
            <input name="c_sequence" type="text" class="form-control" value="{{ $isEdit ? $row->c_sequence : '' }}" {{ $isEdit ? '' : 'required' }}>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_alt_name_chn" class="col-sm-2 col-form-label">別名漢字(c_alt_name_chn)</label>
        <div class="col-sm-10">
            @php
                $c_alt_name_chn_value = $isEdit ? unionPKDef_decode_for_convert($row->c_alt_name_chn) : '';
            @endphp
            <input name="c_alt_name_chn" type="text" class="form-control" value="{{ $c_alt_name_chn_value }}" {{ $isEdit ? '' : 'required' }}>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_alt_name" class="col-sm-2 col-form-label">別名拼音(c_alt_name)</label>
        <div class="col-sm-10">
            @php
                $c_alt_name_value = $isEdit ? unionPKDef_decode_for_convert($row->c_alt_name) : '';
            @endphp
            <input name="c_alt_name" type="text" class="form-control" value="{{ $c_alt_name_value }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_alt_name_type_code" class="col-sm-2 col-form-label">別名類別代碼(c_alt_name_type_code)</label>
        <div class="col-sm-10">
            <select-vue name="c_alt_name_type_code" model="altcode" selected="{{ $isEdit ? $row->c_alt_name_type_code : '0' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_source" class="col-sm-2 col-form-label">出處(c_source)</label>
        <div class="col-sm-{{ $isEdit ? '10' : '5' }}">
            <select class="form-control c_source" name="c_source" id="c_source">
                @if($isEdit && isset($text_str) && $text_str)
                    <option value="{{ $row->c_source }}" selected="selected">{{ $text_str }}</option>
                @else
                    <option value="" selected="selected">请搜索</option>
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
            @php
                $c_notes_value = $isEdit ? unionPKDef_decode_for_convert($row->c_notes) : '';
            @endphp
            <textarea class="form-control" name="c_notes" id="" cols="30" rows="5">{{ $c_notes_value }}</textarea>
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

    @if($isEdit)
        <div class="form-group row">
            <label for="" class="col-sm-2 col-form-label">建檔</label>
            <div class="col-sm-10">
                <input type="text" name="" class="form-control" value="{{ $row->c_created_by.'/'.$row->c_created_date }}" disabled>
            </div>
        </div>
        <div class="form-group row">
            <label for="" class="col-sm-2 col-form-label">更新</label>
            <div class="col-sm-10">
                <input type="text" name="" class="form-control" value="{{ $row->c_modified_by.'/'.$row->c_modified_date }}" disabled>
            </div>
        </div>
    @else
        <div class="form-group row">
            <label for="" class="col-sm-2 col-form-label">建檔</label>
            <div class="col-sm-10">
                <input type="text" name="" class="form-control" value="" disabled>
            </div>
        </div>
        <div class="form-group row">
            <label for="" class="col-sm-2 col-form-label">更新</label>
            <div class="col-sm-10">
                <input type="text" name="" class="form-control" value="" disabled>
            </div>
        </div>
    @endif

    <div class="form-group row">
        <label for="__proposal_comment" class="col-sm-2 col-form-label">提案說明</label>
        <div class="col-sm-10">
            <textarea class="form-control" name="__proposal_comment" rows="3" placeholder="提交提案時請簡述修改原因或補充說明"></textarea>
            <small class="text-muted">僅在提交提案時需要填寫，直接儲存時可略過</small>
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

            <a href="{{ route('basicinformation.altnames.index', ['basicinformation' => $id]) }}" class="btn btn-secondary">
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
                    //console.log(item);
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
