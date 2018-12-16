@extends('layouts.dashboard')

@section('content')
    @include('biogmains.banner')
    <div class="panel panel-default">
        <div class="panel-heading">别名</div>

        <div class="panel-body">
            <table class="table table-hover table-condensed">
                <a href="{{ route('basicinformation.altnames.create', $basicinformation->c_personid) }}" class="btn btn-default pull-right">新增</a>
                <caption>共查询到{{ $basicinformation->altnames_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>別名拼音</th>
                    <th>別名漢字</th>
                    <th style="width: 120px">操作</th>
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->altnames as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $value->pivot->c_alt_name }}</td>
                        <td>{{ $value->pivot->c_alt_name_chn }}</td>
                        <td>
                            <div class="btn-group">
                                <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.altnames.edit', ['id' => $basicinformation->c_personid, 'alt' => $value->pivot->c_personid."-".$value->pivot->c_alt_name_chn."-".$value->pivot->c_alt_name_type_code]) }}">edit</a>
                                <a href=""
                                   onclick="
                                           let msg = '您真的确定要删除吗？\n\n请确认！';
                                           if (confirm(msg)===true){
                                               event.preventDefault();
                                               document.getElementById('delete-form-{{ $value->pivot->c_personid."-".$value->pivot->c_alt_name_chn."-".$value->pivot->c_alt_name_type_code }}').submit();
                                           }else{
                                               return false;
                                           }
                                "
                                   class="btn btn-sm btn-danger">delete</a>

                            </div>
                            <form id="delete-form-{{ $value->pivot->c_personid."-".$value->pivot->c_alt_name_chn."-".$value->pivot->c_alt_name_type_code }}" action="{{ route('basicinformation.altnames.destroy', ['id' => $basicinformation->c_personid, 'alt' => $value->pivot->c_personid."-".$value->pivot->c_alt_name_chn."-".$value->pivot->c_alt_name_type_code]) }}" method="POST" style="display: none;">
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
