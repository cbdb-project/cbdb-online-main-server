@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('biogmains.events_list') }}</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.events.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">{{ __('common.add') }}</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>{{ __('biogmains.record_count', ['count' => $basicinformation->events_count]) }}</caption>
                <thead>
                <tr>
                    <th>{{ __('person.seq_no') }}</th>
                    <th>sequence</th>
                    <th>{{ __('biogmains.event_name') }}</th>
                    @auth
                        @if(Auth::user()->isActive())
                            <th style="width: 120px">{{ __('biogmains.actions') }}</th>
                        @endif
                    @endauth
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->events as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $value->pivot->c_sequence }}</td>
                        <td>{{ $value->c_event_name_chn }}</td>
                        <td>
                            @php
                            $eventPk = [
                                'c_personid' => $basicinformation->c_personid,
                                'c_sequence' => $value->pivot->c_sequence,
                                'c_event_code' => $value->pivot->c_event_code,
                            ];
                            $eventFormId = 'delete-form-' . $value->pivot->c_sequence . '-' . $value->pivot->c_event_code;
                            @endphp
                            <div class="btn-group">
                                <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.events.edit.query', ['id' => $basicinformation->c_personid], $eventPk) }}">{{ __('common.edit') }}</a>
                                <a href=""
                                   onclick="
                                           if (confirm({!! Js::from(__('biogmains.delete_confirm_js')) !!})===true){
                                               event.preventDefault();
                                               document.getElementById('{{ $eventFormId }}').submit();
                                           }else{
                                               return false;
                                           }
                                           "
                                   class="btn btn-sm btn-danger">{{ __('common.delete') }}</a>

                            </div>
                            <form id="{{ $eventFormId }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.events.destroy.query', ['id' => $basicinformation->c_personid], $eventPk) }}" method="POST" style="display: none;">
                                {{ method_field('DELETE') }}
                                {{ csrf_field() }}
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
                </table>
            </div>
        </div>
    </div>
    @include('biogmains.history-button')
@endsection
