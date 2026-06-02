@php
use App\Support\CompositePrimaryKey;
@endphp
@extends('layouts.dashboard-v3')

@section('css')
<style>
    .operations-action-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }

    .operations-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        border-radius: 999px;
        font-weight: 600;
        letter-spacing: 0.01em;
        padding: 0.3rem 0.8rem;
        box-shadow: none;
    }

    .operations-action-form {
        margin: 0;
    }
</style>
@endsection

@section('content')
@include('biogmains.defense')
    <div class="card card-default">
        <div class="card-body">
            @if(!empty($history_context))
                <div class="alert alert-info py-2 px-3">
                    {{ __('operations.history_label') }} {{ $history_context['person_id'] }}「{{ $history_context['label'] }}」
                </div>
            @endif
            <form method="GET" action="{{ route('operations.index') }}" class="form-inline" style="margin-bottom: 15px;" id="operations-filters">
                @if(!empty($proposals_only))
                    <input type="hidden" name="proposals_only" value="1">
                @endif
                @if(!empty($history_context))
                    <input type="hidden" name="c_personid" value="{{ $history_context['person_id'] }}">
                    <input type="hidden" name="history_page" value="{{ $history_context['page'] }}">
                @endif

                {{-- 修改人篩選 --}}
                <div class="form-group mr-3">
                    <label class="mr-1">{{ __('operations.modified_by') }}：</label>
                    <input type="text" name="editor" class="form-control form-control-sm"
                           placeholder="{{ __('operations.editor_placeholder') }}" value="{{ request('editor', '') }}" style="width: 150px;">
                </div>

                @if(!empty($proposals_only))
                    {{-- 提案狀態篩選 --}}
                    @php
                        $statusOptions = [
                            'pending'   => __('operations.status_pending'),
                            'approved'  => __('operations.status_approved'),
                            'rejected'  => __('operations.status_rejected'),
                            'cancelled' => __('operations.status_withdrawn'),
                        ];
                        $selectedStatuses = $status_filters ?? [];
                    @endphp
                    <div class="form-group mr-3">
                        <label class="mr-1">{{ __('operations.status_label') }}：</label>
                        @foreach($statusOptions as $value => $label)
                            <label class="form-check-inline mr-2">
                                <input type="checkbox" name="status[]" value="{{ $value }}" {{ in_array($value, $selectedStatuses, true) ? 'checked' : '' }}>
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                @else
                    {{-- 修改類型篩選（僅一般模式） --}}
                    @php
                        $opTypeOptions = [
                            1 => __('operations.op_create'),
                            2 => __('operations.op_overwrite'),
                            3 => __('operations.op_update'),
                            4 => __('operations.op_delete'),
                        ];
                        $selectedOpTypes = array_map('intval', (array) request('op_type', []));
                    @endphp
                    <div class="form-group mr-3">
                        <label class="mr-1">{{ __('operations.operation_type') }}：</label>
                        @foreach($opTypeOptions as $val => $lbl)
                            <label class="form-check-inline mr-2">
                                <input type="checkbox" name="op_type[]" value="{{ $val }}"
                                       {{ in_array($val, $selectedOpTypes, true) ? 'checked' : '' }}>
                                {{ $lbl }}
                            </label>
                        @endforeach
                    </div>
                @endif

                <button type="submit" class="btn btn-primary btn-sm mr-2">{{ __('operations.filter') }}</button>
                <a href="{{ route('operations.index', array_merge(
                    !empty($proposals_only) ? ['proposals_only' => 1] : [],
                    !empty($history_context) ? [
                        'c_personid' => $history_context['person_id'],
                        'history_page' => $history_context['page'],
                    ] : [],
                )) }}"
                   class="btn btn-secondary btn-sm">{{ __('operations.clear_filter') }}</a>
            </form>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                <thead>
                <tr>
                    <th>{{ __('operations.person_label') }}</th>
                    <th>{{ __('operations.modified_resource') }}</th>
                    <th>{{ __('operations.resource_location') }}</th>
                    <th>{{ __('operations.modified_value') }}</th>
                    <th>{{ __('operations.operation_type') }}</th>
                    <th>{{ __('operations.modified_by') }}</th>
                    <th>{{ __('operations.modified_time') }}</th>
                </tr>
                </thead>
                <tbody>
                    @php
                        $codeTableKeys = array_keys(config('codes.tables', []));
                        $codeTables = array_map('strtoupper', $codeTableKeys);
                    @endphp
