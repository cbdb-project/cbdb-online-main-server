@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">社會機構編碼表</div>
        <div class="panel-body">
            <div class="panel-body">
                <form action="{{ route('socialinstitutioncodes.store') }}" class="form-horizontal" method="post">
                    {{ csrf_field() }}
                    <div class="form-group">
                        <label for="c_inst_name_code" class="col-sm-2 control-label">c_inst_name_code</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_inst_name_code" class="form-control" value="{{ $temp_id or '' }}" {{ $temp_id or '' }}>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="c_inst_code" class="col-sm-2 control-label">c_inst_code</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_inst_code" class="form-control" placeholder="c_inst_code" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="c_inst_type_code" class="col-sm-2 control-label">c_inst_type_code</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_inst_type_code" class="form-control" placeholder="c_inst_type_code" required>
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
