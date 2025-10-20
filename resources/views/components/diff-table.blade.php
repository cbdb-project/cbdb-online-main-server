@php
    $diff = $diff ?? null;
@endphp

@if (is_array($diff))
    @php
        $rows = $diff['rows'] ?? [];
        $note = $diff['note'] ?? null;
    @endphp
    @if (!empty($rows))
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-condensed">
                <thead>
                    <tr>
                        <th>欄位</th>
                        <th>原本的</th>
                        <th>修改後</th>
                        <th>目前資料</th>
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
                            <td class="{{ $currentClass }}">{{ $row['current'] ?? '(未取得)' }}</td>
                       </tr>
                   @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-muted small">沒有比對紀錄</p>
    @endif
@elseif (is_string($diff) && trim($diff) !== '')
    {!! $diff !!}
@else
    <p class="text-muted small">沒有比對紀錄</p>
@endif
