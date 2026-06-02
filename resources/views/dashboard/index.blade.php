@extends('layouts.dashboard-v3')

@section('content')
    <!-- Info boxes -->
    <div class="row">
        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-info elevation-1"><i class="fas fa-user"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ __('common.stat_persons') }}</span>
                    <span class="info-box-number">{{ number_format($totalPersons) }}</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-success elevation-1"><i class="fas fa-id-card"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ __('common.stat_altnames') }}</span>
                    <span class="info-box-number">{{ number_format($totalAltnames) }}</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-warning elevation-1"><i class="fas fa-briefcase"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ __('common.stat_offices') }}</span>
                    <span class="info-box-number">{{ number_format($totalOffices) }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-primary elevation-1"><i class="fas fa-book"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ __('common.stat_texts') }}</span>
                    <span class="info-box-number">{{ number_format($totalTexts) }}</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-secondary elevation-1"><i class="fas fa-users"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ __('common.stat_users') }}</span>
                    <span class="info-box-number">{{ number_format($totalUsers) }}</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-danger elevation-1"><i class="fas fa-history"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ __('common.stat_operations') }}</span>
                    <span class="info-box-number">{{ number_format($totalOperations) }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ __('common.op_type_stats_title') }}</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        @foreach($operationTypeStats as $type => $count)
                            <div class="col-6 col-md-3">
                                <div class="small-box bg-light">
                                    <div class="inner">
                                        <h3>{{ number_format($count) }}</h3>
                                        <p>{{ $type }}</p>
                                    </div>
                                    <div class="icon">
                                        <i class="fas fa-edit"></i>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12 col-md-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ __('common.stat_daily_title') }}</h3>
                </div>
                <div class="card-body p-0">
                    @if($dailyStats->count() > 0)
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('common.submitted_by') }}</th>
                                    <th class="text-right">{{ __('common.op_count') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($dailyStats as $stat)
                                    <tr>
                                        <td>{{ $stat->user_name ?? __('common.unknown') }}</td>
                                        <td class="text-right">{{ number_format($stat->count) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="p-3 text-muted">{{ __('common.no_data_yet') }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ __('common.stat_weekly_title') }}</h3>
                </div>
                <div class="card-body p-0">
                    @if($weeklyStats->count() > 0)
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('common.submitted_by') }}</th>
                                    <th class="text-right">{{ __('common.op_count') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($weeklyStats as $stat)
                                    <tr>
                                        <td>{{ $stat->user_name ?? __('common.unknown') }}</td>
                                        <td class="text-right">{{ number_format($stat->count) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="p-3 text-muted">{{ __('common.no_data_yet') }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ __('common.stat_monthly_title') }}</h3>
                </div>
                <div class="card-body p-0">
                    @if($monthlyStats->count() > 0)
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('common.submitted_by') }}</th>
                                    <th class="text-right">{{ __('common.op_count') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($monthlyStats as $stat)
                                    <tr>
                                        <td>{{ $stat->user_name ?? __('common.unknown') }}</td>
                                        <td class="text-right">{{ number_format($stat->count) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="p-3 text-muted">{{ __('common.no_data_yet') }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
