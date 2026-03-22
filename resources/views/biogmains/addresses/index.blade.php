@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">地址清單</h3>
        </div>
        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.addresses.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">新增</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>共查詢到{{ $basicinformation->biog_addresses_count }}筆記錄</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>地址類別</th>
                    <th>地名</th>
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
                @for ($i = 0; $i < $basicinformation->biog_addresses_count; $i++)
                    <tr>
                        <td>{{ $basicinformation->biog_addresses[$i]->c_sequence }}</td>
                        <td>{{ $basicinformation->biog_addresses[$i]->addr_type->c_addr_desc_chn }}</td>
                        <td>{{ $basicinformation->biog_addresses[$i]->addr->c_name_chn }}</td>
                        <td>{{ $basicinformation->biog_addresses[$i]->c_firstyear }}</td>
                        <td>{{ $basicinformation->biog_addresses[$i]->c_lastyear }}</td>
                        @auth
                            @if(Auth::user()->isActive())
                                <td>
                                    @php
                                        $addrPk = [
                                            'c_personid' => $basicinformation->c_personid,
                                            'c_addr_id' => $basicinformation->biog_addresses[$i]->c_addr_id,
                                            'c_addr_type' => $basicinformation->biog_addresses[$i]->c_addr_type,
                                            'c_sequence' => $basicinformation->biog_addresses[$i]->c_sequence,
                                        ];
                                    @endphp
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.addresses.edit.query', ['id' => $basicinformation->c_personid], $addrPk) }}">edit</a>
                                        <a href=""
                                           onclick="
                                                   let msg = '您真的確定要刪除嗎？\n\n請確認！';
                                                   if (confirm(msg)===true){
                                                       event.preventDefault();
                                                       document.getElementById('delete-form-{{ $basicinformation->c_personid."-".$basicinformation->biog_addresses[$i]->c_addr_id."-".$basicinformation->biog_addresses[$i]->c_addr_type."-".$basicinformation->biog_addresses[$i]->c_sequence }}').submit();
                                                   }else{
                                                       return false;
                                                   }"
                                           class="btn btn-sm btn-danger">delete</a>
                                    </div>
                                    <form id="delete-form-{{ $basicinformation->c_personid.'-'.$basicinformation->biog_addresses[$i]->c_addr_id.'-'.$basicinformation->biog_addresses[$i]->c_addr_type.'-'.$basicinformation->biog_addresses[$i]->c_sequence }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.addresses.destroy.query', ['id' => $basicinformation->c_personid], $addrPk) }}" method="POST" style="display: none;">
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
    @include('biogmains.history-button')
@endsection
