@extends('layouts.dashboard')

@section('content')
    @include('biogmains.banner')
    <div class="panel panel-default">
        <div class="panel-heading">親屬清單</div>

        <div class="panel-body">
            @auth
                @if(Auth::user()->is_active == 1)
                    <a href="{{ route('basicinformation.kinship.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-default pull-right">新增</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-condensed">
                <caption>共查询到{{ $basicinformation->kinship_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>親屬關係類別</th>
                    <th>親戚姓名</th>
                    @auth
                        @if(Auth::user()->is_active == 1)
                            <th style="width: 120px">操作</th>
                        @endif
                    @endauth
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->kinship as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $value->c_kinrel_chn. ' '. $value->c_kinrel_alt }}</td>
                        <td><a href="{{ route('basicinformation.edit', $basicinformation->kinship_name[$key]->c_kin_id) }}" target="_blank">{{ $basicinformation->kinship_name[$key]->c_name_chn.' '.$basicinformation->kinship_name[$key]->c_name }}</a></td>
                        @auth
                            @if(Auth::user()->is_active == 1)
                                <td>
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.kinship.edit', ['basicinformation' => $basicinformation->c_personid, 'kinship' => $value->pivot->c_personid.'-'.$value->pivot->c_kin_id.'-'.$value->pivot->c_kin_code]) }}">edit</a>
                                        <a href=""
                                           onclick="
                                                   let msg = '您真的确定要删除吗？\n\n请确认！';
                                                   if (confirm(msg)===true){
                                                       event.preventDefault();
                                                       document.getElementById('delete-form-{{ $value->pivot->c_personid."-".$value->pivot->c_kin_id."-".$value->pivot->c_kin_code }}').submit();
                                                   }else{
                                                       return false;
                                                   }
                                                   "
                                           class="btn btn-sm btn-danger">delete</a>

                                    </div>
                                    <form id="delete-form-{{ $value->pivot->c_personid.'-'.$value->pivot->c_kin_id.'-'.$value->pivot->c_kin_code }}" action="{{ route('basicinformation.kinship.destroy', ['basicinformation' => $basicinformation->c_personid, 'kinship' => $value->pivot->c_personid.'-'.$value->pivot->c_kin_id.'-'.$value->pivot->c_kin_code]) }}" method="POST" style="display: none;">
                                        {{ method_field('DELETE') }}
                                        {{ csrf_field() }}
                                    </form>
                                </td>
                            @endif
                        @endauth
                    </tr>
                @endforeach
                </tbody>
                </table>
            </div>
        </div>
    </div>

@endsection
