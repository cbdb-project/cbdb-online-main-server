@extends('layouts.dashboard-v3')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-clipboard-check mr-1"></i> {{ $page_title }}</h3>
            </div>

            <div class="card-body">
                <form method="GET" action="{{ route('admin.audit-logs') }}" class="mb-3">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="search">關鍵字</label>
                                <input type="text" class="form-control" id="search" name="search"
                                       value="{{ $filters['search'] ?? '' }}"
                                       placeholder="operation_id / row_pk_text / table_name">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="table_name">資料表</label>
                                <select class="form-control" id="table_name" name="table_name">
                                    <option value="">全部</option>
                                    @foreach($table_names as $tableName)
                                        <option value="{{ $tableName }}" {{ ($filters['table_name'] ?? '') == $tableName ? 'selected' : '' }}>
                                            {{ $tableName }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="operation">操作</label>
                                <select class="form-control" id="operation" name="operation">
                                    <option value="">全部</option>
                                    @foreach(['INSERT', 'UPDATE', 'DELETE'] as $op)
                                        <option value="{{ $op }}" {{ ($filters['operation'] ?? '') == $op ? 'selected' : '' }}>
                                            {{ $op }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="actor_type">角色</label>
                                <select class="form-control" id="actor_type" name="actor_type">
                                    <option value="">全部</option>
                                    @foreach($actor_types as $actorType)
                                        <option value="{{ $actorType }}" {{ ($filters['actor_type'] ?? '') == $actorType ? 'selected' : '' }}>
                                            {{ $actorType }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="actor_id">操作者 ID</label>
                                <input type="text" class="form-control" id="actor_id" name="actor_id"
                                       value="{{ $filters['actor_id'] ?? '' }}"
                                       placeholder="例如 1">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> 搜尋
                            </button>
                            @if(($filters['search'] ?? '') || ($filters['table_name'] ?? '') || ($filters['operation'] ?? '') || ($filters['actor_type'] ?? '') || ($filters['actor_id'] ?? ''))
                                <a href="{{ route('admin.audit-logs') }}" class="btn btn-secondary ml-1">
                                    <i class="fas fa-times"></i> 清除
                                </a>
                            @endif
                        </div>
                    </div>
                </form>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    共 {{ $logs->total() }} 筆記錄，顯示第 {{ $logs->firstItem() ?? 0 }} - {{ $logs->lastItem() ?? 0 }} 筆
                </div>

                @if($logs->isEmpty())
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 暫無記錄
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>時間</th>
                                    <th>表名</th>
                                    <th>操作</th>
                                    <th>操作者</th>
                                    <th>PK</th>
                                    <th>operation_id</th>
                                    <th>資料</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($logs as $log)
                                    @php
                                        $rowPk = $log->row_pk ? json_decode($log->row_pk, true) : null;
                                        $oldData = $log->old_data ? json_decode($log->old_data, true) : null;
                                        $newData = $log->new_data ? json_decode($log->new_data, true) : null;
                                        $formatValue = function ($value) {
                                            if (is_array($value) || is_object($value)) {
                                                return json_encode($value, JSON_UNESCAPED_UNICODE);
                                            }
                                            if ($value === null) {
                                                return '(null)';
                                            }
                                            if (is_bool($value)) {
                                                return $value ? 'true' : 'false';
                                            }
                                            return (string) $value;
                                        };
                                        $normalizeValue = function ($value) {
                                            if (is_array($value) || is_object($value)) {
                                                return json_encode($value);
                                            }
                                            if ($value === null) {
                                                return '';
                                            }
                                            if (is_bool($value)) {
                                                return $value ? '1' : '0';
                                            }
                                            return trim((string) $value);
                                        };
                                        $oldArr = is_array($oldData) ? $oldData : [];
                                        $newArr = is_array($newData) ? $newData : [];
                                        $allKeys = array_unique(array_merge(array_keys($oldArr), array_keys($newArr)));
                                        $diffRows = [];
                                        foreach ($allKeys as $key) {
                                            if (in_array($key, ['_method', '_token'], true)) {
                                                continue;
                                            }
                                            $beforeRaw = array_key_exists($key, $oldArr) ? $oldArr[$key] : null;
                                            $afterRaw = array_key_exists($key, $newArr) ? $newArr[$key] : null;
                                            if ($normalizeValue($beforeRaw) === $normalizeValue($afterRaw)) {
                                                continue;
                                            }
                                            $diffRows[] = [
                                                'field' => $key,
                                                'before' => $formatValue($beforeRaw),
                                                'after' => $formatValue($afterRaw),
                                                'current' => '(未取得)',
                                                'matches_current' => false,
                                                'matches_before' => false,
                                            ];
                                        }
                                        $diffSource = !empty($diffRows) ? ['rows' => $diffRows] : null;
                                    @endphp
                                    <tr>
                                        <td>{{ $log->id }}</td>
                                        <td>
                                            @php
                                                $appTimezone = config('app.timezone', 'Asia/Shanghai');
                                                $occurredUtc = '';
                                                $occurredDisplay = '';
                                                $occurredRaw = $log->occurred_at;
                                                if ($occurredRaw instanceof \Carbon\Carbon) {
                                                    $occurredDisplay = $occurredRaw;
                                                    $occurredUtc = $occurredRaw->copy()->setTimezone('UTC')->toIso8601String();
                                                } elseif (is_string($occurredRaw) && trim($occurredRaw) !== '') {
                                                    $occurredDisplay = trim($occurredRaw);
                                                    try {
                                                        $parsed = \Carbon\Carbon::parse($occurredRaw, $appTimezone);
                                                        $occurredUtc = $parsed->setTimezone('UTC')->toIso8601String();
                                                    } catch (\Exception $e) {
                                                        $occurredUtc = $occurredDisplay;
                                                    }
                                                }

                                                $createdUtc = '';
                                                $createdDisplay = '';
                                                $createdRaw = $log->created_at;
                                                if ($createdRaw instanceof \Carbon\Carbon) {
                                                    $createdDisplay = $createdRaw;
                                                    $createdUtc = $createdRaw->copy()->setTimezone('UTC')->toIso8601String();
                                                } elseif (is_string($createdRaw) && trim($createdRaw) !== '') {
                                                    $createdDisplay = trim($createdRaw);
                                                    try {
                                                        $parsed = \Carbon\Carbon::parse($createdRaw, $appTimezone);
                                                        $createdUtc = $parsed->setTimezone('UTC')->toIso8601String();
                                                    } catch (\Exception $e) {
                                                        $createdUtc = $createdDisplay;
                                                    }
                                                }
                                            @endphp
                                            <div class="js-utc-datetime" data-utc="{{ $occurredUtc }}">
                                                {{ $occurredDisplay }}
                                            </div>
                                            @if($log->created_at !== $log->occurred_at)
                                                <small class="text-muted js-utc-datetime" data-utc="{{ $createdUtc }}">
                                                    寫入：{{ $createdDisplay }}
                                                </small>
                                            @endif
                                        </td>
                                        <td>{{ $log->table_name }}</td>
                                        <td>
                                            <span class="badge badge-{{ $log->operation === 'DELETE' ? 'danger' : ($log->operation === 'INSERT' ? 'success' : 'warning') }}">
                                                {{ $log->operation }}
                                            </span>
                                        </td>
                                        <td>
                                            <div>{{ $log->actor_type }}</div>
                                            <small class="text-muted">{{ $log->actor_id }}</small>
                                        </td>
                                        <td>
                                            <div class="text-monospace">{{ $log->row_pk_text }}</div>
                                            @if($rowPk)
                                                <small class="text-muted">{{ json_encode($rowPk, JSON_UNESCAPED_UNICODE) }}</small>
                                            @endif
                                        </td>
                                        <td class="text-monospace">{{ $log->operation_id }}</td>
                                        <td>
                                            <div class="btn-group mb-2" role="group">
                                                <button type="button" class="btn btn-info btn-sm" data-toggle="modal" data-target="#audit-diff-{{ $log->id }}"
                                                    {{ $diffSource ? '' : 'disabled' }}>
                                                    比較
                                                </button>
                                                <button type="button" class="btn btn-secondary btn-sm" data-toggle="modal" data-target="#audit-old-{{ $log->id }}"
                                                    {{ $oldData ? '' : 'disabled' }}>
                                                    old_data
                                                </button>
                                                <button type="button" class="btn btn-secondary btn-sm" data-toggle="modal" data-target="#audit-new-{{ $log->id }}"
                                                    {{ $newData ? '' : 'disabled' }}>
                                                    new_data
                                                </button>
                                            </div>
                                            @if(!$oldData && !$newData)
                                                <div class="text-muted">—</div>
                                            @endif

                                            <div id="audit-diff-{{ $log->id }}" class="modal fade" role="dialog" tabindex="-1">
                                                <div class="modal-dialog modal-lg" style="width:80vw;max-width:80vw;">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h4 class="modal-title">比較</h4>
                                                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                        </div>
                                                        <div class="modal-body" style="word-break: break-all;">
                                                            @include('components.diff-table', ['diff' => $diffSource])
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div id="audit-old-{{ $log->id }}" class="modal fade" role="dialog" tabindex="-1">
                                                <div class="modal-dialog modal-lg">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h4 class="modal-title">old_data</h4>
                                                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                        </div>
                                                        <div class="modal-body" style="word-break: break-all;">
                                                            @include('components.key-value-table', ['data' => $oldData])
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div id="audit-new-{{ $log->id }}" class="modal fade" role="dialog" tabindex="-1">
                                                <div class="modal-dialog modal-lg">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h4 class="modal-title">new_data</h4>
                                                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                        </div>
                                                        <div class="modal-body" style="word-break: break-all;">
                                                            @include('components.key-value-table', ['data' => $newData])
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3">
                        {{ $logs->links() }}
                    </div>
                @endif
            </div>
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
