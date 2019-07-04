@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">地址編碼表(ADDRESSES)</div>
        <div class="panel-body">
            <a href="{{ route('addresscodes.create') }}" class="pull-right btn btn-default">新增</a>
            <div class="panel-body">
                <address-code-list></address-code-list>
            </div>
        </div>
    </div>

@endsection
@section('js')

@endsection
