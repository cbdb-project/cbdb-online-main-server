@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('person.sources') }}</h3>
        </div>
        <div class="card-body">
            <div class="card-body">
                @include('biogmains.sources._form')
            </div>
        </div>
    </div>
@endsection
