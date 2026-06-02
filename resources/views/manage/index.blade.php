@extends('layouts.dashboard-v3')

@section('content')

    @if($inactiveUsers->isNotEmpty())
    <div class="card card-warning card-outline mb-4">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-user-clock mr-1"></i>{{ __('admin.manage_inactive_users_title', ['count' => $inactiveUsers->count()]) }}</h3>
            <div class="card-tools">
                <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm table-hover mb-0">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Institution</th>
                        <th>{{ __('admin.manage_approved_col') }}</th>
                        <th>{{ __('admin.manage_role_col') }}</th>
                        <th style="width: 120px">{{ __('admin.manage_actions_col') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($inactiveUsers as $inactiveUser)
                        <tr>
                            <td>{{ $inactiveUser->id }}</td>
                            <td>{{ $inactiveUser->name }}</td>
                            <td>{{ $inactiveUser->email }}</td>
                            <td>{{ $inactiveUser->institution }}</td>
                            <td><span class="badge badge-warning">{{ __('admin.manage_not_activated') }}</span></td>
                            <td><span class="badge badge-primary">{{ $inactiveUser->getRoleName() }}</span></td>
                            <td>
                                <a class="btn btn-sm btn-primary" href="{{ route('manage.edit', $inactiveUser->id) }}">
                                    <i class="fa fa-edit"></i> {{ __('common.edit') }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('nav.user_management') }}</h3>
            <div class="card-tools">
                <form method="GET" action="{{ route('manage.index', [], false) }}" class="form-inline">
                    <input type="hidden" name="sort_by" value="{{ request('sort_by', 'id') }}">
                    <input type="hidden" name="sort_order" value="{{ request('sort_order', 'asc') }}">
                    <input type="hidden" name="per_page" value="{{ request('per_page', 50) }}">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="{{ __('admin.manage_search_placeholder') }}" value="{{ request('search') }}" style="width: 200px;">
                    <button type="submit" class="btn btn-sm btn-primary ml-2">
                        <i class="fa fa-search"></i> {{ __('common.search') }}
                    </button>
                    @if(request('search'))
                        <a href="{{ route('manage.index', [], false) }}" class="btn btn-sm btn-secondary ml-1">
                            <i class="fa fa-times"></i> {{ __('common.clear') }}
                        </a>
                    @endif
                </form>
            </div>
        </div>
        <div class="card-body">
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <strong>{{ __('admin.manage_role_desc_title') }}</strong><br>
                @include('manage._role-descriptions')
                <button type="button" class="close" data-dismiss="alert" aria-label="{{ __('common.cancel') }}">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm table-hover">
                    <thead>
                    <tr>
                        @php
                            $sortParams = function($column) {
                                $params = request()->all();
                                $params['sort_by'] = $column;
                                $currentSort = request('sort_by');
                                $currentOrder = request('sort_order', 'asc');

                                // 如果點擊的是當前排序欄，則切換排序方向
                                if ($currentSort === $column) {
                                    $params['sort_order'] = $currentOrder === 'asc' ? 'desc' : 'asc';
                                } else {
                                    $params['sort_order'] = 'asc';
                                }

                                return $params;
                            };

                            $sortIcon = function($column) {
                                if (request('sort_by') === $column) {
                                    return request('sort_order', 'asc') === 'asc' ? '▲' : '▼';
                                }
                                return '';
                            };
                        @endphp
                        <th>
                            <a href="{{ route('manage.index', $sortParams('id'), false) }}" class="text-dark">
                                ID {!! $sortIcon('id') !!}
                            </a>
                        </th>
                        <th>
                            <a href="{{ route('manage.index', $sortParams('name'), false) }}" class="text-dark">
                                Name {!! $sortIcon('name') !!}
                            </a>
                        </th>
                        <th>
                            <a href="{{ route('manage.index', $sortParams('email'), false) }}" class="text-dark">
                                Email {!! $sortIcon('email') !!}
                            </a>
                        </th>
                        <th>
                            <a href="{{ route('manage.index', $sortParams('institution'), false) }}" class="text-dark">
                                Institution {!! $sortIcon('institution') !!}
                            </a>
                        </th>
                        <th>
                            <a href="{{ route('manage.index', $sortParams('is_active'), false) }}" class="text-dark">
                                {{ __('admin.manage_approved_col') }} {!! $sortIcon('is_active') !!}
                            </a>
                        </th>
                        <th>
                            <a href="{{ route('manage.index', $sortParams('is_admin'), false) }}" class="text-dark">
                                {{ __('admin.manage_role_col') }} {!! $sortIcon('is_admin') !!}
                            </a>
                        </th>
                        <th style="width: 120px">{{ __('admin.manage_actions_col') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($data as $user)
                        <tr>
                            <td>{{ $user->id }}</td>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->email }}</td>
                            <td>{{ $user->institution }}</td>
                            <td>
                                @if($user->isActive())
                                    <span class="badge badge-success">{{ __('admin.manage_activated') }}</span>
                                @else
                                    <span class="badge badge-warning">{{ __('admin.manage_not_activated') }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-primary">{{ $user->getRoleName() }}</span>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-primary" href="{{ route('manage.edit', $user->id) }}">
                                    <i class="fa fa-edit"></i> {{ __('common.edit') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                @if(request('search'))
                                    {{ __('admin.manage_no_results') }}
                                @else
                                    {{ __('admin.manage_no_users') }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if($data->hasPages())
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted">
                        {{ __('admin.manage_showing', ['from' => $data->firstItem() ?? 0, 'to' => $data->lastItem() ?? 0, 'total' => $data->total()]) }}
                    </div>
                    <div>
                        {{ $data->links() }}
                    </div>
                </div>
            @endif

            <div class="mt-2">
                <form method="GET" action="{{ route('manage.index', [], false) }}" class="form-inline">
                    <input type="hidden" name="search" value="{{ request('search') }}">
                    <input type="hidden" name="sort_by" value="{{ request('sort_by', 'id') }}">
                    <input type="hidden" name="sort_order" value="{{ request('sort_order', 'asc') }}">
                    <label class="mr-2">{{ __('admin.manage_per_page') }}</label>
                    <select name="per_page" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="10" {{ request('per_page') == 10 ? 'selected' : '' }}>10</option>
                        <option value="25" {{ request('per_page') == 25 ? 'selected' : '' }}>25</option>
                        <option value="50" {{ request('per_page', 50) == 50 ? 'selected' : '' }}>50</option>
                        <option value="75" {{ request('per_page') == 75 ? 'selected' : '' }}>75</option>
                        <option value="100" {{ request('per_page') == 100 ? 'selected' : '' }}>100</option>
                    </select>
                </form>
            </div>
        </div>
        <!-- /.card-body -->
    </div>
    <!-- /.card -->

@endsection
