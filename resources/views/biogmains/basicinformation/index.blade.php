@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">人名查询</div>
        <div class="panel-body">
            <name-list user="{{ Auth::id() }}"></name-list>
        </div>
    </div>
    <passport-clients></passport-clients>
    <passport-authorized-clients></passport-authorized-clients>
    <passport-personal-access-tokens></passport-personal-access-tokens>

@endsection

@section('js')

@endsection
