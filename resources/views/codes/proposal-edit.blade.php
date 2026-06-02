@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ $table }} 提案調整</h3>
        </div>
        <div class="card-body">
            @if($reviewStatus === 'rejected' && !empty($reviewComment))
                <div class="alert alert-warning">
                    <strong>{{ __('codes.rejection_reason') }}：</strong> {{ $reviewComment }}
                </div>
            @endif
            @if(isset($proposalMeta['cancel_reason']) && $reviewStatus === 'cancelled')
                <div class="alert alert-info">
                    <strong>{{ __('codes.withdrawal_reason') }}：</strong> {{ $proposalMeta['cancel_reason'] }}
                </div>
            @endif
            <form method="post"
                  action="{{ route('codes.proposals.update', ['table_name' => $table, 'operation' => $operationId]) }}">
                {{ method_field('PATCH') }}
                {{ csrf_field() }}
                @foreach($columns as $column)
                    @php
                        $value = $values[$column] ?? '';
                        $isKeyColumn = in_array($column, $keyColumns, true);
                    @endphp
                    <div class="form-group row">
                        <label for="{{ $column }}" class="col-sm-2 col-form-label">
                            {{ $column }}
                            @if($isKeyColumn)
                                <span class="badge badge-secondary" style="margin-left:6px;">主鍵</span>
                            @endif
                        </label>
                        <div class="col-sm-10">
                            <input type="text"
                                   name="{{ $column }}"
                                   id="{{ $column }}"
                                   class="form-control"
                                   value="{{ old($column, $value) }}">
                        </div>
                    </div>
                @endforeach
                <div class="form-group row">
                    <label for="__proposal_comment" class="col-sm-2 col-form-label">{{ __('codes.proposal_desc') }}</label>
                    <div class="col-sm-10">
                        <textarea name="__proposal_comment" id="__proposal_comment" class="form-control" rows="3" placeholder="補充此提案的說明（選填）">{{ old('__proposal_comment', $proposalMeta['comment'] ?? '') }}</textarea>
                        <p class="help-block">{{ __('codes.resubmit_notice') }}</p>
                    </div>
                </div>
                <div class="form-group row">
                    <div class="col-sm-10 offset-sm-2">
                        <button type="submit" class="btn btn-info">{{ __('codes.update_proposal') }}</button>
                        <a href="{{ route('operations.index', ['proposals_only' => 1]) }}" class="btn btn-secondary" style="margin-left:8px;">{{ __('codes.return_to_proposals') }}</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('js')
@endsection
