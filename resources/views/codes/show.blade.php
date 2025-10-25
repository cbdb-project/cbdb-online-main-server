@extends('layouts.dashboard')

@section('content')

    @php
        $dynastyMap = $dynastyMap ?? [];
        $isReadOnly = $isReadOnly ?? false;
        $showActions = Auth::check() && !$isReadOnly;
    @endphp

    <div class="box">
        <div class="box-header">
            <h3 class="box-title">代碼表</h3>

            <div class="box-tools pull-right">
                @if($showActions)
                    <a class="btn btn-default" href="/codes/{{ $q }}/create">新增</a>
                @endif
            </div>
        </div>
        <!-- /.box-header -->
        <div class="box-body">
            <form method="GET" action="{{ route('codes.show', ['table_name' => $q]) }}" style="margin-bottom: 15px;">
                <div class="input-group input-group-sm" style="width: 100%; max-width: 420px;">
                    <input type="text"
                           name="search"
                           class="form-control"
                           placeholder="搜尋"
                           value="{{ $search ?? '' }}">
                    <span class="input-group-btn">
                        <button class="btn btn-default" type="submit">搜尋</button>
                        @if(!empty($search))
                            <a class="btn btn-default" href="{{ route('codes.show', ['table_name' => $q]) }}">清除</a>
                        @endif
                    </span>
                </div>
            </form>
            <div class="table-responsive" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                <table class="table table-bordered table-striped table-condensed">
                    <thead>
                    <tr>
                        @foreach ($thead as $item)
                            <th>{{ $item }}</th>
                        @endforeach
                        @if($showActions)
                            <th style="width: 120px">操作</th>
                        @endif
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($data as $item)
                        @php
                            $row = (array) $item;
                            $idParts = [];
                            foreach ($row as $value) {
                                $idParts[] = $value;
                                if (count($idParts) >= 2) {
                                    break;
                                }
                            }
                            $id_ = implode('_._', $idParts);
                        @endphp
                        <tr>
                            @foreach ($thead as $column)
                                @php
                                    $value = $row[$column] ?? '';
                                    if ($column === 'c_dy' && $value !== '') {
                                        $key = is_scalar($value) ? (string) $value : null;
                                        if ($key !== null && isset($dynastyMap[$key])) {
                                            $value = $value . ' - ' . $dynastyMap[$key];
                                        }
                                    }
                                @endphp
                                <td>{{ $value }}</td>
                            @endforeach
                            @if($showActions)
                                <td>
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="/codes/{{ $q }}/{{ $id_ }}/edit">edit</a>
                                        <a href="{{ route('codes.destroy', ['table_name'=>$q, 'id'=>$id_]) }}"
                                           onclick="alert('确认删除');
                                                    event.preventDefault();
                                                   document.getElementById('delete-form-{{ $id_ }}').submit();"
                                           class="btn btn-sm btn-danger">delete</a>
                                    </div>
                                    <form id="delete-form-{{ $id_ }}" action="{{ route('codes.destroy', ['table_name'=>$q, 'id'=>$id_]) }}" method="POST" style="display: none;">
                                        {{ method_field('DELETE') }}
                                        {{ csrf_field() }}
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($thead) + ($showActions ? 1 : 0) }}" class="text-center text-muted">沒有資料</td>
                        </tr>
                    @endforelse
                    </tbody>
                    <tfoot>
                    <tr>
                        @foreach ($thead as $item)
                            <th>{{ $item }}</th>
                        @endforeach
                        @if($showActions)
                            <th style="width: 120px">操作</th>
                        @endif
                    </tr>
                    </tfoot>
                </table>
            </div>
            <div class="pull-right">{{ $data->links() }}</div>
        </div>
        <!-- /.box-body -->
    </div>
    <!-- /.box -->
@endsection
