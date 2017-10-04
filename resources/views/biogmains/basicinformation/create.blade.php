@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">Edit Person Information</div>

        <div class="panel-body">
            <form action="{{ route('basicinformation.store') }}" method="post">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="c_name_chn">姓名（中）</label>
                    <input type="text" name="c_name_chn" class="form-control" placeholder="姓名（中）">
                </div>
                <div class="form-group">
                    <label for="c_name">姓名（英）</label>
                    <input type="text" name="c_name" class="form-control" placeholder="姓名（英）">
                </div>
                <div class="form-group"><button class="btn btn-success pull-right" type="submit">Submit</button></div>
            </form>
        </div>
    </div>

    @section('js')
        <script type="text/javascript">

        </script>
    @endsection
@endsection
