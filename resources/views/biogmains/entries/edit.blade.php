@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('person.entry') }}</h3>
        </div>
        <div class="card-body">
            @include('biogmains.entries._form')
        </div>
    </div>
@endsection
