@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">地址编码表(ADDRESSES)</div>
        <div class="panel-body">
            <div class="panel-body">
                <form action="{{ route('addresscodes.store') }}" class="form-horizontal" method="post">
                    {{ csrf_field() }}
                    <div class="form-group">
                        <label for="c_addr_id" class="col-sm-2 control-label">c_addr_id</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_addr_id" class="form-control" value="{{ $temp_id or '' }}" {{ $temp_id or '' }}>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="c_name_chn" class="col-sm-2 control-label">c_name_chn（中）</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_name_chn" class="form-control" placeholder="c_name_chn（中）" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="c_name" class="col-sm-2 control-label">c_name（英）</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_name" class="form-control" placeholder="c_name（英）" required>
                        </div>
                    </div>
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
