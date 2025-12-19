@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">財產 Possession</h3>
        </div>
        <div class="card-body">
            @include('biogmains.possession._form')
        </div>
    </div>
@endsection
