@extends('layouts.dashboard')

@section('content')

    @php
        $currentUserName = optional(Auth::user())->name;
        $currentDateYmd = \Carbon\Carbon::now()->format('Ymd');
    @endphp

    <div class="panel panel-default">
        <div class="panel-heading">{{ $table }}</div>
        <div class="panel-body">
            <div class="panel-body">
                <form action="/codes/{{ $table }}/{{ $id }}" class="form-horizontal" method="post">
                    {{ method_field('PATCH') }}
                    {{ csrf_field() }}
                    @if($table === 'TEXT_CODES')
                    <div class="form-group">
                        <label for="author" class="col-sm-2 control-label">author</label>
                        <div class="col-sm-8">
                            <select class="form-control author" name="" readonly="readonly"></select>
                        </div>
                        <div class="col-sm-2">
                            <button type="button" id="button_ajax_load" class="btn btn-info">Jump to author</button>
                        </div>
                    </div>
                    @endif
                    @foreach($row as $key => $value)
                        @php
                            $isCreatedField = in_array($key, ['c_created_by', 'c_created_date'], true);
                            $isModifiedField = in_array($key, ['c_modified_by', 'c_modified_date'], true);
                            $inputValue = $value;
                            if ($isModifiedField) {
                                if ($key === 'c_modified_by' && $currentUserName !== null) {
                                    $inputValue = $currentUserName;
                                } elseif ($key === 'c_modified_date') {
                                    $inputValue = $currentDateYmd;
                                }
                            }
                            $shouldDisable = $isCreatedField || $isModifiedField;
                        @endphp
                        <div class="form-group">
                            <label for="{{ $key }}" class="col-sm-2 control-label">{{ $key }}</label>
                            <div class="col-sm-10">
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ old($key, $inputValue) }}" @if($shouldDisable) readonly @endif>
                            </div>
                        </div>
                    @endforeach
                    <div class="form-group">
                        <div class="col-sm-offset-2 col-sm-10">
                            <button type="submit" class="btn btn-default">Submit</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection
@section('js')
@if($table === 'TEXT_CODES')
<script>
    author_first_load();
    function author_first_load(){
        let c_textid = $("input[name='c_textid']").val();
        let data = [{
            id: 0,
            text: 'author'
        }];
        $.get('/api/select/search/textauthor', {q: c_textid}, function (data, textStatus){
            for (let i=data.data.length-1; i>-1; i--){
                item = data.data[i];
                $(".author").append(new Option(item['text'], item['value']));
            }
        });
    }

    $("#button_ajax_load").click(function(){
        let author = $(".author").val();
        if (!author) {
            return;
        }
        let url = "/basicinformation/" + author + "/texts";
        let new_window = window.open('_blank');
        new_window.location = url ;
    });
</script>
@endif
@endsection
