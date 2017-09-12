@extends('layouts.dashboard')

@section('content')
    <!-- SELECT2 EXAMPLE -->
    <div class="box">
        <div class="box-header">
            <h3 class="box-title">编码表</h3>
        </div>
        <!-- /.box-header -->
        <div class="box-body">
            <table class="table table-bordered">
                <tr>
                    @foreach ($thead as $item)
                        <th>{{ $item }}</th>
                    @endforeach
                    <th style="width: 120px">操作</th>
                </tr>
                @foreach ($data as $item)
                    <tr>
                        @php($count = 0)
                        @php($id_ = '')
                        @foreach($item as $key=>$value)
                            @if($count > count($thead)-1)
                                @break
                            @endif
                            @if(str_contains($key, 'name') or str_contains($key, 'desc') or str_contains($key, 'code') or str_contains($key, 'id') or str_contains($key, 'sequence'))
                                @if($count == 0)
                                    @php($id_ = $value)
                                    @endif
                                <td>{{ $value }}</td>
                                @php($count++)
                            @endif
                        @endforeach
                        <td>
                            <div class="btn-group">
                                <a type="button" class="btn btn-sm btn-info" href="/codes/{{ $id_ }}/edit?q={{ $q }}">edit</a>
                                <a type="button" class="btn btn-sm btn-danger">delete</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
        <!-- /.box-body -->
        <div class="box-footer clearfix">
            {{ $data->appends(['q' => $q])->links() }}
        </div>
    </div>
    <!-- /.box -->
@endsection
@section('js')

@endsection