@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">地址從屬表</div>
        <div class="panel-body">
            @if(Auth::check() && Auth::user()->isActive())
            <a href="{{ route('addrbelongsdata.create') }}" class="pull-right btn btn-default">新增</a>
            @endif
            <div class="panel-body">
                <addr-belongs-data-list :user-can-edit="{{ Auth::check() && Auth::user()->isActive() ? 'true' : 'false' }}"></addr-belongs-data-list>
            </div>
        </div>
    </div>

@endsection
@section('js')

@endsection
