@php
    $dataset = $data ?? null;

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
@endphp

@if (is_array($dataset))
    @php
        $rows = [];
        foreach ($dataset as $key => $value) {
            if (in_array($key, ['_method', '_token'], true)) {
                continue;
            }
            $rows[] = [
                'field' => $key,
                'value' => $formatValue($value),
            ];
        }
    @endphp

    @if (empty($rows))
        <p class="text-muted small">{{ __('common.no_data') }}</p>
    @else
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-condensed">
                <thead>
                    <tr>
                        <th>{{ __('operations.diff_field') }}</th>
                        <th>{{ __('operations.content_col') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row['field'] }}</td>
                            <td>{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@elseif (is_string($dataset) && trim($dataset) !== '')
    <pre>{{ $dataset }}</pre>
@else
    <p class="text-muted small">{{ __('common.no_data') }}</p>
@endif
