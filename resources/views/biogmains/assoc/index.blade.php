@extends('layouts.dashboard')

@section('content')
    @include('biogmains.banner')
    <div class="panel panel-default">
        <div class="panel-heading">社會關係清單</div>

        <div class="panel-body">
            <table class="table table-hover table-condensed">
                <a href="{{ route('basicinformation.assoc.create', $basicinformation->c_personid) }}" class="btn btn-default pull-right">新增</a>
                <caption>共查询到{{ $basicinformation->assoc_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>社會關係類別</th>
                    <th>社會關係人</th>
                    <th style="width: 120px">操作</th>
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->assoc as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $value->c_assoc_desc_chn }}</td>
                        <td>
                            @if($assoc_name[$key])
                                <a href="{{ route('basicinformation.edit', $assoc_name[$key]['c_personid']) }}" target="_blank">{{ $assoc_name[$key]['assoc_name'] }}</a></td>
                            @endif
                        <td>
                            <div class="btn-group">
                                <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.assoc.edit', ['id' => $basicinformation->c_personid, 'id_' => $value->pivot->tts_sysno]) }}">edit</a>
                                <a href=""
                                   onclick="
                                           let msg = '您真的确定要删除吗？\n\n请确认！';
                                           if (confirm(msg)===true){
                                               event.preventDefault();
                                               document.getElementById('delete-form-{{ $value->pivot->tts_sysno }}').submit();
                                           }else{
                                                return false;
                                           }
                                           "
                                   class="btn btn-sm btn-danger">delete</a>

                            </div>
                            <form id="delete-form-{{ $value->pivot->tts_sysno }}" action="{{ route('basicinformation.assoc.destroy', ['id' => $basicinformation->c_personid, 'id_' => $value->pivot->tts_sysno]) }}" method="POST" style="display: none;">
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
