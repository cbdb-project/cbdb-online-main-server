@extends('layouts.dashboard-v3')

@push('styles')
    {{-- 使用 Vite 構建的 DataTables 資產，避免 CDN 與 jQuery 載入順序問題 --}}
    @vite(['resources/js/datatables.js'])
@endpush

@section('content')

    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('nav.crowdsourcing_records') }}</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="example1" class="table table-bordered table-striped table-sm">
                <p>{{ __('operations.crowdsourcing_op_type_desc') }}<br>
                {{ __('operations.crowdsourcing_status_desc') }}
                </p>
                <thead>
                <tr>

                    <th>{{ __('operations.modified_resource') }}</th>
                    <th>{{ __('operations.modified_value') }}</th>
                    <th>{{ __('operations.resource_tts') }}</th>
                    <th>{{ __('operations.operation_type') }}</th>
                    <th>{{ __('operations.modified_by') }}</th>
                    <th>{{ __('operations.count') }}</th>
                    <th>{{ __('operations.entry_time') }}</th>
                    <th>{{ __('operations.status_label') }}</th>
                    <th>{{ __('codes.actions') }}</th>
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
                                <a href="../../crowdsourcing/{{$item->id}}/confirm" type="button" class="btn btn-success">{{ __('operations.confirm_btn') }}</a>
                                <a href="../../crowdsourcing/{{$item->id}}/reject" type="button" class="btn btn-danger">{{ __('operations.reject_btn') }}</a>
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
    <script>
        var getFormatTimestampFn = function() {
            return typeof window.formatTimestamp === 'function'
                ? window.formatTimestamp
                : function(value) { return value; };
        };
        var userOffsetMinutes = typeof window.getUserOffsetMinutes === 'function'
            ? window.getUserOffsetMinutes()
            : new Date().getTimezoneOffset();

        function applyTimestampFormatting() {
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
        }

        onViteReady(function() {
            // 等待 DOM ready，確保 DataTables 插件已註冊在 jQuery 上
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
        });
    </script>
@endsection
