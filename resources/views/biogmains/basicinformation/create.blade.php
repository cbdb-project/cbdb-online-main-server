@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('biogmains.basic_info_title') }}</h3>
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
                    <label for="c_name_chn" class="col-sm-2 col-form-label">{{ __('biogmains.person_name_chn_label') }}</label>
                    <div class="col-sm-10">
                        <input type="text" name="c_name_chn" class="form-control" placeholder="{{ __('biogmains.person_name_chn_label') }}" required>
                    </div>
                </div>
                <div class="form-group row">
                    <div class="offset-sm-2 col-sm-10">
                        <button class="btn btn-success" type="submit">{{ __('common.submit') }}</button>
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
