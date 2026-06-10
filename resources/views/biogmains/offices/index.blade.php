@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('biogmains.offices_list') }}</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.offices.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">{{ __('common.add') }}</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>{{ __('biogmains.record_count', ['count' => $basicinformation->offices_count]) }}</caption>
                <thead>
                <tr>
                    <th>{{ __('person.seq_no') }}</th>
                    <th>{{ __('biogmains.sequence') }}</th>
                    <th>{{ __('person.posting_id') }}</th>
                    <th style="width: 40%;">{{ __('biogmains.office_name_field') }}</th>
                    <th>{{ __('biogmains.place_name') }}</th>
                    <th>{{ __('biogmains.start_year') }}</th>
                    <th>{{ __('biogmains.end_year') }}</th>
                    @auth
                        @if(Auth::user()->isActive())
                            <th style="width: 120px">{{ __('biogmains.actions') }}</th>
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
                        <td>{!! $value->c_office_pinyin. '<br>'. $value->c_office_chn . (!empty($value->c_office_trans) ? '<br><span class="text-muted">'. e($value->c_office_trans) .'</span>' : '') !!}</td>
                        <td>
                            @php
                                $chgisOfficeKey = $value->pivot->c_office_id . ':' . $value->pivot->c_posting_id;
                                $chgisPlaces = ($officePlaces ?? [])[$chgisOfficeKey] ?? [];
                                $fallbackPlaces = collect($post2addr[$chgisOfficeKey] ?? []);
                            @endphp
                            {{-- 無地點的官職任命維持空白（沿用原行為），不顯示「地名不詳」 --}}
                            @if(!empty($chgisPlaces))
                                @foreach($chgisPlaces as $chgisPlace)
                                    @include('biogmains._place_link', ['entry' => $chgisPlace, 'personId' => $basicinformation->c_personid])@if(!$loop->last){{ __('chgis_map.place_separator') }}@endif
                                @endforeach
                            @elseif($fallbackPlaces->isNotEmpty())
                                {{ $fallbackPlaces->implode(__('chgis_map.place_separator')) }}
                            @endif
                        </td>
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
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.offices.edit.query', ['id' => $basicinformation->c_personid], $officePk) }}">{{ __('common.edit') }}</a>
                                        <a href=""
                                           onclick="
                                                   if (confirm({!! Js::from(__('biogmains.delete_confirm_js')) !!})===true){
                                                       event.preventDefault();
                                                       document.getElementById('{{ $officeFormId }}').submit();
                                                   }else{
                                                       return false;
                                                   }"
                                           class="btn btn-sm btn-danger">{{ __('common.delete') }}</a>

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
