{{-- 共享表单组件 - Alt Names --}}
@php
    use App\Support\CompositePrimaryKey;

    $isEdit = isset($row);

    // 处理編輯模式的数据 - 必须在构建 formAction 之前执行
    if ($isEdit) {
        // $alt 從 Controller 傳來時已經是編碼格式（如 12345-1-測試(slash)別名-1）
        // 分隔符 - 不應該被編碼，所以不能對整個 $alt 調用 unionPKDef()
        // 只需要對欄位值（用於顯示）進行編碼處理
        $row->c_alt_name_chn = unionPKDef($row->c_alt_name_chn);
        $row->c_alt_name = unionPKDef($row->c_alt_name);
        $row->c_notes = unionPKDef($row->c_notes);
    }

    // 使用查詢參數模式（推薦）或舊的 path-based 模式（向後相容）
    if ($isEdit && isset($pk)) {
        // 查詢參數模式：從 $pk 陣列建立 URL
        $formAction = CompositePrimaryKey::buildUrl(
            'basicinformation.altnames.update.query',
            ['id' => $id],
            $pk
        );
    } elseif ($isEdit) {
        // 舊的 path-based 模式（向後相容）
        $formAction = route('basicinformation.altnames.update', ['basicinformation' => $id, 'altname' => $alt]);
    } else {
        $formAction = route('basicinformation.altnames.store', ['basicinformation' => $id]);
    }
@endphp

<form action="{{ $formAction }}" method="post">
    @if($isEdit)
        {{ method_field('PATCH') }}
    @endif
    {{ csrf_field() }}

    <x-forms.person-id-display :personId="$id" />

    <div class="form-group row">
        <label for="c_sequence" class="col-sm-2 col-form-label">{{ __('biogmains.sequence') }} (c_sequence)</label>
        <div class="col-sm-{{ $isEdit ? '4' : '10' }}">
            <input name="c_sequence" type="text" class="form-control" value="{{ $isEdit ? $row->c_sequence : '' }}" {{ $isEdit ? '' : 'required' }}>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_alt_name_chn" class="col-sm-2 col-form-label">{{ __('biogmains.altname_chinese') }} (c_alt_name_chn)</label>
        <div class="col-sm-10">
            @php
                $c_alt_name_chn_value = $isEdit ? unionPKDef_decode_for_convert($row->c_alt_name_chn) : '';
            @endphp
            <input name="c_alt_name_chn" type="text" class="form-control" value="{{ $c_alt_name_chn_value }}" {{ $isEdit ? '' : 'required' }}>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_alt_name" class="col-sm-2 col-form-label">{{ __('biogmains.altname_pinyin_label') }} (c_alt_name)</label>
        <div class="col-sm-10">
            @php
                $c_alt_name_value = $isEdit ? unionPKDef_decode_for_convert($row->c_alt_name) : '';
            @endphp
            <input name="c_alt_name" type="text" class="form-control" value="{{ $c_alt_name_value }}">
        </div>
    </div>

    <div class="form-group row">
        <label for="c_alt_name_type_code" class="col-sm-2 col-form-label">{{ __('biogmains.altname_type_code') }} (c_alt_name_type_code)</label>
        <div class="col-sm-10">
            <select-vue name="c_alt_name_type_code" model="altcode" selected="{{ $isEdit ? $row->c_alt_name_type_code : '0' }}"></select-vue>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_source" class="col-sm-2 col-form-label">{{ __('biogmains.source_field') }} (c_source)</label>
        <div class="col-sm-{{ $isEdit ? '10' : '5' }}">
            <select class="form-control c_source" name="c_source" id="c_source">
                @if($isEdit && isset($text_str) && $text_str)
                    <option value="{{ $row->c_source }}" selected="selected">{{ $text_str }}</option>
                @else
                    <option value="" selected="selected">{{ __('biogmains.please_search') }}</option>
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
            @php
                $c_notes_value = $isEdit ? unionPKDef_decode_for_convert($row->c_notes) : '';
            @endphp
            <textarea class="form-control" name="c_notes" id="" cols="30" rows="5">{{ $c_notes_value }}</textarea>
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

            <a href="{{ route('basicinformation.altnames.index', ['basicinformation' => $id]) }}" class="btn btn-secondary">
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
