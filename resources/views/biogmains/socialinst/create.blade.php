@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('person.tab_social_institutions') }}</h3>
        </div>
        <div class="card-body">
            @include('biogmains.socialinst._form')
        </div>
    </div>
@endsection
