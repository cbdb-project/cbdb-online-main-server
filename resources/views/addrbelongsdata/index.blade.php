@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">行政單位等級编码表</div>
        <div class="panel-body">
            <div class="panel-body">
                <addr-belongs-data-list></addr-belongs-data-list>
            </div>
        </div>
    </div>

@endsection
@section('js')

@endsection
