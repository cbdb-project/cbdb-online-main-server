@extends('layouts.dashboard-v3')

@push('styles')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
@endpush

@section('content')

    <div class="card card-default">
        <div class="card-body">
            <div class="table-responsive">
                <table id="example1" class="table table-bordered table-striped table-sm">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Institution</th>
                        <th>是否通过审核</th>
                        <th>用户角色</th>
                        <th style="width: 120px">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($data as $user)
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
                                <div class="btn-group">
                                    <a type="button" class="btn btn-sm btn-primary" href="{{ route('manage.edit', $user->id) }}">
                                        <i class="fa fa-edit"></i> 编辑
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <!-- /.card-body -->
    </div>
    <!-- /.card -->
    <passport-clients></passport-clients>
    <passport-authorized-clients></passport-authorized-clients>
    <passport-personal-access-tokens></passport-personal-access-tokens>
@endsection
@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script>
        $(function() {
            $('#example1').DataTable({
                lengthMenu: [10, 25, 50, 75, 100],
                pageLength: 50,
                order: [[0, 'asc']],
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/zh-HANT.json'
                }
            });
        });
    </script>
@endsection
