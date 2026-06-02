@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-primary">
        <div class="card-header">
            <h3 class="card-title">{{ __('common.welcome_back') }}</h3>
        </div>
        <div class="card-body">
            <p class="mb-3">{{ __('nav.home_nav_hint') }}</p>
            <ul class="mb-0">
                <li>{{ __('nav.home_admin_hint') }}</li>
                <li>{{ __('nav.home_query_hint') }}</li>
                <li>{{ __('nav.home_ops_hint') }}</li>
            </ul>
        </div>
    </div>
@endsection
