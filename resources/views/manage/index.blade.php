@extends('layouts.dashboard-v3')

@section('content')

    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">用戶管理</h3>
            <div class="card-tools">
                <form method="GET" action="{{ route('manage.index', [], false) }}" class="form-inline">
                    <input type="hidden" name="sort_by" value="{{ request('sort_by', 'id') }}">
                    <input type="hidden" name="sort_order" value="{{ request('sort_order', 'asc') }}">
                    <input type="hidden" name="per_page" value="{{ request('per_page', 50) }}">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="搜尋姓名/郵箱/機構" value="{{ request('search') }}" style="width: 200px;">
                    <button type="submit" class="btn btn-sm btn-primary ml-2">
                        <i class="fa fa-search"></i> 搜尋
                    </button>
                    @if(request('search'))
                        <a href="{{ route('manage.index', [], false) }}" class="btn btn-sm btn-secondary ml-1">
                            <i class="fa fa-times"></i> 清除
                        </a>
                    @endif
                </form>
            </div>
        </div>
        <div class="card-body">
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <strong>角色說明：</strong><br>
                @include('manage._role-descriptions')
                <button type="button" class="close" data-dismiss="alert" aria-label="關閉">
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

                                // 如果点击的是当前排序列，则切换排序方向
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
                                是否通過審核 {!! $sortIcon('is_active') !!}
                            </a>
                        </th>
                        <th>
                            <a href="{{ route('manage.index', $sortParams('is_admin'), false) }}" class="text-dark">
                                用戶角色 {!! $sortIcon('is_admin') !!}
                            </a>
                        </th>
                        <th style="width: 120px">操作</th>
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
                                    <span class="badge badge-success">已激活</span>
                                @else
                                    <span class="badge badge-warning">未激活</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-primary">{{ $user->getRoleName() }}</span>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-primary" href="{{ route('manage.edit', $user->id) }}">
                                    <i class="fa fa-edit"></i> 編輯
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                @if(request('search'))
                                    沒有找到符合條件的用戶
                                @else
                                    目前沒有用戶
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
                        顯示第 {{ $data->firstItem() ?? 0 }} 至 {{ $data->lastItem() ?? 0 }} 筆，
                        共 {{ $data->total() }} 筆
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
                    <label class="mr-2">每頁顯示：</label>
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
