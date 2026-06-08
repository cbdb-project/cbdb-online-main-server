@extends('layouts.dashboard-v3')

@php
use App\Support\CompositePrimaryKey;
@endphp

@section('content')
    @include('biogmains.banner')
    @include('biogmains.defense')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('biogmains.assoc_list') }}</h3>
        </div>

        <div class="card-body">
            @auth
                @if(Auth::user()->isActive())
                    <a href="{{ route('basicinformation.assoc.create', ['basicinformation' => $basicinformation->c_personid]) }}" class="btn btn-secondary float-right">{{ __('common.add') }}</a>
                @endif
            @endauth
            <div class="table-responsive">
                <table class="table table-hover table-sm">
                <caption>{{ __('biogmains.record_count', ['count' => $basicinformation->assoc_count]) }}</caption>
                <thead>
                <tr>
                    <th>{{ __('person.seq_no') }}</th>
                    <th>sequence</th>
                    <th>{{ __('biogmains.assoc_category_col') }}</th>
                    <th>{{ __('biogmains.assoc_person_col') }}</th>
                    <th>{{ __('biogmains.work_title') }}</th>
                    @auth
                        @if(Auth::user()->isActive())
                            <th style="width: 120px">{{ __('biogmains.actions') }}</th>
                        @endif
                    @endauth
                </tr>
                </thead>
                <tbody>
                @foreach($basicinformation->assoc as $key=>$value)
                    <tr>
                        <td>{{ $key+1 }}</td>
                        <td>
                            @if($assoc_name[$key])
                                {{ $assoc_name[$key]['c_sequence'] }}
                            @endif
                        </td>
                        <td>
                            {{ $value->c_assoc_desc_chn }}
                            @if(!empty($value->c_assoc_desc))
                                <br><span class="text-muted small">{{ $value->c_assoc_desc }}</span>
                            @endif
                        </td>
                        <td>
                            @if($assoc_name[$key])
                                <a href="{{ route('basicinformation.edit', $assoc_name[$key]['c_personid']) }}" target="_blank">{{ $assoc_name[$key]['assoc_name'] }}</a></td>
                            @endif
                        <td>{{ $value->pivot->c_text_title }}</td>
                        @auth
                            @if(Auth::user()->isActive())
                                <td>
                                    <div class="btn-group">
                                    @php
                                    $assocPk = [
                                        'c_personid' => $value->pivot->c_personid,
                                        'c_assoc_code' => $value->pivot->c_assoc_code,
                                        'c_assoc_id' => $value->pivot->c_assoc_id,
                                        'c_kin_code' => $value->pivot->c_kin_code,
                                        'c_kin_id' => $value->pivot->c_kin_id,
                                        'c_assoc_kin_code' => $value->pivot->c_assoc_kin_code,
                                        'c_assoc_kin_id' => $value->pivot->c_assoc_kin_id,
                                        'c_text_title' => $value->pivot->c_text_title,
                                        'c_assoc_first_year' => $value->pivot->c_assoc_first_year ?? '',
                                    ];
                                    @endphp
                                        <a type="button" class="btn btn-sm btn-info" href="{{ CompositePrimaryKey::buildUrl('basicinformation.assoc.edit.query', ['id' => $basicinformation->c_personid], $assocPk) }}">{{ __('common.edit') }}</a>
                                        <a href=""
                                           onclick="
                                                   if (confirm({!! Js::from(__('biogmains.delete_confirm_js')) !!})===true){
                                                       event.preventDefault();
                                                       document.getElementById('delete-form-{{ $value->pivot->c_personid."-".$value->pivot->c_assoc_code."-".$value->pivot->c_assoc_id."-".$value->pivot->c_kin_code."-".$value->pivot->c_kin_id."-".$value->pivot->c_assoc_kin_code."-".$value->pivot->c_assoc_kin_id."-".$value->pivot->c_text_title }}').submit();
                                                   }else{
                                                        return false;
                                                   }
                                                   "
                                           class="btn btn-sm btn-danger">{{ __('common.delete') }}</a>

                                    </div>
                                    <form id="delete-form-{{ $value->pivot->c_personid.'-'.$value->pivot->c_assoc_code.'-'.$value->pivot->c_assoc_id.'-'.$value->pivot->c_kin_code.'-'.$value->pivot->c_kin_id.'-'.$value->pivot->c_assoc_kin_code.'-'.$value->pivot->c_assoc_kin_id.'-'.($value->pivot->c_text_title ?? '') }}" action="{{ CompositePrimaryKey::buildUrl('basicinformation.assoc.destroy.query', ['id' => $basicinformation->c_personid], $assocPk) }}" method="POST" style="display: none;">
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
