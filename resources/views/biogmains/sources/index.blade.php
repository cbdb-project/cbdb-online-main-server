@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    @include('biogmains.defense')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('biogmains.sources_list') }}</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.sources.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">{{ __('common.add') }}</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>{{ __('biogmains.record_count', ['count' => $basicinformation->sources_count]) }}</caption>
                <thead>
                <tr>
                    <th>{{ __('person.seq_no') }}</th>
                    <th>{{ __('biogmains.source_field') }}</th>
                    <th>{{ __('biogmains.page_no') }}</th>
                    @auth
                        @if(Auth::user()->isActive())
                            <th style="width: 120px">{{ __('biogmains.actions') }}</th>
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
                                @php
                                    $sourcePk = [
                                        'c_personid' => $value->pivot->c_personid,
                                        'c_textid' => $value->pivot->c_textid,
                                        'c_pages' => $value->pivot->c_pages ?? '',
                                    ];
                                @endphp
                                <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.sources.edit.query', ['id' => $basicinformation->c_personid], $sourcePk) }}">{{ __('common.edit') }}</a>
                                <a href=""
                                   onclick="
                                           if (confirm({!! Js::from(__('biogmains.delete_confirm_js')) !!})===true){
                                               event.preventDefault();
                                               document.getElementById('delete-form-{{ $value->pivot->c_personid."-".$value->pivot->c_textid."-".($value->pivot->c_pages ?? '') }}').submit();
                                           }else{
                                               return false;
                                           }
                                           "
                                   class="btn btn-sm btn-danger">{{ __('common.delete') }}</a>
                                    </div>
                                    <form id="delete-form-{{ $value->pivot->c_personid.'-'.$value->pivot->c_textid.'-'.($value->pivot->c_pages ?? '') }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.sources.destroy.query', ['id' => $basicinformation->c_personid], $sourcePk) }}" method="POST" style="display: none;">
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
