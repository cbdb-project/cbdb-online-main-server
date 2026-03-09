@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">入仕清單</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.entries.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">新增</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>共查詢到{{ $basicinformation->entries_count }}筆記錄</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>sequence</th>
                    <th>入仕法</th>
                    <th>入仕年</th>
                    @auth
                        @if(Auth::user()->isActive())
                            <th style="width: 120px">操作</th>
                        @endif
                    @endauth
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->entries as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $value->pivot->c_sequence }}</td>
                        <td>{{ $value->c_entry_desc_chn }}</td>
                        <td>
                            @if($value->pivot->c_year && $value->pivot->c_year != 0)
                                {{ $value->pivot->c_year }}
                            @elseif($value->pivot->c_entry_nh_id && $value->pivot->c_entry_nh_id != 0)
                                {{ $nianhaoMap[$value->pivot->c_entry_nh_id] ?? '' }}{{ $value->pivot->c_entry_nh_year ? $value->pivot->c_entry_nh_year . '年' : '' }}
                            @endif
                        </td>
                        @auth
                            @if(Auth::user()->isActive())
                                <td>
                                    @php
                                    $entryPk = [
                                        'c_personid' => $value->pivot->c_personid,
                                        'c_entry_code' => $value->pivot->c_entry_code,
                                        'c_sequence' => $value->pivot->c_sequence,
                                        'c_kin_code' => $value->pivot->c_kin_code,
                                        'c_assoc_code' => $value->pivot->c_assoc_code,
                                        'c_kin_id' => $value->pivot->c_kin_id,
                                        'c_year' => $value->pivot->c_year,
                                        'c_assoc_id' => $value->pivot->c_assoc_id,
                                        'c_inst_code' => $value->pivot->c_inst_code,
                                        'c_inst_name_code' => $value->pivot->c_inst_name_code,
                                    ];
                                    $entryFormId = 'delete-form-' . $key;
                                    @endphp
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.entries.edit.query', ['id' => $basicinformation->c_personid], $entryPk) }}">edit</a>
                                        <a href=""
                                           onclick="
                                                   let msg = '您真的確定要刪除嗎？\n\n請確認！';
                                                   if (confirm(msg)===true){
                                                       event.preventDefault();
                                                       document.getElementById('{{ $entryFormId }}').submit();
                                                   }else{
                                                        return false;
                                                   }"
                                           class="btn btn-sm btn-danger">delete</a>

                                    </div>
                                    <form id="{{ $entryFormId }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.entries.destroy.query', ['id' => $basicinformation->c_personid], $entryPk) }}" method="POST" style="display: none;">
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
