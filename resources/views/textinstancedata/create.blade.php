@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">著作版本表</div>
        <div class="panel-body">
            <div class="panel-body">
                <form action="{{ route('textinstancedata.store') }}" class="form-horizontal" method="post">
                    {{ csrf_field() }}
                    <div class="form-group">
                        <label for="c_textid" class="col-sm-2 control-label">c_textid</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_textid" class="form-control" value="{{ $temp_id or '' }}" {{ $temp_id or '' }}>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="c_instance_title_chn" class="col-sm-2 control-label">c_instance_title_chn（中）</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_instance_title_chn" class="form-control" placeholder="c_instance_title_chn（中）" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="c_instance_title" class="col-sm-2 control-label">c_instance_title（英）</label>
                        <div class="col-sm-10">
                            <input type="text" name="c_instance_title" class="form-control" placeholder="c_instance_title（英）" required>
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
