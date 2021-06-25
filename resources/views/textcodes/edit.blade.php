@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">著作編碼表</div>
        <div class="panel-body">
            <div class="panel-body">
                <form action="/textcodes/{{ $id }}" class="form-horizontal" method="post">
                    {{ method_field('PATCH') }}
                    {{ csrf_field() }}
                    @foreach($row->toArray() as $key => $value)
                        <div class="form-group"
                        @if($key == 'c_created_by' || $key == 'c_created_date' || $key == 'c_modified_by' || $key == 'c_modified_date')
                            style="display:none"
                        @endif
                        >
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
