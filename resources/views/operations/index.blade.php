@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">人名列表</div>
        <div class="panel-body">
            <table class="table table-bordered table-striped">
                <thead>
                <tr>
                    <th>c_personid</th>
                    <th>姓名</th>
                    <th>姓名（英）</th>
                    <th style="width: 120px">操作</th>
                </tr>
                </thead>
                <tbody>
                    @foreach($lists as $item)
                        <tr>
                            <td>{{ $item->c_personid }}</td>
                            <td>{{ $item->c_name }}</td>
                            <td>{{ $item->c_name_chn }}</td>
                            <td>
                                <div class="btn-group">
                                    <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.edit', $item->c_personid) }}">查看信息</a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>

            </table>
        </div>
    </div>

@endsection

@section('js')

@endsection