@foreach($lists as $item)
@php
$rawResourceId = $item->resource_id;
$formatResourceDescription = static function ($resourceName, $resourceId) {
    if (!is_string($resourceId) || trim($resourceId) === '') {
        return '';
    }

    $parsedResourceId = CompositePrimaryKey::parseStoredResourceId($resourceId, (string) $resourceName);
    if (is_array($parsedResourceId) && !empty($parsedResourceId)) {
        $parts = [];
        foreach ($parsedResourceId as $column => $value) {
            $parts[] = $column . '：' . (($value === 'NULL' || $value === null) ? '(null)' : (string) $value);
        }

        return implode("\n", $parts);
    }

    return unionPKDef_decode_for_convert(unionPKDef($resourceId));
};
$resourceDescription = $formatResourceDescription($item->resource, (string) $rawResourceId);
$item->resource_data = unionPKDef($item->resource_data);
@endphp
                        @php
                            $affectedPeople = is_array($item->affected_people ?? null) ? $item->affected_people : [];
                            if (empty($affectedPeople)) {
                                if (!empty($item->biogmain) && (int) ($item->c_personid ?? 0) !== 0) {
                                    $affectedPeople[] = [
                                        'id' => (int) $item->c_personid,
                                        'name_chn' => $item->biogmain->c_name_chn ?? '',
                                        'name' => $item->biogmain->c_name ?? '',
                                        'is_primary' => true,
                                    ];
                                } else {
                                    $affectedPeople[] = [
                                        'id' => null,
                                        'name_chn' => '',
                                        'name' => '',
                                        'is_primary' => false,
                                    ];
                                }
                            }
                            $personRowspan = count($affectedPeople);
                            $firstPerson = $affectedPeople[0];
                        @endphp
                        <tr>
                            <td>
                            @php
                                $originalResourceId = $rawResourceId;
                                $personLink = null;
                                $resourceSpecificLink = null;
                                $a = $item->resource;
                                $id = $item->c_personid;
                                if ((int) $id !== 0) {
                                    // 人物链接统一指向編輯页面
                                    $personLink = "/basicinformation/{$id}/edit";

                                    // 根据资源类型生成查詢參數模式的編輯連結
                                    if ($item->op_type != 4) {
                                        $resourceSpecificLink = CompositePrimaryKey::buildResourceEditUrl($a, $rawResourceId, $id);
                                    }
                                }
                                $item->resource_data = unionPKDef_decode_for_convert($item->resource_data);
                                $hasPersonLink = $personLink && !empty($item->biogmain);
                                $isCodeResource = in_array(strtoupper($item->resource), $codeTables, true);
                                $resourceLink = null;

                                // 优先使用人物相关的特定资源链接
                                if ($hasPersonLink && $resourceSpecificLink) {
                                    $resourceLink = $resourceSpecificLink;
                                }
                                // 对于代码表资源（无论是否涉及人物），如果没有特定资源链接，则使用 codes 路由
                                elseif ($isCodeResource && $item->op_type != 4) {
                                    $codeRouteId = CompositePrimaryKey::normalizeSingleKeyResourceIdForCodeRoute($item->resource, $originalResourceId);
                                    $resourceLink = route('codes.edit', ['table_name' => $item->resource, 'id' => $codeRouteId], false);
                                }
                                $showPerPersonResourceButtons = in_array(strtoupper($item->resource), ['KIN_DATA', 'ASSOC_DATA'], true) && $personRowspan > 1;
                            @endphp
                            @if(empty($firstPerson['id']))
                                <span class="text-muted">{{ __('operations.no_person_involved') }}</span>
                            @else
                                <a href="/basicinformation/{{ $firstPerson['id'] }}/edit">
                                    {{ trim(($firstPerson['name_chn'] ?? '').' '.($firstPerson['name'] ?? '')) !== '' ? trim(($firstPerson['name_chn'] ?? '').' '.($firstPerson['name'] ?? '')) : $firstPerson['id'] }}
                                </a>
                                @if($personRowspan > 1)
                                    <span class="badge badge-secondary" style="margin-left:4px;">
                                        {{ !empty($firstPerson['is_primary']) ? __('operations.main_op') : __('operations.linked_op') }}
                                    </span>
                                @endif
                            @endif
                            </td>
                            @php
                                $auditLogs = is_array($item->audit_logs ?? null) ? $item->audit_logs : [];
                                $auditTableNames = [];
                                foreach ($auditLogs as $audit) {
                                    $tableName = trim((string) ($audit['table_name'] ?? ''));
                                    if ($tableName !== '') {
                                        $auditTableNames[] = $tableName;
                                    }
                                }
                                $auditTableNames = array_values(array_unique($auditTableNames));
                                $resourceDisplay = !empty($auditTableNames)
                                    ? implode(' / ', $auditTableNames)
                                    : (string) $item->resource;
                            @endphp
                            @php
                                $resourceNoteTooltipShort = '';
                                $hasResourceNoteIcon = false;
                                $resourceDataForIcon = json_decode($item->resource_data, true);
                                $summaryNoteLabel = in_array((int) $item->op_type, [\App\Models\Operation::TYPE_PROPOSAL_CREATE, \App\Models\Operation::TYPE_PROPOSAL_UPDATE], true)
                                    ? __('operations.proposal_desc')
                                    : __('operations.modification_desc');
                                if (is_array($resourceDataForIcon)) {
                                    $notesForIcon = [];
                                    $iconDirectNote = trim((string) ($resourceDataForIcon['__note'] ?? ''));
                                    if ($iconDirectNote !== '') {
                                        $notesForIcon[] = $summaryNoteLabel . '：' . $iconDirectNote;
                                    }
                                    $iconProposalMeta = $resourceDataForIcon['__proposal_meta'] ?? [];
                                    if (is_array($iconProposalMeta)) {
                                        $iconProposalComment = trim((string) ($iconProposalMeta['comment'] ?? ''));
                                        if ($iconProposalComment !== '') {
                                            $notesForIcon[] = __('operations.proposal_desc').'：'.$iconProposalComment;
                                        }
                                        $iconCancelReason = trim((string) ($iconProposalMeta['cancel_reason'] ?? ''));
                                        if ($iconCancelReason !== '') {
                                            $notesForIcon[] = __('operations.withdrawal_reason').'：'.$iconCancelReason;
                                        }
                                    }
                                    $iconReviewComment = trim((string) ($resourceDataForIcon['__review_comment'] ?? ''));
                                    if ($iconReviewComment !== '') {
                                        $notesForIcon[] = __('operations.review_notes').'：'.$iconReviewComment;
                                    }
                                    if (!empty($notesForIcon)) {
                                        $hasResourceNoteIcon = true;
                                        $resourceNoteTooltipShort = mb_strimwidth(implode(' ｜ ', $notesForIcon), 0, 120, '…', 'UTF-8');
                                    }
                                }
                            @endphp
                            <td rowspan="{{ $personRowspan }}">
                                {{ $resourceDisplay }}
                                @if($hasResourceNoteIcon)
                                    <button type="button"
                                            class="btn btn-link btn-sm text-dark p-0 ml-1 align-baseline js-operation-note-tooltip"
                                            data-toggle="modal"
                                            data-target="#operation-notes-{{ $item->id }}"
                                            data-note-tooltip="{{ $resourceNoteTooltipShort }}">
                                        <i class="far fa-edit"></i>
                                    </button>
                                @endif
                            </td>
                            @if($showPerPersonResourceButtons)
                                <td>
                                    @if(!empty($firstPerson['resource_link']))
                                        <a href="{{ $firstPerson['resource_link'] }}" class="btn btn-outline-primary btn-sm">{{ __('operations.view_page') }}</a>
                                    @else
                                        <button type="button" class="btn btn-outline-secondary btn-sm" disabled>{{ __('operations.no_resource_page') }}</button>
                                    @endif
                                    @if(!empty($firstPerson['resource_id']))
                                        <div class="small text-muted mt-1" style="word-break: break-all;">
                                            {!! nl2br(e($formatResourceDescription($item->resource, (string) $firstPerson['resource_id']))) !!}
                                        </div>
                                    @endif
                                </td>
                            @endif
                            @php
                                $diffSource = $item->resource_diff ?? $item->resource_original;
                                $hasAuditLogs = !empty($auditLogs);
                                $hasDiffContent = false;
                                if (is_array($diffSource)) {
                                    if (($diffSource['type'] ?? null) === 'POSTED_TO_ADDR_DATA') {
                                        $hasDiffContent = !empty($diffSource['addresses'] ?? []);
                                    } else {
                                        $hasDiffContent = !empty($diffSource['rows'] ?? []);
                                    }
                                } elseif (is_string($diffSource) && trim($diffSource) !== '') {
                                    $hasDiffContent = true;
                                }
                                $resourceDataParsed = json_decode($item->resource_data, true);
                                if (!is_array($resourceDataParsed)) {
                                    $resourceDataParsed = is_string($item->resource_data) ? trim($item->resource_data) : $item->resource_data;
                                }
                                $proposalMeta = is_array($resourceDataParsed) ? ($resourceDataParsed['__proposal_meta'] ?? []) : [];
                                $reviewStatus = is_array($resourceDataParsed) ? ($resourceDataParsed['__review_status'] ?? null) : null;
                                $reviewComment = is_array($resourceDataParsed) ? ($resourceDataParsed['__review_comment'] ?? null) : null;
                                $reviewedBy = is_array($resourceDataParsed) ? ($resourceDataParsed['__reviewed_by'] ?? null) : null;
                                $reviewedAt = is_array($resourceDataParsed) ? ($resourceDataParsed['__reviewed_at'] ?? null) : null;
                                $isProposal = in_array((int) $item->op_type, [\App\Models\Operation::TYPE_PROPOSAL_CREATE, \App\Models\Operation::TYPE_PROPOSAL_UPDATE], true);
                                $primaryNoteLabel = $isProposal ? __('operations.proposal_desc') : __('operations.modification_desc');
                                $operationNotes = [];
                                $directNote = is_array($resourceDataParsed) ? trim((string) ($resourceDataParsed['__note'] ?? '')) : '';
                                if ($directNote !== '') {
                                    $operationNotes[] = ['label' => $primaryNoteLabel, 'content' => $directNote];
                                }
                                $proposalComment = is_array($proposalMeta) ? trim((string) ($proposalMeta['comment'] ?? '')) : '';
                                if ($proposalComment !== '') {
                                    $operationNotes[] = ['label' => __('operations.proposal_desc'), 'content' => $proposalComment];
                                }
                                $reviewCommentText = trim((string) ($reviewComment ?? ''));
                                if ($reviewCommentText !== '') {
                                    $operationNotes[] = ['label' => __('operations.review_notes'), 'content' => $reviewCommentText];
                                }
                                $cancelReasonText = is_array($proposalMeta) ? trim((string) ($proposalMeta['cancel_reason'] ?? '')) : '';
                                if ($cancelReasonText !== '') {
                                    $operationNotes[] = ['label' => __('operations.withdrawal_reason'), 'content' => $cancelReasonText];
                                }
                                $hasOperationNotes = !empty($operationNotes);
                                $noteTooltip = implode(' ｜ ', array_map(function ($note) {
                                    return ($note['label'] ?? __('operations.remarks')) . '：' . ($note['content'] ?? '');
                                }, $operationNotes));
                                $noteTooltipShort = mb_strimwidth($noteTooltip, 0, 120, '…', 'UTF-8');
                                $submittedDisplay = '';
                                $submittedUtc = '';
                                if (!empty($proposalMeta['submitted_at'])) {
                                    try {
                                        $submittedCarbon = \Carbon\Carbon::parse($proposalMeta['submitted_at'], config('app.timezone', 'Asia/Shanghai'));
                                        $submittedDisplay = $submittedCarbon;
                                        $submittedUtc = $submittedCarbon->copy()->setTimezone('UTC')->toIso8601String();
                                    } catch (\Exception $e) {
                                        $submittedDisplay = $proposalMeta['submitted_at'];
                                        $submittedUtc = $proposalMeta['submitted_at'];
                                    }
                                }
                                $reviewedDisplay = '';
                                $reviewedUtc = '';
                                if (!empty($reviewedAt)) {
                                    try {
                                        $reviewedCarbon = \Carbon\Carbon::parse($reviewedAt, config('app.timezone', 'Asia/Shanghai'));
                                        $reviewedDisplay = $reviewedCarbon;
                                        $reviewedUtc = $reviewedCarbon->copy()->setTimezone('UTC')->toIso8601String();
                                    } catch (\Exception $e) {
                                        $reviewedDisplay = $reviewedAt;
                                        $reviewedUtc = $reviewedAt;
                                    }
                                }
                                $cancelledDisplay = '';
                                $cancelledUtc = '';
                                if (!empty($proposalMeta['cancelled_at'])) {
                                    try {
                                        $cancelledCarbon = \Carbon\Carbon::parse($proposalMeta['cancelled_at'], config('app.timezone', 'Asia/Shanghai'));
                                        $cancelledDisplay = $cancelledCarbon;
                                        $cancelledUtc = $cancelledCarbon->copy()->setTimezone('UTC')->toIso8601String();
                                    } catch (\Exception $e) {
                                        $cancelledDisplay = $proposalMeta['cancelled_at'];
                                        $cancelledUtc = $proposalMeta['cancelled_at'];
                                    }
                                }
                                $cancelledBy = $proposalMeta['cancelled_by'] ?? null;
                                $submittedById = $proposalMeta['submitted_by_id'] ?? null;
                                $isProposalOwner = Auth::check() && $submittedById !== null && (int) Auth::id() === (int) $submittedById;
                                $canEditProposal = $isProposalOwner && in_array($reviewStatus, ['pending', 'rejected'], true);
                                $resourceDataDisplay = $resourceDataParsed;
                                if (is_array($resourceDataDisplay)) {
                                    $resourceDataDisplay = array_filter($resourceDataDisplay, function ($value, $key) {
                                        return strpos($key, '__') !== 0;
                                    }, ARRAY_FILTER_USE_BOTH);
                                }
                            @endphp
                                @php
                                    $canCompare = ($hasAuditLogs || $hasDiffContent) && (int)$item->op_type !== 4;
                            @endphp
                            @if(!$showPerPersonResourceButtons)
                                <td rowspan="{{ $personRowspan }}">
                                    @if($resourceLink)
                                        <a href="{{ $resourceLink }}" class="btn btn-outline-primary btn-sm">{{ __('operations.view_page') }}</a>
                                    @else
                                        <button type="button" class="btn btn-outline-secondary btn-sm" disabled>{{ __('operations.no_resource_page') }}</button>
                                    @endif
                                    @if($resourceDescription !== '')
                                        <div class="small text-muted mt-1" style="word-break: break-all;">
                                            {!! nl2br(e($resourceDescription)) !!}
                                        </div>
                                    @endif
                                </td>
                            @endif
                            <td rowspan="{{ $personRowspan }}" style="max-width: 28rem; white-space: normal; word-break: break-word; overflow-wrap: anywhere;">
                                @if($isProposal)
                                    <div class="proposal-status" style="margin-bottom:6px;">
                                        @if($reviewStatus === 'approved')
                                            <span class="badge badge-success">{{ __('operations.status_approved') }}</span>
                                        @elseif($reviewStatus === 'rejected')
                                            <span class="badge badge-danger">{{ __('operations.status_rejected') }}</span>
                                        @elseif($reviewStatus === 'cancelled')
                                            <span class="badge badge-secondary">{{ __('operations.status_withdrawn') }}</span>
                                        @else
                                            <span class="badge badge-warning">{{ __('operations.status_pending') }}</span>
                                        @endif
                                @if(!empty($proposalMeta['comment']))
                                    <small class="text-muted" style="display:block;">
                                        {{ __('operations.proposal_desc') }}：{{ $proposalMeta['comment'] }}
                                    </small>
                                @endif
                                @if(!empty($proposalMeta['submitted_by']))
                                            <small class="text-muted" style="display:block;">
                                                {{ __('operations.proposer') }}：{{ $proposalMeta['submitted_by'] }}
                                                @if($submittedUtc)
                                                    （<span class="js-utc-datetime" data-utc="{{ $submittedUtc }}">{{ $submittedDisplay }}</span>）
                                                @endif
                                            </small>
                                        @endif
                                        @if($reviewStatus === 'cancelled')
                                            <small class="text-muted" style="display:block;">
                                                {{ __('operations.cancelled_by') }}：{{ $cancelledBy ?? '—' }}
                                                @if($cancelledUtc)
                                                    （<span class="js-utc-datetime" data-utc="{{ $cancelledUtc }}">{{ $cancelledDisplay }}</span>）
                                                @endif
                                            </small>
                                            @if(!empty($proposalMeta['cancel_reason']))
                                                <small class="text-muted" style="display:block;">{{ __('operations.withdrawal_reason') }}：{{ $proposalMeta['cancel_reason'] }}</small>
                                            @endif
                                        @endif
                                        @if($reviewComment)
                                            <small class="text-muted" style="display:block;">{{ __('operations.review_notes') }}：{{ $reviewComment }}</small>
                                        @endif
                                        @if($reviewedBy)
                                            <small class="text-muted" style="display:block;">
                                                {{ __('operations.reviewer') }}：{{ $reviewedBy }}
                                                @if($reviewedUtc)
                                                    （<span class="js-utc-datetime" data-utc="{{ $reviewedUtc }}">{{ $reviewedDisplay }}</span>）
                                                @endif
                                            </small>
                                        @endif
                                    </div>
                                @endif
                                <div class="operations-action-list">
                                    <button type="button"
                                            class="btn btn-outline-primary btn-sm operations-action-btn"
                                            data-toggle="modal"
                                            data-target="#myModal{{ $item->id }}">
                                        <span>{{ __('operations.content_snapshot') }}</span>
                                    </button>
                                    <button type="button"
                                            class="btn btn-outline-info btn-sm operations-action-btn"
                                            data-toggle="modal"
                                            data-target="#myModal-mapping{{ $item->id }}"
                                            {{ $canCompare ? '' : 'disabled' }}>
                                        <i class="fas fa-code-compare" aria-hidden="true"></i>
                                        <span>{{ __('operations.compare') }}</span>
                                    </button>
                                </div>
                                <div id="myModal{{ $item->id }}" class="modal fade" role="dialog" tabindex="-1">
                                  <div class="modal-dialog">
                                    <div class="modal-content">
                                      <div class="modal-header">
                                        <h4 class="modal-title">{{ __('operations.content_snapshot') }}</h4>
                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                      </div>
                                      <div class="modal-body" style="word-break: break-all;">
                                        @include('components.key-value-table', ['data' => $resourceDataDisplay])
                                      </div>
                                      <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('common.close') }}</button>
                                      </div>
                                    </div>
                                  </div>
                                </div>

                                <div id="myModal-mapping{{ $item->id }}" class="modal fade" role="dialog" tabindex="-1">
                                  <div class="modal-dialog modal-lg" style="width:80vw;max-width:80vw;">
                                    <div class="modal-content">
                                      <div class="modal-header">
                                        <h4 class="modal-title">{{ __('operations.compare') }}</h4>
                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                      </div>
                                      <div class="modal-body" style="word-break: break-all;">
                                        @if($hasAuditLogs)
                                            <div class="text-muted small" style="margin-bottom: 8px;">{{ __('operations.audit_log_count_item', ['count' => count($auditLogs)]) }}</div>
                                            @foreach($auditLogs as $audit)
                                                <div class="border rounded p-2" style="margin-bottom: 10px;">
                                                    <div class="small" style="margin-bottom: 6px;">
                                                        <strong>{{ $audit['table_name'] ?? __('operations.unknown_table') }}</strong>
                                                        · {{ strtoupper($audit['operation'] ?? 'UNKNOWN') }}
                                                        · <span class="text-monospace">{{ $audit['row_pk_text'] ?? '' }}</span>
                                                    </div>
                                                    @include('components.diff-table', ['diff' => $audit['diff'] ?? null])
                                                </div>
                                            @endforeach
                                        @else
                                            <div>
                                            @include('components.diff-table', ['diff' => $diffSource])
                                            </div>
                                        @endif
                                      </div>
                                      <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('common.close') }}</button>
                                      </div>
                                    </div>
                                  </div>
                                </div>
                                @if($hasOperationNotes)
                                        <div id="operation-notes-{{ $item->id }}" class="modal fade" role="dialog" tabindex="-1">
                                      <div class="modal-dialog">
                                        <div class="modal-content">
                                          <div class="modal-header">
                                            <h4 class="modal-title">{{ $primaryNoteLabel }}</h4>
                                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                                          </div>
                                          <div class="modal-body" style="word-break: break-word;">
                                            @foreach($operationNotes as $note)
                                                <div style="margin-bottom: 12px;">
                                                    @if(($note['label'] ?? '') !== $primaryNoteLabel)
                                                        <strong>{{ $note['label'] ?? __('operations.remarks') }}</strong>
                                                    @endif
                                                    <div>{!! nl2br(e($note['content'] ?? '')) !!}</div>
                                                </div>
                                            @endforeach
                                          </div>
                                          <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('common.close') }}</button>
                                          </div>
                                        </div>
                                      </div>
                                    </div>
                                @endif
                                @if(Auth::check() && Auth::user()->isAdmin() && in_array((int)$item->op_type, [3,4]) && $item->resource !== 'POSTED_TO_ADDR_DATA' && $canCompare)
                                    <form method="post" action="{{ route('operations.restore', $item->id) }}" class="operations-action-form">
                                        {{ csrf_field() }}
                                        <button type="submit" class="btn btn-outline-warning btn-sm operations-action-btn"
                                            onclick="return confirm({!! Js::from(__('operations.revert_confirm')) !!});">
                                            <i class="fas fa-history" aria-hidden="true"></i>
                                            {{ __('operations.revert') }}
                                        </button>
                                    </form>
                                @endif
                                @if($canEditProposal)
                                    <div class="operations-action-list">
                                        <a href="{{ route('codes.proposals.edit', ['table_name' => $item->resource, 'operation' => $item->id]) }}"
                                           class="btn btn-outline-secondary btn-sm operations-action-btn">
                                            <i class="far fa-pen-to-square" aria-hidden="true"></i>
                                            {{ __('operations.edit_proposal') }}
                                        </a>
                                        <form method="post"
                                              action="{{ route('codes.proposals.cancel', ['table_name' => $item->resource, 'operation' => $item->id]) }}"
                                              class="operations-action-form">
                                            {{ csrf_field() }}
                                            {{ method_field('DELETE') }}
                                            <button type="submit" class="btn btn-outline-warning btn-sm operations-action-btn"
                                                    onclick="return confirm({!! Js::from(__('operations.withdraw_confirm')) !!});">
                                                <i class="fas fa-ban" aria-hidden="true"></i>
                                                {{ __('operations.revoke') }}
                                            </button>
                                        </form>
                                    </div>
                                @endif
                                @if($isProposal && Auth::check() && Auth::user()->canReviewProposals() && $reviewStatus === 'pending')
                                    <div class="operations-action-list">
                                        <form method="post" action="{{ route('operations.proposals.approve', $item->id) }}" class="operations-action-form">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="review_comment" value="">
                                            <button type="submit" class="btn btn-outline-success btn-sm operations-action-btn" onclick="return confirm({!! Js::from(__('operations.approve_confirm')) !!});">
                                                <i class="fas fa-check" aria-hidden="true"></i>
                                                {{ __('operations.approve') }}
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-outline-danger btn-sm operations-action-btn" data-toggle="modal" data-target="#proposal-reject-{{ $item->id }}">
                                            <i class="fas fa-reply" aria-hidden="true"></i>
                                            {{ __('operations.reject_proposal') }}
                                        </button>
                                    </div>
                                    <div id="proposal-reject-{{ $item->id }}" class="modal fade" role="dialog" tabindex="-1">
                                      <div class="modal-dialog">
                                        <div class="modal-content">
                                          <form method="post" action="{{ route('operations.proposals.reject', $item->id) }}">
                                              {{ csrf_field() }}
                                              <div class="modal-header">
                                                <h4 class="modal-title">{{ __('operations.reject_modal_title') }}</h4>
                                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                              </div>
                                              <div class="modal-body">
                                                <div class="form-group">
                                                    <label for="proposal-review-comment-{{ $item->id }}">{{ __('operations.reject_reason_opt') }}</label>
                                                    <textarea name="review_comment" id="proposal-review-comment-{{ $item->id }}" class="form-control" rows="3"></textarea>
                                                </div>
                                              </div>
                                              <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('common.cancel') }}</button>
                                                <button type="submit" class="btn btn-danger">{{ __('operations.confirm_reject') }}</button>
                                              </div>
                                          </form>
                                        </div>
                                      </div>
                                    </div>
                                @endif
                            </td>
                            <td rowspan="{{ $personRowspan }}">
                                @php
                                    $opTypeLabels = [
                                        1 => '1-'.__('operations.op_create'),
                                        2 => '2-'.__('operations.op_overwrite'),
                                        3 => '3-'.__('operations.op_update'),
                                        4 => '4-'.__('operations.op_delete'),
                                        \App\Models\Operation::TYPE_PROPOSAL_CREATE => '8-'.__('operations.op_proposal_create'),
                                        \App\Models\Operation::TYPE_PROPOSAL_UPDATE => '9-'.__('operations.op_proposal_update'),
                                    ];
                                @endphp
                                {{ $opTypeLabels[$item->op_type] ?? $item->op_type }}
                            </td>
                            <td rowspan="{{ $personRowspan }}">
                                @if(Auth::check())
                                    {{ $item->user->name ?? ('User ' . $item->user_id) }}
                                @else
                                    {{ 'User ' . $item->user_id }}
                                @endif
                            </td>
                            @php
                                $updatedUtc = '';
                                $updatedDisplay = '';
                                $updatedAtRaw = $item->updated_at;
                                $appTimezone = config('app.timezone', 'Asia/Shanghai');
                                if ($updatedAtRaw instanceof \Carbon\Carbon) {
                                    $updatedDisplay = $updatedAtRaw;
                                    $updatedUtc = $updatedAtRaw->copy()->setTimezone('UTC')->toIso8601String();
                                } elseif (is_string($updatedAtRaw) && trim($updatedAtRaw) !== '') {
                                    $updatedDisplay = trim($updatedAtRaw);
                                    try {
                                        $parsed = \Carbon\Carbon::parse($updatedAtRaw, $appTimezone);
                                        $updatedUtc = $parsed->setTimezone('UTC')->toIso8601String();
                                    } catch (\Exception $e) {
                                        $updatedUtc = $updatedDisplay;
                                    }
                                }
                            @endphp
                            <td rowspan="{{ $personRowspan }}" class="js-utc-datetime" data-utc="{{ $updatedUtc }}">
                                {{ $updatedDisplay }}
                            </td>
                        </tr>
                        @if($personRowspan > 1)
                            @for($personIdx = 1; $personIdx < $personRowspan; $personIdx++)
                                @php
                                    $relatedPerson = $affectedPeople[$personIdx];
                                @endphp
                                <tr>
                                    <td>
                                        @if(empty($relatedPerson['id']))
                                            <span class="text-muted">{{ __('operations.no_person_involved') }}</span>
                                        @else
                                            <a href="/basicinformation/{{ $relatedPerson['id'] }}/edit">
                                                {{ trim(($relatedPerson['name_chn'] ?? '').' '.($relatedPerson['name'] ?? '')) !== '' ? trim(($relatedPerson['name_chn'] ?? '').' '.($relatedPerson['name'] ?? '')) : $relatedPerson['id'] }}
                                            </a>
                                            <span class="badge badge-secondary" style="margin-left:4px;">
                                                {{ !empty($relatedPerson['is_primary']) ? __('operations.main_op') : __('operations.linked_op') }}
                                            </span>
                                        @endif
                                    </td>
                                    @if($showPerPersonResourceButtons)
                                        <td>
                                            @if(!empty($relatedPerson['resource_link']))
                                                <a href="{{ $relatedPerson['resource_link'] }}" class="btn btn-outline-primary btn-sm">{{ __('operations.view_page') }}</a>
                                            @else
                                                <button type="button" class="btn btn-outline-secondary btn-sm" disabled>{{ __('operations.no_resource_page') }}</button>
                                            @endif
                                            @if(!empty($relatedPerson['resource_id']))
                                                <div class="small text-muted mt-1" style="word-break: break-all;">
                                                    {!! nl2br(e($formatResourceDescription($item->resource, (string) $relatedPerson['resource_id']))) !!}
                                                </div>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endfor
                        @endif
                    @endforeach
                </tbody>

                </table>
            </div>
            <div class="float-right">
                {{ $lists->links() }}
            </div>
        </div>
    </div>

