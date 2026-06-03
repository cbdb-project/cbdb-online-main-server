@extends('layouts.dashboard-v3')

@section('content')

    @php
        $currentUserName = optional(Auth::user())->name;
        // Use application timezone (consistent with write operations)
        $currentTimestamp = \Carbon\Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s');
    @endphp

    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ $table }}</h3>
        </div>
        <div class="card-body">
                <form action="{{ route('codes.update', ['table_name' => $table, 'id' => $id], false) }}" method="post">
                    {{ method_field('PATCH') }}
                    {{ csrf_field() }}
                    @if($table === 'TEXT_CODES')
                    <div class="form-group row">
                        <label class="col-sm-2 col-form-label">{{ __('codes.author_label') }}</label>
                        <div class="col-sm-10" id="author_list_container">
                            <span class="text-muted">{{ __('codes.loading_author') }}</span>
                        </div>
                    </div>
                    @endif
                    @foreach($row as $key => $value)
                        @php
                            $isCreatedField = in_array($key, ['c_created_by', 'c_created_date'], true);
                            $isModifiedField = in_array($key, ['c_modified_by', 'c_modified_date'], true);
                            // 显示原始值而不是替换后的值
                            $inputValue = $value;
                            $shouldDisable = $isCreatedField || $isModifiedField;

                            // 为 modified_* 字段准备提示文字
                            $helpText = null;
                            if ($isModifiedField && Auth::check() && Auth::user()->isActive()) {
                                if ($key === 'c_modified_by' && $currentUserName !== null) {
                                    $helpText = __('codes.field_will_be_replaced') . $currentUserName;
                                } elseif ($key === 'c_modified_date') {
                                    $helpText = __('codes.field_will_be_replaced') . $currentTimestamp;
                                }
                            }
                        @endphp
                        <div class="form-group row">
                            <label for="{{ $key }}" class="col-sm-2 col-form-label">{{ $key }}</label>
                            @if($table === 'TEXT_INSTANCE_DATA' && $key === 'c_textid')
                            <div class="col-sm-8">
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $inputValue) }}" @if($shouldDisable) readonly @endif>
                            </div>
                            <div class="col-sm-2">
                                <button type="button" id="button_ajax_load_instance" class="btn btn-info">Load Data</button>
                            </div>
                            <div class="offset-sm-2 col-sm-10">
                                <p class="help-block text-muted">{!! __('codes.text_codes_copy_hint') !!}</p>
                            </div>
                            @elseif($table === 'ALTNAME_DATA' && $key === 'c_personid')
                            <div class="col-sm-10">
                                <select class="form-control altname-person js-person-select" name="{{ $key }}" data-initial-id="{{ $inputValue }}">
                                    <option value="{{ $inputValue }}" selected>{{ $inputValue }}</option>
                                </select>
                                @if($helpText)
                                <p class="help-block text-info"><strong>{{ $helpText }}</strong></p>
                                @endif
                            </div>
                            @elseif($key === 'c_personid' || $key === 'c_kin_id')
                            <div class="col-sm-10">
                                <select class="form-control person-select js-person-select" name="{{ $key }}" data-initial-id="{{ $inputValue }}">
                                    <option value="{{ $inputValue }}" selected>{{ $inputValue }}</option>
                                </select>
                                @if($helpText)
                                <p class="help-block text-info"><strong>{{ $helpText }}</strong></p>
                                @endif
                            </div>
                            @elseif($table === 'ADDR_BELONGS_DATA' && $key === 'c_addr_id')
                            <div class="col-sm-10">
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $inputValue) }}" @if($shouldDisable) readonly @endif>
                                <p class="help-block text-muted">{!! __('codes.addr_copy_hint') !!}</p>
                                @if($helpText)
                                <p class="help-block text-info"><strong>{{ $helpText }}</strong></p>
                                @endif
                            </div>
                            @elseif($table === 'ADDR_BELONGS_DATA' && $key === 'c_belongs_to')
                            <div class="col-sm-10">
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $inputValue) }}" @if($shouldDisable) readonly @endif>
                                <p class="help-block text-muted">{!! __('codes.addr_copy_hint') !!}</p>
                                @if($helpText)
                                <p class="help-block text-info"><strong>{{ $helpText }}</strong></p>
                                @endif
                            </div>
                            @else
                            <div class="col-sm-10">
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $inputValue) }}" @if($shouldDisable) readonly @endif>
                                @if($helpText)
                                <p class="help-block text-info"><strong>{{ $helpText }}</strong></p>
                                @endif
                            </div>
                            @endif
                        </div>
                    @endforeach
                    @if(Auth::check() && Auth::user()->isActive())
                    <div class="form-group row">
                        <label for="__proposal_comment" class="col-sm-2 col-form-label">{{ __('codes.proposal_desc') }}</label>
                        <div class="col-sm-10">
                            <textarea name="__proposal_comment" id="__proposal_comment" class="form-control" rows="3" placeholder="{{ __('codes.proposal_desc_hint') }}">{{ old('__proposal_comment') }}</textarea>
                            <p class="help-block">{{ __('codes.proposal_ignore_hint') }}</p>
                        </div>
                    </div>
                    <div class="form-group row">
                        <div class="col-sm-10 offset-sm-2">
                            <button type="submit" class="btn btn-primary">{{ __('codes.save_direct') }}</button>
                            <button type="submit" class="btn btn-info"
                                    formaction="{{ route('codes.propose.update', ['table_name' => $table, 'id' => $id], false) }}">
                                {{ __('codes.submit_proposal') }}
                            </button>
                        </div>
                    </div>
                    @endif
                </form>
        </div>
    </div>

