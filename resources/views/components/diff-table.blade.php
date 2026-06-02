@php
    $diff = $diff ?? null;
@endphp

@if (is_array($diff) && ($diff['type'] ?? null) === 'POSTED_TO_ADDR_DATA')
    @include('components.posted-to-addr-diff', ['diff' => $diff])
@elseif (is_array($diff))
    @php
        $rows = $diff['rows'] ?? [];
        $note = $diff['note'] ?? null;
    @endphp
    @if (!empty($rows))
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-condensed diff-table diff-table--quad">
                <colgroup>
                    <col style="width:10%;">
                    <col style="width:30%;">
                    <col style="width:30%;">
                    <col style="width:30%;">
                </colgroup>
                <thead>
                    <tr>
                        <th>{{ __('operations.diff_field') }}</th>
                        <th>{{ __('operations.diff_before') }}</th>
                        <th>{{ __('operations.diff_after') }}</th>
                        <th>{{ __('operations.diff_current') }}</th>
                    </tr>
                </thead>
                <tbody>
                   @foreach ($rows as $row)
                       @php
                            $afterMatchesCurrent = !empty($row['matches_current']);
                            $beforeMatchesCurrent = !empty($row['matches_before']);
                            $currentClass = $afterMatchesCurrent ? 'success' : ($beforeMatchesCurrent ? 'info' : '');
                       @endphp
                       <tr>
                           <td>{{ $row['field'] }}</td>
                            <td class="{{ $beforeMatchesCurrent ? 'info' : '' }}">{{ $row['before'] }}</td>
                            <td class="{{ $afterMatchesCurrent ? 'success' : '' }}">{{ $row['after'] }}</td>
                            <td class="{{ $currentClass }}">{{ $row['current'] ?? __('operations.not_retrieved') }}</td>
                       </tr>
                   @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-muted small">{{ __('operations.no_diff_records') }}</p>
    @endif
@elseif (is_string($diff) && trim($diff) !== '')
    {!! $diff !!}
@else
    <p class="text-muted small">{{ __('operations.no_diff_records') }}</p>
@endif
