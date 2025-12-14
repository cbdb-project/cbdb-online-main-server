@extends('layouts.dashboard-v3')

@section('content')
    <!-- Info boxes -->
    <div class="row">
        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-info elevation-1"><i class="fas fa-user"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">人物記錄</span>
                    <span class="info-box-number">{{ number_format($totalPersons) }}</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-success elevation-1"><i class="fas fa-id-card"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">別名記錄</span>
                    <span class="info-box-number">{{ number_format($totalAltnames) }}</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-warning elevation-1"><i class="fas fa-briefcase"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">任官記錄</span>
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
                    <span class="info-box-text">著作記錄</span>
                    <span class="info-box-number">{{ number_format($totalTexts) }}</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-secondary elevation-1"><i class="fas fa-users"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">系統用戶</span>
                    <span class="info-box-number">{{ number_format($totalUsers) }}</span>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-danger elevation-1"><i class="fas fa-history"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">操作記錄</span>
                    <span class="info-box-number">{{ number_format($totalOperations) }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- 操作类型统计 -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">操作類型統計（過去一個月）</h3>
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

    <!-- 近期修改统计 -->
    <div class="row">
        <!-- 过去一天 -->
        <div class="col-12 col-md-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">過去一天修改統計</h3>
                </div>
                <div class="card-body p-0">
                    @if($dailyStats->count() > 0)
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>提交人</th>
                                    <th class="text-right">操作次數</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($dailyStats as $stat)
                                    <tr>
                                        <td>{{ $stat->user_name ?? '未知' }}</td>
                                        <td class="text-right">{{ number_format($stat->count) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="p-3 text-muted">暫無數據</div>
                    @endif
                </div>
            </div>
        </div>

        <!-- 过去一周 -->
        <div class="col-12 col-md-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">過去一週修改統計</h3>
                </div>
                <div class="card-body p-0">
                    @if($weeklyStats->count() > 0)
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>提交人</th>
                                    <th class="text-right">操作次數</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($weeklyStats as $stat)
                                    <tr>
                                        <td>{{ $stat->user_name ?? '未知' }}</td>
                                        <td class="text-right">{{ number_format($stat->count) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="p-3 text-muted">暫無數據</div>
                    @endif
                </div>
            </div>
        </div>

        <!-- 过去一个月 -->
        <div class="col-12 col-md-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">過去一個月修改統計</h3>
                </div>
                <div class="card-body p-0">
                    @if($monthlyStats->count() > 0)
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>提交人</th>
                                    <th class="text-right">操作次數</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($monthlyStats as $stat)
                                    <tr>
                                        <td>{{ $stat->user_name ?? '未知' }}</td>
                                        <td class="text-right">{{ number_format($stat->count) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="p-3 text-muted">暫無數據</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
