@extends('layouts.dashboard')

@section('content')
    @include('biogmains.banner')
    <div class="panel panel-default">
        <div class="panel-heading">出处</div>

        <div class="panel-body">
            <table class="table table-hover table-condensed">
                <a href="{{ route('basicinformation.sources.create', $basicinformation->c_personid) }}" class="btn btn-default pull-right">新增</a>
                <caption>共查询到{{ $basicinformation->sources_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>出處</th>
                    <th>頁碼</th>
                    <th style="width: 120px">操作</th>
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->sources as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $value->c_title_chn }}</td>
                        <td>{{ $value->pivot->c_pages }}</td>
                        <td>
                            <div class="btn-group">
                                <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.sources.edit', ['id' => $basicinformation->c_personid, 'id_' => $value->c_textid]) }}">edit</a>
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
                            <form id="delete-form-{{ $value->pivot->tts_sysno }}" action="{{ route('basicinformation.sources.destroy', ['id' => $basicinformation->c_personid, 'id_' => $value->c_textid]) }}" method="POST" style="display: none;">
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
