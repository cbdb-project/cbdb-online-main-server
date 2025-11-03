@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">{{ $table }}</div>
        <div class="panel-body">
            <div class="panel-body">
                <form action="/codes/{{ $table }}" class="form-horizontal" method="post">
                    {{ csrf_field() }}
                    @php($i = 1)
                    @foreach($row as $key)
                        <div class="form-group">
                            <label for="{{ $key }}" class="col-sm-2 control-label">{{ $key }}</label>
                            <div class="col-sm-10">
                                <input type="text" name="{{ $key }}" class="form-control" 
                                @if($i == 1)
                                    value="{{ $id }}"
                                @endif
                                >
                            </div>
                        </div>
                    @php($i++)
                    @endforeach
                    <div class="form-group">
                        <label for="__proposal_comment" class="col-sm-2 control-label">提案說明</label>
                        <div class="col-sm-10">
                            <textarea name="__proposal_comment" id="__proposal_comment" class="form-control" rows="3" placeholder="僅在提交提案時填寫（選填）">{{ old('__proposal_comment') }}</textarea>
                            <p class="help-block">如果直接儲存，此欄位會被忽略。</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="col-sm-offset-2 col-sm-10">
                            <button type="submit" class="btn btn-primary">直接儲存</button>
                            <button type="submit" class="btn btn-info" formaction="{{ route('codes.propose.store', ['table_name' => $table], false) }}">提交提案</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection
@section('js')

@endsection
