@extends('layouts.dashboard')

@section('content')
    @include('biogmains.banner')
    <div class="panel panel-default">
        <div class="panel-heading">官名清單</div>

        <div class="panel-body">
            <a href="{{ route('basicinformation.offices.create', $basicinformation->c_personid) }}" class="btn btn-default pull-right">新增</a>
            <table class="table table-hover table-condensed">
                <caption>共查询到{{ $basicinformation->offices_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>sequence</th>
                    <th>posting_id</th>
                    <th style="width: 40%;">官名</th>
                    <th>地名</th>
                    <th>始年</th>
                    <th>終年</th>
                    <th style="width: 120px">操作</th>
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->offices as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $value->pivot->c_sequence }}</td>
                        <td>{{ $value->pivot->c_posting_id }}</td>
                        <td>{!! $value->c_office_pinyin. '<br>'. $value->c_office_chn !!}</td>
                        <td>{{ $post2addr[$value->pivot->c_posting_id] or '' }}</td>
                        <td>{{ $value->pivot->c_firstyear }}</td>
                        <td>{{ $value->pivot->c_lastyear }}</td>
                        <td>
                            <div class="btn-group">
                                <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.offices.edit', ['id' => $basicinformation->c_personid, 'office' => $value->pivot->tts_sysno]) }}">edit</a>
                                <a href=""
                                   onclick="
                                           let msg = '您真的确定要删除吗？\n\n请确认！';
                                           if (confirm(msg)===true){
                                               event.preventDefault();
                                               document.getElementById('delete-form-{{ $value->pivot->tts_sysno }}').submit();
                                           }else{
                                               return false;
                                           }"
                                   class="btn btn-sm btn-danger">delete</a>

                            </div>
                            <form id="delete-form-{{ $value->pivot->tts_sysno }}" action="{{ route('basicinformation.offices.destroy', ['id' => $basicinformation->c_personid, 'office' => $value->pivot->tts_sysno]) }}" method="POST" style="display: none;">
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
