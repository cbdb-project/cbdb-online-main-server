@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">

            <div class="panel panel-default">
                <div class="panel-heading">人名查询</div>
                <div class="panel-body">
                    {{--<div class="form-group">--}}
                        {{--<button class="btn btn-block btn-default">最近新增人名</button>--}}
                    {{--</div>--}}

                    <name-list user="{{ Auth::id() }}"></name-list>
                </div>
            </div>
            <passport-clients></passport-clients>
            <passport-authorized-clients></passport-authorized-clients>
            <passport-personal-access-tokens></passport-personal-access-tokens>
        </div>
    </div>
</div>
@section('js')

@endsection
@endsection
