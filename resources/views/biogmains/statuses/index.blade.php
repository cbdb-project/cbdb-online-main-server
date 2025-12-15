@extends('layouts.dashboard-v3')

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">社會區分清單</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.statuses.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">新增</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>共查询到{{ $basicinformation->statuses_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>SEQUENCE</th>
                    <th>社會區分(英)</th>
                    <th>社會區分(中)</th>
                    <th>始年</th>
                    <th>終年</th>
                    @auth
                        @if(Auth::user()->isActive())
                            <th style="width: 120px">操作</th>
                        @endif
                    @endauth
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->statuses as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $value->pivot->c_sequence }}</td>
                        <td>{{ $value->c_status_desc }}</td>
                        <td>{{ $value->c_status_desc_chn }}</td>
                        <td>{{ $value->pivot->c_firstyear }}</td>
                        <td>{{ $value->pivot->c_lastyear }}</td>
                        @auth
                            @if(Auth::user()->isActive())
                                <td>
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.statuses.edit', ['basicinformation' => $basicinformation->c_personid, 'status' => $value->pivot->c_personid.'-'.$value->pivot->c_sequence.'-'.$value->pivot->c_status_code]) }}">edit</a>
                                        <a href=""
                                           onclick="
                                                   let msg = '您真的确定要删除吗？\n\n请确认！';
                                                   if (confirm(msg)===true){
                                                       event.preventDefault();
                                                       document.getElementById('delete-form-{{ $value->pivot->c_personid."-".$value->pivot->c_sequence."-".$value->pivot->c_status_code }}').submit();
                                                   }else{
                                                       return false;
                                                   }
                                                   "
                                           class="btn btn-sm btn-danger">delete</a>

                                    </div>
                                    <form id="delete-form-{{ $value->pivot->c_personid.'-'.$value->pivot->c_sequence.'-'.$value->pivot->c_status_code }}" action="{{ route('basicinformation.statuses.destroy', ['basicinformation' => $basicinformation->c_personid, 'status' => $value->pivot->c_personid.'-'.$value->pivot->c_sequence.'-'.$value->pivot->c_status_code]) }}" method="POST" style="display: none;">
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
