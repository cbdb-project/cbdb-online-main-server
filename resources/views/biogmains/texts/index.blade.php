@extends('layouts.dashboard')

@section('content')
    @include('biogmains.banner')
    <div class="panel panel-default">
        <div class="panel-heading">著述清單</div>

        <div class="panel-body">
            <table class="table table-hover table-condensed">
                <a href="{{ route('basicinformation.texts.create', $basicinformation->c_personid) }}" class="btn btn-default pull-right">新增</a>
                <caption>共查询到{{ $basicinformation->texts_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>書名</th>
                    <th>著述角色</th>
                    <th style="width: 120px">操作</th>
                </tr>
                </thead>
                <tbody>
                @for ($i = 0; $i < $basicinformation->texts_count; $i++)
                    <tr>
                        <td>{{ $i+1 }}</td>
                        <td>{{ $basicinformation->texts[$i]->c_title_chn }}</td>
                        <td>{{ $basicinformation->texts_role[$i]->c_role_desc_chn }}</td>
                        <td>
                            <div class="btn-group">
                                <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.texts.edit', ['id' => $basicinformation->c_personid, 'text' => $basicinformation->c_personid."-".$basicinformation->texts[$i]->pivot->c_textid."-".$basicinformation->texts[$i]->pivot->c_role_id]) }}">edit</a>
                                <a href=""
                                   onclick="
                                           let msg = '您真的确定要删除吗？\n\n请确认！';
                                           if (confirm(msg)===true){
                                               event.preventDefault();
                                               document.getElementById('delete-form-{{ $basicinformation->c_personid."-".$basicinformation->texts[$i]->pivot->c_textid."-".$basicinformation->texts[$i]->pivot->c_role_id }}').submit();
                                           }else{
                                                return false;
                                           }
                                           "
                                   class="btn btn-sm btn-danger">delete</a>

                            </div>
                            <form id="delete-form-{{ $basicinformation->c_personid."-".$basicinformation->texts[$i]->pivot->c_textid."-".$basicinformation->texts[$i]->pivot->c_role_id }}" action="{{ route('basicinformation.texts.destroy', ['id' => $basicinformation->c_personid, 'text' => $basicinformation->c_personid."-".$basicinformation->texts[$i]->pivot->c_textid."-".$basicinformation->texts[$i]->pivot->c_role_id]) }}" method="POST" style="display: none;">
                                {{ method_field('DELETE') }}
                                {{ csrf_field() }}
                            </form>
                        </td>
                    </tr>
                @endfor
                </tbody>
            </table>
        </div>
    </div>
@endsection
