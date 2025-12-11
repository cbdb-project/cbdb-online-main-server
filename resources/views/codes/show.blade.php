@extends('layouts.dashboard')

@section('content')

    @php
        $dynastyMap = $dynastyMap ?? [];
        $isReadOnly = $isReadOnly ?? false;
        $showActions = Auth::check() && !$isReadOnly;
        $keyColumns = $keyColumns ?? [];
    @endphp

    <div class="box">
        <!-- /.box-header -->
        <div class="box-body">
            @if(!empty($copyrightNote))
                <div class="alert alert-info">
                    <i class="fa fa-info-circle"></i> {!! $copyrightNote !!}
                </div>
            @endif
            <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                <form method="GET" action="{{ route('codes.show', ['table_name' => $q]) }}" style="flex: 0 0 auto; margin: 0;">
                    <div class="input-group input-group-sm" style="width: 420px;">
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
                @if($showActions)
                    <a class="btn btn-sm btn-default" href="/codes/{{ $q }}/create">新增</a>
                @endif
            </div>
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
                    @forelse (($useCursorPagination ?? false) ? $data['data'] : $data as $item)
                        @php
                            $row = (array) $item;
                            $idParts = [];
                            if (!empty($keyColumns)) {
                                foreach ($keyColumns as $column) {
                                    if (array_key_exists($column, $row)) {
                                        $value = (string) $row[$column];
                                        if ($value !== '') {
                                            $idParts[] = $value;
                                        }
                                    }
                                }
                            }
                            if (empty($idParts)) {
                                foreach ($row as $value) {
                                    $stringValue = (string) $value;
                                    if ($stringValue !== '') {
                                        $idParts[] = $stringValue;
                                    }
                                    if (count($idParts) >= 2) {
                                        break;
                                    }
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
            <div class="pull-right">
                @if($useCursorPagination ?? false)
                    {{-- 游标分页导航 --}}
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div class="btn-group" role="group">
                            @if($data['has_prev_pages'])
                                <a href="{{ route('codes.show', array_merge(['table_name' => $q], $search ? ['search' => $search] : [], ['before' => $data['prev_cursor']])) }}"
                                   class="btn btn-default btn-sm">
                                    <i class="fa fa-chevron-left"></i> 上一頁
                                </a>
                            @else
                                <button class="btn btn-default btn-sm" disabled>
                                    <i class="fa fa-chevron-left"></i> 上一頁
                                </button>
                            @endif

                            <span class="btn btn-default btn-sm" disabled style="cursor: default;">
                                ID: {{ number_format($data['first_id']) }} - {{ number_format($data['last_id']) }}
                            </span>

                            @if($data['has_more_pages'])
                                <a href="{{ route('codes.show', array_merge(['table_name' => $q], $search ? ['search' => $search] : [], ['after' => $data['next_cursor']])) }}"
                                   class="btn btn-default btn-sm">
                                    下一頁 <i class="fa fa-chevron-right"></i>
                                </a>
                            @else
                                <button class="btn btn-default btn-sm" disabled>
                                    下一頁 <i class="fa fa-chevron-right"></i>
                                </button>
                            @endif
                        </div>

                        {{-- 跳转到 ID --}}
                        <form method="GET" style="margin: 0;">
                            @if($search)
                                <input type="hidden" name="search" value="{{ $search }}">
                            @endif
                            <div class="input-group input-group-sm" style="width: 200px;">
                                <input type="number" name="after" placeholder="跳轉到 ID"
                                       class="form-control" min="0">
                                <span class="input-group-btn">
                                    <button type="submit" class="btn btn-primary">跳轉</button>
                                </span>
                            </div>
                        </form>
                    </div>
                @else
                    {{-- 标准 offset 分页 --}}
                    {{ $data->links() }}
                @endif
            </div>
        </div>
        <!-- /.box-body -->
    </div>
    <!-- /.box -->
@endsection
