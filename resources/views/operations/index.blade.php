@php
use App\Support\CompositePrimaryKey;
@endphp
@extends('layouts.dashboard-v3')

@section('content')
@include('biogmains.defense')
    <div class="card card-default">
        <div class="card-body">
            @if(!empty($proposals_only))
                @php
                    $statusOptions = [
                        'pending' => '待審核',
                        'approved' => '已核准',
                        'rejected' => '已退修',
                        'cancelled' => '已撤回',
                    ];
                    $selectedStatuses = $status_filters ?? [];
                @endphp
                <form method="GET" action="{{ route('operations.index') }}" class="form-inline" style="margin-bottom: 15px;" id="proposal-status-filters">
                    <input type="hidden" name="proposals_only" value="1">
                    @foreach($statusOptions as $value => $label)
                        <label class="checkbox-inline" style="margin-right: 12px;">
                            <input type="checkbox" name="status[]" value="{{ $value }}" {{ in_array($value, $selectedStatuses, true) ? 'checked' : '' }}>
                            {{ $label }}
                        </label>
                    @endforeach
                    <a href="{{ route('operations.index', ['proposals_only' => 1]) }}" class="btn btn-secondary btn-sm" style="margin-left: 8px;">清除篩選</a>
                </form>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var form = document.getElementById('proposal-status-filters');
                        if (!form) {
                            return;
                        }
                        form.addEventListener('change', function () {
                            form.submit();
                        });
                    });
                </script>
            @endif
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                <thead>
                <tr>
                    <th>人物</th>
                    <th>修改資源</th>
                    <th>修改值</th>
                    <th>資源 TTS</th>
                    <th>修改類型</th>
                    <th>修改人</th>
                    <th>修改時間</th>
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
// 用於顯示：對整個字串編碼然後解碼（處理 Blade 模板衝突）
$item->resource_id = unionPKDef($item->resource_id);
$item->resource_data = unionPKDef($item->resource_data);
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
                                    // 人物链接统一指向编辑页面
                                    $personLink = "/basicinformation/{$id}/edit";

                                    // 根据资源类型生成查詢參數模式的編輯連結
                                    if ($item->op_type != 4) {
                                        $resourceSpecificLink = CompositePrimaryKey::buildResourceEditUrl($a, $rawResourceId, $id);
                                    }
                                }
                                $item->resource_id = unionPKDef_decode_for_convert($item->resource_id);
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
                                    $resourceLink = route('codes.edit', ['table_name' => $item->resource, 'id' => $originalResourceId], false);
                                }
                            @endphp
                            @if(!$hasPersonLink)
                                <span class="text-muted">(本修改不涉及人物)</span>
                            @else
                                <a href="{{ $personLink }}">{{ $item->biogmain->c_name_chn.' '.$item->biogmain->c_name }}</a>
                            @endif
                            </td>
                            <td>{{ $item->resource }}</td>
                            @php
                                $diffSource = $item->resource_diff ?? $item->resource_original;
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
                                $isProposal = in_array((int) $item->op_type, [\App\Models\Operation::TYPE_PROPOSAL_CREATE, \App\Models\Operation::TYPE_PROPOSAL_UPDATE], true);
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
                                    $canCompare = $hasDiffContent && (int)$item->op_type !== 4;
                                @endphp
                            <td>
                                @if($isProposal)
                                    <div class="proposal-status" style="margin-bottom:6px;">
                                        @if($reviewStatus === 'approved')
                                            <span class="badge badge-success">已核准</span>
                                        @elseif($reviewStatus === 'rejected')
                                            <span class="badge badge-danger">已退修</span>
                                        @elseif($reviewStatus === 'cancelled')
                                            <span class="badge badge-secondary">已撤回</span>
                                        @else
                                            <span class="badge badge-warning">待審核</span>
                                        @endif
                                @if(!empty($proposalMeta['comment']))
                                    <small class="text-muted" style="display:block;">
                                        提案說明：{{ $proposalMeta['comment'] }}
                                    </small>
                                @endif
                                @if(!empty($proposalMeta['submitted_by']))
                                            <small class="text-muted" style="display:block;">
                                                提案者：{{ $proposalMeta['submitted_by'] }}
                                                @if($submittedUtc)
                                                    （<span class="js-utc-datetime" data-utc="{{ $submittedUtc }}">{{ $submittedDisplay }}</span>）
                                                @endif
                                            </small>
                                        @endif
                                        @if($reviewStatus === 'cancelled')
                                            <small class="text-muted" style="display:block;">
                                                撤回者：{{ $cancelledBy ?? '（未知）' }}
                                                @if($cancelledUtc)
                                                    （<span class="js-utc-datetime" data-utc="{{ $cancelledUtc }}">{{ $cancelledDisplay }}</span>）
                                                @endif
                                            </small>
                                            @if(!empty($proposalMeta['cancel_reason']))
                                                <small class="text-muted" style="display:block;">撤回原因：{{ $proposalMeta['cancel_reason'] }}</small>
                                            @endif
                                        @endif
                                        @if($reviewComment)
                                            <small class="text-muted" style="display:block;">審核備註：{{ $reviewComment }}</small>
                                        @endif
                                        @if($reviewedBy)
                                            <small class="text-muted" style="display:block;">
                                                審核者：{{ $reviewedBy }}
                                                @if($reviewedUtc)
                                                    （<span class="js-utc-datetime" data-utc="{{ $reviewedUtc }}">{{ $reviewedDisplay }}</span>）
                                                @endif
                                            </small>
                                        @endif
                                    </div>
                                @endif
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#myModal{{ $item->id }}">內容快照</button>
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#myModal-mapping{{ $item->id }}"
                                    {{ $canCompare ? '' : 'disabled' }}>
                                    比較
                                </button>

                                <div id="myModal{{ $item->id }}" class="modal fade" role="dialog" tabindex="-1">
                                  <div class="modal-dialog">
                                    <div class="modal-content">
                                      <div class="modal-header">
                                        <h4 class="modal-title">內容快照</h4>
                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                      </div>
                                      <div class="modal-body" style="word-break: break-all;">
                                        @include('components.key-value-table', ['data' => $resourceDataDisplay])
                                      </div>
                                      <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                      </div>
                                    </div>
                                  </div>
                                </div>

                                <div id="myModal-mapping{{ $item->id }}" class="modal fade" role="dialog" tabindex="-1">
                                  <div class="modal-dialog modal-lg" style="width:80vw;max-width:80vw;">
                                    <div class="modal-content">
                                      <div class="modal-header">
                                        <h4 class="modal-title">比較</h4>
                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                      </div>
                                      <div class="modal-body" style="word-break: break-all;">
                                        <div>
                                        @include('components.diff-table', ['diff' => $diffSource])
                                        </div>
                                      </div>
                                      <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                      </div>
                                    </div>
                                  </div>
                                </div>
                                @if(Auth::check() && Auth::user()->isAdmin() && in_array((int)$item->op_type, [3,4]) && $item->resource !== 'POSTED_TO_ADDR_DATA' && $canCompare)
                                    <form method="post" action="{{ route('operations.restore', $item->id) }}" style="display:inline;">
                                        {{ csrf_field() }}
                                        <button type="submit" class="btn btn-warning"
                                            onclick="return confirm('將以你的名義對該資源進行一次修改，恢復至本次改動之前，是否繼續？');">
                                            復原
                                        </button>
                                    </form>
                                @endif
                                @if($canEditProposal)
                                    <div class="proposal-actions" style="margin-top:8px;">
                                        <a href="{{ route('codes.proposals.edit', ['table_name' => $item->resource, 'operation' => $item->id]) }}"
                                           class="btn btn-secondary btn-sm">
                                            修改提案
                                        </a>
                                        <form method="post"
                                              action="{{ route('codes.proposals.cancel', ['table_name' => $item->resource, 'operation' => $item->id]) }}"
                                              style="display:inline;">
                                            {{ csrf_field() }}
                                            {{ method_field('DELETE') }}
                                            <button type="submit" class="btn btn-warning btn-sm"
                                                    onclick="return confirm('確定要撤回此提案？');">
                                                撤回提案
                                            </button>
                                        </form>
                                    </div>
                                @endif
                                @if($isProposal && Auth::check() && Auth::user()->canManageUsers() && $reviewStatus === 'pending')
                                    <div class="proposal-actions" style="margin-top:8px;">
                                        <form method="post" action="{{ route('operations.proposals.approve', $item->id) }}" style="display:inline;">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="review_comment" value="">
                                            <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('確定核准此提案並寫入資料表？');">
                                                核准
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-danger btn-sm" data-toggle="modal" data-target="#proposal-reject-{{ $item->id }}">
                                            退修
                                        </button>
                                    </div>
                                    <div id="proposal-reject-{{ $item->id }}" class="modal fade" role="dialog" tabindex="-1">
                                      <div class="modal-dialog">
                                        <div class="modal-content">
                                          <form method="post" action="{{ route('operations.proposals.reject', $item->id) }}">
                                              {{ csrf_field() }}
                                              <div class="modal-header">
                                                <h4 class="modal-title">退修提案</h4>
                                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                              </div>
                                              <div class="modal-body">
                                                <div class="form-group">
                                                    <label for="proposal-review-comment-{{ $item->id }}">退修原因（選填）</label>
                                                    <textarea name="review_comment" id="proposal-review-comment-{{ $item->id }}" class="form-control" rows="3"></textarea>
                                                </div>
                                              </div>
                                              <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                                                <button type="submit" class="btn btn-danger">確認退修</button>
                                              </div>
                                          </form>
                                        </div>
                                      </div>
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if($resourceLink)
                                    <a href="{{ $resourceLink }}">{{ $item->resource_id }}</a>
                                @else
                                    {{ $item->resource_id }}
                                @endif
                            </td>
                            <td>
                                @php
                                    $opTypeLabels = [
                                        1 => '1-新增',
                                        2 => '2-整體覆寫',
                                        3 => '3-修改',
                                        4 => '4-刪除',
                                        \App\Models\Operation::TYPE_PROPOSAL_CREATE => '8-提案（新增）',
                                        \App\Models\Operation::TYPE_PROPOSAL_UPDATE => '9-提案（修改）',
                                    ];
                                @endphp
                                {{ $opTypeLabels[$item->op_type] ?? $item->op_type }}
                            </td>
                            <td>{{ $item->user->name }}</td>
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
                            <td class="js-utc-datetime" data-utc="{{ $updatedUtc }}">
                                {{ $updatedDisplay }}
                            </td>
                        </tr>
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
});

</script>
@endsection
