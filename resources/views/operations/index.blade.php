@extends('layouts.dashboard')

@section('content')

    <div class="panel panel-default">
        <div class="panel-heading">人名列表</div>
        <div class="panel-body">
            <table class="table table-bordered table-striped">
                <p>* 修改类型 1表示新增， 3表示修改，4表示删除</p>
                <thead>
                <tr>
                    <th>人物</th>
                    <th>修改资源</th>
                    <th>资源tts</th>
                    <th>修改类型</th>
                    <th>修改人</th>
                    <th>修改时间</th>
                </tr>
                </thead>
                <tbody>
                    @foreach($lists as $item)
                        <tr>
                            <td><a href="/basicinformation/{{ $item->biogmain->c_personid }}/edit">{{ $item->biogmain->c_name_chn.' '.$item->biogmain->c_name }}</a></td>
                            <td>{{ $item->resource }}</td>
                            <td>{{ $item->resource_id }}</td>
                            <td>{{ $item->op_type }}</td>
                            <td>{{ $item->user->name }}</td>
                            <td>{{ $item->updated_at }}</td>
                        </tr>
                    @endforeach
                </tbody>

            </table>
            <div class="pull-right">
                {{ $lists->links() }}
            </div>
        </div>
    </div>

@endsection

@section('js')

@endsection
