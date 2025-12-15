@extends('layouts.dashboard-v3')

@section('content')
    @include('biogmains.banner')
    @include('biogmains.defense')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">出處</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.sources.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">新增</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>共查询到{{ $basicinformation->sources_count }}条记录</caption>
                <thead>
                <tr>
                    <th>序號</th>
                    <th>出處</th>
                    <th>頁碼</th>
                    @auth
                        @if(Auth::user()->isActive())
                            <th style="width: 120px">操作</th>
                        @endif
                    @endauth
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->sources as $key=>$value)
@php
$value->pivot->c_pages = unionPKDef($value->pivot->c_pages);
$c_pages_view = unionPKDef_decode_for_convert($value->pivot->c_pages);
@endphp
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $value->c_title_chn }}</td>
                        <td>
                            @if($value->c_url_api && $value->c_textid)
                                @php
                                    $url_part = $c_pages_view;
                                    if (preg_match('/[\x{4e00}-\x{9fff}]/u', $url_part)) {
                                        $url_part = rawurlencode($url_part);
                                    }
                                    $full_url = $value->c_url_api . $url_part . ($value->c_url_api_coda ?? '');
                                @endphp
                                <a href="{{ $full_url }}" target="_blank">{{ $c_pages_view }}</a>
                            @else
                                {{ $c_pages_view }}
                            @endif
                        </td>
                        @auth
                            @if(Auth::user()->isActive())
                        <td>
                            <div class="btn-group">
                                <a type="button" class="btn btn-sm btn-info" href="{{ route('basicinformation.sources.edit', ['basicinformation' => $basicinformation->c_personid, 'source' => $value->pivot->c_personid.'-'.$value->pivot->c_textid.'-'.$value->pivot->c_pages]) }}">edit</a>
                                <a href=""
                                   onclick="
                                           let msg = '您真的确定要删除吗？\n\n请确认！';
                                           if (confirm(msg)===true){
                                               event.preventDefault();
                                               document.getElementById('delete-form-{{ $value->pivot->c_personid."-".$value->pivot->c_textid."-".$value->pivot->c_pages }}').submit();
                                           }else{
                                               return false;
                                           }
                                           "
                                   class="btn btn-sm btn-danger">delete</a>
                                    </div>
                                    <form id="delete-form-{{ $value->pivot->c_personid.'-'.$value->pivot->c_textid.'-'.$value->pivot->c_pages }}" action="{{ route('basicinformation.sources.destroy', ['basicinformation' => $basicinformation->c_personid, 'source' => $value->pivot->c_personid.'-'.$value->pivot->c_textid.'-'.$value->pivot->c_pages]) }}" method="POST" style="display: none;">
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
