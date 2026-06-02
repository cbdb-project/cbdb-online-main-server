@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('person.texts') }}</h3>
        </div>
        <div class="card-body">
            @include('biogmains.texts._form')
        </div>
    </div>
@endsection
