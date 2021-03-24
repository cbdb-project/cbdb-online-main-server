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

@endsection
