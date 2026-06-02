@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('biogmains.statuses_list') }}</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.statuses.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">{{ __('common.add') }}</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>{{ __('biogmains.record_count', ['count' => $basicinformation->statuses_count]) }}</caption>
                <thead>
                <tr>
                    <th>{{ __('person.seq_no') }}</th>
                    <th>{{ __('biogmains.sequence') }}</th>
                    <th>{{ __('biogmains.status_en_col') }}</th>
                    <th>{{ __('biogmains.status_zh_col') }}</th>
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
                @foreach($basicinformation->statuses as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $value->pivot->c_sequence }}</td>
                        <td>{{ $value->c_status_desc }}</td>
                        <td>{{ $value->c_status_desc_chn }}</td>
                        <td>{{ $value->pivot->c_firstyear }}</td>
                        <td>{{ $value->pivot->c_lastyear }}</td>
                        @auth
                            @if(Auth::user()->isActive())
                                <td>
                                    @php
                                    $statusPk = [
                                        'c_personid' => $value->pivot->c_personid,
                                        'c_sequence' => $value->pivot->c_sequence,
                                        'c_status_code' => $value->pivot->c_status_code,
                                    ];
                                    $statusFormId = 'delete-form-' . $value->pivot->c_personid . '-' . $value->pivot->c_sequence . '-' . $value->pivot->c_status_code;
                                    @endphp
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.statuses.edit.query', ['id' => $basicinformation->c_personid], $statusPk) }}">{{ __('common.edit') }}</a>
                                        <a href=""
                                           onclick="
                                                   if (confirm({!! Js::from(__('biogmains.delete_confirm_js')) !!})===true){
                                                       event.preventDefault();
                                                       document.getElementById('{{ $statusFormId }}').submit();
                                                   }else{
                                                       return false;
                                                   }
                                                   "
                                           class="btn btn-sm btn-danger">{{ __('common.delete') }}</a>

                                    </div>
                                    <form id="{{ $statusFormId }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.statuses.destroy.query', ['id' => $basicinformation->c_personid], $statusPk) }}" method="POST" style="display: none;">
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
