@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">親屬清單</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.kinship.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">新增</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>共查詢到{{ $basicinformation->kinship_count }}筆記錄</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>親屬關係類別</th>
                    <th>親戚姓名</th>
                    @auth
                        @if(Auth::user()->isActive())
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
                            @if(Auth::user()->isActive())
                                <td>
                                    @php
                                    $kinPk = [
                                        'c_personid' => $value->pivot->c_personid,
                                        'c_kin_id' => $value->pivot->c_kin_id,
                                        'c_kin_code' => $value->pivot->c_kin_code,
                                    ];
                                    $kinFormId = 'delete-form-' . $value->pivot->c_personid . '-' . $value->pivot->c_kin_id . '-' . $value->pivot->c_kin_code;
                                    @endphp
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.kinship.edit.query', ['id' => $basicinformation->c_personid], $kinPk) }}">edit</a>
                                        <a href=""
                                           onclick="
                                                   let msg = '您真的確定要刪除嗎？\n\n請確認！';
                                                   if (confirm(msg)===true){
                                                       event.preventDefault();
                                                       document.getElementById('{{ $kinFormId }}').submit();
                                                   }else{
                                                       return false;
                                                   }
                                                   "
                                           class="btn btn-sm btn-danger">delete</a>

                                    </div>
                                    <form id="{{ $kinFormId }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.kinship.destroy.query', ['id' => $basicinformation->c_personid], $kinPk) }}" method="POST" style="display: none;">
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
