@extends('layouts.dashboard')

@section('content')
    @include('biogmains.banner')
    <div class="panel panel-default">
        <div class="panel-heading">地址清單</div>
        <div class="panel-body">
            @auth
                @if(Auth::user()->is_active == 1)
                    <a href="{{ route('basicinformation.addresses.create', $basicinformation->c_personid) }}" class="btn btn-default pull-right">新增</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-condensed">
                <caption>共查询到{{ $basicinformation->biog_addresses_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>地址類別</th>
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
                @for ($i = 0; $i < $basicinformation->biog_addresses_count; $i++)
                    <tr>
                        <td>{{ $basicinformation->biog_addresses[$i]->c_sequence }}</td>
                        <td>{{ $basicinformation->biog_addresses[$i]->addr_type->c_addr_desc_chn }}</td>
                        <td>{{ $basicinformation->biog_addresses[$i]->addr->c_name_chn }}</td>
                        <td>{{ $basicinformation->biog_addresses[$i]->c_firstyear }}</td>
                        <td>{{ $basicinformation->biog_addresses[$i]->c_lastyear }}</td>
                        @auth
                            @if(Auth::user()->is_active == 1)
                                <td>
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.addresses.edit', ['id' => $basicinformation->c_personid, 'addr' => $basicinformation->c_personid."-".$basicinformation->biog_addresses[$i]->c_addr_id."-".$basicinformation->biog_addresses[$i]->c_addr_type."-".$basicinformation->biog_addresses[$i]->c_sequence]) }}">edit</a>
                                        <a href=""
                                           onclick="
                                                   let msg = '您真的确定要删除吗？\n\n请确认！';
                                                   if (confirm(msg)===true){
                                                       event.preventDefault();
                                                       document.getElementById('delete-form-{{ $basicinformation->c_personid."-".$basicinformation->biog_addresses[$i]->c_addr_id."-".$basicinformation->biog_addresses[$i]->c_addr_type."-".$basicinformation->biog_addresses[$i]->c_sequence }}').submit();
                                                   }else{
                                                       return false;
                                                   }"
                                           class="btn btn-sm btn-danger">delete</a>
                                    </div>
                                    <form id="delete-form-{{ $basicinformation->c_personid."-".$basicinformation->biog_addresses[$i]->c_addr_id."-".$basicinformation->biog_addresses[$i]->c_addr_type."-".$basicinformation->biog_addresses[$i]->c_sequence }}" action="{{ route('basicinformation.addresses.destroy', ['id' => $basicinformation->c_personid, 'addr' => $basicinformation->c_personid."-".$basicinformation->biog_addresses[$i]->c_addr_id."-".$basicinformation->biog_addresses[$i]->c_addr_type."-".$basicinformation->biog_addresses[$i]->c_sequence]) }}" method="POST" style="display: none;">
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
