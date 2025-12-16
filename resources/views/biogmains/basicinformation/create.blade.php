@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">Edit Person Information</h3>
        </div>

        <div class="card-body">
            <form action="{{ route('basicinformation.store') }}" method="post">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="c_personid">person_id</label>
                    <input type="number" name="c_personid" class="form-control" value="{{ $temp_id ?? '' }}" placeholder="person_id" maxlength="11" required>
                </div>
                <div class="form-group">
                    <label for="c_name_chn">姓名（中）</label>
                    <input type="text" name="c_name_chn" class="form-control" placeholder="姓名（中）" required>
                </div>
                <div class="form-group"><button class="btn btn-success float-right" type="submit">Submit</button></div>
            </form>
        </div>
    </div>

    @section('js')
        <script type="text/javascript">

        </script>
    @endsection
@endsection
