@extends('layouts.dashboard')

@section('content')

    <div class="box">
        <div class="box-header">
            <h3 class="box-title">代碼表</h3>

            <div class="box-tools pull-right">
                <a class="btn btn-default" href="/codes/{{ $q }}/create">新增</a>
            </div>
        </div>
        <!-- /.box-header -->
        <div class="box-body">
            <form method="GET" action="{{ route('codes.show', ['table_name' => $q]) }}" class="form-inline" style="margin-bottom: 15px;">
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
            <table class="table table-bordered table-striped table-condensed">
                <thead>
                <tr>
                    @foreach ($thead as $item)
                        <th>{{ $item }}</th>
                    @endforeach
                    <th style="width: 120px">操作</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($data as $item)
                    <tr>
                        @php($count = 0)
                        @php($sum = 0)
                        @php($id_ = '')
                        @foreach((array)$item as $key => $value)
                            @if($count > count($thead)-1)
                                @break
                            @endif
                            @if(str_contains($key, 'name') or str_contains($key, 'desc') or str_contains($key, 'code') or str_contains($key, 'id') or str_contains($key, 'sequence') or str_contains($key, 'chn') or str_contains($key, 'dy'))
                                <td>{{ $value }}</td>
                                @php($count++)
                            @endif
                            @if($sum <= 1)
                                @if($sum != 0 && $sum <= 1)
                                    @php($id_ .= '_._')
                                @endif
                                @php($id_ .= $value)
                            @endif
                            @php($sum++)
                        @endforeach
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
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($thead) + 1 }}" class="text-center text-muted">沒有資料</td>
                    </tr>
                @endforelse
                </tbody>
                <tfoot>
                <tr>
                    @foreach ($thead as $item)
                        <th>{{ $item }}</th>
                    @endforeach
                    <th style="width: 120px">操作</th>
                </tr>
                </tfoot>
            </table>
            <div class="pull-right">{{ $data->links() }}</div>
        </div>
        <!-- /.box-body -->
    </div>
    <!-- /.box -->
@endsection
