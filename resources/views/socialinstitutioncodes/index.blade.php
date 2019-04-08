@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">社会机构编码表</div>
        <div class="panel-body">
            <a href="{{ route('socialinstitutioncodes.create') }}" class="pull-right btn btn-default">新增</a>
            <div class="panel-body">
                <social-institution-code-list></social-institution-code-list>
            </div>
        </div>
    </div>

@endsection
@section('js')

@endsection
