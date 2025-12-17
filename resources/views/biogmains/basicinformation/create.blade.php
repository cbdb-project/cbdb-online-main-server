@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">Edit Person Information</h3>
        </div>

        <div class="card-body">
            <form action="{{ route('basicinformation.store') }}" method="post">
                {{ csrf_field() }}
                <div class="form-group row">
                    <label for="c_personid" class="col-sm-2 col-form-label">person_id</label>
                    <div class="col-sm-10">
                        <input type="number" name="c_personid" class="form-control" value="{{ $temp_id ?? '' }}" placeholder="person_id" maxlength="11" required>
                    </div>
                </div>
                <div class="form-group row">
                    <label for="c_name_chn" class="col-sm-2 col-form-label">姓名（中）</label>
                    <div class="col-sm-10">
                        <input type="text" name="c_name_chn" class="form-control" placeholder="姓名（中）" required>
                    </div>
                </div>
                <div class="form-group row">
                    <div class="offset-sm-2 col-sm-10">
                        <button class="btn btn-success" type="submit">Submit</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @section('js')
        <script type="text/javascript">

        </script>
    @endsection
@endsection