@endsection

@section('js')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var getFormatTimestampFn = function() {
        return typeof window.formatTimestamp === 'function'
            ? window.formatTimestamp
            : function(value) { return value; };
    };
    var userOffsetMinutes = typeof window.getUserOffsetMinutes === 'function'
        ? window.getUserOffsetMinutes()
        : new Date().getTimezoneOffset();

    var nodes = document.querySelectorAll('.js-utc-datetime');
    Array.prototype.forEach.call(nodes, function (node) {
        var original = node.getAttribute('data-utc') || (node.textContent || '').trim();
        if (!original) {
            return;
        }

        var displayText = getFormatTimestampFn()(original);
        node.textContent = displayText;
        if (userOffsetMinutes !== -480) {
            var chinaText = getFormatTimestampFn()(original, 'Asia/Shanghai');
            if (chinaText && chinaText !== original) {
                node.setAttribute('title', chinaText);
            } else {
                node.removeAttribute('title');
            }
        } else {
            node.removeAttribute('title');
        }
    });

    if (typeof window.$ !== 'undefined') {
        window.$('.js-operation-note-tooltip').each(function () {
            var $el = window.$(this);
            var tip = $el.attr('data-note-tooltip') || '';
            $el.tooltip({
                title: tip,
                placement: 'top',
                trigger: 'hover',
            });
        });
    }
});

</script>
@endsection
