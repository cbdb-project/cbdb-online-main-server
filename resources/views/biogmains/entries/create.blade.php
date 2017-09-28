@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">入仕 Entry</div>
        <div class="panel-body">
            <div class="panel-body">
            <form action="{{ route('basicinformation.entries.store', $id) }}" class="form-horizontal" method="post">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="person_id" class="col-sm-2 control-label">person id</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" value="{{ $id }}" disabled>
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_sequence" class="col-sm-2 control-label">次序(entry_sequence)</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_sequence">
                    </div>
                </div>
                <div class="form-group">
                    <label for="c_entry_code" class="col-sm-2 control-label"></label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="c_entry_code">
                    </div>
                </div>
                <div class="form-group">
                    <label for="" class="col-sm-2 control-label"></label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control" name="">
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
    <script>
        $(".select2").select2();
    </script>
@endsection