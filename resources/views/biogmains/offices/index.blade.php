@extends('layouts.dashboard')

@section('content')
    @include('biogmains.banner')
    <div class="panel panel-default">
        <div class="panel-heading">官名清單</div>

        <div class="panel-body">
            @auth
                @if(Auth::user()->is_active == 1)
                    <a href="{{ route('basicinformation.offices.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-default pull-right">新增</a>
                @endif
            @endauth
            <div class="table-responsive">
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
                    @auth
                        @if(Auth::user()->is_active == 1)
                            <th style="width: 120px">操作</th>
                        @endif
                    @endauth
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->offices as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $value->pivot->c_sequence }}</td>
                        <td>{{ $value->pivot->c_posting_id }}</td>
                        <td>{!! $value->c_office_pinyin. '<br>'. $value->c_office_chn !!}</td>
                        <td>{{ $post2addr[$value->pivot->c_posting_id] ?? '' }}</td>
                        <td>{{ $value->pivot->c_firstyear }}</td>
                        <td>{{ $value->pivot->c_lastyear }}</td>
                        @auth
                            @if(Auth::user()->is_active == 1)
                                <td>
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.offices.edit', ['basicinformation' => $basicinformation->c_personid, 'office' => $value->pivot->c_office_id.'-'.$value->pivot->c_posting_id]) }}">edit</a>
                                        <a href=""
                                           onclick="
                                                   let msg = '您真的确定要删除吗？\n\n请确认！';
                                                   if (confirm(msg)===true){
                                                       event.preventDefault();
                                                       document.getElementById('delete-form-{{ $value->pivot->c_office_id."-".$value->pivot->c_posting_id }}').submit();
                                                   }else{
                                                       return false;
                                                   }"
                                           class="btn btn-sm btn-danger">delete</a>

                                    </div>
                                    <form id="delete-form-{{ $value->pivot->c_office_id.'-'.$value->pivot->c_posting_id }}" action="{{ route('basicinformation.offices.destroy', ['basicinformation' => $basicinformation->c_personid, 'office' => $value->pivot->c_office_id.'-'.$value->pivot->c_posting_id]) }}" method="POST" style="display: none;">
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
