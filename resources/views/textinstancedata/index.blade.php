@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">著作版本表</div>
        <div class="panel-body">
            <a href="{{ route('textinstancedata.create') }}" class="pull-right btn btn-default">新增</a>
            <div class="panel-body">
                <text-instance-data-list></text-instance-data-list>
            </div>
        </div>
    </div>

@endsection
@section('js')

@endsection
