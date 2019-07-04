@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">地址編碼表(ADDR_CODES)</div>
        <div class="panel-body">
            <a href="{{ route('addrcodes.create') }}" class="pull-right btn btn-default">新增</a>
            <div class="panel-body">
                <addr-code-list></addr-code-list>
            </div>
        </div>
    </div>

@endsection
@section('js')

@endsection
