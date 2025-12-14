@extends('layouts.dashboard-v3')

@push('styles')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
@endpush

@section('content')

    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">最近眾包錄入記錄</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="example1" class="table table-bordered table-striped table-sm">
                <p>* 修改類型 0表示crowdsourcing記錄，1表示新增，2表示整體覆寫（完整替換現有記錄，主要用於 code 表修改），3表示修改，4表示刪除，8表示記錄新增提案，9表示記錄修改提案<br />
                * 狀態 1表示crowdsourcing記錄並已插入數據庫，2表示記錄尚未處理，3表示記錄reject，4表示記錄處理失敗。
                </p>
                <thead>
                <tr>

                    <th>修改資源</th>
                    <th>修改值</th>
                    <th>資源 TTS</th>
                    <th>修改類型</th>
                    <th>修改人</th>
                    <th>次數</th>
                    <th>錄入時間</th>
                    <th>狀態</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                    @foreach($lists as $item)
                        <tr>

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
                            @endphp
                            <td>
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#myModal{{ $item->id }}">resource_data</button>
                                <button type="button" class="btn btn-info" data-toggle="modal" data-target="#myModal-mapping{{ $item->id }}"
                                    {{ $hasDiffContent ? '' : 'disabled' }}>
                                    compare
                                </button>                                
                                <div id="myModal{{ $item->id }}" class="modal fade" role="dialog" tabindex="-1">
                                  <div class="modal-dialog">
                                    <div class="modal-content">
                                      <div class="modal-header">
                                        <h4 class="modal-title">resource_data</h4>
                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                      </div>
                                      <div class="modal-body" style="word-break: break-all;">
                                        @include('components.key-value-table', ['data' => $resourceDataParsed])
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
                                        <h4 class="modal-title">compare</h4>
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
                            </td>
                            <td>{{ $item->resource_id }}</td>
                            <td>{{ $item->op_type }}</td>
                            <td>{{ $item->user->name }}</td>
                            <td>{{ $item->rate }}</td>
                            @php
                                $createdUtc = '';
                                $createdDisplay = '';
                                $createdAtRaw = $item->created_at;
                                $appTimezone = config('app.timezone', 'Asia/Shanghai');
                                if ($createdAtRaw instanceof \Carbon\Carbon) {
                                    $createdDisplay = $createdAtRaw;
                                    $createdUtc = $createdAtRaw->copy()->setTimezone('UTC')->toIso8601String();
                                } elseif (is_string($createdAtRaw) && trim($createdAtRaw) !== '') {
                                    $createdDisplay = trim($createdAtRaw);
                                    try {
                                        $parsed = \Carbon\Carbon::parse($createdAtRaw, $appTimezone);
                                        $createdUtc = $parsed->setTimezone('UTC')->toIso8601String();
                                    } catch (\Exception $e) {
                                        $createdUtc = $createdDisplay;
                                    }
                                }
                            @endphp
                            <td class="js-utc-datetime" data-utc="{{ $createdUtc }}">
                                {{ $createdDisplay }}
                            </td>
                            <td>{{ $item->crowdsourcing_status }}</td>
                            <td>
                                @if($item->crowdsourcing_status == 2 and Auth::check() and !Auth::user()->isCrowdsourcingUser())
                                <a href="../../crowdsourcing/{{$item->id}}/confirm" type="button" class="btn btn-success">confirm</a>
                                <a href="../../crowdsourcing/{{$item->id}}/reject" type="button" class="btn btn-danger">reject</a>
                                @endif
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
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script>
        var userTimeZone = (Intl.DateTimeFormat().resolvedOptions().timeZone) || 'UTC';
        var userOffsetMinutes = new Date().getTimezoneOffset();

        function formatTimestamp(utcTimeString, targetTimeZone) {
            try {
                var utcDate = new Date(utcTimeString);
                if (isNaN(utcDate.getTime())) {
                    console.warn('Invalid time:', utcTimeString);
                    return utcTimeString;
                }

                var zone = targetTimeZone || userTimeZone;
                var parts = new Intl.DateTimeFormat(undefined, {
                    timeZone: zone,
                    timeZoneName: 'short'
                }).formatToParts(utcDate);
                var timeZoneName = '';
                for (var i = 0; i < parts.length; i++) {
                    if (parts[i].type === 'timeZoneName') {
                        timeZoneName = parts[i].value || '';
                        break;
                    }
                }

                var dateTimeWithoutTZ = utcDate.toLocaleString('sv-SE', {
                    timeZone: zone,
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: false
                });

                return dateTimeWithoutTZ + ' ' + timeZoneName;
            } catch (error) {
                console.warn('Time conversion failed:', utcTimeString, error);
                return utcTimeString;
            }
        }

        function applyTimestampFormatting() {
            var nodes = document.querySelectorAll('.js-utc-datetime');
            Array.prototype.forEach.call(nodes, function (node) {
                var original = node.getAttribute('data-utc') || (node.textContent || '').trim();
                if (!original) {
                    return;
                }

                var displayText = formatTimestamp(original);
                node.textContent = displayText;
                if (userOffsetMinutes !== -480) {
                    var chinaText = formatTimestamp(original, 'Asia/Shanghai');
                    if (chinaText && chinaText !== original) {
                        node.setAttribute('title', chinaText);
                    } else {
                        node.removeAttribute('title');
                    }
                } else {
                    node.removeAttribute('title');
                }
            });
        }

        $(function() {
            $('#example1').DataTable({
                lengthMenu: [10, 25, 50, 75, 100, 150, 200],
                pageLength: 100,
                order: [[6, 'desc']],
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/zh-HANT.json'
                }
            });

            applyTimestampFormatting();
        });
    </script>
@endsection
