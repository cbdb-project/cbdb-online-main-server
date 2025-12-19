@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">社會區分 Status</h3>
        </div>
        <div class="card-body">
            <div class="card-body">
                @include('biogmains.statuses._form')
            </div>
        </div>
    </div>

@endsection
