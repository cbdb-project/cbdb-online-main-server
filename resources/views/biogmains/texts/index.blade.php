@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">著述清單</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.texts.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">新增</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>共查詢到{{ $basicinformation->texts_count }}筆記錄</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>書名</th>
                    <th>著述角色</th>
                    @auth
                        @if(Auth::user()->isActive())
                            <th style="width: 120px">操作</th>
                        @endif
                    @endauth
                </tr>
                </thead>
                <tbody>
                @for ($i = 0; $i < $basicinformation->texts_count; $i++)
                    <tr>
                        <td>{{ $i+1 }}</td>
                        <td>{{ $basicinformation->texts[$i]->c_title_chn }}</td>
                        <td>{{ $basicinformation->texts_role[$i]->c_role_desc_chn }}</td>
                        @auth
                            @if(Auth::user()->isActive())
                                <td>
                                    @php
                                        $textPk = [
                                            'c_personid' => $basicinformation->c_personid,
                                            'c_textid' => $basicinformation->texts[$i]->pivot->c_textid,
                                            'c_role_id' => $basicinformation->texts[$i]->pivot->c_role_id,
                                        ];
                                    @endphp
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.texts.edit.query', ['id' => $basicinformation->c_personid], $textPk) }}">edit</a>
                                        <a href=""
                                           onclick="
                                                   let msg = '您真的確定要刪除嗎？\n\n請確認！';
                                                   if (confirm(msg)===true){
                                                       event.preventDefault();
                                                       document.getElementById('delete-form-{{ $basicinformation->c_personid."-".$basicinformation->texts[$i]->pivot->c_textid."-".$basicinformation->texts[$i]->pivot->c_role_id }}').submit();
                                                   }else{
                                                        return false;
                                                   }
                                                   "
                                           class="btn btn-sm btn-danger">delete</a>

                                    </div>
                                    <form id="delete-form-{{ $basicinformation->c_personid.'-'.$basicinformation->texts[$i]->pivot->c_textid.'-'.$basicinformation->texts[$i]->pivot->c_role_id }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.texts.destroy.query', ['id' => $basicinformation->c_personid], $textPk) }}" method="POST" style="display: none;">
                                        {{ method_field('DELETE') }}
                                        {{ csrf_field() }}
                                    </form>
                                </td>
                            @endif
                        @endauth
                    </tr>
                @endfor
                </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
