@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">{{ $table }} 提案調整</div>
        <div class="panel-body">
            @if($reviewStatus === 'rejected' && !empty($reviewComment))
                <div class="alert alert-warning">
                    <strong>退修說明：</strong> {{ $reviewComment }}
                </div>
            @endif
            @if(isset($proposalMeta['cancel_reason']) && $reviewStatus === 'cancelled')
                <div class="alert alert-info">
                    <strong>撤回原因：</strong> {{ $proposalMeta['cancel_reason'] }}
                </div>
            @endif
            <form method="post"
                  action="{{ route('codes.proposals.update', ['table_name' => $table, 'operation' => $operationId]) }}"
                  class="form-horizontal">
                {{ method_field('PATCH') }}
                {{ csrf_field() }}
                @foreach($columns as $column)
                    @php
                        $value = $values[$column] ?? '';
                        $isKeyColumn = in_array($column, $keyColumns, true);
                    @endphp
                    <div class="form-group">
                        <label for="{{ $column }}" class="col-sm-2 control-label">
                            {{ $column }}
                            @if($isKeyColumn)
                                <span class="label label-default" style="margin-left:6px;">主鍵</span>
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
                <div class="form-group">
                    <label for="__proposal_comment" class="col-sm-2 control-label">提案說明</label>
                    <div class="col-sm-10">
                        <textarea name="__proposal_comment" id="__proposal_comment" class="form-control" rows="3" placeholder="補充此提案的說明（選填）">{{ old('__proposal_comment', $proposalMeta['comment'] ?? '') }}</textarea>
                        <p class="help-block">修改後將重新送審，審核狀態會回到「待審核」。</p>
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-sm-offset-2 col-sm-10">
                        <button type="submit" class="btn btn-info">更新提案</button>
                        <a href="{{ route('operations.index', ['proposals_only' => 1]) }}" class="btn btn-default" style="margin-left:8px;">返回提案列表</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('js')
@endsection
