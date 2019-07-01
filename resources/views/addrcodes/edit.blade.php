@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">地址編碼表(ADDR_CODES)</div>
        <div class="panel-body">
            <div class="panel-body">
                <form action="/addrcodes/{{ $id }}" class="form-horizontal" method="post">
                    {{ method_field('PATCH') }}
                    {{ csrf_field() }}
                    @foreach($row->toArray() as $key => $value)
                        <div class="form-group">
                            <label for="{{ $key }}" class="col-sm-2 control-label">{{ $key }}</label>
                            <div class="col-sm-10">
                                <input type="text" name="{{ $key }}" class="form-control"
                                       value="{{ $value }}">
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

@endsection
