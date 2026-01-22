@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">事件清單</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.events.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">新增</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>共查询到{{ $basicinformation->events_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>SEQUENCE</th>
                    <th>事件名稱</th>
                    @auth
                        @if(Auth::user()->isActive())
                            <th style="width: 120px">操作</th>
                        @endif
                    @endauth
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->events as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $value->pivot->c_sequence }}</td>
                        <td>{{ $value->c_event_name_chn }}</td>
                        <td>
                            @php
                            $eventPk = [
                                'c_personid' => $basicinformation->c_personid,
                                'c_sequence' => $value->pivot->c_sequence,
                            ];
                            $eventFormId = 'delete-form-' . $value->pivot->c_sequence;
                            @endphp
                            <div class="btn-group">
                                <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.events.edit.query', ['id' => $basicinformation->c_personid], $eventPk) }}">edit</a>
                                <a href=""
                                   onclick="
                                           let msg = '您真的确定要删除吗？\n\n请确认！';
                                           if (confirm(msg)===true){
                                               event.preventDefault();
                                               document.getElementById('{{ $eventFormId }}').submit();
                                           }else{
                                               return false;
                                           }
                                           "
                                   class="btn btn-sm btn-danger">delete</a>

                            </div>
                            <form id="{{ $eventFormId }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.events.destroy.query', ['id' => $basicinformation->c_personid], $eventPk) }}" method="POST" style="display: none;">
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
    </div>
@endsection