@endsection
@section('js')
@if($table === 'TEXT_CODES')
<script>
    var _msg_no_textid = {!! Js::from(__('codes.no_textid_msg')) !!};
    var _msg_no_author = {!! Js::from(__('codes.no_author_data')) !!};
    var _msg_load_failed = {!! Js::from(__('codes.load_failed')) !!};
</script>
<style>
    .author-list-scroll {
        border: 1px solid #ced4da;
        border-radius: 4px;
        background-color: #f8f9fa;
    }
    .author-list-scroll::-webkit-scrollbar {
        width: 10px;
    }
    .author-list-scroll::-webkit-scrollbar-thumb {
        background-color: #6c757d;
        border-radius: 6px;
        border: 2px solid #f8f9fa;
    }
    .author-list-scroll::-webkit-scrollbar-track {
        background-color: #e9ecef;
    }
    .author-list-scroll {
        scrollbar-width: auto;
        scrollbar-color: #6c757d #e9ecef;
    }
</style>
<script>
    onViteReady(function() {
        const $authorList = $('#author_list_container');

        const loadAuthors = function() {
            const c_textid = $("input[name='c_textid']").val();
            if (!c_textid) {
                $authorList.html('<span class="text-muted" style="padding-top: 7px; display: inline-block;">' + _msg_no_textid + '</span>');
                return;
            }

            $.get('/api/select/search/textauthor', { q: c_textid }, function (data) {
                $authorList.empty();

                if (data && Array.isArray(data.data) && data.data.length > 0) {
                    const list = $('<ul class="list-unstyled mb-0" style="padding-top: 7px;"></ul>');
                    if (data.data.length > 5) {
                        list.css({
                            'max-height': '160px',
                            'overflow-y': 'auto',
                            'padding-right': '8px',
                        });
                        list.addClass('author-list-scroll');
                    }
                    data.data.forEach(function(item) {
                        const id = item.value;
                        let displayText = item.text;
                        // Remove ID prefix
                        if (displayText.startsWith(id + ' - ')) {
                            displayText = displayText.substring(id.toString().length + 3);
                        }
                        // Connect Name and Pinyin with / instead of -
                        const firstHyphen = displayText.indexOf(' - ');
                        if (firstHyphen !== -1) {
                            displayText = displayText.substring(0, firstHyphen) + ' / ' + displayText.substring(firstHyphen + 3);
                        }

                        // Create elements securely
                        const $li = $('<li></li>');
                        const $link = $('<a></a>')
                            .attr('href', `/basicinformation/${id}/texts`)
                            .attr('target', '_blank')
                            .attr('rel', 'noopener noreferrer')
                            .text(`[${id}]`);
                        
                        $li.append($link);
                        // Safely append text node
                        $li.append(document.createTextNode(' ' + displayText));
                        
                        list.append($li);
                    });
                    $authorList.append(list);
                } else {
                    $authorList.html('<span class="text-muted" style="padding-top: 7px; display: inline-block;">' + _msg_no_author + '</span>');
                }
            }).fail(function() {
                $authorList.html('<span class="text-danger" style="padding-top: 7px; display: inline-block;">' + _msg_load_failed + '</span>');
            });
        };

        loadAuthors();
    });
