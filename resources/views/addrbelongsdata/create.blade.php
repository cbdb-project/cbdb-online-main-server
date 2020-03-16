@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">地址從屬表</div>
        <div class="panel-body">
            <div class="panel-body">
                <form action="{{ route('addrbelongsdata.store') }}" class="form-horizontal" method="post">
                    {{ csrf_field() }}
                    <div class="form-group">
                        <label for="c_addr_id" class="col-sm-2 control-label">c_addr_id</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_addr_id" class="form-control" value="{{ $temp_id or '' }}" {{ $temp_id or '' }}>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="c_firstyear" class="col-sm-2 control-label">c_firstyear</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_firstyear" class="form-control" placeholder="c_firstyear" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="c_lastyear" class="col-sm-2 control-label">c_lastyear</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_lastyear" class="form-control" placeholder="c_lastyear" required>
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
