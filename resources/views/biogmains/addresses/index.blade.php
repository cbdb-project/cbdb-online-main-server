@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('biogmains.addresses_list') }}</h3>
        </div>
        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.addresses.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">{{ __('common.add') }}</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>{{ __('biogmains.record_count', ['count' => $basicinformation->biog_addresses_count]) }}</caption>
                <thead>
                <tr>
                    <th>{{ __('person.seq_no') }}</th>
                    <th>{{ __('biogmains.address_type') }}</th>
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
                @for ($i = 0; $i < $basicinformation->biog_addresses_count; $i++)
                    <tr>
                        <td>{{ $basicinformation->biog_addresses[$i]->c_sequence }}</td>
                        <td>
                            {{ $basicinformation->biog_addresses[$i]->addr_type->c_addr_desc_chn }}
                            @if(!empty($basicinformation->biog_addresses[$i]->addr_type->c_addr_desc))
                                <br><span class="text-muted">{{ $basicinformation->biog_addresses[$i]->addr_type->c_addr_desc }}</span>
                            @endif
                        </td>
                        <td>
                            @php
                                $addressRecord = $basicinformation->biog_addresses[$i];
                                $chgisAddrKey = 'addr:' . $basicinformation->biog_addresses[$i]->c_addr_id . ':' . $basicinformation->biog_addresses[$i]->c_addr_type . ':' . $basicinformation->biog_addresses[$i]->c_sequence;
                                $chgisAddrEntry = ($addressPointMap ?? collect())->get($chgisAddrKey);
                                $fallbackAddr = $addressRecord->addr;
                                $fallbackAddrName = $fallbackAddr?->c_name_chn;
                                if ($fallbackAddrName === null || $fallbackAddrName === '') {
                                    $fallbackAddrName = $fallbackAddr?->c_name;
                                }
                                if ($fallbackAddrName === null || $fallbackAddrName === '') {
                                    $fallbackAddrName = $addressRecord->c_addr_id === null || $addressRecord->c_addr_id === ''
                                        ? __('chgis_map.unknown_place')
                                        : '#' . $addressRecord->c_addr_id;
                                }
                            @endphp
                            @if($chgisAddrEntry)
                                @include('biogmains._place_link', ['entry' => $chgisAddrEntry, 'personId' => $basicinformation->c_personid])
                            @else
                                {{ $fallbackAddrName }}
                            @endif
                        </td>
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
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.addresses.edit.query', ['id' => $basicinformation->c_personid], $addrPk) }}">{{ __('common.edit') }}</a>
                                        <a href=""
                                           onclick="
                                                   if (confirm({!! Js::from(__('biogmains.delete_confirm_js')) !!})===true){
                                                       event.preventDefault();
                                                       document.getElementById('delete-form-{{ $basicinformation->c_personid."-".$basicinformation->biog_addresses[$i]->c_addr_id."-".$basicinformation->biog_addresses[$i]->c_addr_type."-".$basicinformation->biog_addresses[$i]->c_sequence }}').submit();
                                                   }else{
                                                       return false;
                                                   }"
                                           class="btn btn-sm btn-danger">{{ __('common.delete') }}</a>
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
    @include('biogmains._chgis_map_assets')
@endsection
