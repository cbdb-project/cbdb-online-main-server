{{-- 共享表单组件 - Sources --}}
@php
    use App\Support\CompositePrimaryKey;
    $isEdit = isset($row);

    // 处理编辑模式的数据 - 必须在构建 formAction 之前执行
    if ($isEdit) {
        $row->c_notes = unionPKDef($row->c_notes);
        $row->c_pages = unionPKDef($row->c_pages);
        $wikiSourceIds = [60795, 68942, 68943];
        $isWikiSource = in_array($row->c_textid, $wikiSourceIds);
    }

    if ($isEdit && isset($pk)) {
        $formAction = CompositePrimaryKey::buildUrl(
            'basicinformation.sources.update.query',
            ['id' => $id],
            $pk
        );
    } elseif ($isEdit) {
        $formAction = route('basicinformation.sources.update', ['basicinformation' => $id, 'source' => $row->c_personid.'-'.$row->c_textid.'-'.$row->c_pages]);
    } else {
        $formAction = route('basicinformation.sources.store', ['basicinformation' => $id]);
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

    <x-forms.person-id-display :personId="$id" />

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

            <a href="{{ route('basicinformation.sources.index', ['basicinformation' => $id]) }}" class="btn btn-secondary">
                <i class="fa fa-times"></i> 取消
            </a>
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
