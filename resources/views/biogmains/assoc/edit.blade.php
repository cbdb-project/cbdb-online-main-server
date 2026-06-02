@extends('layouts.dashboard-v3')

@section('content')
@include('biogmains.defense')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('person.associations') }}</h3>
        </div>
        <div class="card-body">
            <div class="card-body">
                @include('biogmains.assoc._form')
            </div>
        </div>
    </div>
@endsection
