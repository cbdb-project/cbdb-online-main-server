@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">中国行政地理单位编码表</div>
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
