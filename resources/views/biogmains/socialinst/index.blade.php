@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('biogmains.socialinst_list') }}</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.socialinst.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">{{ __('common.add') }}</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>{{ __('biogmains.record_count', ['count' => $basicinformation->inst_count]) }}</caption>
                <thead>
                <tr>
                    <th>{{ __('person.seq_no') }}</th>
                    <th>{{ __('biogmains.socialinst_field') }}</th>
                    <th>{{ __('biogmains.socialinst_role') }}</th>
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
                @foreach($basicinformation->inst as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>{{ $basicinformation->inst_name[$key]->c_inst_name_hz }}</td>
                        <td>
                            {{ $value->c_bi_role_chn }}
                            @if(!empty($value->c_bi_role_desc))
                                <br><span class="text-muted small">{{ $value->c_bi_role_desc }}</span>
                            @endif
                        </td>
                        <td>{{ $value->pivot->c_bi_begin_year }}</td>
                        <td>{{ $value->pivot->c_bi_end_year }}</td>
                        @auth
                            @if(Auth::user()->isActive())
                                <td>
                                    @php
                                    $instPk = [
                                        'c_personid' => $value->pivot->c_personid,
                                        'c_inst_code' => $value->pivot->c_inst_code,
                                        'c_inst_name_code' => $value->pivot->c_inst_name_code,
                                        'c_bi_role_code' => $value->pivot->c_bi_role_code,
                                    ];
                                    $instFormId = 'delete-form-' . $key;
                                    @endphp
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.socialinst.edit.query', ['id' => $basicinformation->c_personid], $instPk) }}">{{ __('common.edit') }}</a>
                                        <a href=""
                                           onclick="
                                                   if (confirm({!! Js::from(__('biogmains.delete_confirm_js')) !!})===true){
                                                       event.preventDefault();
                                                       document.getElementById('{{ $instFormId }}').submit();
                                                   }else{
                                                       return false;
                                                   }
                                                   "
                                           class="btn btn-sm btn-danger">{{ __('common.delete') }}</a>

                                    </div>
                                    <form id="{{ $instFormId }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.socialinst.destroy.query', ['id' => $basicinformation->c_personid], $instPk) }}" method="POST" style="display: none;">
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
