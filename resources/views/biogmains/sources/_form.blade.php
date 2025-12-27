{{-- 共享表单组件 - Sources --}}
@php
    $isEdit = isset($row);
    $formAction = $isEdit
        ? route('basicinformation.sources.update', ['basicinformation' => $id, 'source' => $row->c_personid.'-'.$row->c_textid.'-'.$row->c_pages])
        : route('basicinformation.sources.store', ['basicinformation' => $id]);

    // 处理编辑模式的数据
    if ($isEdit) {
        $row->c_notes = unionPKDef($row->c_notes);
        $row->c_pages = unionPKDef($row->c_pages);
        $wikiSourceIds = [60795, 68942, 68943];
        $isWikiSource = in_array($row->c_textid, $wikiSourceIds);
    }
@endphp

<form action="{{ $formAction }}" method="post">
    @if($isEdit)
        {{ method_field('PATCH') }}
    @endif
    {{ csrf_field() }}

    {{-- Wiki数据源警告 --}}
    @if($isEdit && $isWikiSource)
        <div class="alert alert-warning alert-dismissible" role="alert">
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
            <strong><i class="fa fa-exclamation-triangle"></i> 警告：</strong>
            本記錄為批量導入的 Wiki 對照資料，如果修改此記錄，下次導入時會丟失您的修改。
            請確認是否需要進行手動修改。
        </div>
    @endif

    <div class="form-group row">
        <label for="person_id" class="col-sm-2 col-form-label">person id</label>
        <div class="col-sm-10">
            <input type="text" class="form-control" value="{{ $id }}" disabled>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_textid" class="col-sm-2 col-form-label">出處(c_source)</label>
        <div class="col-sm-10">
            <select class="form-control c_source" name="c_textid" required>
                @if($isEdit && isset($res['text_str']))
                    <option value="{{ $row->c_textid }}" selected="selected">{{ $res['text_str'] }}</option>
                @endif
            </select>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_pages" class="col-sm-2 col-form-label">頁數/條目</label>
        <div class="col-sm-4">
            @php
                $pages_value = $isEdit ? unionPKDef_decode_for_convert($row->c_pages) : '0';
            @endphp
            <input type="text" class="form-control" name="c_pages" value="{{ $pages_value }}">
        </div>
    </div>

    <div class="form-group row">
        <label class="col-sm-2 col-form-label">選項</label>
        <div class="col-sm-10">
            <div class="custom-control custom-checkbox">
                <input type="hidden" name="c_main_source" value="0">
                <input type="checkbox"
                       class="custom-control-input"
                       id="c_main_source"
                       name="c_main_source"
                       value="1"
                       {{ ($isEdit && $row->c_main_source == 1) ? 'checked' : '' }}>
                <label class="custom-control-label" for="c_main_source">主要出處</label>
            </div>
            <div class="custom-control custom-checkbox mt-2">
                <input type="hidden" name="c_self_bio" value="0">
                <input type="checkbox"
                       class="custom-control-input"
                       id="c_self_bio"
                       name="c_self_bio"
                       value="1"
                       {{ ($isEdit && $row->c_self_bio == 1) ? 'checked' : '' }}>
                <label class="custom-control-label" for="c_self_bio">本人傳記</label>
            </div>
        </div>
    </div>

    <div class="form-group row">
        <label for="c_notes" class="col-sm-2 col-form-label">註(c_notes)</label>
        <div class="col-sm-10">
            @php
                $notes_value = $isEdit ? unionPKDef_decode_for_convert($row->c_notes) : '';
            @endphp
            <textarea class="form-control" name="c_notes" cols="30" rows="5">{{ $notes_value }}</textarea>
        </div>
    </div>

    @if($isEdit)
        <div class="form-group row">
            <label for="" class="col-sm-2 col-form-label">建檔</label>
            <div class="col-sm-10">
                <input type="text" class="form-control" value="{{ $row->c_created_by.'/'.$row->c_created_date }}" disabled>
            </div>
        </div>
        <div class="form-group row">
            <label for="" class="col-sm-2 col-form-label">更新</label>
            <div class="col-sm-10">
                <input type="text" class="form-control" value="{{ $row->c_modified_by.'/'.$row->c_modified_date }}" disabled>
            </div>
        </div>
    @endif

    <div class="form-group row">
        <div class="offset-sm-2 col-sm-10">
            <button type="submit" class="btn btn-secondary">Submit</button>
        </div>
    </div>
</form>

@section('js')
    <script>
    onViteReady(function() {
        $(".select2").select2();

        // 使用统一的 AJAX Select2 初始化助手函数
        window.initAjaxSelect($(".c_source"), 'text');
    });
    </script>
@endsection
