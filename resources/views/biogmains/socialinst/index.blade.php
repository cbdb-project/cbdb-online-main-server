@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">社交機構清單</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.socialinst.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">新增</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>共查詢到{{ $basicinformation->inst_count }}筆記錄</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>社交機構</th>
                    <th>社交機構角色</th>
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
                @foreach($basicinformation->inst as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $basicinformation->inst_name[$key]->c_inst_name_hz }}</td>
                        <td>{{ $value->c_bi_role_chn }}</td>
                        <td>{{ $value->pivot->c_bi_begin_year }}</td>
                        <td>{{ $value->pivot->c_bi_end_year }}</td>
                        @auth
                            @if(Auth::user()->isActive())
                                <td>
                                    @php
                                    $instPk = [
                                        'c_personid' => $value->pivot->c_personid,
                                        'c_inst_code' => $value->pivot->c_inst_code,
                                        'c_inst_name_code' => $value->pivot->c_inst_name_code,
                                        'c_bi_role_code' => $value->pivot->c_bi_role_code,
                                    ];
                                    $instFormId = 'delete-form-' . $key;
                                    @endphp
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.socialinst.edit.query', ['id' => $basicinformation->c_personid], $instPk) }}">edit</a>
                                        <a href=""
                                           onclick="
                                                   let msg = '您真的確定要刪除嗎？\n\n請確認！';
                                                   if (confirm(msg)===true){
                                                       event.preventDefault();
                                                       document.getElementById('{{ $instFormId }}').submit();
                                                   }else{
                                                       return false;
                                                   }
                                                   "
                                           class="btn btn-sm btn-danger">delete</a>

                                    </div>
                                    <form id="{{ $instFormId }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.socialinst.destroy.query', ['id' => $basicinformation->c_personid], $instPk) }}" method="POST" style="display: none;">
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

    @include('biogmains.history-button')
@endsection
