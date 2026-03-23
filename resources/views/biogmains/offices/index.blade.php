@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">官名清單</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.offices.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">新增</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>共查詢到{{ $basicinformation->offices_count }}筆記錄</caption>
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
                        @if(Auth::user()->isActive())
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
                            @if(Auth::user()->isActive())
                                <td>
                                    @php
                                    $officePk = [
                                        'c_office_id' => $value->pivot->c_office_id,
                                        'c_posting_id' => $value->pivot->c_posting_id,
                                    ];
                                    $officeFormId = 'delete-form-' . $value->pivot->c_office_id . '-' . $value->pivot->c_posting_id;
                                    @endphp
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.offices.edit.query', ['id' => $basicinformation->c_personid], $officePk) }}">edit</a>
                                        <a href=""
                                           onclick="
                                                   let msg = '您真的確定要刪除嗎？\n\n請確認！';
                                                   if (confirm(msg)===true){
                                                       event.preventDefault();
                                                       document.getElementById('{{ $officeFormId }}').submit();
                                                   }else{
                                                       return false;
                                                   }"
                                           class="btn btn-sm btn-danger">delete</a>

                                    </div>
                                    <form id="{{ $officeFormId }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.offices.destroy.query', ['id' => $basicinformation->c_personid], $officePk) }}" method="POST" style="display: none;">
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