</script>
@endif
@if($table === 'TEXT_INSTANCE_DATA')
<script>
    var _msg_load_no_data = {!! Js::from(__('codes.load_no_data_alert')) !!};
    var _msg_load_success = {!! Js::from(__('codes.load_success_alert')) !!};
    var _msg_load_failed_alert = {!! Js::from(__('codes.load_failed_alert')) !!};
</script>
<script type="text/javascript">
onViteReady(function (){

    var DoAjax = function(requestUrl, sentData, sHandler, eHandler, pageNotFoundHandler){
        $.ajax({
            type: 'GET',
            url: requestUrl,
            cache: false,
            data: sentData,
            success: sHandler,
            error: eHandler,
            statusCode: {
              404: pageNotFoundHandler
            }
        });
    };

    /* Load Data from TEXT_CODES */
    $("#button_ajax_load_instance").click(function(){

        var c_textid = $("input[name='c_textid']").val();
        var url = "/api/select/search/text?q=" + c_textid + "";
        /* disable trigger button, preventing multiple requests */
        $(this).attr("disabled", true);

        /* wait for ajax */
        setTimeout(function(){

            DoAjax(url, {todo : "exSucceed"},
                function(data, textStatus, jqXHR){
                    if(data.data == '') {
                        alert(_msg_load_no_data);
                    }
                    else if(data.data != '') {
                        /* 在這裡添加錄入表單更新的欄位與資料 */
                        $("input[name='c_instance_title_chn']").val(data.data[0].c_title_chn);
                        $("input[name='c_instance_title_chn']").css("background","#FFFFBB");
                        $("input[name='c_instance_title']").val(data.data[0].c_title);
                        $("input[name='c_instance_title']").css("background","#FFFFBB");
                        alert(_msg_load_success);
                    }
                    else {
                        alert(_msg_load_failed_alert);
                    }
                });

            /* enable trigger button */
            $("#button_ajax_load_instance").attr("disabled", false);
        }, 10);
    });

});
</script>
@endif
@php
    $hasPersonIdField = in_array('c_personid', array_keys($row ?? []), true) || in_array('c_kin_id', array_keys($row ?? []), true);
@endphp
@if(in_array($table, ['ALTNAME_DATA', 'BIOG_ADDR_DATA']) || $hasPersonIdField)
<script>
    var _person_search_placeholder = {!! Js::from(__('codes.person_search_placeholder')) !!};
    onViteReady(function() {
        const $persons = $('.js-person-select');

        $persons.each(function() {
            const $person = $(this);
            const initialId = $person.data('initial-id') || $person.data('initial-value') || $person.val();

            window.initPersonSelect($person, {
                placeholder: _person_search_placeholder,
            });

            if (initialId) {
                window.fetchPersonOption(initialId).then(function(opt) {
                    if (opt) {
                        const option = new Option(opt.text, opt.id, true, true);
                        $person.append(option).trigger('change.select2');
                    } else {
                        $person.val(initialId).trigger('change.select2');
                    }
                });
            }
        });
    });
</script>
@endif
@endsection
