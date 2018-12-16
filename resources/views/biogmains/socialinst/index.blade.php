@extends('layouts.dashboard')

@section('content')
    @include('biogmains.banner')
    <div class="panel panel-default">
        <div class="panel-heading">社交機構清單</div>

        <div class="panel-body">
            <table class="table table-hover table-condensed">
                <a href="{{ route('basicinformation.socialinst.create', $basicinformation->c_personid) }}" class="btn btn-default pull-right">新增</a>
                <caption>共查询到{{ $basicinformation->inst_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>社交機構</th>
                    <th>社交機構角色</th>
                    <th>始年</th>
                    <th>終年</th>
                    <th style="width: 120px">操作</th>
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->inst as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $basicinformation->inst_name[$key]->c_inst_name_hz }}</td>
                        <td>{{ $value->c_bi_role_chn }}</td>
                        <td>{{ $value->pivot->c_bi_begin_year }}</td>
                        <td>{{ $value->pivot->c_bi_end_year }}</td>
                        <td>
                            <div class="btn-group">
                                @php($id_ = $value->pivot->c_personid."-".$value->pivot->c_bi_role_code)
                                <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.socialinst.edit', ['id' => $basicinformation->c_personid, 'id_' => $id_]) }}">edit</a>
                                <a href=""
                                   onclick="
                                           let msg = '您真的确定要删除吗？\n\n请确认！';
                                           if (confirm(msg)===true){
                                               event.preventDefault();
                                               document.getElementById('delete-form-{{ $id_ }}').submit();
                                           }else{
                                               return false;
                                           }
                                           "
                                   class="btn btn-sm btn-danger">delete</a>

                            </div>
                            <form id="delete-form-{{ $id_ }}" action="{{ route('basicinformation.socialinst.destroy', ['id' => $basicinformation->c_personid, 'id_' => $id_]) }}" method="POST" style="display: none;">
                                {{ method_field('DELETE') }}
                                {{ csrf_field() }}
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

@endsection
