@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">著作編碼表</div>
        <div class="panel-body">
            <a href="{{ route('textcodes.create') }}" class="pull-right btn btn-default">新增</a>
            <div class="panel-body">
                <text-code-list></text-code-list>
            </div>
        </div>
    </div>

@endsection
@section('js')

@endsection
